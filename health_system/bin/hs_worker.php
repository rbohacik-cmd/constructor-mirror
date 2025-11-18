<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap_hs.php';
require_once __DIR__ . '/../controllers/ImportWorker.php'; // ✅ use the real pipeline

$pdo = hs_pdo();
$sentinel = new debug_sentinel('health_system', $pdo);
$GLOBALS['sentinel'] = $sentinel;  // ✅ for loadCsv()/batches


/* -------- tiny logger (kept for boot diagnostics) -------- */
function hs_wlog(debug_sentinel $s, string $label, array $payload = [], string $level = 'info', ?string $code = null): void {
    $val = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
    try { $s->record($label, $val, $code, $level, 'health_system'); }
    catch (\Throwable $e) { try { $s->log('health_system', $label, $val, $code, $level); } catch (\Throwable $e2) { error_log('[Sentinel] hs_wlog failed: '.$e2->getMessage()); } }
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
hs_wlog($sentinel, 'worker.boot', [
    'argv'      => $argv ?? [],
    'cwd'       => getcwd(),
    'sapi'      => PHP_SAPI,
    'php_bin'   => PHP_BINARY,
    'pid'       => getmypid(),
    'run_id'    => $runId,
    'upload_id' => $uploadId,
    'os'        => PHP_OS,
], 'info');

if ($runId <= 0 || $uploadId <= 0) {
    hs_wlog($sentinel, 'worker.invalid_ids', ['run_id'=>$runId, 'upload_id'=>$uploadId], 'error', 'MISSING_IDS');
    fwrite(STDERR, "ERROR: Missing run/upload id\n");
    exit(2);
}

try {
    // Let ImportWorker do the full pipeline: XLSX→CSV→(LOAD DATA | batched INSERT)→swap/merge
    \HS\ImportWorker::work($pdo, $runId, $uploadId, $sentinel);

    // Success is handled inside ImportWorker (statuses/logs/progress). Exit cleanly.
    hs_wlog($sentinel, 'worker.done', ['run_id'=>$runId, 'upload_id'=>$uploadId], 'info');
    exit(0);

} catch (\Throwable $e) {
    // Make failures visible to UI and logs
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

    // Progress table is updated inside ImportWorker on most errors; keep a safety update here:
    try {
        qexec('UPDATE hs_progress p JOIN hs_uploads u ON u.id=p.upload_id SET p.status="failed", p.percent=COALESCE(p.percent,0), p.updated_at=NOW() WHERE u.id=?', [$uploadId]);
    } catch (\Throwable $_) {}

    fwrite(STDERR, "Worker failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
