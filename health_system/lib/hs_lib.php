<?php
declare(strict_types=1);

/* ---------------- Slug + naming ---------------- */

if (!function_exists('hs_slugify')) {
  function hs_slugify(string $name): string {
    if (function_exists('iconv')) {
      $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
      if ($t !== false) $name = $t;
    }
    $s = strtolower(trim($name));
    $s = preg_replace('~[^a-z0-9]+~', '_', $s);
    $s = trim($s, '_');
    return $s !== '' ? $s : 'n_a';
  }
}

if (!function_exists('hs_table_name')) {
  function hs_table_name(string $slug): string {
    $slug = preg_replace('~[^a-z0-9_]+~', '_', strtolower($slug));
    $slug = trim($slug, '_');
    return 'hs_' . ($slug !== '' ? $slug : 'n_a');
  }
}

/* -------------- Manufacturer helpers -------------- */

if (!function_exists('hs_make_unique_table_name')) {
  /** Choose a table_name that doesn't exist yet (hs_<slug>, hs_<slug>_2, …). */
  function hs_make_unique_table_name(\PDO $pdo, string $base): string {
    $table = $base; $n = 1;
    while (true) {
      $exists = (int)qval(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
      );
      if ($exists === 0) return $table;
      $n++; $table = $base . '_' . $n;
    }
  }
}

if (!function_exists('hs_ensure_manufacturer')) {
  /**
   * Ensure a manufacturer row exists (by slug) and its data table exists.
   * Returns the full manufacturer row.
   */
  function hs_ensure_manufacturer(\PDO $pdo, string $nameOrSlug): array {
    $slug = hs_slugify($nameOrSlug);

    $row = qrow('SELECT * FROM hs_manufacturers WHERE slug = ? LIMIT 1', [$slug]);
    if (!$row) {
      $name      = trim($nameOrSlug) !== '' ? trim($nameOrSlug) : $slug;
      $baseTable = hs_table_name($slug);
      $table     = hs_make_unique_table_name($pdo, $baseTable);

      try {
        qexec(
          'INSERT INTO hs_manufacturers (name, slug, table_name, created_at)
           VALUES (?, ?, ?, NOW())',
          [$name, $slug, $table]
        );
      } catch (\Throwable $e) {
        $row = qrow('SELECT * FROM hs_manufacturers WHERE slug = ? LIMIT 1', [$slug]);
        if (!$row) { throw $e; }
      }
      if (!$row) {
        $row = qrow('SELECT * FROM hs_manufacturers WHERE slug = ? LIMIT 1', [$slug]);
      }
    }

    $tableName = (string)$row['table_name'];
    hs_ensure_data_table($pdo, $tableName);

    return $row;
  }
}

/* -------------- Data table helpers -------------- */

if (!function_exists('hs_ensure_data_table')) {
  /**
   * Create per-manufacturer unified table if missing — lean & fast.
   * Columns: id, code (UNIQUE), ean, name, stock, updated_at.
   */
  function hs_ensure_data_table(\PDO $pdo, string $table): void {
    if (!preg_match('~^[a-zA-Z0-9_]+$~', $table)) {
      throw new RuntimeException("Invalid table name: $table");
    }

    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
      `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `code`       VARCHAR(128)    NOT NULL,
      `ean`        VARCHAR(32)     NULL,
      `name`       VARCHAR(512)    NULL,
      `stock`      INT             NULL,
      `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_code` (`code`),
      KEY `ix_ean` (`ean`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    qexec($sql);

    // Be resilient if table existed from older schema:
    try { qexec("ALTER TABLE `{$table}` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (\Throwable $e) {}
    try { qexec("ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_code` (`code`)"); } catch (\Throwable $e) {}
    try { qexec("ALTER TABLE `{$table}` ADD KEY `ix_ean` (`ean`)"); } catch (\Throwable $e) {}

    // We intentionally DO NOT add last_seen/source_hash anymore (kept minimal).
  }
}

/* -------------- Locks -------------- */

if (!function_exists('hs_acquire_lock')) {
  function hs_acquire_lock(\PDO $pdo, string $table, int $timeout = 300): bool {
    $k   = 'hs:import:' . $table;
    $row = qrow('SELECT GET_LOCK(?, ?) AS ok', [$k, $timeout]);
    return (int)($row['ok'] ?? 0) === 1;
  }
}

if (!function_exists('hs_release_lock')) {
  function hs_release_lock(\PDO $pdo, string $table): void {
    $k = 'hs:import:' . $table;
    qrow('SELECT RELEASE_LOCK(?) AS rel', [$k]);
  }
}

/* -------------- Insert/upsert chunk -------------- */

if (!function_exists('hs_insert_rows')) {
  /**
   * Insert a batch with upsert semantics (merge on code).
   * Each row: ['code','ean','name','stock']
   * @return array{ok:int,fail:int}
   */
  function hs_insert_rows(\PDO $pdo, string $table, array $rows, string $mode, ?\debug_sentinel $sentinel = null): array {
    $rowsOk = 0; $rowsFail = 0;

    foreach ($rows as $r) {
      try {
        qexec(
          "INSERT INTO `{$table}` (code, ean, name, stock, updated_at)
           VALUES (?,?,?,?, NOW())
           ON DUPLICATE KEY UPDATE
             ean        = VALUES(ean),
             name       = VALUES(name),
             stock      = VALUES(stock),
             updated_at = NOW()",
          [
            $r['code'] ?? null,
            $r['ean']  ?? null,
            $r['name'] ?? null,
            isset($r['stock']) ? (int)$r['stock'] : null,
          ]
        );
        $rowsOk++;
      } catch (\Throwable $e) {
        $rowsFail++;
        if ($sentinel) {
          try {
            $sentinel->log('error', 'hs_insert_row_fail', [
              'table' => $table,
              'code'  => $r['code'] ?? null,
              'err'   => $e->getMessage()
            ]);
          } catch (\Throwable $_) {}
        }
      }
    }
    return ['ok' => $rowsOk, 'fail' => $rowsFail];
  }
}
