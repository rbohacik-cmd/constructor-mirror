<?php
declare(strict_types=1);

/**
 * Health Check library (DB helpers, parsers, mapping, import)
 * - Loaded via /health_check/bootstrap.php (do NOT require db/mysql/sentinel here)
 * - Safe to include multiple times (function_exists guards)
 *
 * Expects the following globals from bootstrap:
 * - db(), qi(), qexec(), qall(), qrow(), qlastid()
 * - class debug_sentinel
 */

/* ----------------------------------------------------------------------------
 * Module config access
 * ------------------------------------------------------------------------- */
if (!function_exists('hc_cfg')) {
/**
 * Read /health_check/config/config.php once and return a key or default.
 * @param mixed $default
 */
function hc_cfg(string $key, $default = null) {
    static $CFG = null;
    if ($CFG === null) {
        $path = dirname(__DIR__) . '/config/config.php';
        $CFG = is_file($path) ? (require $path) : [];
        if (!is_array($CFG)) $CFG = [];
    }
    return array_key_exists($key, $CFG) ? $CFG[$key] : $default;
}}

/* ----------------------------------------------------------------------------
 * PhpSpreadsheet lazy autoload (robust discovery)
 * ------------------------------------------------------------------------- */
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

if (!function_exists('hc_try_ps_autoload')) {
function hc_try_ps_autoload(): void {
    if (class_exists(Xlsx::class, false)) return;

    $candidates = [
        // typical project root
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        // module-local vendor
        dirname(__DIR__) . '/vendor/autoload.php',
        // sometimes the project root is three levels up
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        // legacy local bundle
        __DIR__ . '/PhpSpreadsheet/vendor/autoload.php',
    ];
    foreach ($candidates as $auto) {
        if (is_file($auto)) {
            /** @noinspection PhpIncludeInspection */
            @require_once $auto;
            if (class_exists(Xlsx::class, false)) return;
        }
    }
}}
/* ----------------------------------------------------------------------------
 * Slug / table helpers
 * ------------------------------------------------------------------------- */
if (!function_exists('hc_slugify')) {
function hc_slugify(string $name): string {
    $s = mb_strtolower(trim($name));
    $s = preg_replace('~[^a-z0-9]+~u', '_', $s);
    $s = trim($s, '_');
    return $s ?: 'mfg';
}}
if (!function_exists('hc_table_name')) {
function hc_table_name(string $slug): string {
    return 'hc_' . $slug;
}}

/**
 * Ensure manufacturer exists in registry; return row (id, slug, table_name, name)
 */
if (!function_exists('hc_ensure_manufacturer')) {
function hc_ensure_manufacturer(PDO $pdo, string $nameOrSlug): array {
    $slug  = hc_slugify($nameOrSlug);
    $table = hc_table_name($slug);

    $row = qrow("SELECT * FROM `hc_manufacturers` WHERE `slug` = ?", [$slug]);
    if ($row) return $row;

    qi("INSERT INTO `hc_manufacturers` (`slug`,`name`,`table_name`) VALUES (?,?,?)",
       [$slug, $nameOrSlug, $table]);

    return qrow("SELECT * FROM `hc_manufacturers` WHERE `slug` = ?", [$slug]);
}}

/**
 * Create per-manufacturer table if missing; if exists, ensure it has `stock`.
 */
