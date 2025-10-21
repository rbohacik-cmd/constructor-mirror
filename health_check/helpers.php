<?php
declare(strict_types=1);

/**
 * Helpers for Health-Check controllers:
 * - Import root & path resolution
 * - Jobs tables bootstrap
 * - Register local upload & process upload (with per-table advisory lock + queueing)
 *
 * Requires:
 *   - db(), qi(), qexec(), qrow(), qall(), qlastid(), qcell()
 *   - debug_sentinel
 *   - health_check_lib.php (hc_cfg, hc_ensure_manufacturer, hc_ensure_data_table,
 *     hc_read_csv, hc_read_xlsx, hc_guess_mapping, hc_insert_rows, hc_safe_json, etc.)
 */

/* ---------- Import roots & path helpers ---------- */

if (!function_exists('hc_import_root')) {
function hc_import_root(): string {
  $win = (string)hc_cfg('import_root_win', 'C:\\xampp\\htdocs\\imports');
  $nix = (string)hc_cfg('import_root_unx', '/var/xampp/htdocs/imports');
  return stripos(PHP_OS_FAMILY, 'Windows') !== false ? $win : $nix;
}}

if (!function_exists('hc_is_abs_path')) {
function hc_is_abs_path(string $p): bool {
  if ($p === '') return false;
  if ($p[0] === '/' || $p[0] === '\\') return true; // *nix / UNC
  return (bool)preg_match('/^[a-zA-Z]:[\\\\\\/]/', $p); // C:\ or C:/
}}

if (!function_exists('hc_resolve_local_path')) {
/** @return array{0:?string,1:?string} [absolutePath|null, error|null] */
function hc_resolve_local_path(string $requested): array {
  $req  = trim($requested);
  $root = rtrim(hc_import_root(), "\\/");

  if ($req === '') return [null, 'Empty file path'];

  // Absolute -> return directly (caller will validate is_file)
  if (hc_is_abs_path($req)) return [$req, null];

  // Normalize & validate
  $rel = str_replace('\\', '/', $req);
  if (str_contains($rel, '..')) return [null, 'Invalid path (.. not allowed)'];
  if (preg_match('/[[:cntrl:]]/u', $rel)) return [null, 'Invalid path'];
  if (!preg_match('~^[A-Za-z0-9 _.\-()/\[\]]+$~u', $rel)) {
    return [null, 'Invalid characters in path'];
  }

  $abs  = $root . '/' . ltrim($rel, '/');
  $real = @realpath($abs);
  if ($real !== false) {
    $rootReal = @realpath($root) ?: $root;
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
      if (strncasecmp($real, $rootReal, strlen($rootReal)) !== 0) return [null, 'Resolved path escapes import root'];
    } else {
      if (strncmp($real, $rootReal, strlen($rootReal)) !== 0) return [null, 'Resolved path escapes import root'];
    }
    return [$real, null];
  }

  // If it doesn't exist yet, return joined absolute; caller will check.
  return [$abs, null];
}}

/* ---------- Manufacturer busy state (pre-flight checks for controllers) ---------- */

