<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap_hs.php';

$pdo = hs_pdo();
$sentinel = new debug_sentinel('health_system', $pdo);

/* ---------- tiny logger wrapper (correct order for your sentinel) ---------- */
function hs_wlog(debug_sentinel $s, string $label, array $payload = [], string $level = 'info', ?string $code = null): void {
    $val = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
    try { $s->record($label, $val, $code, $level, 'health_system'); }
    catch (\Throwable $e) { try { $s->log('health_system', $label, $val, $code, $level); } catch (\Throwable $e2) { error_log('[Sentinel] hs_wlog failed: '.$e2->getMessage()); } }
}

/* -------- detect hs_progress column names (supports old/new schemas) -------- */
function hs_progress_schema(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cols = [];
    foreach (qall('SHOW COLUMNS FROM `hs_progress`', []) as $r) {
        $cols[strtolower($r['Field'])] = true;
    }

    $cache = [
        'msg'       => isset($cols['last_message']) ? 'last_message' : (isset($cols['message']) ? 'message' : null),
        'updated'   => isset($cols['updated_at'])   ? 'updated_at'   : null,
        'rows_total'=> isset($cols['rows_total'])   ? 'rows_total'   : null,
        'rows_done' => isset($cols['rows_done'])    ? 'rows_done'    : null,
        'percent'   => isset($cols['percent'])      ? 'percent'      : null,
        'status'    => isset($cols['status'])       ? 'status'       : null,
    ];
    return $cache;
}

/** Build a safe UPDATE for hs_progress that adapts to present columns. */
function hs_progress_update(PDO $pdo, int $uploadId, array $fields): void {
    $sch = hs_progress_schema($pdo);
    $sets = [];
    $args = [];

    foreach ($fields as $k => $v) {
        if ($k === 'last_message' || $k === 'message') {
            if ($sch['msg']) { $sets[] = "`{$sch['msg']}` = ?"; $args[] = $v; }
            continue;
        }
        if (!empty($sch[$k])) { // rows_total/rows_done/percent/status/updated_at
            $col = $sch[$k];
            $sets[] = "`$col` = ?";
            $args[] = $v;
        }
    }
    // Stamp updated_at if present
    if (!isset($fields['updated_at']) && $sch['updated']) {
        $sets[] = "`{$sch['updated']}` = NOW()";
    }

    if (!$sets) return;
    $sql = 'UPDATE `hs_progress` SET '.implode(', ', $sets).' WHERE `upload_id` = ?';
    $args[] = $uploadId;
    qexec($sql, $args);
}

/* --------------------- parse args (positional + getopt) --------------------- */
$runId    = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 0;
$uploadId = isset($argv[2]) && is_numeric($argv[2]) ? (int)$argv[2] : 0;

try {
    $opt = getopt('', ['run-id::','upload-id::']);
    if (!$runId && !empty($opt['run-id']))       $runId    = (int)$opt['run-id'];
    if (!$uploadId && !empty($opt['upload-id'])) $uploadId = (int)$opt['upload-id'];
} catch (\Throwable $e) {}

/* -------------------------- announce worker boot --------------------------- */
$boot = [
    'argv'      => $argv ?? [],
    'cwd'       => getcwd(),
    'sapi'      => PHP_SAPI,
    'php_bin'   => PHP_BINARY,
    'pid'       => getmypid(),
    'run_id'    => $runId,
    'upload_id' => $uploadId,
    'os'        => PHP_OS,
];
hs_wlog($sentinel, 'worker.boot', $boot, 'info');

if ($runId <= 0 || $uploadId <= 0) {
    hs_wlog($sentinel, 'worker.invalid_ids', ['run_id'=>$runId, 'upload_id'=>$uploadId], 'error', 'MISSING_IDS');
    fwrite(STDERR, "ERROR: Missing run/upload id\n");
    exit(2);
}

