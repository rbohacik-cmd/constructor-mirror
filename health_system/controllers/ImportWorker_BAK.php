<?php
declare(strict_types=1);

namespace HS;

use PDO;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;

final class ImportWorker
{
    /** Allowed enum states in hs_runs.status & hs_progress.status */
    private const STATUS = [
        'pending','started','running','reading','inserting','cancelling','cancelled','imported','failed'
    ];

    /** @var array<int,string> runId => MFG TAG (slug|name uppercased) */
    private static array $runMfgTag = []; // ← ADDED

    /* -------------------- Sentinel helper -------------------- */

    /** Minimal sentinel mirror (safe no-op if missing) */
    private static function slog(?\debug_sentinel $s, string $label, array $ctx = [], string $level='info', ?string $code=null): void {
        if (!$s) return;
        $val = $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        try { $s->record($label, $val, $code, $level, 'health_system'); }
        catch (\Throwable $e) { try { $s->log('health_system', $label, $val, $code, $level); } catch (\Throwable $_) {} }
    }

    /* -------------------- Public API -------------------- */

    /** Start an import run (creates run & upload rows if needed, acquires lock, executes work) */
    public static function start(PDO $pdo, array $in, \debug_sentinel $sentinel): array
    {
        // Make sentinel available to helper methods
        $GLOBALS['sentinel'] = $sentinel;

        $jobId = (int)($in['job_id'] ?? 0);
        if ($jobId <= 0) self::json_error('Missing job_id');

        $job = \qrow('SELECT * FROM hs_import_jobs WHERE id=? LIMIT 1', [$jobId]);
        if (!$job) self::json_error('Job not found');

        $mfgId = (int)($job['manufacturer_id'] ?? 0);
        $mfg   = \qrow('SELECT * FROM hs_manufacturers WHERE id=? LIMIT 1', [$mfgId]);
        if (!$mfg) self::json_error('Manufacturer not found');

        $finalTable = (string)$mfg['table_name']; // e.g., hs_inline
        $stageTable = "{$finalTable}_stage";

        self::slog($sentinel, 'import.start', [
            'job_id'=>$jobId,'mfg_id'=>$mfgId,'final'=>$finalTable,'stage'=>$stageTable
        ]);

        // Ensure FINAL table exists (prefer global helper), and ensure stage + indexes
        if (function_exists('\hs_ensure_data_table')) {
            \hs_ensure_data_table($pdo, $finalTable);
        } else {
            self::ensureMinimalDataTable($pdo, $finalTable);
        }
        // Ensure ETA exists on FINAL even if older schema
        self::ensureEtaColumn($pdo, $finalTable);

        self::ensureStageTable($pdo, $stageTable);
        self::ensureStageIndexes($pdo, $stageTable);

        // Create run
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        \qexec("INSERT INTO hs_runs (job_id, status, started_at) VALUES (?, 'pending', ?)", [$jobId, $now]);
        $runId = (int)\qlastid();

        /* -------- ADDED: set run -> manufacturer tag cache -------- */
        $tag = strtoupper(trim((string)($mfg['slug'] ?? ''))) ?: strtoupper(trim((string)($mfg['name'] ?? 'UNKNOWN')));
        self::$runMfgTag[$runId] = $tag;

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
            self::slog($sentinel, 'lock.acquire.try', ['table'=>$finalTable,'timeout_s'=>$lockTimeout]);
            if (!\hs_acquire_lock($pdo, $finalTable, $lockTimeout)) {
                $msg = "Importer busy for table: {$finalTable}";
                self::slog($sentinel, 'lock.acquire.fail', ['table'=>$finalTable], 'error', 'LOCK_BUSY');
                self::setRunStatus($runId, 'failed', null, $msg);
                self::logWarn($pdo, $jobId, $runId, 'failed', $msg);
                self::json_error($msg, 409);
            }
            self::slog($sentinel, 'lock.acquire.ok', ['table'=>$finalTable]);
        }