if (!function_exists('hc_manufacturer_busy')) {
/** Is there a RUNNING import for given manufacturer id? */
function hc_manufacturer_busy(PDO $pdo, int $manufacturerId): bool {
  $cnt = (int)qcell("
    SELECT COUNT(*)
    FROM hc_progress p
    JOIN hc_uploads u ON u.id = p.upload_id
    WHERE u.manufacturer_id = ?
      AND p.status = 'running'
  ", [$manufacturerId]);
  return $cnt > 0;
}}

if (!function_exists('hc_running_upload_for_manufacturer')) {
/** Return the most recent running upload_id for the manufacturer (or null). */
function hc_running_upload_for_manufacturer(PDO $pdo, int $manufacturerId): ?int {
  $id = qcell("
    SELECT p.upload_id
    FROM hc_progress p
    JOIN hc_uploads u ON u.id = p.upload_id
    WHERE u.manufacturer_id = ?
      AND p.status = 'running'
    ORDER BY p.updated_at DESC
    LIMIT 1
  ", [$manufacturerId]);
  return $id ? (int)$id : null;
}}

/* ---------- Per-table advisory lock (serialize same-manufacturer imports) ---------- */

if (!function_exists('hc_import_lock_key')) {
function hc_import_lock_key(string $table): string {
  return 'hc:import:' . strtolower($table);
}}

if (!function_exists('hc_acquire_import_lock')) {
/** Try to acquire a per-table lock. Returns true if acquired. */
function hc_acquire_import_lock(PDO $pdo, string $table, int $timeoutSeconds = 180): bool {
  $key = hc_import_lock_key($table);
  $stmt = $pdo->prepare('SELECT GET_LOCK(:k, :t)');
  $stmt->execute([':k' => $key, ':t' => $timeoutSeconds]);
  return (int)$stmt->fetchColumn() === 1;
}}

if (!function_exists('hc_release_import_lock')) {
/** Release a per-table lock (best-effort). */
function hc_release_import_lock(PDO $pdo, string $table): void {
  try {
    $key = hc_import_lock_key($table);
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:k)');
    $stmt->execute([':k' => $key]);
  } catch (Throwable $ignore) {}
}}

/* ---------- Jobs schema bootstrap (incl. failed rows for rescue mode) ---------- */

if (!function_exists('hc_jobs_ensure_tables')) {
function hc_jobs_ensure_tables(PDO $pdo): void {
  qexec("
    CREATE TABLE IF NOT EXISTS hc_import_jobs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      manufacturer VARCHAR(255) NOT NULL,
      file_path VARCHAR(1024) NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      notes VARCHAR(1000) NULL,
      last_status VARCHAR(50) NOT NULL DEFAULT 'never',
      last_import_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY(enabled), KEY(manufacturer), KEY(last_import_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  qexec("
    CREATE TABLE IF NOT EXISTS hc_import_runs_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      job_id INT NOT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      status VARCHAR(50) NOT NULL DEFAULT 'started',
      message VARCHAR(2000) NULL,
      stats_json JSON NULL,
      KEY(job_id),
      CONSTRAINT fk_runs_jobs FOREIGN KEY (job_id) REFERENCES hc_import_jobs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // used by hc_insert_rows() rescue path
  qexec("
    CREATE TABLE IF NOT EXISTS hc_failed_rows (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      table_name VARCHAR(255) NOT NULL,
      upload_id BIGINT UNSIGNED NULL,
      chunk_index INT UNSIGNED NULL,
      source_row INT UNSIGNED NULL,
      last_key VARCHAR(255) NULL,
      error VARCHAR(1000) NULL,
      raw_excerpt MEDIUMTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY (upload_id), KEY (table_name), KEY (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}}

/* ---------- Register a local file as an upload ---------- */

if (!function_exists('hc_register_local_upload')) {
function hc_register_local_upload(PDO $pdo, string $manufacturer, string $fullPath, debug_sentinel $sentinel): int {
  if (!is_file($fullPath)) throw new RuntimeException("File not found: {$fullPath}");

  // Ensure manufacturer + data table
  $mfg   = hc_ensure_manufacturer($pdo, $manufacturer);
  $table = (string)$mfg['table_name'];
  hc_ensure_data_table($pdo, $table);

  // Copy file into module storage
  $base    = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2); // /health_check
  $storage = $base . '/storage/health_check';
  if (!is_dir($storage)) @mkdir($storage, 0775, true);

  $orig = basename($fullPath);
  $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  $safe = preg_replace('~[^a-zA-Z0-9_.-]+~', '_', pathinfo($orig, PATHINFO_FILENAME));
  $dest = $storage . '/' . date('Ymd_His') . '_' . $safe . '.' . $ext;

  if (!@copy($fullPath, $dest)) throw new RuntimeException("Failed to copy file to storage: {$dest}");

  // Register upload
  qi("INSERT INTO `hc_uploads` (`manufacturer_id`,`filename`,`stored_path`,`mime`,`status`)
      VALUES (?,?,?,?,?)",
     [$mfg['id'], $orig, $dest, null, 'queued']);
  $uploadId = (int)qlastid();

  // Seed progress
  qi("INSERT INTO `hc_progress`
        (`upload_id`,`status`,`processed`,`bytes_written`,`started_at`)
      VALUES (?,?,0,0,NOW())
      ON DUPLICATE KEY UPDATE
        `status`=VALUES(`status`),
        `processed`=0,
        `bytes_written`=0,
        `started_at`=NOW(),
        `updated_at`=NOW()",
     [$uploadId, 'running']);

  // Quick total estimate
  try {
    if ($ext === 'csv' || $ext === 'txt') {
      $lines = 0; if ($fh = fopen($dest, 'rb')) { while (!feof($fh)) { fgets($fh); $lines++; } fclose($fh); }
      if ($lines > 1) qi("UPDATE `hc_progress` SET `total_rows`=? WHERE `upload_id`=?", [$lines - 1, $uploadId]);
    } elseif ($ext === 'xlsx' || $ext === 'xls') {
      $rows = hc_read_xlsx($dest);
      qi("UPDATE `hc_progress` SET `total_rows`=? WHERE `upload_id`=?", [count($rows), $uploadId]);
      unset($rows);
    }
  } catch (Throwable $ignore) {}

  $sentinel->info('Local upload registered', [
    'upload_id' => $uploadId, 'file' => $dest, 'manufacturer' => $mfg['name']
  ]);

  return $uploadId;
}}

/* ---------- Process an existing upload_id (with per-table lock + queueing) ---------- */

if (!function_exists('hc_process_upload')) {
function hc_process_upload(PDO $pdo, int $uploadId, debug_sentinel $sentinel): array {
  $u = qrow("
    SELECT u.*, m.id AS mid, m.slug AS mslug, m.table_name AS mtable, m.name AS mname
    FROM `hc_uploads` u
    JOIN `hc_manufacturers` m ON m.id = u.manufacturer_id
    WHERE u.id = ?
  ", [$uploadId]);
  if (!$u) throw new RuntimeException('Upload not found.');

  $path  = (string)$u['stored_path'];
  $slug  = (string)$u['mslug'];
  $table = (string)$u['mtable'];
  $mname = (string)$u['mname'];
  $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));

  $sentinel->info('Processing started', ['upload_id'=>$uploadId, 'slug'=>$slug, 'ext'=>$ext, 'table'=>$table]);

  // ===== Acquire per-table advisory lock (serialize by manufacturer/table) =====
  // Fast try (1s). If busy, mark queued and wait up to 300s.
  if (!hc_acquire_import_lock($pdo, $table, 1)) {
    qi("UPDATE `hc_progress`
          SET `status`='queued', `note`='waiting for another import to finish', `updated_at`=NOW()
        WHERE `upload_id`=?", [$uploadId]);

    if (!hc_acquire_import_lock($pdo, $table, 300)) { // wait up to 5 minutes
      $msg = 'Another import is still running for this manufacturer.';
      qexec("UPDATE `hc_uploads`  SET `status`='failed', `error_message`=? WHERE `id`=?", [$msg, $uploadId]);
      qexec("UPDATE `hc_progress` SET `status`='failed', `note`=?, `updated_at`=NOW() WHERE `upload_id`=?", ['import lock not acquired', $uploadId]);
      $sentinel->warn('import_lock_not_acquired', ['table'=>$table, 'upload_id'=>$uploadId]);
      throw new RuntimeException('Import lock not acquired (concurrent import in progress).');
    }

    // got the lock now, flip back to running
    qi("UPDATE `hc_progress` SET `status`='running', `note`=NULL, `updated_at`=NOW() WHERE `upload_id`=?", [$uploadId]);
  }

  try {
    // Read rows (now that we own the lock)
    $rows = ($ext === 'xlsx' || $ext === 'xls') ? hc_read_xlsx($path) : hc_read_csv($path);
    $ri = 2; foreach ($rows as &$r) { $r['_row_index'] = $ri++; } unset($r);

    if (!$rows) {
      qexec("UPDATE `hc_uploads` SET `status`='failed', `error_message`=? WHERE `id`=?", ['No rows parsed (check header/format).', $uploadId]);
      qexec("UPDATE `hc_progress` SET `status`='failed', `note`=? WHERE `upload_id`=?", ['No rows parsed', $uploadId]);
      throw new RuntimeException('No rows parsed.');
    }

    // Total rows + note
    qi("UPDATE `hc_progress` SET `total_rows`=?, `note`='replacing data (truncate existing rows)' WHERE `upload_id`=?", [count($rows), $uploadId]);

    // Replace mode (exclusive section)
    try { qexec("TRUNCATE TABLE `{$table}`"); }
    catch (Throwable $e) { qexec("DELETE FROM `{$table}`"); }

    // Mapping
    $headers = array_keys($rows[0]);
    $map = hc_guess_mapping($headers);

    if ($slug === 'inline') {
      $map = array_merge($map, [
        'code'         => $map['code']         ?? 'Artikelnummer',
        'ean'          => $map['ean']          ?? 'ean',
        'name'         => $map['name']         ?? 'Kurzbeschreibung_en',
        'price'        => $map['price']        ?? '_KundenPreis',
        'availability' => $map['availability'] ?? 'stock',
      ]);
    }

    $sentinel->info('Header mapping', ['upload_id'=>$uploadId, 'map'=>$map, 'headers'=>$headers]);

    // Insert with live progress
    $count = hc_insert_rows($pdo, $table, $rows, $map, $sentinel, $uploadId);

    // Finalize
    qexec("UPDATE `hc_uploads` SET `status`='imported', `rows_imported`=? WHERE `id`=?", [$count, $uploadId]);
    qexec("UPDATE `hc_progress` SET `status`='imported', `processed`=?, `updated_at`=NOW() WHERE `upload_id`=?", [$count, $uploadId]);
    qexec("UPDATE `hc_progress` SET `status`='imported', `updated_at`=NOW()
           WHERE `upload_id`=? AND `status` <> 'imported'
             AND (`total_rows` IS NULL OR `processed` >= `total_rows`)", [$uploadId]);

    return [
      'rows'  => $count,
      'map'   => $map,
      'slug'  => $slug,
      'table' => $table,
      'name'  => $mname,
    ];
  } finally {
    hc_release_import_lock($pdo, $table);
  }
}}
