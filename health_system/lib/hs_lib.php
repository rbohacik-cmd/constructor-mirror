<?php
declare(strict_types=1);

/** Slug + table name */
function hs_slugify(string $name): string {
  $s = strtolower(trim($name));
  $s = preg_replace('~[^a-z0-9]+~', '_', $s);
  return trim($s, '_');
}
function hs_table_name(string $slug): string { return 'hs_' . $slug; }

/** Ensure manufacturer row + table */
function hs_ensure_manufacturer(PDO $pdo, string $nameOrSlug): array {
  $slug  = hs_slugify($nameOrSlug);
  $table = hs_table_name($slug);

  $row = qrow('SELECT * FROM hs_manufacturers WHERE slug=?', [$slug]);
  if (!$row) {
    qexec('INSERT INTO hs_manufacturers (slug, name, table_name) VALUES (?,?,?)', [$slug, $nameOrSlug, $table]);
    $row = qrow('SELECT * FROM hs_manufacturers WHERE slug=?', [$slug]);
  }

  hs_ensure_data_table($pdo, (string)$row['table_name']);
  return $row;
}

/** Create per-manufacturer unified table if missing (no raw_json) */
function hs_ensure_data_table(PDO $pdo, string $table): void {
	$sql = "CREATE TABLE IF NOT EXISTS `$table` (
	  id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	  code  VARCHAR(128) NOT NULL,
	  ean   VARCHAR(20)  NULL,
	  name  VARCHAR(512) NULL,
	  stock INT NOT NULL DEFAULT 0,
	  PRIMARY KEY (id),
	  UNIQUE KEY uq_code (code)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	qexec($sql);
}

/** Locks */
function hs_acquire_lock(PDO $pdo, string $table, int $timeout=300): bool {
  $k   = 'hs:import:' . $table;
  $row = qrow('SELECT GET_LOCK(?, ?) AS ok', [$k, $timeout]);
  return (int)($row['ok'] ?? 0) === 1;
}
function hs_release_lock(PDO $pdo, string $table): void {
  $k = 'hs:import:' . $table;
  qrow('SELECT RELEASE_LOCK(?) AS rel', [$k]);
}

/** Insert chunk with upsert (no raw_json) */
function hs_insert_rows(PDO $pdo, string $table, array $rows, string $mode, debug_sentinel $sentinel): array {
  $rowsOk = 0; $rowsFail = 0;
  // (If mode === 'replace', caller TRUNCATEs once before first batch)

  foreach ($rows as $r) {
    try {
      qexec(
        "INSERT INTO `$table` (code, ean, name, stock, last_seen, source_hash)
         VALUES (?,?,?,?,NOW(),?)
         ON DUPLICATE KEY UPDATE
           ean         = VALUES(ean),
           name        = VALUES(name),
           stock       = VALUES(stock),
           last_seen   = NOW(),
           source_hash = VALUES(source_hash)",
        [
          $r['code'],
          $r['ean'],
          $r['name'],
          $r['stock'],
          $r['source_hash'],
        ]
      );
      $rowsOk++;
    } catch (Throwable $e) {
      $rowsFail++;
      $sentinel->log('error', 'hs_insert_row_fail', [
        'table'=>$table,
        'code'=>$r['code'] ?? null,
        'err'=>$e->getMessage()
      ]);
    }
  }
  return ['ok'=>$rowsOk,'fail'=>$rowsFail];
}