        try {
            self::setRunStatus($runId, 'started');
            self::work($pdo, $runId, $uploadId, $sentinel);
        } catch (\Throwable $e) {
            // make failures visible to UI
            self::slog($sentinel, 'work.fail', [
                'run_id'=>$runId,'upload_id'=>$uploadId,'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()
            ], 'error', 'EXCEPTION');
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

        /* -------- ADDED: ensure tag is cached even if start() didn’t set it -------- */
        if (empty(self::$runMfgTag[$runId])) {
            $tag = strtoupper(trim((string)($mfg['slug'] ?? ''))) ?: strtoupper(trim((string)($mfg['name'] ?? 'UNKNOWN')));
            self::$runMfgTag[$runId] = $tag;
        }

        $finalTable = (string)$mfg['table_name'];    // e.g., hs_inline
        $stageTable = "{$finalTable}_stage";
        $mode       = (string)($job['mode'] ?? 'replace'); // 'replace' | 'merge'

        $columnsMap = json_decode((string)($job['columns_map'] ?? '{}'), true) ?: [];
        $transforms = self::toPrefixOnly(json_decode((string)($job['transforms'] ?? '{}'), true) ?: []);

        // Exact mapping required, no tolerance
        $required = ['code','ean','name','stock'];
        foreach ($required as $k) {
            if (!isset($columnsMap[$k]) || trim((string)$columnsMap[$k]) === '') {
                self::setRunStatus($runId, 'failed', null, "columns_map missing required key: {$k}");
                self::logErr($pdo, (int)$job['id'], $runId, 'init', "columns_map missing key", ['missing'=>$k]);
                return;
            }
        }

        // Optional ETA support
        $hasEta = isset($columnsMap['eta']) && trim((string)$columnsMap['eta']) !== '';

        // flip to running immediately and surface initial context
        \qexec("UPDATE hs_runs SET status='running' WHERE id=?", [$runId]);
        self::logInfo($pdo, (int)$job['id'], $runId, 'start', 'Import worker started', [
            'run_id'=>$runId,'upload_id'=>$uploadId
        ]);
        self::logInfo($pdo, (int)$job['id'], $runId, 'init', 'Resolved tables/mode', [
            'final'=>$finalTable, 'stage'=>$stageTable, 'mode'=>$mode, 'has_eta'=>$hasEta
        ]);
        self::slog($sentinel, 'work.begin', [
            'run_id'=>$runId,'upload_id'=>$uploadId,'final'=>$finalTable,'stage'=>$stageTable,'mode'=>$mode,
            'columns_map'=>$columnsMap,'transforms'=>$transforms,'has_eta'=>$hasEta
        ]);

        // capture file info early for the UI
        $inputPath = (string)$upload['stored_path'];
        self::logInfo($pdo, (int)$job['id'], $runId, 'open', 'Opening input file', [
            'stored_path'=>$inputPath, 'src_path'=>$upload['src_path'] ?? null, 'format'=>$upload['format'] ?? null
        ]);

        // 1) INPUT (xlsx/xls/csv/txt) -> normalized CSV
        $wantedHeaders = [
            (string)$columnsMap['code'],
            (string)$columnsMap['ean'],
            (string)$columnsMap['name'],
            (string)$columnsMap['stock'],
        ];
        if ($hasEta) $wantedHeaders[] = (string)$columnsMap['eta'];

        if ($inputPath === '' || !is_file($inputPath)) {
            self::setRunStatus($runId, 'failed', null, 'Stored file not found');
            self::bumpProgress($runId, $uploadId, 100, 100, 'failed');
            self::logErr($pdo, (int)$job['id'], $runId, 'error', 'Stored file not found', ['path'=>$inputPath]);
            self::slog($sentinel, 'file.missing', ['path'=>$inputPath], 'error', 'NO_INPUT');
            return;
        }

        $ext = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
        $csv = ($ext === 'csv' || $ext === 'txt')
            ? preg_replace('~\.(csv|txt)$~i', '.norm.csv', $inputPath) // don’t overwrite original CSV
            : preg_replace('~\.(xlsx|xls)$~i', '.csv', $inputPath);

        if (!$csv) $csv = $inputPath . '.csv';

        self::setRunStatus($runId, 'reading');
        self::logInfo($pdo, (int)$job['id'], $runId, 'reading', 'INPUT->CSV begin', [
            'input'=>$inputPath, 'csv'=>$csv, 'wanted_headers'=>$wantedHeaders
        ]);
        self::slog($sentinel, 'input->csv.begin', ['input'=>$inputPath,'csv'=>$csv,'wanted_headers'=>$wantedHeaders]);

        $t0 = microtime(true);
        $rowsTotal = self::inputToCsv($inputPath, $csv, $wantedHeaders, $transforms);
        $ms = (int)round((microtime(true)-$t0)*1000);

        self::logInfo($pdo, (int)$job['id'], $runId, 'reading', 'INPUT->CSV done', ['csv'=>$csv, 'rows_total'=>$rowsTotal, 'duration_ms'=>$ms]);
        self::slog($sentinel, 'input->csv.done', ['rows_total'=>$rowsTotal,'duration_ms'=>$ms]);

        // baseline progress for UI
        self::bumpProgress($runId, $uploadId, 0, max($rowsTotal,1), 'reading');

        // 2) Bulk load
        self::setRunStatus($runId, 'inserting', ['rows_total'=>$rowsTotal, 'rows_done'=>0]);
        self::bumpProgress($runId, $uploadId, 0, max($rowsTotal,1), 'inserting');
        self::logInfo($pdo, (int)$job['id'], $runId, 'inserting', 'Load begin', ['mode'=>$mode]);
        self::slog($sentinel, 'inserting.begin', ['mode'=>$mode,'rows_total'=>$rowsTotal]);

        // dynamic load column set
        $loadCols = ['code','ean','name','stock'];
        if ($hasEta) $loadCols[] = 'eta';

        if ($mode === 'replace') {
            // Build & swap into FINAL
            $new = "{$finalTable}__new";
            $old = "{$finalTable}__old";

            // Try cloning FINAL; if FINAL missing, build fresh schema for __new
            $cloned = false;
            try {
                try {
                    $pdo->exec("CREATE TABLE `{$new}` LIKE `{$finalTable}`");
                } catch (\Throwable $e) {
                    // fallback: final table missing
                    self::ensureMinimalDataTable($pdo, $new);
                }
                try { $pdo->exec("ALTER TABLE `{$new}` DROP INDEX `uq_code`"); } catch (\Throwable $_) {}
                $cloned = true;
            } catch (\Throwable $e) {
                // FINAL didn’t exist (1146) or LIKE failed → create fresh minimal schema for __new
                self::ensureMinimalDataTable($pdo, $new);
                try { $pdo->exec("ALTER TABLE `{$new}` DROP INDEX `uq_code`"); } catch (\Throwable $_) {}
            }

            // Make sure __new has eta if we plan to load it
            if ($hasEta) self::ensureEtaColumn($pdo, $new);

            self::slog($sentinel, 'replace.prepare', ['new'=>$new,'old'=>$old,'final'=>$finalTable,'cloned'=>$cloned]);
            self::slog($sentinel, 'replace.load.start', ['table'=>$new]);

            self::loadCsv($pdo, $csv, $new, $loadCols, $runId, $uploadId, $rowsTotal, (int)$job['id']);
            self::slog($sentinel, 'replace.load.done', ['table'=>$new]);

            try { $pdo->exec("ALTER TABLE `{$new}` ADD UNIQUE KEY `uq_code` (`code`)"); } catch (\Throwable $_) {}

            // Does FINAL exist right now?
            $finalExists = (bool)\qcell("
                SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ", [$finalTable]);

            // Swap in
            try {
                if ($finalExists) {
                    $pdo->exec("RENAME TABLE `{$finalTable}` TO `{$old}`, `{$new}` TO `{$finalTable}`");
                    try { $pdo->exec("DROP TABLE `{$old}`"); } catch (\Throwable $_) {}
                } else {
                    $pdo->exec("RENAME TABLE `{$new}` TO `{$finalTable}`");
                }
            } catch (\Throwable $e) {
                try { $pdo->exec("RENAME TABLE `{$new}` TO `{$finalTable}`"); } catch (\Throwable $_) {}
                self::logWarn($pdo, (int)$job['id'], $runId, 'swap', 'Swap encountered issue, kept new as final', ['error'=>$e->getMessage()]);
            }

            self::logInfo($pdo, (int)$job['id'], $runId, 'inserting', 'Swap completed');
            self::slog($sentinel, 'replace.swap', ['final'=>$finalTable,'final_existed'=>$finalExists]);
        } else {
            // Merge: stage + upsert
            self::ensureStageIndexes($pdo, $stageTable);
            $pdo->exec("TRUNCATE `{$stageTable}`");
            self::slog($sentinel, 'merge.stage.prepare', ['stage'=>$stageTable]);

            // Make sure STAGE has eta if needed
            if ($hasEta) self::ensureEtaColumn($pdo, $stageTable);

            self::loadCsv($pdo, $csv, $stageTable, $loadCols, $runId, $uploadId, $rowsTotal, (int)$job['id']);
            self::slog($sentinel, 'merge.load.done', ['stage'=>$stageTable]);

            self::slog($sentinel, 'merge.upsert.begin');

            // Ensure FINAL has eta before upsert, if needed
            if ($hasEta) self::ensureEtaColumn($pdo, $finalTable);

            // Dynamic MERGE SQL with optional ETA
            $colList   = '`code`,`ean`,`name`,`stock`' . ($hasEta ? ',`eta`' : '');
            $selList   = 'SELECT `code`,`ean`,`name`,`stock`' . ($hasEta ? ',`eta`' : '') . " FROM `{$stageTable}`";
            $updates   = [
                '`ean`=VALUES(`ean`)',
                '`name`=VALUES(`name`)',
                '`stock`=VALUES(`stock`)',
                '`updated_at`=NOW()',
            ];
            if ($hasEta) $updates[] = '`eta`=VALUES(`eta`)';

            $sql = "
                INSERT INTO `{$finalTable}` ({$colList})
                {$selList}
                ON DUPLICATE KEY UPDATE " . implode(',', $updates);

            $pdo->exec($sql);
            self::logInfo($pdo, (int)$job['id'], $runId, 'inserting', 'Merge upsert completed', ['has_eta'=>$hasEta]);
            self::slog($sentinel, 'merge.upsert.done');
        }

        // 3) Done
        self::setRunStatus($runId, 'imported', ['rows_total'=>$rowsTotal,'rows_done'=>$rowsTotal]);
        self::bumpProgress($runId, $uploadId, 100, 100, 'imported');
        self::logInfo($pdo, (int)$job['id'], $runId, 'finish', 'Import finished', ['rows_total'=>$rowsTotal]);
        self::slog($sentinel, 'work.done', ['rows_total'=>$rowsTotal]);
    }

    /* -------------------- XLSX/CSV/Bulk helpers -------------------- */

    /**
     * Generic dispatcher: read input (xlsx/xls/csv/txt) and write normalized CSV
     * header: code, ean, name, stock[, eta]
     * Returns data row count (without header).
     */
    private static function inputToCsv(string $inputPath, string $csvPath, array $headersWanted, array $transforms): int
    {
        $ext = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv','txt'], true)) {
            return self::csvToCsvNormalize($inputPath, $csvPath, $headersWanted, $transforms);
        }
        // xlsx / xls
        return self::sheetToCsv($inputPath, $csvPath, $headersWanted, $transforms);
    }

