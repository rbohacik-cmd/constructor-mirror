<?php
declare(strict_types=1);
require_once __DIR__ . '/../apps/transfer/connection_mssql.php';
require_once __DIR__ . '/../apps/transfer/connection_mysql.php';

$type   = strtolower((string)($_GET['type'] ?? ''));
$server = (string)($_GET['server'] ?? '');
$dbName = (string)($_GET['db'] ?? '');

header('Content-Type: application/json; charset=utf-8');

try {
  if ($type === 'mssql') {
    $pdo = mssql_connect_server($server);
    $pdo->query("USE [$dbName]");
    $rows = $pdo->query("SELECT name FROM sys.tables ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $tables = array_map(fn($r)=>$r['name'], $rows);
  } elseif ($type === 'mysql') {
    $db = mysql_connect_server($server);
    mysqli_select_db($db, $dbName);
    $tables = [];
    if ($res = $db->query("SHOW TABLES")) {
      while ($r = $res->fetch_array(MYSQLI_NUM)) $tables[] = $r[0];
    }
    sort($tables, SORT_NATURAL|SORT_FLAG_CASE);
  } else {
    throw new RuntimeException('Unknown type');
  }
  echo json_encode(['ok'=>true,'tables'=>$tables]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
