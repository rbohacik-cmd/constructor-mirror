<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $run_id = (int)($_POST['run_id'] ?? 0);
    if ($run_id <= 0) throw new RuntimeException('Missing run_id');

    $ok = xfer_run_request_stop($run_id);
    if (!$ok) {
        $cur = xfer_run_status($run_id);
        throw new RuntimeException($cur === null ? 'Run not found' : "Cannot stop run in status '$cur'");
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