    /**
     * Normalize a raw CSV/TXT into canonical CSV (code, ean, name, stock[, eta]) using exact header names from $headersWanted.
     * Applies transforms and stock normalization.
     */
    private static function csvToCsvNormalize(string $srcCsv, string $dstCsv, array $headersWanted, array $transforms): int
    {
        [$delimiter, $enclosure, $escape] = self::sniffCsvFormat($srcCsv);
        $encoding = self::sniffEncoding($srcCsv);

        $s = $GLOBALS['sentinel'] ?? null;
        self::slog($s, 'csv.normalize.start', ['src'=>$srcCsv,'dst'=>$dstCsv,'delimiter'=>$delimiter,'encoding'=>$encoding]);

        // Read via PhpSpreadsheet (respects encoding + delimiter consistently)
        $reader = new CsvReader();
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure($enclosure);
        $reader->setEscapeCharacter($escape);
        $reader->setInputEncoding($encoding);
        $reader->setReadDataOnly(true);
		

        $ss  = $reader->load($srcCsv);
        $ws  = $ss->getActiveSheet();
        $arr = $ws->toArray(null, false, false, false);
        $ss->disconnectWorksheets();

        if (empty($arr)) throw new \RuntimeException('CSV appears empty');

        // Map header (row 1) -> index
        $header = array_map(fn($v)=> (string)$v, (array)$arr[0]);
        $map    = [];
        foreach ($header as $i => $h) {
            if ($h !== '') $map[$h] = $i;
        }

        $idx = [];
        foreach ($headersWanted as $h) {
            $h = (string)$h;
            if (!array_key_exists($h, $map)) throw new \RuntimeException("Header not found in CSV: '{$h}'");
            $idx[] = (int)$map[$h];
        }

        $t = self::toPrefixOnly($transforms);
        $codePrefix = (string)($t['code']['prefix'] ?? '');
        $codeTrim   = (bool)  ($t['code']['trim']   ?? false);
        $namePrefix = (string)($t['name']['prefix'] ?? '');

        $hasEta = (count($headersWanted) === 5);

        $out = fopen($dstCsv, 'wb');
        if (!$out) throw new \RuntimeException('Cannot open temp CSV for writing: '.$dstCsv);

        fputcsv($out, $hasEta ? ['code','ean','name','stock','eta'] : ['code','ean','name','stock']);
        $count = 0;

        $lastHeartbeat = microtime(true);
        $totalRows = max(count($arr) - 1, 0);

        for ($r = 1; $r < count($arr); $r++) {
            $row = (array)$arr[$r];

            // pick in the fixed order per wanted header mapping
            $picked = [];
            foreach ($idx as $i) {
                $v = $row[$i] ?? '';
                $picked[] = is_scalar($v) ? (string)$v : '';
            }
            if (implode('', $picked) === '') continue;

            // stock normalize (int) — index 3 is stock regardless of ETA
            $picked[3] = (string)((int)preg_replace('~[^\d\-]+~', '', (string)($picked[3] ?? '0')));

            // transforms
            $code = (string)($picked[0] ?? '');
            if ($codeTrim)  $code = trim($code);
            if ($codePrefix !== '' && $code !== '') $code = $codePrefix . $code;
            $picked[0] = $code;

            $name = (string)($picked[2] ?? '');
            if ($namePrefix !== '' && $name !== '') $name = $namePrefix . $name;
            $picked[2] = $name;

            // eta: trim only (keep free-form)
            if ($hasEta) {
                $eta = trim((string)($picked[4] ?? ''));
                $picked[4] = ($eta === '') ? null : $eta;
            }

            fputcsv($out, $picked);
            $count++;

            if ((microtime(true) - $lastHeartbeat) >= 1.0) {
                self::slog($s, 'csv.normalize.heartbeat', ['written'=>$count,'of_estimate'=>$totalRows]);
                $lastHeartbeat = microtime(true);
            }
        }

        fclose($out);
        self::slog($s, 'csv.normalize.done', ['rows_total'=>$count]);

        return $count;
    }