if (!function_exists('hc_ensure_data_table')) {
function hc_ensure_data_table(PDO $pdo, string $table): void {
    $exists = qcell("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);

    if ($exists) {
        // Backward-compatible migration: add stock if missing
        $hasStock = qcell("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'stock'", [$table]);
        if (!$hasStock) {
            qexec("ALTER TABLE `{$table}` ADD COLUMN `stock` INT NULL AFTER `availability`");
        }
        return;
    }

    $sql = "
    CREATE TABLE IF NOT EXISTS `{$table}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `code` VARCHAR(120) NULL,
      `article_number` VARCHAR(120) NULL,
      `ean` VARCHAR(32) NULL,
      `name` VARCHAR(512) NULL,
      `price` DECIMAL(12,4) NULL,
      `currency` VARCHAR(8) NULL,
      `availability` VARCHAR(64) NULL,
      `stock` INT NULL,
      `raw` JSON NULL,
      `source_row` INT UNSIGNED NULL,
      `source_hash` CHAR(40) NULL,
      `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `issues` TEXT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `ux_code_article` (`code`,`article_number`),
      KEY `ix_ean` (`ean`),
      KEY `ix_source_hash` (`source_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    qexec($sql);
}}

/** Ensure error bucket table used by rescue mode exists. */
if (!function_exists('hc_ensure_failed_rows_table')) {
function hc_ensure_failed_rows_table(): void {
    qexec("
      CREATE TABLE IF NOT EXISTS `hc_failed_rows` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `table_name` VARCHAR(128) NOT NULL,
        `upload_id` BIGINT UNSIGNED NULL,
        `chunk_index` INT UNSIGNED NULL,
        `source_row` INT UNSIGNED NULL,
        `last_key` VARCHAR(255) NULL,
        `error` VARCHAR(1000) NULL,
        `raw_excerpt` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY (`upload_id`),
        KEY (`table_name`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}}

/* ----------------------------------------------------------------------------
 * CSV utilities
 * ------------------------------------------------------------------------- */
if (!function_exists('hc_detect_delimiter')) {
function hc_detect_delimiter(string $head): string {
    $candidates = [",",";","\t","|"];
    $best = ","; $bestCount = 0;
    foreach ($candidates as $d) {
        $cnt = substr_count($head, $d);
        if ($cnt > $bestCount) { $bestCount = $cnt; $best = $d; }
    }
    return $best;
}}

/** Read CSV into array of associative rows. */
if (!function_exists('hc_read_csv')) {
function hc_read_csv(string $path): array {
    $rows = [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return $rows;

    $head = fread($fh, 4096);
    rewind($fh);
    $delim = hc_detect_delimiter($head);

    $headers = [];
    $i = 0;
    while (($cols = fgetcsv($fh, 0, $delim)) !== false) {
        $i++;
        if ($i === 1) {
            $headers = array_map(fn($h) => trim((string)$h), $cols);
            // Strip UTF-8 BOM on first header cell if present
            if (!empty($headers)) $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            continue;
        }
        if (empty(array_filter($cols, fn($v) => $v !== null && $v !== ''))) continue;
        $row = [];
        foreach ($headers as $idx => $key) {
            $row[$key ?: ("col".$idx)] = array_key_exists($idx, $cols) ? trim((string)$cols[$idx]) : null;
        }
        $row['_row_index'] = $i;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}}

/* ----------------------------------------------------------------------------
 * XLSX utilities
 * ------------------------------------------------------------------------- */
/** Read XLSX via PhpSpreadsheet with a friendly Zip guard. */
if (!function_exists('hc_read_xlsx')) {
function hc_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException("XLSX support requires PHP Zip extension. Enable it in php.ini: extension=zip");
    }
    hc_try_ps_autoload();
    if (!class_exists(Xlsx::class)) {
        throw new RuntimeException("PhpSpreadsheet not found. Install it (composer require phpoffice/phpspreadsheet) or provide vendor/autoload.php.");
    }

    $reader = new Xlsx();
    $reader->setReadDataOnly(true);    // speedup
    $reader->setReadEmptyCells(false); // skip empties
    $spreadsheet = $reader->load($path);

    $sheet = $spreadsheet->getActiveSheet();
    $rows = [];
    $headers = [];
    $rowIndex = 0;

    foreach ($sheet->toArray(null, true, true, true) as $r) {
        $rowIndex++;
        if ($rowIndex === 1) {
            $headers = array_map(fn($h) => trim((string)$h), array_values($r));
            continue;
        }
        if (!array_filter($r, fn($v) => $v !== null && $v !== '')) continue;
        $assoc = [];
        $i = 0;
        foreach ($r as $cell) {
            $key = $headers[$i] ?? ("col".$i);
            $assoc[$key] = is_string($cell) ? trim($cell) : (is_null($cell) ? null : (string)$cell);
            $i++;
        }
        $assoc['_row_index'] = $rowIndex;
        $rows[] = $assoc;
    }

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return $rows;
}}

/* ----------------------------------------------------------------------------
 * UTF-8 & JSON safety helpers
 * ------------------------------------------------------------------------- */
if (!function_exists('hc_supported_encoding')) {
function hc_supported_encoding(array $aliases): ?string {
    if (!function_exists('mb_list_encodings')) return null;
    static $supported = null;
    if ($supported === null) {
        $supported = array_fill_keys(array_map('mb_strtolower', mb_list_encodings()), true);
    }
    foreach ($aliases as $a) {
        if (isset($supported[mb_strtolower($a)])) return $a;
    }
    return null;
}}
if (!function_exists('hc_utf8ize')) {
function hc_utf8ize($value) {
    if (is_string($value)) {
        if (function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8')) return $value;
        $groups = [
            ['CP1250','Windows-1250','WIN1250'],
            ['CP1252','Windows-1252','WIN1252'],
            ['ISO-8859-2','ISO8859-2','Latin2'],
            ['ISO-8859-1','ISO8859-1','Latin1'],
        ];
        $detectList = [];
        foreach ($groups as $g) {
            $pick = hc_supported_encoding($g);
            if ($pick) $detectList[] = $pick;
        }
        if ($detectList && function_exists('mb_detect_encoding')) {
            $det = @mb_detect_encoding($value, $detectList, true);
            if (is_string($det)) {
                $out = @mb_convert_encoding($value, 'UTF-8', $det);
                if (is_string($out)) return $out;
            }
        }
        foreach ($detectList as $enc) {
            $out = @mb_convert_encoding($value, 'UTF-8', $enc);
            if (is_string($out)) return $out;
        }
        if (function_exists('iconv')) {
            foreach (['CP1250','Windows-1250','CP1252','Windows-1252','ISO-8859-2','ISO-8859-1'] as $enc) {
                $out = @iconv($enc, 'UTF-8//IGNORE', $value);
                if ($out !== false) return $out;
            }
        }
        return $value;
    } elseif (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = hc_utf8ize($v);
        return $value;
    } elseif (is_object($value)) {
        foreach ($value as $k => $v) $value->$k = hc_utf8ize($v);
        return $value;
    }
    return $value;
}}
if (!function_exists('hc_safe_json')) {
function hc_safe_json($data): array {
    $j = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($j !== false) return [$j, false];

    $fixed = hc_utf8ize($data);
    $j = json_encode($fixed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($j === false) return ['{}', true];
    return [$j, true];
}}
if (!function_exists('hc_norm_text')) {
/** Safely normalize a scalar string to UTF-8. */
function hc_norm_text(?string $s): ?string {
    if ($s === null || $s === '') return $s;
    if (function_exists('mb_check_encoding') && @mb_check_encoding($s, 'UTF-8')) return $s;

    $groups = [
        ['Windows-1250','CP1250','CP-1250','WIN1250'],
        ['ISO-8859-2','ISO8859-2','Latin2'],
        ['Windows-1252','CP1252','CP-1252','WIN1252'],
        ['ISO-8859-1','ISO8859-1','Latin1'],
    ];

    $mbCandidates = [];
    if (function_exists('mb_list_encodings')) {
        $supported = array_fill_keys(array_map('mb_strtolower', mb_list_encodings()), true);
        foreach ($groups as $aliases) {
            foreach ($aliases as $label) {
                if (isset($supported[mb_strtolower($label)])) { $mbCandidates[] = $label; break; }
            }
        }
    }

    if ($mbCandidates && function_exists('mb_convert_encoding')) {
        foreach ($mbCandidates as $enc) {
            $out = @mb_convert_encoding($s, 'UTF-8', $enc);
            if (is_string($out) && @mb_check_encoding($out, 'UTF-8')) return $out;
        }
    }

    if (function_exists('iconv')) {
        foreach (['Windows-1250','CP1250','ISO-8859-2','Windows-1252','CP1252','ISO-8859-1'] as $enc) {
            $out = @iconv($enc, 'UTF-8//IGNORE', $s);
            if ($out !== false && $out !== '') return $out;
        }
    }
    return $s;
}}

/* ----------------------------------------------------------------------------
 * Mapping + normalization
 * ------------------------------------------------------------------------- */
if (!function_exists('hc_guess_mapping')) {
function hc_guess_mapping(array $headerKeys): array {
    $map = [
        'code' => [
            'code','kód','kod','product code','item code','sku','part','part no','partno','pn',
            'symbol','artikelnummer','item number','itemno','catalog number','katalognummer'
        ],
        'article_number'  => ['article','article number','art.-nr.','hersteller-art.-nr.','artnr','artnr.'],
        'ean'             => ['ean','ean13','barcode','gtin'],
        'name'            => ['name','product name','title','názov','nazev','bezeichnung','kurzbeschreibung','kurzbeschreibung_en'],
        'price'           => ['price','cena','preis','net price','gross price','kundenpreis','_kundenpreis'],
        'currency'        => ['currency','mena','währung','waehrung'],
        // Keep legacy behavior: availability may map to a "stock"/"lager" text column
        'availability'    => ['availability','stock','lager','dostupnosť','dostupnost'],
        // NEW: explicit numeric stock mapping (can point to same header as availability)
        'stock'           => [
            // EN
            'stock','stock level','stock_available','stock available','available','available qty','available quantity',
            'available pieces','available piece','pieces','pieces available','qty','quantity','on hand','onhand',
            'inventory','inventory qty','stock qty','stock quantity',
            // DE
            'stock_available_de','lager','lagerbestand','verfügbar','verfuegbar',
            // CZ/SK
            'sklad','skladom','stav skladu','stav_skladu'
        ],
    ];

    $norm = fn(string $s) => mb_strtolower(preg_replace('~\s+~',' ', trim($s)));

    $chosen = [];
    foreach ($map as $canon => $candidates) {
        foreach ($headerKeys as $h) {
            $hn = $norm((string)$h);
            foreach ($candidates as $cand) {
                if (str_contains($hn, $norm($cand))) {
                    $chosen[$canon] = $h;
                    break 2;
                }
            }
        }
    }
    return $chosen;
}}
if (!function_exists('hc_norm_price')) {
function hc_norm_price(?string $v): ?string {
    if ($v === null || $v === '') return null;
    $v = str_replace([' ', '€'], '', $v);
    // European formats like 1.234,56 => 1234.56
    if (preg_match('~^\d{1,3}(\.\d{3})*,\d+(?:$)~', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        if (strpos($v, ',') !== false && strpos($v, '.') === false) {
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);
        }
    }
    return is_numeric($v) ? number_format((float)$v, 4, '.', '') : null;
}}

/** NEW: robust integer normalization for stock-like values */
if (!function_exists('hc_norm_int')) {
function hc_norm_int($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)floor($v);
    $s = trim((string)$v);
    if ($s === '' || strtolower($s) === 'n/a') return null;
    // remove NBSP, spaces and thousands separators
    $sNoGroup = preg_replace('/[\x{00A0}\s\.,]/u', '', $s);
    if ($sNoGroup !== '' && preg_match('/^-?\d+$/', $sNoGroup)) {
        return (int)$sNoGroup;
    }
    if (preg_match('/-?\d+/', $s, $m)) return (int)$m[0];
    return null;
}}

/* ----------------------------------------------------------------------------
 * Long request prep
 * ------------------------------------------------------------------------- */
if (!function_exists('hc_prepare_long_request')) {
function hc_prepare_long_request(?debug_sentinel $sentinel = null, int $uploadId = 0): void {
    @ignore_user_abort(true);
    @ini_set('max_execution_time', '0');
    @ini_set('max_input_time', '0');
    @set_time_limit(0);

    if ($sentinel || $uploadId) {
        register_shutdown_function(function() use ($sentinel, $uploadId) {
            $err = error_get_last();
            if (!$err) return;

            $msg = $err['message'] ?? 'request terminated';
            $isExecTime = stripos($msg, 'Maximum execution time') !== false;

            if ($sentinel) {
                $sentinel->error('Importer terminated by runtime', [
                    'message' => $msg,
                    'type'    => $err['type'] ?? null,
                    'file'    => $err['file'] ?? null,
                    'line'    => $err['line'] ?? null,
                ]);
            }
            if ($uploadId > 0) {
                $note = $isExecTime
                    ? 'PHP max_execution_time timeout'
                    : 'Request terminated by server (FPM/Nginx?)';
                qi("UPDATE `hc_progress`
                      SET `status`='failed', `note`=?, `updated_at`=CURRENT_TIMESTAMP
                    WHERE `upload_id` = ?",
                   [$note, $uploadId]);
            }
        });
    }
}}

/* ----------------------------------------------------------------------------
 * Generic insert with progress + rescue mode
 * ------------------------------------------------------------------------- */
/**
 * Insert rows (chunked), upsert by (code, article_number).
 * Optional $uploadId: when >0, updates hc_progress per chunk (processed & bytes_written).
 */
if (!function_exists('hc_insert_rows')) {
function hc_insert_rows(PDO $pdo, string $table, array $rows, array $map, debug_sentinel $sentinel, int $uploadId = 0): int {
    // Keep the request alive as much as PHP allows
    hc_prepare_long_request($sentinel, $uploadId);

    $inserted  = 0;
    $chunkSize = (int)($_GET['chunk'] ?? 0);
    if ($chunkSize <= 0) {
        $chunkSize = (int)hc_cfg('chunk_size_default', 200);
    }
    $chunkSize = max((int)hc_cfg('chunk_size_min', 10), $chunkSize);
    $chunks    = array_chunk($rows, $chunkSize);

    // Apply per-connection MySQL session settings (lock/net timeouts) from config
    $applySession = function(PDO $pdo) {
        try {
            $lock = (int)hc_cfg('lock_wait_timeout_s', 8);
            $netr = (int)hc_cfg('net_read_timeout_s', 30);
            $pdo->query("SET SESSION innodb_lock_wait_timeout = {$lock}");
            $pdo->query("SET SESSION lock_wait_timeout = {$lock}");
            $pdo->query("SET SESSION net_read_timeout = {$netr}");
            $pdo->query("SET SESSION net_write_timeout = {$netr}");
        } catch (Throwable $e) { /* ignore on hosts that restrict this */ }
    };
    $applySession($pdo);

    // MySQL thread id (useful for phpMyAdmin)
    $threadId = 0;
    try { $threadId = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn(); } catch (Throwable $e) {}
    $sentinel->info('hc_import_thread', ['table' => $table, 'thread_id' => $threadId]);

    $sql = "
        INSERT INTO `{$table}`
        (`code`,`article_number`,`ean`,`name`,`price`,`currency`,`availability`,`stock`,`raw`,`source_row`,`source_hash`,`last_seen`,`issues`)
        VALUES
        (:code,:article_number,:ean,:name,:price,:currency,:availability,:stock,:raw,:source_row,:source_hash,NOW(),:issues)
        ON DUPLICATE KEY UPDATE
          `ean`=VALUES(`ean`),
          `name`=VALUES(`name`),
          `price`=VALUES(`price`),
          `currency`=VALUES(`currency`),
          `availability`=VALUES(`availability`),
          `stock`=VALUES(`stock`),
          `raw`=VALUES(`raw`),
          `source_row`=VALUES(`source_row`),
          `source_hash`=VALUES(`source_hash`),
          `last_seen`=NOW(),
          `issues`=VALUES(`issues`)
    ";

    $chunkIndex = 0;
    $stmt = null;

    foreach ($chunks as $batch) {
        $chunkIndex++;
        @set_time_limit(0);

        $needReprepare = false;
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            $sentinel->warn('MySQL connection dropped — reconnecting', ['err' => $e->getMessage()]);
            $pdo = db(); // recreate
            $applySession($pdo);
            $needReprepare = true;
            try { $threadId = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn(); } catch (Throwable $ee) {}
            $sentinel->info('hc_import_thread_reconnected', ['table' => $table, 'thread_id' => $threadId]);
        }

        if ($stmt === null || $needReprepare) {
            $stmt = $pdo->prepare($sql);
        }

        if ($uploadId > 0) {
            qi("UPDATE `hc_progress`
               SET `note`=?, `updated_at`=CURRENT_TIMESTAMP
               WHERE `upload_id`=?",
               ["starting chunk {$chunkIndex} (thr={$threadId}, rows=".count($batch).")", $uploadId]);
        }

        $pdo->beginTransaction();
        $chunkBytes = 0;
        $insertedThisChunk = 0;

        $sentinel->info('hc_insert_chunk_start', [
            'table' => $table,
            'chunk' => $chunkIndex,
            'rows_in_chunk' => count($batch),
            'inserted_total_before' => $inserted,
        ]);

        try {
            $rowInChunk = 0;
            $lastKey    = null;

            foreach ($batch as $r) {
                $rowInChunk++;

                $codeK = $map['code']           ?? null; $code = $codeK ? ($r[$codeK] ?? null) : null;
                $artK  = $map['article_number'] ?? null; $art  = $artK  ? ($r[$artK]  ?? null) : null;
                $eanK  = $map['ean']            ?? null; $ean  = $eanK  ? ($r[$eanK]  ?? null) : null;
                $nameK = $map['name']           ?? null; $name = $nameK ? ($r[$nameK] ?? null) : null;
                $priceK= $map['price']          ?? null; $praw = $priceK? ($r[$priceK]?? null) : null;
                $currK = $map['currency']       ?? null; $curr = $currK ? ($r[$currK] ?? null) : null;
                $availK= $map['availability']   ?? null; $avail= $availK? ($r[$availK]?? null) : null;
                $stockK= $map['stock']          ?? null; $stock= $stockK? ($r[$stockK]?? null) : null;

                $price = is_string($praw) ? hc_norm_price($praw) : hc_norm_price((string)$praw);
                $stock = hc_norm_int($stock);

                list($rawJson, $utf8FixedRaw) = hc_safe_json($r);
                $chunkBytes += strlen($rawJson);
                $srcRow = $r['_row_index'] ?? null;
                $hash   = sha1($rawJson);

                $scalarFixed = false;
                $orig = $code;  $code  = hc_norm_text($code);   if ($orig !== $code)   $scalarFixed = true;
                $orig = $art;   $art   = hc_norm_text($art);    if ($orig !== $art)    $scalarFixed = true;
                $orig = $ean;   $ean   = hc_norm_text($ean);    if ($orig !== $ean)    $scalarFixed = true;
                $orig = $name;  $name  = hc_norm_text($name);   if ($orig !== $name)   $scalarFixed = true;
                $orig = $curr;  $curr  = hc_norm_text($curr);   if ($orig !== $curr)   $scalarFixed = true;
                $orig = $avail; $avail = hc_norm_text($avail);  if ($orig !== $avail)  $scalarFixed = true;

                $issues = [];
                if (!$code && !$art && !$ean) $issues[] = 'no primary identifier (code/art/ean)';
                if ($praw !== null && $price === null) $issues[] = 'price parse failed';
                if (!empty($utf8FixedRaw)) $issues[] = 'non-UTF8 normalized in raw';
                if ($scalarFixed) $issues[] = 'non-UTF8 normalized in columns';

                $stmt->execute([
                    ':code' => $code ?: null,
                    ':article_number' => $art ?: null,
                    ':ean' => $ean ?: null,
                    ':name' => $name ?: null,
                    ':price' => $price,
                    ':currency' => $curr ?: null,
                    ':availability' => $avail ?: null,
                    ':stock' => ($stock !== null ? (int)$stock : null),
                    ':raw' => $rawJson,
                    ':source_row' => is_numeric($srcRow) ? (int)$srcRow : null,
                    ':source_hash' => $hash,
                    ':issues' => $issues ? implode('; ', $issues) : null,
                ]);

                $inserted++;
                $insertedThisChunk++;

                $lastKey = ($code ?: $art ?: $ean ?: ('row#'.(is_numeric($srcRow)?(int)$srcRow:'?')));
                if ($uploadId > 0 && ($rowInChunk % 25) === 0) {
                    qi("UPDATE `hc_progress`
                       SET `note`=?, `updated_at`=CURRENT_TIMESTAMP
                       WHERE `upload_id`=?",
                       ["running (thr={$threadId}, chunk {$chunkIndex}, row {$rowInChunk}, last={$lastKey})", $uploadId]);
                }
            }

            $pdo->commit();

            if ($uploadId > 0) {
                qi("UPDATE `hc_progress`
                    SET `processed`=`processed`+?, `bytes_written`=`bytes_written`+?, `updated_at`=CURRENT_TIMESTAMP,
                        `note`=?
                    WHERE `upload_id`=?",
                   [$insertedThisChunk, $chunkBytes, "chunk {$chunkIndex} done (thr={$threadId}, last={$lastKey})", $uploadId]
                );
            }

            $sentinel->info('hc_insert_chunk_end', [
                'table' => $table,
                'chunk' => $chunkIndex,
                'inserted_in_chunk' => $insertedThisChunk,
                'inserted_total_after' => $inserted,
                'last_key' => $lastKey ?? null,
            ]);

        } catch (Throwable $e) {
            $pdo->rollBack();

            $sentinel->warn('HC chunk failed — switching to row-by-row rescue', [
                'table' => $table,
                'chunk' => $chunkIndex,
                'error' => $e->getMessage(),
            ]);

            // ensure error bucket exists
            hc_ensure_failed_rows_table();

            $ok = 0; $fail = 0; $bytes = 0; $lastKey = null;

            foreach ($batch as $r) {
                try {
                    $pdo->beginTransaction();

                    $codeK = $map['code']           ?? null; $code = $codeK ? ($r[$codeK] ?? null) : null;
                    $artK  = $map['article_number'] ?? null; $art  = $artK  ? ($r[$artK]  ?? null) : null;
                    $eanK  = $map['ean']            ?? null; $ean  = $eanK  ? ($r[$eanK]  ?? null) : null;
                    $nameK = $map['name']           ?? null; $name = $nameK ? ($r[$nameK] ?? null) : null;
                    $priceK= $map['price']          ?? null; $praw = $priceK? ($r[$priceK]?? null) : null;
                    $currK = $map['currency']       ?? null; $curr = $currK ? ($r[$currK] ?? null) : null;
                    $availK= $map['availability']   ?? null; $avail= $availK? ($r[$availK]?? null) : null;
                    $stockK= $map['stock']          ?? null; $stock= $stockK? ($r[$stockK]?? null) : null;

                    $price = is_string($praw) ? hc_norm_price($praw) : hc_norm_price((string)$praw);
                    $stock = hc_norm_int($stock);

                    list($rawJson, $utf8FixedRaw) = hc_safe_json($r);
                    $bytes += strlen($rawJson);
                    $srcRow = $r['_row_index'] ?? null;
                    $hash   = sha1($rawJson);

                    $scalarFixed = false;
                    $orig = $code;  $code  = hc_norm_text($code);   if ($orig !== $code)   $scalarFixed = true;
                    $orig = $art;   $art   = hc_norm_text($art);    if ($orig !== $art)    $scalarFixed = true;
                    $orig = $ean;   $ean   = hc_norm_text($ean);    if ($orig !== $ean)    $scalarFixed = true;
                    $orig = $name;  $name  = hc_norm_text($name);   if ($orig !== $name)   $scalarFixed = true;
                    $orig = $curr;  $curr  = hc_norm_text($curr);   if ($orig !== $curr)   $scalarFixed = true;
                    $orig = $avail; $avail = hc_norm_text($avail);  if ($orig !== $avail)  $scalarFixed = true;

                    $issues = [];
                    if (!$code && !$art && !$ean) $issues[] = 'no primary identifier (code/art/ean)';
                    if ($praw !== null && $price === null) $issues[] = 'price parse failed';
                    if (!empty($utf8FixedRaw)) $issues[] = 'non-UTF8 normalized in raw';
                    if ($scalarFixed) $issues[] = 'non-UTF8 normalized in columns';

                    $lastKey = ($code ?: $art ?: $ean ?: ('row#'.(is_numeric($srcRow)?(int)$srcRow:'?')));

                    if (!$stmt) $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':code' => $code ?: null,
                        ':article_number' => $art ?: null,
                        ':ean' => $ean ?: null,
                        ':name' => $name ?: null,
                        ':price' => $price,
                        ':currency' => $curr ?: null,
                        ':availability' => $avail ?: null,
                        ':stock' => ($stock !== null ? (int)$stock : null),
                        ':raw' => $rawJson,
                        ':source_row' => is_numeric($srcRow) ? (int)$srcRow : null,
                        ':source_hash' => $hash,
                        ':issues' => $issues ? implode('; ', $issues) : null,
                    ]);

                    $pdo->commit();
                    $ok++; $inserted++;

                    if ($uploadId > 0 && ($ok % 10) === 0) {
                        qi("UPDATE `hc_progress`
                           SET `processed`=`processed`+10, `updated_at`=CURRENT_TIMESTAMP,
                               `note`=?
                           WHERE `upload_id`=?",
                           ["rescue mode (thr={$threadId}, chunk {$chunkIndex}, last={$lastKey})", $uploadId]);
                    }
                } catch (Throwable $rowE) {
                    $pdo->rollBack();

                    $errMsg = function_exists('mb_substr') ? mb_substr($rowE->getMessage(), 0, 1000) : substr($rowE->getMessage(), 0, 1000);
                    $rawCut = isset($rawJson)
                        ? (function_exists('mb_substr') ? mb_substr($rawJson, 0, 2000) : substr($rawJson, 0, 2000))
                        : null;

                    qi("INSERT INTO `hc_failed_rows`
                        (`table_name`,`upload_id`,`chunk_index`,`source_row`,`last_key`,`error`,`raw_excerpt`)
                        VALUES (?,?,?,?,?,?,?)",
                       [$table, $uploadId ?: null, $chunkIndex, is_numeric($srcRow)?(int)$srcRow:null,
                        $lastKey, $errMsg, $rawCut]
                    );

                    $fail++;
                    $sentinel->warn('HC row failed (skipped)', [
                        'table'    => $table,
                        'chunk'    => $chunkIndex,
                        'last_key' => $lastKey,
                        'error'    => $rowE->getMessage(),
                    ]);
                }
            }

            if ($uploadId > 0) {
                qi("UPDATE `hc_progress`
                   SET `bytes_written`=`bytes_written`+?, `updated_at`=CURRENT_TIMESTAMP,
                       `note`=?
                   WHERE `upload_id`=?",
                   [$bytes, "rescue chunk {$chunkIndex} done (ok={$ok}, fail={$fail}, thr={$threadId})", $uploadId]);
            }

            continue; // next chunk
        }
    }

    return $inserted;
}}
