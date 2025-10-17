<?php
declare(strict_types=1);

namespace HS;

use PDO;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

final class ImportWorker
{
    /** Allowed enum states in hs_runs.status & hs_progress.status */
    private const STATUS = [
        'pending','started','running','reading','inserting','cancelling','cancelled','imported','failed'
    ];

    /* -------------------- Public API -------------------- */

    /** Start an import run (creates run & upload rows if needed, acquires lock, executes work) */
    public static function start(PDO $pdo, array $in, \debug_sentinel $sentinel): array
    {
        $jobId = (int)($in['job_id'] ?? 0);
        if ($jobId <= 0) self::json_error('Missing job_id');

        $job = \qrow('SELECT * FROM hs_import_jobs WHERE id=? LIMIT 1', [$jobId]);
        if (!$job) self::json_error('Job not found');

        $mfgId = (int)($job['manufacturer_id'] ?? 0);
        $mfg   = \qrow('SELECT * FROM hs_manufacturers WHERE id=? LIMIT 1', [$mfgId]);
        if (!$mfg) self::json_error('Manufacturer not found');

        $finalTable = (string)$mfg['table_name']; // e.g., hs_inline
        $stageTable = "{$finalTable}_stage";

        // Ensure tables exist & stage indexes are present
        self::ensureMinimalDataTable($pdo, $finalTable);
        self::ensureStageTable($pdo, $stageTable);
        self::ensureStageIndexes($pdo, $stageTable);

        // Create run
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        \qexec("INSERT INTO hs_runs (job_id, status, started_at) VALUES (?, 'pending', ?)", [$jobId, $now]);
        $runId = (int)\qlastid();

        // Upload row (either provided or create)
        if (!empty($in['upload_id'])) {
            $uploadId = (int)$in['upload_id'];
        } elseif (!empty($in['stored_path'])) {
            \qexec("INSERT INTO hs_uploads (run_id, stored_path, created_at) VALUES (?, ?, ?)", [$runId, (string)$in['stored_path'], $now]);
            $uploadId = (int)\qlastid();
        } else {
            self::setRunStatus($runId, 'failed', null, 'Missing upload_id or stored_path');
            self::logErr($pdo, $jobId, $runId, 'failed', 'Missing upload_id or stored_path');
            self::json_error('Missing upload_id or stored_path');
        }

        // Init progress row
        self::initProgress($runId, $uploadId, 'started');
        self::logInfo($pdo, $jobId, $runId, 'started', 'Run created', ['upload_id'=>$uploadId, 'final'=>$finalTable, 'stage'=>$stageTable]);

        // Acquire table lock (if advisory lock function is present)
        $lockTimeout = (int)self::cfg('lock_timeout_s', 300);
        if (function_exists('\hs_acquire_lock')) {
            if (!\hs_acquire_lock($pdo, $finalTable, $lockTimeout)) {
                $msg = "Importer busy for table: {$finalTable}";
                self::setRunStatus($runId, 'failed', null, $msg);
                self::logWarn($pdo, $jobId, $runId, 'failed', $msg);
                self::json_error($msg, 409);
            }
        }

        try {
            self::setRunStatus($runId, 'started');
            self::work($pdo, $runId, $uploadId, $sentinel);
        } catch (\Throwable $e) {
            // make failures visible to UI
            self::setRunStatus($runId, 'failed', null, $e->getMessage());
            self::bumpProgress($runId, $uploadId, 100, 100, 'failed');
            self::logErr($pdo, $jobId, $runId, 'failed', $e->getMessage());
            if (method_exists($sentinel, 'log')) $sentinel->log('import.failed', ['run_id'=>$runId, 'error'=>$e->getMessage()]);
        } finally {
            if (function_exists('\hs_release_lock')) \hs_release_lock($pdo, $finalTable);
        }

        return ['ok' => true, 'run_id' => $runId, 'upload_id' => $uploadId];
    }