    /**
     * Export wanted headers from XLSX/XLS to CSV.
     * Header becomes: code, ean, name, stock[, eta]
     * Expects headers on the first row (A1:…).
     */
    private static function sheetToCsv(string $sheetPath, string $csvPath, array $headersWanted, array $transforms): int
    {
        $ext = strtolower(pathinfo($sheetPath, PATHINFO_EXTENSION));
        $reader = ($ext === 'xls') ? new Xls() : new Xlsx();
        $reader->setReadDataOnly(true);

        $t = self::toPrefixOnly($transforms);
        $codePrefix = (string)($t['code']['prefix'] ?? '');
        $codeTrim   = (bool)  ($t['code']['trim']   ?? false);
        $namePrefix = (string)($t['name']['prefix'] ?? '');

        $ss = $reader->load($sheetPath);
        $ws = $ss->getActiveSheet();

        $highestCol = $ws->getHighestDataColumn();
        $highestRow = $ws->getHighestDataRow();

        // read header row
        $row1 = $ws->rangeToArray('A1:' . $highestCol . '1', null, true, true, true);
        $headerRow = $row1[1] ?? [];
        if (!$headerRow) {
            throw new \RuntimeException('No header row found in spreadsheet');
        }

        // Build exact match map: header text => column letter
        $map = [];
        foreach ($headerRow as $colLetter => $name) {
            $name = (string)$name;
            if ($name !== '') $map[$name] = $colLetter;
        }

        // Resolve exact columns for the wanted headers
        $orderedCols = [];
        foreach ($headersWanted as $h) {
            $h = (string)$h;
            if (!isset($map[$h])) {
                throw new \RuntimeException("Header not found in sheet: '{$h}'");
            }
            $orderedCols[] = $map[$h];
        }

        $hasEta = (count($headersWanted) === 5);

        // write CSV
        $out = fopen($csvPath, 'wb');
        if (!$out) throw new \RuntimeException('Cannot open temp CSV for writing: '.$csvPath);

        // normalized loader header
        fputcsv($out, $hasEta ? ['code','ean','name','stock','eta'] : ['code','ean','name','stock']);

        $count = 0;
        $lastHeartbeat = microtime(true);
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = $ws->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true)[$r] ?? [];

