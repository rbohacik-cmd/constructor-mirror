<?php
declare(strict_types=1);
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/runner.php';

header('Content-Type: application/json; charset=utf-8');

$jobId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if (!$jobId) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Missing id']); exit; }

$runId = xfer_run_job($jobId);
echo json_encode(['ok'=>true,'run_id'=>$runId]);