    /** Polled by UI */
    public static function status(PDO $pdo, int $runId): array
    {
        if ($runId <= 0) {
            return ['run'=>null, 'progress'=>['status'=>'pending','rows_total'=>0,'rows_done'=>0,'percent'=>0.0]];
        }

        $run = \qrow(
            'SELECT id, job_id, status, started_at, finished_at, error_message
               FROM hs_runs
              WHERE id = ? LIMIT 1',
            [$runId]
        );
        if (!$run) {
            return ['run'=>null, 'progress'=>['status'=>'pending','rows_total'=>0,'rows_done'=>0,'percent'=>0.0]];
        }

        $upload = \qrow('SELECT id FROM hs_uploads WHERE run_id=? ORDER BY id DESC LIMIT 1', [$runId]);

        $progress = null;
        if ($upload) {
            $progress = \qrow(
                'SELECT upload_id, status, rows_total, rows_done, percent, updated_at
                   FROM hs_progress
                  WHERE upload_id = ?
                  LIMIT 1',
                [(int)$upload['id']]
            );
        }

        if (!$progress) {
            $progress = [
                'upload_id'  => $upload ? (int)$upload['id'] : null,
                'status'     => (string)$run['status'],
                'rows_total' => 0,
                'rows_done'  => 0,
                'percent'    => ($run['status'] === 'imported' ? 100.0 : 0.0),
                'updated_at' => null,
            ];
        }
        return ['run'=>$run, 'progress'=>$progress];
    }

    /** Main worker */
    public static function work(PDO $pdo, int $runId, int $uploadId, \debug_sentinel $sentinel): void
    {
        $run    = \qrow("SELECT * FROM hs_runs WHERE id=? LIMIT 1", [$runId]) ?: [];
        $job    = \qrow("SELECT * FROM hs_import_jobs WHERE id=? LIMIT 1", [$run['job_id'] ?? 0]) ?: [];
        $upload = \qrow("SELECT * FROM hs_uploads WHERE id=? LIMIT 1", [$uploadId]) ?: [];
        if (!$run || !$job || !$upload) { self::setRunStatus($runId, 'failed', null, 'Missing run/job/upload'); return; }

        $mfg = \qrow("SELECT * FROM hs_manufacturers WHERE id=? LIMIT 1", [(int)$job['manufacturer_id']]);
        if (!$mfg) { self::setRunStatus($runId, 'failed', null, 'Manufacturer not found'); return; }

        $finalTable = (string)$mfg['table_name'];    // e.g., hs_inline
        $stageTable = "{$finalTable}_stage";
        $mode       = (string)($job['mode'] ?? 'replace'); // 'replace' | 'merge'
        $columnsMap = json_decode((string)($job['columns_map'] ?? '{}'), true) ?: [];

        // flip to running immediately and surface initial context
        \qexec("UPDATE hs_runs SET status='running' WHERE id=?", [$runId]);
        self::logInfo($pdo, (int)$job['id'], $runId, 'start', 'Import worker started', [
            'run_id'=>$runId,'upload_id'=>$uploadId
        ]);

        self::logInfo($pdo, (int)$job['id'], $runId, 'init', 'Resolved tables/mode', [
            'final'=>$finalTable, 'stage'=>$stageTable, 'mode'=>$mode
        ]);

        // capture file info early for the UI
        $xlsx = (string)$upload['stored_path'];
        self::logInfo($pdo, (int)$job['id'], $runId, 'open', 'Opening input file', [
            'stored_path'=>$xlsx, 'src_path'=>$upload['src_path'] ?? null, 'format'=>$upload['format'] ?? null
        ]);

        $wantedHeaders = [
            $columnsMap['code']  ?? 'Artikelnummer',
            $columnsMap['ean']   ?? 'ean',
            $columnsMap['name']  ?? 'Kurzbeschreibung_en',
            $columnsMap['stock'] ?? 'stock',
        ];

        if ($xlsx === '' || !is_file($xlsx)) {
            self::setRunStatus($runId, 'failed', null, 'Stored file not found');
            self::bumpProgress($runId, $uploadId, 100, 100, 'failed');
            self::logErr($pdo, (int)$job['id'], $runId, 'error', 'Stored file not found', ['path'=>$xlsx]);
            return;
        }
        $csv  = preg_replace('~\.xlsx$~i', '.csv', $xlsx) ?: ($xlsx . '.csv');

        // 1) XLSX -> CSV
        self::setRunStatus($runId, 'reading');
        self::logInfo($pdo, (int)$job['id'], $runId, 'reading', 'XLSX->CSV begin', ['xlsx'=>$xlsx]);

        $t0 = microtime(true);
        $rowsTotal = self::xlsxToCsv($xlsx, $csv, $wantedHeaders); // returns count
        $ms = (int)round((microtime(true)-$t0)*1000);

        // headers have been mapped inside xlsxToCsv based on $wantedHeaders → call this out explicitly
        self::logInfo($pdo, (int)$job['id'], $runId, 'header', 'Headers resolved', ['wanted'=>$wantedHeaders]);
        self::logInfo($pdo, (int)$job['id'], $runId, 'reading', 'XLSX->CSV done', ['csv'=>$csv, 'rows_total'=>$rowsTotal, 'duration_ms'=>$ms]);

        // initial progress baseline for UI
        self::bumpProgress($runId, $uploadId, 0, max($rowsTotal,1), 'reading');

        // 2) Bulk load
        self::setRunStatus($runId, 'inserting', ['rows_total'=>$rowsTotal, 'rows_done'=>0]);
        // reflect phase immediately
        self::bumpProgress($runId, $uploadId, 0, max($rowsTotal,1), 'inserting');
        self::logInfo($pdo, (int)$job['id'], $runId, 'inserting', 'Load begin', ['mode'=>$mode]);

        if ($mode === 'replace') {
            // Build & swap
            $new = "{$finalTable}__new";
            $old = "{$finalTable}__old";

            $pdo->exec("DROP TABLE IF EXISTS `{$new}`");
            $pdo->exec("CREATE TABLE `{$new}` LIKE `{$finalTable}`");
            try { $pdo->exec("ALTER TABLE `{$new}` DROP INDEX `uq_code`"); } catch (\Throwable $_) {}

            self::loadCsv($pdo, $csv, $new, ['code','ean','name','stock'], $runId, $uploadId, $rowsTotal, (int)$job['id']);
            $pdo->exec("ALTER TABLE `{$new}` ADD UNIQUE KEY `uq_code` (`code`)");

            $pdo->exec("RENAME TABLE `{$finalTable}` TO `{$old}`, `{$new}` TO `{$finalTable}`");
            $pdo->exec("DROP TABLE `{$old}`");
            self::logInfo($pdo, (int)$job['id'], $runId, 'inserting', 'Swap completed');
        } else {
            // Merge
            self::ensureStageIndexes($pdo, $stageTable);
            $pdo->exec("TRUNCATE `{$stageTable}`");
            self::loadCsv($pdo, $csv, $stageTable, ['code','ean','name','stock'], $runId, $uploadId, $rowsTotal, (int)$job['id']);

            // Simple, fast upsert
            $sql = "
                INSERT INTO `{$finalTable}` (`code`,`ean`,`name`,`stock`)
                SELECT `code`,`ean`,`name`,`stock` FROM `{$stageTable}`
                ON DUPLICATE KEY UPDATE
                    `ean`  = VALUES(`ean`),
                    `name` = VALUES(`name`),
                    `stock`= VALUES(`stock`)";
            $pdo->exec($sql);
            self::logInfo($pdo, (int)$job['id'], $runId, 'inserting', 'Merge upsert completed');
        }

        // 3) Done
        self::setRunStatus($runId, 'imported', ['rows_total'=>$rowsTotal,'rows_done'=>$rowsTotal]);
        self::bumpProgress($runId, $uploadId, 100, 100, 'imported');
        self::logInfo($pdo, (int)$job['id'], $runId, 'finish', 'Import finished', ['rows_total'=>$rowsTotal]);
    }

