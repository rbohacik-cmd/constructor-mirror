<?php
declare(strict_types=1);
require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');

$raw = $_POST ?: json_decode(file_get_contents('php://input'), true) ?: [];
$id  = isset($raw['id']) ? (int)$raw['id'] : null;

// Normalize JSON fields
$raw['src_cols_json']   = json_encode($raw['src_cols'] ?? []);
$raw['column_map_json'] = json_encode($raw['column_map'] ?? []);

$jobId = xfer_job_save($id, $raw);
echo json_encode(['ok'=>true, 'id'=>$jobId]);