            // pick in the fixed order
            $raw = [];
            foreach ($orderedCols as $letter) {
                $v = $row[$letter] ?? '';
                $raw[] = is_scalar($v) ? (string)$v : '';
            }

            // skip fully empty rows
            if (implode('', $raw) === '') continue;

            // stock normalize (int)
            $raw[3] = (string)((int)preg_replace('~[^\d\-]+~', '', (string)($raw[3] ?? '0')));

            // transforms
            $code = (string)($raw[0] ?? '');
            if ($codeTrim)  $code = trim($code);
            if ($codePrefix !== '' && $code !== '') $code = $codePrefix . $code;
            $raw[0] = $code;

            $name = (string)($raw[2] ?? '');
            if ($namePrefix !== '' && $name !== '') $name = $namePrefix . $name;
            $raw[2] = $name;

            // eta: trim only (keep free-form)
            if ($hasEta) {
                $eta = trim((string)($raw[4] ?? ''));
                $raw[4] = ($eta === '') ? null : $eta;
            }

            fputcsv($out, $raw);
            $count++;

            // heartbeat every ~1s
            if ((microtime(true) - $lastHeartbeat) >= 1.0) {
                $s = $GLOBALS['sentinel'] ?? null;
                self::slog($s, 'sheet->csv.heartbeat', ['written'=>$count,'of_estimate'=>$highestRow-1]);
                $lastHeartbeat = microtime(true);
            }
        }
        fclose($out);

        $ss->disconnectWorksheets();
        unset($ss);

        return $count;
    }

    /** Tiny CSV sniffer for delimiter (tries ; , \t |). */
    private static function sniffCsvFormat(string $path): array
    {
        $candidates = [';', ',', "\t", '|'];
        $enclosure  = '"';
        $escape     = '\\';

        $fh = @fopen($path, 'rb');
        if (!$fh) return [',', $enclosure, $escape];

        $firstNonEmpty = null;
        $tries = 0;
        while (!feof($fh) && $tries < 50) {
            $line = fgets($fh);
            $tries++;
            if ($line === false) break;
            $trim = trim($line, "\r\n\t ");
            if ($trim !== '') { $firstNonEmpty = $line; break; }
        }
        fclose($fh);

        if ($firstNonEmpty === null) return [',', $enclosure, $escape];

        $bestDelim = ',';
        $bestCols  = 0;
        foreach ($candidates as $d) {
            $cols  = str_getcsv($firstNonEmpty, $d, $enclosure, $escape);
            $count = is_array($cols) ? count($cols) : 0;
            if ($count > $bestCols) { $bestCols = $count; $bestDelim = $d; }
        }
        return [$bestDelim, $enclosure, $escape];
    }

    /** Rudimentary BOM / encoding sniff. Defaults to UTF-8 if unsure. */
    private static function sniffEncoding(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return 'UTF-8';
        $bom = fread($fh, 4) ?: '';
        fclose($fh);

        if (strncmp($bom, "\xEF\xBB\xBF", 3) === 0) return 'UTF-8';
        if (strncmp($bom, "\xFF\xFE", 2) === 0)     return 'UTF-16LE';
        if (strncmp($bom, "\xFE\xFF", 2) === 0)     return 'UTF-16BE';

        return 'UTF-8';
    }

    /** Batch size setting (portable) */
    private static function batchSize(): int {
        $n = (int)self::cfg('bulk_insert_batch_size', 2000);
        return max(200, min(10000, $n));
    }

    /**
     * Bulk load: try LOAD DATA LOCAL INFILE (CRLF/LF), else batched INSERTs.
     * Also bumps progress if we had a row count.
     */
    private static function loadCsv(PDO $pdo, string $csvPath, string $table, array $columns,
                                    int $runId=null, int $uploadId=null, int $rowsTotal=0, int $jobId=0): void
    {
        $colsQuoted = implode(',', array_map(fn($c)=>"`{$c}`", $columns));
        $s = $GLOBALS['sentinel'] ?? null;
        self::slog($s, 'loadcsv.begin', ['table'=>$table,'csv'=>$csvPath,'columns'=>$columns]);

        // Record server/session toggles that influence LOCAL INFILE
        $localInfile = null;
        try {
            $vars = [
                'local_infile'     => (int)\qcell('SELECT @@local_infile'),
                'secure_file_priv' => (string)\qcell('SELECT @@secure_file_priv'),
                'version_comment'  => (string)\qcell('SELECT @@version_comment'),
                'tx_isolation'     => (string)\qcell('SELECT @@transaction_isolation'),
            ];
            $localInfile = (int)$vars['local_infile'];
            self::slog($s, 'loadcsv.session', ['table'=>$table,'vars'=>$vars]);
        } catch (\Throwable $_) {
            // ignore diagnostics errors
        }

        // If server says LOCAL INFILE is OFF, skip straight to batched inserts
        if ($localInfile === 0) {
            self::slog($s, 'loadcsv.local_infile.off', ['table'=>$table], 'warn', 'LOCAL_INFILE_OFF');
            self::loadCsvBatchedInsert($pdo, $csvPath, $table, $columns, $runId, $uploadId, $rowsTotal, $jobId);
            $pdo->exec("COMMIT");
            $pdo->exec("SET unique_checks=1");
            $pdo->exec("SET foreign_key_checks=1");
            $pdo->exec("SET autocommit=1");
            return;
        }

        // Optional kill-switch to skip LOCAL INFILE entirely
        $forceBatched = (bool)self::cfg('force_batched_insert', false);
        if ($forceBatched) {
            self::slog($s, 'loadcsv.force_batched', ['table'=>$table], 'warn', 'FORCE_BATCHED');
            self::loadCsvBatchedInsert($pdo, $csvPath, $table, $columns, $runId, $uploadId, $rowsTotal, $jobId);
            $pdo->exec("COMMIT");
            $pdo->exec("SET unique_checks=1");
            $pdo->exec("SET foreign_key_checks=1");
            $pdo->exec("SET autocommit=1");
            return;
        }

        // try to enable LOCAL INFILE explicitly
        try { $pdo->setAttribute(\PDO::MYSQL_ATTR_LOCAL_INFILE, true); } catch (\Throwable $_) {}

        // perf toggles
        $pdo->exec("SET autocommit=0");
        $pdo->exec("SET unique_checks=0");
        $pdo->exec("SET foreign_key_checks=0");

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
                // try LF if CRLF didn’t load anything (line endings mismatch)
                $n = $pdo->exec($sqlLocalLF);
            }
            if ($n === false) {
                throw new \RuntimeException('LOAD DATA LOCAL INFILE failed');
            }
            $ms = (int)round((microtime(true)-$t0)*1000);
            if ($runId && $uploadId && $rowsTotal > 0) {
                self::bumpProgress($runId, $uploadId, $rowsTotal, max($rowsTotal,1), 'inserting');
            }
            self::logInfo($pdo, $jobId, $runId ?? 0, 'inserting', 'LOAD DATA LOCAL INFILE used', [
                'table'=>$table, 'duration_ms'=>$ms, 'rows_total'=>$rowsTotal, 'loaded_rows'=>(int)$n
            ]);
            self::slog($s, 'loadcsv.local_infile.ok', [
                'table'=>$table,'rows_total'=>$rowsTotal,'loaded_rows'=>(int)$n,'duration_ms'=>$ms
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?? '';
            if (strpos($msg, '3948') !== false
                || stripos($msg, 'Loading local data is disabled') !== false
                || stripos($msg, 'rejected due to restrictions') !== false
                || stripos($msg, 'LOCAL INFILE') !== false) {
                self::logWarn($pdo, $jobId, $runId ?? 0, 'inserting',
                    'LOCAL INFILE unavailable; falling back to batched INSERTs', ['table'=>$table, 'error'=>$msg]);
                self::slog($s, 'loadcsv.local_infile.unavailable', ['table'=>$table,'error'=>$msg], 'warn', 'LOCAL_INFILE_DISABLED');
                self::loadCsvBatchedInsert($pdo, $csvPath, $table, $columns, $runId, $uploadId, $rowsTotal, $jobId);
            } else {
                self::logErr($pdo, $jobId, $runId ?? 0, 'inserting', 'LOAD DATA failed', ['error'=>$msg]);
                self::slog($s, 'loadcsv.error', ['table'=>$table,'error'=>$msg], 'error', 'LOAD_DATA_FAILED');
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
        $batchSize  = self::batchSize();
        $colCount   = count($columns);
        $colsQuoted = implode(',', array_map(fn($c)=>"`{$c}`", $columns));
        $s = $GLOBALS['sentinel'] ?? null;

        self::slog($s, 'batched_insert.begin', ['table'=>$table,'batch_size'=>$batchSize,'rows_total'=>$rowsTotal]);

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
                self::slog($s, 'batched_insert.chunk', [
                    'table'=>$table,'rows_done'=>$done,'rows_total'=>$rowsTotal,'duration_ms'=>$ms
                ]);

                if (self::shouldStopAll()) {
                    $pdo->commit();
                    self::logWarn($pdo, $jobId, $runId ?? 0, 'cancelling', 'Cancelled by stop_all');
                    self::slog($s, 'batched_insert.cancelled', ['table'=>$table,'rows_done'=>$done,'rows_total'=>$rowsTotal], 'warn', 'CANCELLED');
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
            self::slog($s, 'batched_insert.done', ['table'=>$table,'rows_done'=>$done,'rows_total'=>$rowsTotal,'duration_ms'=>$ms]);
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

        $s = $GLOBALS['sentinel'] ?? null;
        self::slog($s, 'insert.batch.exec', ['table'=>$table,'rows'=>count($rows)]);
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

        $s = $GLOBALS['sentinel'] ?? null;
        self::slog($s, 'progress.bump', [
            'run_id'=>$runId,'upload_id'=>$uploadId,'done'=>$done,'total'=>$total,'pct'=>$pct,'status'=>$status
        ]);
    }

    private static function shouldStopAll(): bool
    {
        $v = \qcell("SELECT v FROM hs_control WHERE k='stop_all_at' LIMIT 1");
        return !empty($v);
    }

    /* -------------------- Schema helpers -------------------- */

    private static function ensureMinimalDataTable(PDO $pdo, string $table): void
    {
        // validate name
        if (!preg_match('~^[a-zA-Z0-9_]+$~', $table)) {
            throw new \RuntimeException("Invalid table name: $table");
        }

        // Create lean schema (matches hs_lib.php) + eta
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
          `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `code`       VARCHAR(128)    NOT NULL,
          `ean`        VARCHAR(32)     NULL,
          `name`       VARCHAR(512)    NULL,
          `stock`      INT             NULL,
          `eta`        VARCHAR(64)     NULL,
          `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_code` (`code`),
          KEY `ix_ean` (`ean`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);

        // Resilient ALTERs in case old structure exists
        try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `eta` VARCHAR(64) NULL AFTER `stock`"); } catch (\Throwable $_) {}
        try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (\Throwable $_) {}
        try { $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_code` (`code`)"); } catch (\Throwable $_) {}
        try { $pdo->exec("ALTER TABLE `{$table}` ADD KEY `ix_ean` (`ean`)"); } catch (\Throwable $_) {}
    }

    private static function ensureStageTable(PDO $pdo, string $table): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `code`  VARCHAR(128) NOT NULL,
            `ean`   VARCHAR(32)  NULL,
            `name`  VARCHAR(512) NULL,
            `stock` INT          NULL,
            `eta`   VARCHAR(64)  NULL,
            PRIMARY KEY (`code`),
            KEY `idx_ean` (`ean`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);

        // Upgrades for older stage schemas
        try { $pdo->exec("ALTER TABLE `{$table}` MODIFY `ean` VARCHAR(32) NULL"); } catch (\Throwable $_) {}
        try { $pdo->exec("ALTER TABLE `{$table}` MODIFY `stock` INT NULL"); } catch (\Throwable $_) {}
        try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `eta` VARCHAR(64) NULL AFTER `stock`"); } catch (\Throwable $_) {}
    }

    /** Ensure ETA column exists on a given table (FINAL or STAGE) */
    private static function ensureEtaColumn(PDO $pdo, string $table): void
    {
        try {
            $exists = (int)\qcell("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'eta'
            ", [$table]);
            if ($exists === 0) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `eta` VARCHAR(64) NULL AFTER `stock`");
            }
        } catch (\Throwable $_) {
            // ignore
        }
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

    /* -------------------- Transform helpers -------------------- */

    /**
     * Normalize any legacy/new transforms into prefix-only:
     * returns: ['code'=>['trim'=>bool,'prefix'=>string], 'name'=>['prefix'=>string]]
     */
    private static function toPrefixOnly($t): array
    {
        $t = is_array($t) ? $t : [];
        $code = is_array($t['code'] ?? null) ? $t['code'] : [];
        $name = is_array($t['name'] ?? null) ? $t['name'] : [];

        $codeTrim   = (bool)($code['trim'] ?? false);
        $codePrefix = (string)($code['prefix'] ?? ($code['suffix'] ?? ''));
        $namePrefix = (string)($name['prefix'] ?? ($name['suffix'] ?? ''));

        return [
            'code' => ['trim' => $codeTrim, 'prefix' => $codePrefix],
            'name' => ['prefix' => $namePrefix],
        ];
    }

    /* -------------------- Logger helpers -------------------- */

    private static function log(PDO $pdo, int $jobId, int $runId, string $level, string $phase, string $message, array $meta = []): void
    {
        // ADDED: prefix message with manufacturer tag if not already prefixed
        $tag = self::$runMfgTag[$runId] ?? null;
        if ($tag && ($message === '' || $message[0] !== '[')) {
            $message = '[' . $tag . '] ' . $message;
        }

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
