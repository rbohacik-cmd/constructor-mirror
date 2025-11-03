<?php
declare(strict_types=1);
require_once __DIR__ . '/../apps/transfer/connection_mssql.php';
require_once __DIR__ . '/../apps/transfer/connection_mysql.php';

$type   = strtolower((string)($_GET['type'] ?? ''));
$server = (string)($_GET['server'] ?? '');

header('Content-Type: application/json; charset=utf-8');

try {
  if ($type === 'mssql') {
    $pdo = mssql_connect_server($server);
    $rows = $pdo->query("SELECT name FROM sys.databases ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $dbs = array_map(fn($r)=>$r['name'], $rows);
  } elseif ($type === 'mysql') {
    $db  = mysql_connect_server($server);
    $dbs = [];
    if ($res = $db->query("SHOW DATABASES")) {
      while ($r = $res->fetch_array(MYSQLI_NUM)) $dbs[] = $r[0];
    }
    sort($dbs, SORT_NATURAL|SORT_FLAG_CASE);
  } else {
    throw new RuntimeException('Unknown type');
  }
  echo json_encode(['ok'=>true,'databases'=>$dbs]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
