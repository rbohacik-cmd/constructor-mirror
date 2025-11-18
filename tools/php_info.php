<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

/**
 * FTP Diagnostics (files + DB + schema)
 * - Path policy: prefer PROJECT_FS/BASE_URL from /partials/bootstrap.php
 */

$IS_CLI = (PHP_SAPI === 'cli');
parse_str($_SERVER['QUERY_STRING'] ?? '', $QS);

// Ensure bootstrap (defines PROJECT_FS/BASE_URL)
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../partials/bootstrap.php';
}

$wantJson   = isset($QS['format']) && strtolower((string)$QS['format']) === 'json';
$projectDir = PROJECT_FS;

// Core app helpers
require_once PROJECT_FS . '/appcfg.php';
require_once PROJECT_FS . '/db.php';

// ---------------- helpers ----------------
$results = [];
$hadFail = false;

function add_result(string $section, string $scope, string $status, string $msg): void {
  global $results, $hadFail;
  if ($status === 'FAIL') $hadFail = true;
  $results[] = compact('section','scope','status','msg');
}

/**
 * Try multiple search roots for a project-relative file path (e.g. '/ftp_runs.php')
 * Returns ['path'=>..., 'exists'=>bool, 'read'=>bool, 'root'=>string]
 */
function resolve_file(string $projectDir, string $rel, array $roots): array {
  // normalize
  $rel = '/' . ltrim($rel, '/');
  foreach ($roots as $root) {
    $root = rtrim($root, '/');
    $candidate = $projectDir . ($root === '' ? '' : $root) . $rel;
    $real = realpath($candidate) ?: $candidate;
    if (file_exists($real)) {
      return [
        'path'   => $real,
        'exists' => true,
        'read'   => is_readable($real),
        'root'   => $root === '' ? '/' : $root . '/',
      ];
    }
  }
  // not found in any root
  return [
    'path'   => $projectDir . $rel,
    'exists' => false,
    'read'   => false,
    'root'   => '',
  ];
}

// ---------------- expected schema ----------------
$expected = [
  'ftp_connections'   => ['id','name','protocol','host','port','username','password_enc','passive','root_path','created_at'],
  'ftp_jobs'          => ['id','connection_id','name','remote_path','filename_glob','is_recursive','max_size_mb','only_newer_than','target_pipeline','parser_profile','enabled','schedule_cron','created_at','updated_at'],
  'ftp_runs_log'      => ['id','job_id','started_at','finished_at','status','files_found','files_saved','message','created_at'],
  'ftp_file_manifest' => ['id','job_id','remote_dir','filename','size_bytes','downloaded_at','local_path','mtime_remote','hash_sha1','created_at'],
];

// ---------------- FILES ----------------
// Define *logical* files and where to look for each group (project-relative).
$searchRoots = [
  'ui'     => [''],                 // root
  'logic'  => [''],                 // root
  'js'     => ['/assets/js','/js'], // primary + fallback
  'lib'    => [''],                 // root (paths below include subfolders)
  'assets' => ['/assets'],          // /assets/...
];

// Expected project-relative paths (prefixed with '/')
$need = [
  'ui' => [
    '/ftp_connections.php',
    '/ftp_jobs_manager.php',
    '/ftp_runs.php',
    '/ftp_manifest.php',
  ],
  'logic' => [
    '/ftp_connections_logic.php',
    '/ftp_jobs_logic.php',
  ],
  'js' => [
    '/ftp_connections_controller.js',
    '/ftp_jobs_controller.js',
  ],
  'lib' => [
    '/appcfg.php',
    '/mysql.php',
    '/partials/crypto.php',
    '/lib/ftp_client.php',
  ],
  'assets' => [
    '/css/custom.css',
  ],
];

// Check files with multi-root resolution
foreach ($need as $group => $files) {
  $roots = $searchRoots[$group] ?? [''];
  foreach ($files as $rel) {
    $st = resolve_file($projectDir, $rel, $roots);
    if (!$st['exists']) {
      add_result('FILES', $group, 'FAIL', $rel . ' (missing)');
      continue;
    }
    if (!$st['read']) {
      add_result('FILES', $group, 'FAIL', $rel . ' (not readable)');
      continue;
    }
    // Show where it was found to avoid confusion
    $where = $st['root'] !== '' ? "found in {$st['root']}" : 'found in /';
    add_result('FILES', $group, 'OK', $rel . ' - ' . $where);
  }
}

// ---------------- DB PING ----------------
try {
  $pdo    = db();
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $verRow = $driver === 'mysql' ? qrow('SELECT VERSION() AS v') : null;
  $one    = qscalar('SELECT 1');
  add_result('DB PING','db','OK',"driver={$driver} version=" . ($verRow['v'] ?? '?') . " responded={$one}");
} catch (Throwable $e) {
  add_result('DB PING','db','FAIL','connection/query failed: ' . $e->getMessage());
}

// ---------------- DB SCHEMA ----------------
foreach ($expected as $table => $cols) {
  try {
    $rows = qall("SHOW COLUMNS FROM `$table`");
    if (!$rows) { add_result('DB SCHEMA',$table,'FAIL','table missing'); continue; }
    $have    = array_map(fn($r) => $r['Field'], $rows);
    $missing = array_values(array_diff($cols, $have));
    $extra   = array_values(array_diff($have, $cols));
    if ($missing) {
      add_result('DB SCHEMA',$table,'FAIL','missing: ' . implode(', ', $missing));
    } else {
      add_result('DB SCHEMA',$table,'OK','all expected columns present');
    }
    if ($extra) add_result('DB SCHEMA',$table,'WARN','extra: ' . implode(', ', $extra));
  } catch (Throwable $e) {
    add_result('DB SCHEMA',$table,'FAIL','inspect failed: ' . $e->getMessage());
  }
}

// ---------------- OUTPUT ----------------
if ($wantJson || $IS_CLI) {
  if (!$wantJson) header('Content-Type: application/json; charset=utf-8');
  echo json_encode(
    ['status' => $hadFail ? 'fail' : 'ok', 'results' => $results],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  if (!$IS_CLI) http_response_code($hadFail ? 500 : 200);
  exit;
}

// Pretty HTML
require_once PROJECT_FS . '/partials/header.php';
?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">FTP Diagnostics</h1>
    <div>
      <a href="?format=json" class="btn btn-outline-secondary btn-sm">JSON</a>
      <a href="" class="btn btn-primary btn-sm">Refresh</a>
    </div>
  </div>

  <table class="table table-dark table-striped align-middle">
    <thead>
      <tr>
        <th scope="col">Section</th>
        <th scope="col">Scope</th>
        <th scope="col">Status</th>
        <th scope="col">Message</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['section']) ?></td>
        <td><?= htmlspecialchars($r['scope']) ?></td>
        <td>
          <?php if ($r['status']==='OK'): ?>
            <span class="badge bg-success">OK</span>
          <?php elseif ($r['status']==='WARN'): ?>
            <span class="badge bg-warning text-dark">WARN</span>
          <?php else: ?>
            <span class="badge bg-danger">FAIL</span>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($r['msg']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
require_once PROJECT_FS . '/partials/footer.php';
if ($hadFail) { http_response_code(500); }
