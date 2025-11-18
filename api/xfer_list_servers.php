<?php
declare(strict_types=1);

function load_cfg_safe(string $path): array {
  $full = __DIR__ . '/../' . ltrim($path,'/');
  return is_file($full) ? (require $full) : [];
}

$cfg_mssql = load_cfg_safe('config/db_mssql.php');
$cfg_mysql = load_cfg_safe('config/db_mysql.php');

$out = [];
foreach (($cfg_mssql['servers'] ?? []) as $key => $s) {
  $out[] = ['type'=>'mssql', 'key'=>(string)$key, 'title'=>$s['title'] ?? (string)$key];
}
foreach (($cfg_mysql['servers'] ?? []) as $key => $s) {
  $out[] = ['type'=>'mysql', 'key'=>(string)$key, 'title'=>$s['title'] ?? (string)$key];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'servers'=>$out]);
