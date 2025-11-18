<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

require_once __DIR__ . '/../appcfg.php';
require_once __DIR__ . '/../db.php';     // <- primary DB (BlueHost) via db()
require_once __DIR__ . '/../partials/crypto.php';
require_once __DIR__ . '/ftp_client.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function out_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function parse_boolish($val): int {
  // Accept 1/0, "1"/"0", "true"/"false", "on"/"off", "yes"/"no"
  if (is_bool($val)) return $val ? 1 : 0;
  $s = strtolower(trim((string)$val));
  return in_array($s, ['1','true','on','yes'], true) ? 1 : 0;
}

if ($action === 'list') {
  $rows = qall("
    SELECT id, name, protocol, host, port, username, passive, root_path, created_at
    FROM ftp_connections
    ORDER BY id DESC
  ");
  out_json(['ok'=>true, 'items'=>$rows]);
}

if ($action === 'get') {
  $id = (int)($_GET['id'] ?? 0);
  $row = qrow("SELECT * FROM ftp_connections WHERE id=?", [$id]);
  if (!$row) out_json(['ok'=>false, 'error'=>'Not found'], 404);
  unset($row['password_enc']); // never expose
  out_json(['ok'=>true, 'item'=>$row]);
}

if ($action === 'save') {
  $id        = (int)($_POST['id'] ?? 0);
  $name      = trim((string)($_POST['name'] ?? ''));
  $protocol  = strtoupper(trim((string)($_POST['protocol'] ?? 'SFTP')));
  $host      = trim((string)($_POST['host'] ?? ''));
  $port      = (int)($_POST['port'] ?? 0);
  $username  = trim((string)($_POST['username'] ?? ''));
  $password  = (string)($_POST['password'] ?? '');
  // **** FIXED: parse passive value, not presence ****
  $passive   = array_key_exists('passive', $_POST) ? parse_boolish($_POST['passive']) : 1; // default 1 if omitted
  $root_path = trim((string)($_POST['root_path'] ?? ''));

  if ($name === '' || $host === '' || $username === '' || !in_array($protocol, ['SFTP','FTPS','FTP'], true)) {
    out_json(['ok'=>false, 'error'=>'Invalid input'], 422);
  }
  if ($port <= 0) $port = ($protocol === 'SFTP') ? 22 : 21;

  $cols = [
    'name'      => $name,
    'protocol'  => $protocol,
    'host'      => $host,
    'port'      => $port,
    'username'  => $username,
    'passive'   => $passive,
    'root_path' => ($root_path !== '' ? $root_path : null),
  ];

  if ($id > 0) {
    if ($password !== '') $cols['password_enc'] = encrypt_pass($password);
    $sets = []; $args = [];
    foreach ($cols as $k=>$v) { $sets[] = "`$k`=?"; $args[] = $v; }
    $args[] = $id;
    qexec("UPDATE ftp_connections SET ".implode(',', $sets)." WHERE id=?", $args);
    out_json(['ok'=>true, 'id'=>$id, 'msg'=>'Updated']);
  } else {
    if ($password === '') out_json(['ok'=>false, 'error'=>'Password required for new connection'], 422);
    $cols['password_enc'] = encrypt_pass($password);
    $fields = array_keys($cols);
    $place  = rtrim(str_repeat('?,', count($fields)), ',');
    qexec("INSERT INTO ftp_connections (`".implode('`,`',$fields)."`) VALUES ($place)", array_values($cols));
    $newId = (int)qscalar("SELECT LAST_INSERT_ID()");
    out_json(['ok'=>true, 'id'=>$newId, 'msg'=>'Created']);
  }
}

if ($action === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  $row = qrow("SELECT id FROM ftp_connections WHERE id=?", [$id]);
  if (!$row) out_json(['ok'=>false, 'error'=>'Not found'], 404);
  qexec("DELETE FROM ftp_connections WHERE id=?", [$id]);
  out_json(['ok'=>true, 'msg'=>'Deleted']);
}

if ($action === 'test') {
  // Works with either: (A) id only, or (B) full form payload
  $id = (int)($_POST['id'] ?? 0);

  $payload = [
    'id'        => $id,
    'name'      => trim((string)($_POST['name'] ?? '')),
    'protocol'  => strtoupper(trim((string)($_POST['protocol'] ?? ''))),
    'host'      => trim((string)($_POST['host'] ?? '')),
    'port'      => (int)($_POST['port'] ?? 0),
    'username'  => trim((string)($_POST['username'] ?? '')),
    'password'  => (string)($_POST['password'] ?? ''), // plain if provided
    // **** FIXED: parse passive value here too ****
    'passive'   => array_key_exists('passive', $_POST) ? parse_boolish($_POST['passive']) : null,
    'root_path' => trim((string)($_POST['root_path'] ?? '')),
  ];

  if ($id > 0 && ($payload['host'] === '' || $payload['protocol'] === '' || $payload['username'] === '')) {
    $row = qrow("SELECT * FROM ftp_connections WHERE id=?", [$id]);
    if (!$row) out_json(['ok'=>false, 'error'=>'Connection not found'], 404);
    $payload['name']      = $row['name'];
    $payload['protocol']  = $row['protocol'];
    $payload['host']      = $row['host'];
    $payload['port']      = (int)$row['port'];
    $payload['username']  = $row['username'];
    // if passive not posted, use DB value; else keep user override
    if ($payload['passive'] === null) $payload['passive'] = (int)$row['passive'];
    $payload['root_path'] = (string)($row['root_path'] ?? '');
    if ($payload['password'] === '' && !empty($row['password_enc'])) {
      $payload['password'] = decrypt_pass($row['password_enc']);
    }
  }

  if (!in_array($payload['protocol'], ['SFTP','FTPS','FTP'], true) ||
      $payload['host'] === '' || $payload['username'] === '') {
    out_json(['ok'=>false, 'error'=>'Invalid or incomplete connection details'], 422);
  }
  if ($payload['password'] === '') {
    out_json(['ok'=>false, 'error'=>'Password not available'], 422);
  }
  if ($payload['port'] <= 0) $payload['port'] = ($payload['protocol'] === 'SFTP') ? 22 : 21;
  if ($payload['passive'] === null) $payload['passive'] = 1; // default passive if still unknown

  try {
    $listed = [];
    $root = $payload['root_path'] !== '' ? $payload['root_path'] : '/';

    ftp_download_iter(
      [
        'protocol' => $payload['protocol'],
        'host'     => $payload['host'],
        'port'     => $payload['port'],
        'user'     => $payload['username'],
        'pass'     => $payload['password'],
        'passive'  => ((int)$payload['passive']) === 1,
        'root'     => null,
      ],
      rtrim($root, '/'),
      '*',
      false,
      null,
      function($dir,$name,$size,$mtime,$stream) use (&$listed) {
        if (count($listed) < 8) {
          $listed[] = ['name'=>$name, 'size'=>(int)$size, 'mtime'=>$mtime];
        }
      }
    );

    out_json(['ok'=>true, 'msg'=>'Login OK', 'sample'=>$listed]);
  } catch (Throwable $e) {
    out_json(['ok'=>false, 'error'=>$e->getMessage()], 200);
  }
}

out_json(['ok'=>false, 'error'=>'Bad request'], 400);