try {
    /* ------------------ move statuses to running immediately ------------------ */
    qexec('UPDATE hs_runs SET status="running", started_at = IFNULL(started_at, NOW()) WHERE id=?', [$runId]);
    hs_progress_update($pdo, $uploadId, [
        'status'       => 'running',
        'last_message' => 'Worker started',
        'percent'      => 0.1,
    ]);
    hs_wlog($sentinel, 'worker.status_set_running', ['run_id'=>$runId, 'upload_id'=>$uploadId], 'info');

    /* ---------------------------- resolve job meta ---------------------------- */
    // Your schema uses file_path in hs_import_jobs (UNC or local)
    $job = qrow('SELECT j.*
                   FROM hs_import_jobs j
                  WHERE j.id = (SELECT job_id FROM hs_runs WHERE id = ? LIMIT 1)
                  LIMIT 1', [$runId]);
    if (!$job) throw new RuntimeException('Job not found for run '.$runId);

    $jobId   = (int)$job['id'];
    $fileCfg = (string)($job['file_path'] ?? '');  // UNC/local path configured on job
    hs_wlog($sentinel, 'worker.job_loaded', [
        'job_id' => $jobId,
        'manufacturer_id' => (int)($job['manufacturer_id'] ?? 0),
        'file_path' => $fileCfg,
    ], 'info');

    /* --------------------------- fetch hs_uploads row ------------------------- */
    // Your hs_uploads has src_path (UNC) and stored_path (local copy)
    $up = qrow('SELECT src_path, stored_path, format, bytes_total, created_at
                  FROM hs_uploads
                 WHERE id = ? LIMIT 1', [$uploadId]) ?: [];

    hs_wlog($sentinel, 'worker.upload.meta', [
        'src_path'    => $up['src_path']    ?? null,
        'stored_path' => $up['stored_path'] ?? null,
        'format'      => $up['format']      ?? null,
        'bytes_total' => $up['bytes_total'] ?? null,
        'created_at'  => $up['created_at']  ?? null,
    ], 'info');

    /* ------------------------- resolve an effective path ---------------------- */
    $candidates = array_filter([
        $up['stored_path'] ?? null, // prefer local cached copy
        $up['src_path']    ?? null, // original UNC from upload row
        $fileCfg,                   // job-configured file path (UNC/local)
    ]);

    $chosen = null;
    $reasons = [];
    foreach ($candidates as $cand) {
        if (!$cand) { $reasons[] = ['candidate'=>$cand,'ok'=>false,'why'=>'empty']; continue; }

        // Avoid realpath() for UNC; it often returns false even for valid shares.
        $abs      = $cand;
        $isUNC    = (bool)preg_match('~^\\\\\\\\~', $abs);
        $exists   = file_exists($abs);
        $readable = $exists && is_readable($abs);

        // Probe fopen directly to capture a concrete error (helpful for UNC perms)
        $fopenErr = null; $canOpen = false;
        if ($exists) {
            set_error_handler(function($errno,$errstr) use (&$fopenErr){ $fopenErr = $errstr; });
            $fh = @fopen($abs, 'rb');
            if ($fh) { $canOpen = true; fclose($fh); }
            restore_error_handler();
        }

        $reasons[] = [
            'candidate' => $cand,
            'abs'       => $abs,
            'is_unc'    => $isUNC,
            'exists'    => $exists,
            'readable'  => $readable,
            'fopen_ok'  => $canOpen,
            'fopen_err' => $fopenErr,
        ];

        if ($canOpen || $readable) { $chosen = $abs; break; }
    }

    hs_wlog($sentinel, 'worker.input.resolve', ['candidates'=>$reasons, 'chosen'=>$chosen], 'info');

    if (!$chosen) {
        $hint = [];
        if (stripos(PHP_OS, 'WIN') === 0 && preg_match('~^\\\\\\\\~', (string)($up['src_path'] ?? $fileCfg))) {
            $hint[] = 'UNC path detected; run the PHP worker under a user that has access to the NAS share.';
            $hint[] = 'Service accounts usually lack network credentials. Use Task Scheduler with a domain user.';
        }
        $msg = 'No readable input file (checked hs_uploads.stored_path, hs_uploads.src_path, hs_import_jobs.file_path).';
        if ($hint) $msg .= ' ' . implode(' ', $hint);

        hs_wlog($sentinel, 'worker.fail.no_input', [
            'run_id'=>$runId,'upload_id'=>$uploadId,'job_id'=>$jobId,
            'src_path'=>$up['src_path'] ?? null,'stored_path'=>$up['stored_path'] ?? null,'hints'=>$hint
        ], 'error', 'NO_INPUT');

        qexec('UPDATE hs_runs SET status="failed", finished_at=NOW(), error_message=? WHERE id=?', [$msg, $runId]);
        hs_progress_update($pdo, $uploadId, [
            'status'       => 'failed',
            'last_message' => 'No input file',
            'percent'      => 0.0,
        ]);
        exit(1);
    }

    $srcPath = $chosen;

    /* ------------------------------ preflight I/O ----------------------------- */
    $io = [
        'path'       => $srcPath,
        'exists'     => file_exists($srcPath),
        'readable'   => is_readable($srcPath),
        'filesize'   => is_file($srcPath) ? @filesize($srcPath) : null,
        'is_unc'     => (bool)preg_match('~^\\\\\\\\~', $srcPath),
    ];
    hs_wlog($sentinel, 'worker.io_precheck', $io, 'info');

    /* ---------------------- STEP 1: reading / sniff input --------------------- */
    qexec('UPDATE hs_runs SET status="reading" WHERE id=?', [$runId]);
    hs_progress_update($pdo, $uploadId, [
        'status'       => 'reading',
        'last_message' => 'Reading source',
        'percent'      => 5.0,
    ]);

    $rowsTotal = 0;
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    hs_wlog($sentinel, 'reader.detect', ['ext'=>$ext, 'path'=>$srcPath], 'info');

    if (in_array($ext, ['csv','txt'], true)) {
        $fh = @fopen($srcPath, 'rb');
        if (!$fh) throw new RuntimeException('Cannot open CSV/TXT: '.$srcPath);

        $first = fgets($fh, 4096) ?: '';
        $sep = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
        $bom = (strncmp($first, "\xEF\xBB\xBF", 3) === 0);
        if ($bom) $first = substr($first, 3);
        $header = str_getcsv($first, $sep);
        hs_wlog($sentinel, 'reader.csv.header', ['sep'=>$sep, 'bom'=>$bom, 'header'=>$header], 'info');

        $samples = [];
        while (($line = fgets($fh)) !== false) {
            $row = str_getcsv($line, $sep);
            if (count(array_filter($row, fn($v)=>$v!=='' && $v!==null)) === 0) continue; // skip blank
            $rowsTotal++;
            if (count($samples) < 3) $samples[] = $row;
        }
        fclose($fh);
        hs_wlog($sentinel, 'reader.csv.scan', ['rows_total'=>$rowsTotal, 'samples'=>$samples], 'info');

    } elseif (in_array($ext, ['xlsx','xls'], true)) {
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($srcPath);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($srcPath);
                $sheet = $spreadsheet->getActiveSheet();
                $highestRow = (int)$sheet->getHighestDataRow();
                $highestCol = $sheet->getHighestDataColumn();
                $row1 = $sheet->rangeToArray('A1:'.$highestCol.'1', null, true, true, true)[1] ?? [];
                $header = array_values($row1);
                $rowsTotal = max(0, $highestRow - 1); // minus header
                hs_wlog($sentinel, 'reader.xlsx.header', ['header'=>$header, 'rows_total'=>$rowsTotal], 'info');
            } catch (\Throwable $e) {
                throw new RuntimeException('XLSX parse error: '.$e->getMessage());
            }
        } else {
            $msg = 'XLSX reading not available (PhpSpreadsheet missing).';
            hs_wlog($sentinel, 'reader.xlsx.unavailable', ['path'=>$srcPath], 'error', 'XLSX_UNSUPPORTED');
            qexec('UPDATE hs_runs SET status="failed", finished_at=NOW(), error_message=? WHERE id=?', [$msg, $runId]);
            hs_progress_update($pdo, $uploadId, [
                'status'       => 'failed',
                'last_message' => 'XLSX reader not installed',
                'percent'      => 0.0,
            ]);
            exit(1);
        }

    } else {
        hs_wlog($sentinel, 'reader.unknown_ext', ['ext'=>$ext, 'path'=>$srcPath], 'error', 'UNKNOWN_EXT');
        qexec('UPDATE hs_runs SET status="failed", finished_at=NOW(), error_message=? WHERE id=?',
              ['Unsupported file extension: '.$ext, $runId]);
        hs_progress_update($pdo, $uploadId, [
            'status'       => 'failed',
            'last_message' => 'Unsupported extension: '.$ext,
            'percent'      => 0.0,
        ]);
        exit(1);
    }

    hs_progress_update($pdo, $uploadId, ['rows_total'=>$rowsTotal]);

    /* require data rows */
    if ($rowsTotal === 0) {
        hs_wlog($sentinel, 'worker.no_rows', ['path'=>$srcPath], 'error', 'NO_ROWS');
        qexec('UPDATE hs_runs SET status="failed", finished_at=NOW(), error_message=? WHERE id=?',
              ['No data rows found in input file', $runId]);
        hs_progress_update($pdo, $uploadId, [
            'status'       => 'failed',
            'last_message' => 'No data rows',
            'percent'      => 0.0,
        ]);
        exit(1);
    }

    /* --------------------- STEP 2: inserting (placeholder) -------------------- */
    qexec('UPDATE hs_runs SET status="inserting" WHERE id=?', [$runId]);
    hs_progress_update($pdo, $uploadId, [
        'status'       => 'inserting',
        'last_message' => 'Inserting',
        'percent'      => 60.0,
    ]);
    hs_wlog($sentinel, 'worker.step_inserting.begin', ['run_id'=>$runId, 'rows_total'=>$rowsTotal], 'info');

    // TODO: implement real inserts. For now, log that this is a stub and fail visibly:
    $msg = 'Insert logic not implemented yet (rows counted only).';
    hs_wlog($sentinel, 'insert.stub', ['rows_total'=>$rowsTotal], 'warn', 'NO_INSERTS');
    qexec('UPDATE hs_runs SET status="failed", finished_at=NOW(), error_message=? WHERE id=?', [$msg, $runId]);
    hs_progress_update($pdo, $uploadId, [
        'status'       => 'failed',
        'last_message' => 'Insert logic not implemented',
        'percent'      => 90.0,
    ]);
    exit(1);

    /* If/when implemented:
        - increment rows_done periodically
        - percent = 60 + (rows_done/rows_total)*35
        - on success: set imported + 100%
    */

} catch (\Throwable $e) {
    hs_wlog($sentinel, 'worker.fail', [
        'run_id'    => $runId,
        'upload_id' => $uploadId,
        'type'      => get_class($e),
        'msg'       => $e->getMessage(),
        'code'      => (int)$e->getCode(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ], 'error');

    qexec('UPDATE hs_runs SET status="failed", finished_at=NOW(), error_message=? WHERE id=?', [$e->getMessage(), $runId]);
    hs_progress_update($pdo, $uploadId, [
        'status'       => 'failed',
        'last_message' => $e->getMessage(),
    ]);

    fwrite(STDERR, "Worker failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