    /* -------------------- XLSX/CSV/Bulk helpers -------------------- */

    /** Export wanted headers from XLSX to CSV; returns #data rows written (excludes header) */
    private static function xlsxToCsv(string $xlsxPath, string $csvPath, array $headersWanted): int
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);

        $ss = $reader->load($xlsxPath);
        $ws = $ss->getActiveSheet();

        $highestCol = $ws->getHighestDataColumn();
        $highestRow = $ws->getHighestDataRow();

        $row1 = $ws->rangeToArray('A1:' . $highestCol . '1', null, true, true, true);
        $headerRow = $row1[1] ?? [];
        $map = [];
        $wantedLower = array_map('mb_strtolower', $headersWanted);

        foreach ($headerRow as $col => $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $ln = mb_strtolower($name);
            if (in_array($ln, $wantedLower, true)) $map[$ln] = $col;
        }

        $orderedCols = array_map(fn($h) => $map[mb_strtolower($h)] ?? null, $headersWanted);

        $out = fopen($csvPath, 'wb');
        // normalize header order/names for the loader
        fputcsv($out, ['code','ean','name','stock']);

        $count = 0;
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = $ws->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true)[$r] ?? [];
            $csvRow = [];
            foreach ($orderedCols as $letter) {
                $v = $letter ? ($row[$letter] ?? '') : '';
                $csvRow[] = is_scalar($v) ? (string)$v : '';
            }
            if (implode('', $csvRow) === '') continue;   // skip fully empty rows
            $csvRow[3] = (string)((int)($csvRow[3] ?? 0)); // normalize stock to int string
            fputcsv($out, $csvRow);
            $count++;
        }
        fclose($out);

        $ss->disconnectWorksheets();
        unset($ss);

        return $count;
    }

    /** Batch size setting (portable) */
    private static function batchSize(): int {
        $n = (int)self::cfg('bulk_insert_batch_size', 2000);
        return max(200, min(10000, $n));
    }

    /**
     * Bulk load: try LOCAL INFILE, else batched INSERTs.
     * Also bumps progress if we had a row count.
     */
    private static function loadCsv(PDO $pdo, string $csvPath, string $table, array $columns,
                                    int $runId=null, int $uploadId=null, int $rowsTotal=0, int $jobId=0): void
    {
        $colsQuoted = implode(',', array_map(fn($c)=>"`{$c}`", $columns));

        // try to enable LOCAL INFILE explicitly (PDO mysql)
        try { $pdo->setAttribute(\PDO::MYSQL_ATTR_LOCAL_INFILE, true); } catch (\Throwable $_) {}

        // perf toggles for this phase
        $pdo->exec("SET autocommit=0");
        $pdo->exec("SET unique_checks=0");
        $pdo->exec("SET foreign_key_checks=0");

        // Two variants to handle Windows CRLF and LF
        $sqlLocalCRLF = sprintf(
            "LOAD DATA LOCAL INFILE %s INTO TABLE `%s`
             CHARACTER SET utf8mb4
             FIELDS TERMINATED BY ',' ENCLOSED BY '\"' ESCAPED BY '\\\\'
             LINES TERMINATED BY '\\r\\n'
             IGNORE 1 LINES
             (%s)",
            $pdo->quote($csvPath), $table, $colsQuoted
        );
        $sqlLocalLF = sprintf(
            "LOAD DATA LOCAL INFILE %s INTO TABLE `%s`
             CHARACTER SET utf8mb4
             FIELDS TERMINATED BY ',' ENCLOSED BY '\"' ESCAPED BY '\\\\'
             LINES TERMINATED BY '\\n'
             IGNORE 1 LINES
             (%s)",
            $pdo->quote($csvPath), $table, $colsQuoted
        );

        try {
            $t0 = microtime(true);
            $n  = $pdo->exec($sqlLocalCRLF);
            if ($n === false || ($rowsTotal > 0 && (int)$n === 0)) {
                // try LF if CRLF didn’t load anything (line ending mismatch)
                $n = $pdo->exec($sqlLocalLF);
            }
            if ($n === false) {
                throw new \RuntimeException('LOAD DATA LOCAL INFILE failed');
            }
            $ms = (int)round((microtime(true)-$t0)*1000);
            if ($runId && $uploadId && $rowsTotal > 0) {
                self::bumpProgress($runId, $uploadId, $rowsTotal, $rowsTotal, 'inserting');
            }
            self::logInfo($pdo, $jobId, $runId ?? 0, 'inserting', 'LOAD DATA LOCAL INFILE used', [
                'table'=>$table, 'duration_ms'=>$ms, 'rows_total'=>$rowsTotal, 'loaded_rows'=>(int)$n
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?? '';
            if (strpos($msg, '3948') !== false
                || stripos($msg, 'Loading local data is disabled') !== false
                || stripos($msg, 'rejected due to restrictions') !== false
                || stripos($msg, 'LOCAL INFILE') !== false) {
                self::logWarn($pdo, $jobId, $runId ?? 0, 'inserting',
                    'LOCAL INFILE unavailable; falling back to batched INSERTs', ['table'=>$table, 'error'=>$msg]);
                // Fallback: portable batched multi-row INSERTs
                self::loadCsvBatchedInsert($pdo, $csvPath, $table, $columns, $runId, $uploadId, $rowsTotal, $jobId);
            } else {
                self::logErr($pdo, $jobId, $runId ?? 0, 'inserting', 'LOAD DATA failed', ['error'=>$msg]);
                throw $e;
            }
        }

        $pdo->exec("COMMIT");
        $pdo->exec("SET unique_checks=1");
        $pdo->exec("SET foreign_key_checks=1");
        $pdo->exec("SET autocommit=1");
    }

    /** Portable loader when LOCAL INFILE is disabled — bumps progress & logs as it goes */
    private static function loadCsvBatchedInsert(PDO $pdo, string $csvPath, string $table, array $columns,
                                                 int $runId=null, int $uploadId=null, int $rowsTotal=0, int $jobId=0): void
    {
        $batchSize  = self::batchSize();     // e.g., 2000
        $colCount   = count($columns);
        $colsQuoted = implode(',', array_map(fn($c)=>"`{$c}`", $columns));

        $rows = [];
        $n = 0;
        $done = 0;

        $fh = new \SplFileObject($csvPath, 'r');
        $fh->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $fh->setCsvControl(',','"','\\');

        // skip header
        if (!$fh->eof()) { $fh->fgetcsv(); }

        $pdo->beginTransaction();

        while (!$fh->eof()) {
            $row = $fh->fgetcsv();
            if ($row === [null] || $row === false) continue;

            if (count($row) < $colCount) {
                $row = array_merge($row, array_fill(0, $colCount - count($row), null));
            } elseif (count($row) > $colCount) {
                $row = array_slice($row, 0, $colCount);
            }

            foreach ($row as &$v) {
                if (is_string($v)) $v = trim($v);
                if ($v === '') $v = null;
            }
            unset($v);

            $rows[] = $row;
            $n++;

            if ($n >= $batchSize) {
                $t0 = microtime(true);
                self::insertBatch($pdo, $table, $colsQuoted, $rows);
                $ms = (int)round((microtime(true)-$t0)*1000);

                $done += $n;
                $rows = [];
                $n = 0;

                if ($runId && $uploadId && $rowsTotal > 0) {
                    self::bumpProgress($runId, $uploadId, $done, max($rowsTotal,1), 'inserting');
                }
                self::logInfo($pdo, $jobId, $runId ?? 0, 'chunk', "Inserted batch", [
                    'table'=>$table, 'batch_size'=>$batchSize, 'rows_done'=>$done, 'rows_total'=>$rowsTotal, 'duration_ms'=>$ms
                ]);

                if (self::shouldStopAll()) {
                    $pdo->commit();
                    self::logWarn($pdo, $jobId, $runId ?? 0, 'cancelling', 'Cancelled by stop_all');
                    throw new \RuntimeException('Cancelled');
                }
            }
        }
        if ($n > 0) {
            $t0 = microtime(true);
            self::insertBatch($pdo, $table, $colsQuoted, $rows);
            $ms = (int)round((microtime(true)-$t0)*1000);

            $done += $n;
            if ($runId && $uploadId && $rowsTotal > 0) {
                self::bumpProgress($runId, $uploadId, $done, max($rowsTotal,1), 'inserting');
            }
            self::logInfo($pdo, $jobId, $runId ?? 0, 'chunk', 'Inserted batch (final)', [
                'table'=>$table, 'batch_size'=>$n, 'rows_done'=>$done, 'rows_total'=>$rowsTotal, 'duration_ms'=>$ms
            ]);
        }

        $pdo->commit();
    }

    /** Executes one multi-row INSERT batch with flattened placeholders */
    private static function insertBatch(PDO $pdo, string $table, string $colsQuoted, array $rows): void
    {
        if (empty($rows)) return;

        $rowPlace    = '(' . implode(',', array_fill(0, count($rows[0]), '?')) . ')';
        $valuesPlace = implode(',', array_fill(0, count($rows), $rowPlace));

        $sql  = "INSERT INTO `{$table}` ({$colsQuoted}) VALUES {$valuesPlace}";
        $flat = [];
        foreach ($rows as $r) { foreach ($r as $v) { $flat[] = $v; } }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($flat);
    }

    /* -------------------- Progress / Status -------------------- */

    private static function initProgress(int $runId, int $uploadId, string $status='started'): void
    {
        \qexec(
            "INSERT INTO hs_progress (upload_id, rows_total, rows_done, percent, status, updated_at)
             VALUES (?, 0, 0, 0.00, ?, NOW())
             ON DUPLICATE KEY UPDATE rows_total=VALUES(rows_total), rows_done=VALUES(rows_done),
                                     percent=VALUES(percent), status=VALUES(status), updated_at=VALUES(updated_at)",
            [$uploadId, $status]
        );
    }

    private static function setRunStatus(int $runId, string $status, ?array $stats=null, ?string $error=null): void
    {
        if (!in_array($status, self::STATUS, true)) {
            $status = 'failed';
            $error  = $error ?: 'Invalid status';
        }
        $statsJson = $stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        \qexec(
            "UPDATE hs_runs
                SET status=?,
                    stats_json=?,
                    finished_at = CASE WHEN ? IN ('imported','failed','cancelled') THEN ? ELSE finished_at END,
                    error_message = ?
              WHERE id=?",
            [$status, $statsJson, $status, $now, $error, $runId]
        );
    }

    private static function setProgressImported(int $uploadId): void
    {
        \qexec(
            "UPDATE hs_progress
                SET percent=100.00, status='imported', updated_at=NOW()
              WHERE upload_id=?",
            [$uploadId]
        );
    }

    /** Throttled progress write used by loaders and phases */
    private static function bumpProgress(int $runId, int $uploadId, int $done, int $total, string $status): void
    {
        static $lastWritten = -1;
        $throttle = (int)self::cfg('progress_throttle_rows', 1000);

        if ($done !== $total && ($lastWritten >= 0) && ($done - $lastWritten < $throttle)) {
            return;
        }
        $lastWritten = $done;

        $pct = $total > 0 ? round(($done/$total)*100, 2) : 0.00;

        \qexec(
            "UPDATE hs_progress
                SET rows_done=?, rows_total=?, percent=?, status=?, updated_at=NOW()
              WHERE upload_id=?",
            [$done, $total, $pct, $status, $uploadId]
        );
    }

    private static function shouldStopAll(): bool
    {
        $v = \qcell("SELECT v FROM hs_control WHERE k='stop_all_at' LIMIT 1");
        return !empty($v);
    }

    /* -------------------- Schema helpers -------------------- */

    private static function ensureMinimalDataTable(PDO $pdo, string $table): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code  VARCHAR(128) NOT NULL,
            ean   VARCHAR(20)  NULL,
            name  VARCHAR(512) NULL,
            stock INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
    }

    private static function ensureStageTable(PDO $pdo, string $table): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            code  VARCHAR(128) NOT NULL,
            ean   VARCHAR(20)  NULL,
            name  VARCHAR(512) NULL,
            stock INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
    }

    /** Add/ensure PK(code) + idx_ean on stage (safe if already exists) */
    private static function ensureStageIndexes(PDO $pdo, string $table): void
    {
        $hasPk = (bool)\qcell("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND CONSTRAINT_TYPE = 'PRIMARY KEY'
        ", [$table]);
        if (!$hasPk) { try { $pdo->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (`code`)"); } catch (\Throwable $_) {} }

        $hasIdxEan = (bool)\qcell("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND INDEX_NAME   = 'idx_ean'
        ", [$table]);
        if (!$hasIdxEan) { try { $pdo->exec("ALTER TABLE `{$table}` ADD KEY `idx_ean` (`ean`)"); } catch (\Throwable $_) {} }
    }

    /* -------------------- Small utils -------------------- */

    private static function cfg(string $key, $default=null) { return function_exists('hs_cfg') ? \hs_cfg($key, $default) : $default; }

    private static function json_error(string $msg, int $code=400): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------- Logger helpers -------------------- */

    private static function log(PDO $pdo, int $jobId, int $runId, string $level, string $phase, string $message, array $meta = []): void
    {
        \qexec(
            "INSERT INTO hs_logs (ts, job_id, run_id, level, phase, message, meta_json)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?)",
            [$jobId, $runId, $level, $phase, $message, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]
        );
    }
    private static function logInfo(PDO $pdo, int $jobId, int $runId, string $phase, string $msg, array $meta = []): void {
        self::log($pdo, $jobId, $runId, 'info', $phase, $msg, $meta);
    }
    private static function logWarn(PDO $pdo, int $jobId, int $runId, string $phase, string $msg, array $meta = []): void {
        self::log($pdo, $jobId, $runId, 'warn', $phase, $msg, $meta);
    }
    private static function logErr(PDO $pdo, int $jobId, int $runId, string $phase, string $msg, array $meta = []): void {
        self::log($pdo, $jobId, $runId, 'error', $phase, $msg, $meta);
    }
}
