<?php
// /local_constructor/ftp_file_selector_logic.php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');
header('Content-Type: application/json; charset=utf-8');

// --- Use the SAME selection logic as your working pages ---
require_once __DIR__ . '/../appcfg.php';

// Optional override: /ftp_file_selector_logic.php?action=list&dbkey=blue
$dbkey = isset($_GET['dbkey']) ? (string)$_GET['dbkey'] : null;

$prefer = $dbkey ?: 'blue';
if (function_exists('server_exists') && function_exists('is_mysql')) {
  if ($prefer && server_exists($prefer) && is_mysql($prefer)) {
    current_server_key($prefer);
  } else {
    $auto = function_exists('first_mysql_key') ? first_mysql_key() : null;
    if ($auto) current_server_key($auto);
  }
} else {
  current_server_key($prefer);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mysql.php';

// --- tiny helpers ---
function jfail(string $msg, array $extra = []): void { echo json_encode(['ok'=>false,'error'=>$msg] + $extra); exit; }
function jok(array $data = []): void { echo json_encode(['ok'=>true] + $data); exit; }
function post_json(): array {
  $raw = file_get_contents('php://input') ?: '{}';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function sanitize_path(string $p): string {
  $p = str_replace('\\','/',$p);
  $p = preg_replace('~//+~','/',$p);
  $p = preg_replace('~/\.(?:/|$)~','/',$p);
  while (preg_match('~/(?:[^/]+?)/\.\.(?:/|$)~', $p)) $p = preg_replace('~/(?:[^/]+?)/\.\.(?:/|$)~','/',$p);
  if ($p === '' || $p[0] !== '/') $p = '/'.$p;
  return $p;
}
function join_remote_path(string $root, string $path): string {
  $root = trim($root) === '' ? '/' : $root;
  $root = sanitize_path($root);
  $path = sanitize_path($path);
  $full = rtrim($root,'/').'/'.ltrim($path,'/');
  if ($full === '//') $full = '/';
  if (substr($path,-1) === '/' && substr($full,-1) !== '/') $full .= '/';
  return $full;
}

// --- DB sanity (fail early with useful info) ---
try {
  $pdo = db();
  $pdo->query("SELECT 1"); // ping
} catch (\Throwable $e) {
  $cfg = function_exists('appcfg') ? appcfg() : [];
  $key = function_exists('current_server_key') ? (string)current_server_key() : '(none)';
  $cur = $cfg['servers'][$key]['mysqli'] ?? [];
  jfail('DB connect failed', [
    'server_key' => $key,
    'host' => $cur['host'] ?? '(n/a)',
    'user' => $cur['user'] ?? '(n/a)',
    'detail' => $e->getMessage(),
  ]);
}

// --- load connection row ---
function load_server(PDO $pdo, int $id): array {
  $row = qrow("SELECT * FROM ftp_connections WHERE id = ?", [$id]);
  if (!$row) throw new RuntimeException("Server not found");
  $row['protocol']  = strtolower((string)($row['protocol'] ?? 'ftp')); // ftp|ftps|sftp
  $row['host']      = (string)($row['host'] ?? '');
  $row['port']      = (int)($row['port'] ?? 0);
  $row['username']  = (string)($row['username'] ?? '');
  $row['password']  = (string)($row['password'] ?? '');
  $row['passive']   = (int)($row['passive'] ?? 1); // 1=yes, 0=no
  $row['root_path'] = (string)($row['root_path'] ?? '/');
  if ($row['port'] <= 0) $row['port'] = ($row['protocol'] === 'sftp') ? 22 : 21;
  if ($row['root_path'] === '') $row['root_path'] = '/';
  return $row;
}

// --- FTP/FTPS list (with proper active/passive) ---
function ftp_list_dir(array $srv, string $path, array &$diag): array {
  $proto = ($srv['protocol'] === 'ftps') ? 'ftps' : 'ftp';
  $url   = sprintf('%s://%s:%s@%s:%d%s', $proto, rawurlencode($srv['username']), rawurlencode($srv['password']), $srv['host'], (int)$srv['port'], $path);
  $base = [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_DIRLISTONLY => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_USERPWD => $srv['username'].':'.$srv['password'],
  ];
  if (!empty($srv['passive'])) {
    $base[CURLOPT_FTP_USE_EPSV] = true;         // passive
  } else {
    $base[CURLOPT_FTP_USE_EPSV] = false;        // no EPSV
    $base[CURLOPT_FTPPORT]      = '-';          // force ACTIVE (PORT)
  }
  if ($proto === 'ftps') { $base[CURLOPT_FTP_SSL] = CURLFTPSSL_ALL; $base[CURLOPT_FTPSSLAUTH] = CURLFTPAUTH_TLS; }

  // MLSD
  $ch = curl_init(); $opts = $base; $opts[CURLOPT_CUSTOMREQUEST] = 'MLSD'; curl_setopt_array($ch, $opts);
  $out = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
  $entries = [];
  if ($out && !$err) {
    foreach (preg_split('~\r?\n~', trim((string)$out)) as $line) {
      if ($line==='') continue;
      [$facts,$name] = array_pad(explode(' ', $line, 2), 2, '');
      if ($name==='' || $name==='.' || $name==='..') continue;
      $map=[]; foreach (explode(';', rtrim($facts,';')) as $f){ if (strpos($f,'=')!==false){[$k,$v]=explode('=',$f,2); $map[strtolower($k)]=$v;}}
      $type = strtolower((string)($map['type'] ?? 'file'));
      $entries[] = ['name'=>$name, 'type'=>($type==='dir' || $type==='cdir' || $type==='pdir')?'dir':'file', 'size'=>isset($map['size'])?(int)$map['size']:null,
                    'mtime'=>isset($map['modify'])?substr($map['modify'],0,4).'-'.substr($map['modify'],4,2).'-'.substr($map['modify'],6,2).' '.substr($map['modify'],8,2).':'.substr($map['modify'],10,2):null];
    }
    return $entries;
  }
  // LIST
  $ch = curl_init(); $opts = $base; unset($opts[CURLOPT_CUSTOMREQUEST]); curl_setopt_array($ch, $opts);
  $out2 = curl_exec($ch); $err2 = curl_error($ch); curl_close($ch);
  if ($err2) { $diag['curl_error'] = $err2; return []; }
  foreach (preg_split('~\r?\n~', trim((string)$out2)) as $line) {
    if (!preg_match('~^(?P<mode>[dl\-])[rwx\-]{9}\s+\d+\s+\S+\s+\S+\s+(?P<size>\d+)\s+(?P<mon>\w{3})\s+(?P<day>\d{1,2})\s+(?P<timeyear>[\d:]{4,5})\s+(?P<name>.+)$~', $line, $m)) continue;
    $isDir = $m['mode']==='d'; $name=trim($m['name']); if ($name==='.'||$name==='..') continue;
    $entries[] = ['name'=>$name,'type'=>$isDir?'dir':'file','size'=>$isDir?null:(int)$m['size'],'mtime'=>$m['mon'].' '.$m['day'].' '.$m['timeyear']];
  }
  return $entries;
}

// --- SFTP (only if your cURL build supports it) ---
function sftp_list_dir(array $srv, string $path, array &$diag): array {
  $cv = curl_version(); $protocols = $cv['protocols'] ?? [];
  if (!in_array('sftp', $protocols, true)) { $diag['curl_error'] = 'cURL build has no sftp support'; return []; }
  $url = sprintf('sftp://%s:%d%s', $srv['host'], (int)$srv['port'], $path);
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_DIRLISTONLY => true,
    CURLOPT_USERNAME => $srv['username'],
    CURLOPT_PASSWORD => $srv['password'],
  ]);
  $out = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
  if ($err) { $diag['curl_error'] = $err; return []; }
  $entries = [];
  foreach (preg_split('~\r?\n~', trim((string)$out)) as $name) {
    $name = trim($name); if ($name===''||$name==='.'||$name==='..') continue;
    $entries[] = ['name'=>$name, 'type'=>'file', 'size'=>null, 'mtime'=>null];
  }
  return $entries;
}

// --- Router ---
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = post_json();

try {
  if ($action === 'list') {
    $serverId = (int)($input['server_id'] ?? 0);
    $pathIn   = (string)($input['path'] ?? '/');
    if ($serverId <= 0) jfail('Missing server_id');

    $srv = load_server($pdo, $serverId);
    $path = join_remote_path($srv['root_path'], $pathIn === '' ? '/' : $pathIn);

    $diag = ['protocol'=>$srv['protocol'], 'host'=>$srv['host'], 'port'=>$srv['port'], 'passive'=>(int)$srv['passive'], 'path'=>$path];

    $entries = ($srv['protocol']==='sftp')
      ? sftp_list_dir($srv, $path, $diag)
      : ftp_list_dir($srv, $path, $diag);

    if (empty($entries) && !empty($diag['curl_error'])) jfail('Failed to list (transport)', ['detail'=>$diag]);

    usort($entries, function($a,$b){
      if ($a['type'] !== $b['type']) return $a['type']==='dir' ? -1 : 1;
      return strnatcasecmp($a['name'], $b['name']);
    });

    jok(['entries'=>$entries, 'message'=>'OK']);
  }

  jfail('Unknown action');
} catch (\Throwable $e) {
  jfail('Server error: '.$e->getMessage());
}

