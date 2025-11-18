<?php
declare(strict_types=1);
require_once __DIR__ . '/../apps/transfer/connection_mssql.php';
require_once __DIR__ . '/../apps/transfer/connection_mysql.php';

$type   = strtolower((string)($_GET['type'] ?? ''));
$server = (string)($_GET['server'] ?? '');
$dbName = (string)($_GET['db'] ?? '');
$table  = (string)($_GET['table'] ?? '');

header('Content-Type: application/json; charset=utf-8');

try {
  if ($type === 'mssql') {
    $pdo = mssql_connect_server($server);
    $pdo->query("USE [$dbName]");
    $sql = "
      SELECT c.name AS col
      FROM sys.columns c
      JOIN sys.tables t ON t.object_id = c.object_id
      WHERE t.name = :t
      ORDER BY c.column_id";
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$table]);
    $cols = array_map(fn($r)=>$r['col'], $st->fetchAll(PDO::FETCH_ASSOC));
  } elseif ($type === 'mysql') {
    $db = mysql_connect_server($server);
    mysqli_select_db($db, $dbName);
    $cols = [];
    if ($res = $db->query("SHOW COLUMNS FROM `{$table}`")) {
      while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
    }
  } else {
    throw new RuntimeException('Unknown type');
  }
  echo json_encode(['ok'=>true,'columns'=>$cols]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
