<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../appcfg.php';
require_once __DIR__ . '/../db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function jok($data = null) {
  echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}
function jerr(string $msg, $extra = null, int $code = 400) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg, 'extra' => $extra], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  switch ($action) {
    case 'list':
      $rows = qall("
        SELECT j.*, c.name AS conn_name, c.protocol
        FROM ftp_jobs j
        JOIN ftp_connections c ON c.id = j.connection_id
        ORDER BY j.id DESC
      ");
      jok($rows);

    case 'get':
      $id = (int)($_GET['id'] ?? 0);
      if ($id <= 0) jerr('Missing id');
      $row = qrow("SELECT * FROM ftp_jobs WHERE id = ?", [$id]);
      if (!$row) jerr('Not found', null, 404);
      jok($row);

    case 'save':
      // Upsert
      $id              = (int)($_POST['id'] ?? 0);
      $name            = trim((string)($_POST['name'] ?? ''));
      $connection_id   = (int)($_POST['connection_id'] ?? 0);
      $remote_path     = trim((string)($_POST['remote_path'] ?? ''));
      $filename_glob   = trim((string)($_POST['filename_glob'] ?? ''));
      $is_recursive    = !empty($_POST['is_recursive']) ? 1 : 0;
      $max_size_mb     = ($_POST['max_size_mb'] ?? '') !== '' ? (int)$_POST['max_size_mb'] : null;
      $only_newer_than = trim((string)($_POST['only_newer_than'] ?? ''));
      $target_pipeline = trim((string)($_POST['target_pipeline'] ?? ''));
      $enabled         = !empty($_POST['enabled']) ? 1 : 0;

      if ($name === '' || $connection_id <= 0 || $remote_path === '' || $filename_glob === '') {
        jerr('Missing required fields (name, connection, remote path, filename glob)');
      }

      if ($id > 0) {
        $sql = "UPDATE ftp_jobs
                  SET name=?, connection_id=?, remote_path=?, filename_glob=?, is_recursive=?,
                      max_size_mb=?, only_newer_than=?, target_pipeline=?, enabled=?
                WHERE id=?";
        $args = [$name, $connection_id, $remote_path, $filename_glob, $is_recursive,
                 $max_size_mb, $only_newer_than ?: null, $target_pipeline ?: null, $enabled, $id];
        $n = qi($sql, $args);
        jok(['updated' => $n, 'id' => $id]);
      } else {
        $sql = "INSERT INTO ftp_jobs
                  (name, connection_id, remote_path, filename_glob, is_recursive,
                   max_size_mb, only_newer_than, target_pipeline, enabled)
                VALUES (?,?,?,?,?,?,?,?,?)";
        $args = [$name, $connection_id, $remote_path, $filename_glob, $is_recursive,
                 $max_size_mb, $only_newer_than ?: null, $target_pipeline ?: null, $enabled];
        $n = qi($sql, $args);
        $newId = (int)qlastid();
        jok(['inserted' => $n, 'id' => $newId]);
      }

    case 'delete':
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) jerr('Missing id');
      $n = qi("DELETE FROM ftp_jobs WHERE id = ?", [$id]);
      jok(['deleted' => $n]);

    case 'test':
      // Placeholder: Validate connection exists; you can extend to actually test SFTP/FTP
      $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
      if ($id > 0) {
        $job = qrow("SELECT j.*, c.name AS conn_name, c.protocol, c.id AS conn_id
                      FROM ftp_jobs j
                      JOIN ftp_connections c ON c.id = j.connection_id
                     WHERE j.id = ?", [$id]);
        if (!$job) jerr('Job not found', null, 404);
        jok(['message' => 'Test OK (placeholder)', 'job' => $job]);
      } else {
        jerr('Missing id');
      }

    case 'run':
      // Placeholder for a queued run (implement your runner if available)
      $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
      if ($id <= 0) jerr('Missing id');
      jok(['message' => 'Run queued (placeholder)', 'id' => $id]);

    case 'connections':
      $rows = qall("SELECT id, name, protocol FROM ftp_connections ORDER BY id DESC");
      jok($rows);

    default:
      jerr('Unknown or missing action', ['action'=> $action], 400);
  }

} catch (Throwable $e) {
  jerr('Exception: ' . $e->getMessage());
}

