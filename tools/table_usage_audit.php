<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

/**
 * Table Usage Audit
 * - Scans PHP/SQL for table mentions
 * - Compares against live DB (MySQL or SQL Server)
 * - Uses unified bootstrap (PROJECT_FS/BASE_URL)
 */

$IS_CLI = (PHP_SAPI === 'cli');
parse_str($_SERVER['QUERY_STRING'] ?? '', $QS);

// Ensure bootstrap (defines PROJECT_FS/BASE_URL)
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../partials/bootstrap.php';
}

$wantJson     = isset($QS['format']) && strtolower((string)$QS['format']) === 'json';
$projectDir   = PROJECT_FS;                 // project root
$scanRoots    = [$projectDir];              // you can narrow if you want
$excludeDirs  = ['vendor','node_modules','.git','assets/cache','storage','logs'];
$globs        = ['php','sql'];              // file types to scan
$maxFileSize  = 2_000_000;                  // 2MB guard

require_once PROJECT_FS . '/appcfg.php';
require_once PROJECT_FS . '/db.php';

/* -------------------- helpers -------------------- */
function iter_files(array $roots, array $exts, array $exclude, int $maxBytes): iterable {
  $extMap = array_flip(array_map('strtolower', $exts));
  foreach ($roots as $root) {
    $rii = new RecursiveIteratorIterator(
      new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function (SplFileInfo $f) use ($exclude) {
          if ($f->isDir()) return !in_array($f->getBasename(), $exclude, true);
          return true;
        }
      ),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($rii as $fi) {
      /** @var SplFileInfo $fi */
      if (!$fi->isFile()) continue;
      $ext = strtolower($fi->getExtension());
      if (!isset($extMap[$ext])) continue;
      if ($fi->getSize() > $maxBytes) continue;
      yield $fi->getPathname();
    }
  }
}

function utf8_file_get_contents(string $path): string {
  $raw = @file_get_contents($path);
  if ($raw === false) return '';
  try {
    $enc = mb_detect_encoding($raw, ['UTF-8','ASCII','ISO-8859-1','Windows-1252'], true);
  } catch (Throwable $e) {
    $enc = 'UTF-8';
  }
  return ($enc && strtoupper($enc) !== 'UTF-8')
    ? mb_convert_encoding($raw, 'UTF-8', $enc)
    : $raw;
}

function lineno_from_offset(string $text, int $offset): int {
  return substr_count($text, "\n", 0, $offset) + 1;
}

/** Normalize table identifier → bare table name (strip schema + quotes/backticks/brackets). */
function norm_table(string $t): string {
  $t = trim($t);
  // remove quotes/backticks/brackets
  $t = preg_replace('/[`"\[\]]/', '', $t);
  // collapse spaces
  $t = preg_replace('/\s+/', '', $t);
  // drop schema prefix if present (db.table or schema.table)
  if (strpos($t, '.') !== false) {
    $t = substr($t, strrpos($t, '.') + 1);
  }
  return $t;
}

/* --- classify patterns so we know how risky the ref is --- */
/** Accept backtick/quote/bracket or plain names (optionally schema-qualified). */
$nameRe = '(?:[`"][^`"]+[`"]|\[[^\]]+\]|[a-zA-Z0-9_\.]+)';

$patternDefs = [
  ['kind'=>'SELECT', 're'=>"(?i)\\bFROM\\s+($nameRe)"],
  ['kind'=>'JOIN',   're'=>"(?i)\\bJOIN\\s+($nameRe)"],
  ['kind'=>'INSERT', 're'=>"(?i)\\bINSERT\\s+INTO\\s+($nameRe)"],
  ['kind'=>'REPLACE','re'=>"(?i)\\bREPLACE\\s+INTO\\s+($nameRe)"],
  ['kind'=>'UPDATE', 're'=>"(?i)\\bUPDATE\\s+($nameRe)"],
  ['kind'=>'DELETE', 're'=>"(?i)\\bDELETE\\s+FROM\\s+($nameRe)"],
  ['kind'=>'CREATE', 're'=>"(?i)\\bCREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?($nameRe)"],
  ['kind'=>'ALTER',  're'=>"(?i)\\bALTER\\s+TABLE\\s+($nameRe)"],
];

/** Identify SQL strings inside PHP code where queries live. */
function extract_sql_segments(string $code): array {
  $segments = [];
  $regexes = [
    // qall("..."), qrow('...'), qexec("..."), qscalar('...')
    '~\bq(?:all|row|exec|scalar)\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1~si',
    // PDO ->query("..."), ->prepare('...'), ->exec("...")
    '~->\s*(?:query|prepare|exec)\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1~si',
    // mysqli_query($conn, "...")
    '~\bmysqli_query\s*\([^,]+,\s*([\'"])((?:\\\\.|(?!\1).)*)\1~si',
  ];
  foreach ($regexes as $re) {
    if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) continue;
    foreach ($m[2] as $cap) {
      $segments[] = ['content'=>$cap[0], 'start'=>$cap[1]];
    }
  }
  return $segments;
}

/** Strip SQL comments from a SQL chunk. */
function strip_sql_comments(string $sql): string {
  return preg_replace(
    ['~/\*.*?\*/~s','~--[^\n]*~','~#[^\n]*~'],
    [' ',' ',' '],
    $sql
  );
}

/* -------------------- scan files -------------------- */
$occurs = [];      // table => [ ['file'=>, 'line'=>, 'snippet'=>, 'kind'=>], ...]
$counts = [];      // table => n
$tableKinds = [];  // table => set of kinds encountered

foreach (iter_files($scanRoots, $globs, $excludeDirs, $maxFileSize) as $path) {
  $code = utf8_file_get_contents($path);
  if ($code === '') continue;

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

  $chunks = [];
  if ($ext === 'sql') {
    $chunks[] = ['content'=>$code, 'start'=>0];
  } else {
    $chunks = extract_sql_segments($code);
  }
  if (!$chunks) continue;

  foreach ($chunks as $seg) {
    $sql = strip_sql_comments($seg['content']);
    foreach ($patternDefs as $pd) {
      if (!preg_match_all('~'.$pd['re'].'~', $sql, $m, PREG_OFFSET_CAPTURE)) continue;
      foreach ($m[1] as [$raw, $offInSeg]) {
        $tbl = norm_table($raw);

        // noise filters (information_schema/system)
        static $deny = [
          'tables','columns','statistics','key_column_usage','views','procedures',
          'schemata','events','triggers','partitions','databases','database_files'
        ];
        if ($tbl === '' || preg_match('~^\d+$~', $tbl)) continue;
        if (!preg_match('~^[A-Za-z0-9_]+$~', $tbl)) continue;
        if (in_array(strtolower($tbl), $deny, true)) continue;

        $fileOffset = $seg['start'] + (int)$offInSeg;
        $line       = lineno_from_offset($code, $fileOffset);
        $snippet    = trim(substr($code, max(0, $fileOffset-60), 120));

        $occurs[$tbl][] = ['file'=>$path, 'line'=>$line, 'snippet'=>$snippet, 'kind'=>$pd['kind']];
        $counts[$tbl]   = ($counts[$tbl] ?? 0) + 1;
        $tableKinds[$tbl][$pd['kind']] = true;
      }
    }
  }
}

/* -------------------- DB inventory -------------------- */
$dbInfo = ['present'=>[], 'missing'=>[], 'unused'=>[], 'driver'=>null, 'db'=>null];

try {
  $pdo    = db();
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  $dbInfo['driver'] = $driver;

  if ($driver === 'mysql') {
    $dbName = qscalar('SELECT DATABASE()');
    $dbInfo['db'] = $dbName;

    $rows = qall("
      SELECT TABLE_NAME, ENGINE, TABLE_ROWS, UPDATE_TIME
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = ?
      ORDER BY TABLE_NAME ASC
    ", [$dbName]);

    $dbTables = [];
    foreach ($rows as $r0) {
      $r = array_change_key_case($r0, CASE_LOWER);
      $name = (string)($r['table_name'] ?? '');
      if ($name === '') continue;
      $dbTables[$name] = [
        'engine'     => $r['engine']      ?? null,
        'rows'       => $r['table_rows']  ?? null,
        'updateTime' => $r['update_time'] ?? null,
      ];
    }

  } elseif ($driver === 'sqlsrv') {
    $dbName = qscalar('SELECT DB_NAME()');
    $dbInfo['db'] = $dbName;

    // Basic inventory (row counts omitted by default — expensive in SQL Server)
    $rows = qall("
      SELECT TABLE_NAME
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_TYPE='BASE TABLE' AND TABLE_CATALOG = DB_NAME()
      ORDER BY TABLE_NAME
    ");

    $dbTables = [];
    foreach ($rows as $r0) {
      $r = array_change_key_case($r0, CASE_LOWER);
      $name = (string)($r['table_name'] ?? '');
      if ($name === '') continue;
      $dbTables[$name] = [
        'engine'     => null,
        'rows'       => null,
        'updateTime' => null,
      ];
    }

  } else {
    throw new RuntimeException("Unsupported driver: {$driver}");
  }

  foreach ($counts as $t => $n) {
    if (isset($dbTables[$t])) $dbInfo['present'][$t] = $dbTables[$t] + ['refs'=>$n];
    else                      $dbInfo['missing'][$t] = ['refs'=>$n];
  }
  foreach ($dbTables as $t => $meta) {
    if (!isset($counts[$t])) $dbInfo['unused'][$t] = $meta;
  }

} catch (Throwable $e) {
  $dbInfo['error'] = $e->getMessage();
}

/* -------------------- severity per table -------------------- */
$tableSeverity = [];
foreach (array_keys($occurs) as $t) {
  if (isset($dbInfo['missing'][$t])) {
    $tableSeverity[$t] = 'CRITICAL';
    continue;
  }
  $kinds = array_keys($tableKinds[$t] ?? []);
  $hasWrite = (bool)array_intersect($kinds, ['INSERT','REPLACE','UPDATE','DELETE','ALTER','CREATE']);
  $tableSeverity[$t] = $hasWrite ? 'WRITE' : 'INFO';
}

/* -------------------- OUTPUT -------------------- */
if ($wantJson) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'status'      => 'ok',
    'driver'      => $dbInfo['driver'],
    'database'    => $dbInfo['db'],
    'present'     => $dbInfo['present'],
    'missing'     => $dbInfo['missing'],
    'unused'      => $dbInfo['unused'],
    'occurs'      => $occurs,
    'severity'    => $tableSeverity,
    'error'       => $dbInfo['error'] ?? null,
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  exit;
}

/* -------------------- HTML -------------------- */
require_once PROJECT_FS . '/partials/header.php';
?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">Table Usage Audit</h1>
    <div class="d-flex gap-2">
      <a href="?format=json" class="btn btn-outline-secondary btn-sm">JSON</a>
      <button id="toggle-unused" class="btn btn-outline-light btn-sm" type="button" data-state="show">Hide unused</button>
      <a href="" class="btn btn-primary btn-sm">Refresh</a>
    </div>
  </div>

  <?php if (!empty($dbInfo['driver']) || !empty($dbInfo['db'])): ?>
    <p class="text-secondary small">
      Driver: <code><?= htmlspecialchars((string)$dbInfo['driver']) ?></code>
      <?php if (!empty($dbInfo['db'])): ?> • Database: <code><?= htmlspecialchars((string)$dbInfo['db']) ?></code><?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($dbInfo['error'])): ?>
    <div class="alert alert-danger">DB check failed: <?= htmlspecialchars($dbInfo['error']) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card bg-dark border-secondary">
        <div class="card-header">Referenced & Present (Active)</div>
        <div class="card-body p-0">
          <table class="table table-dark table-striped table-sm mb-0">
            <thead><tr>
              <th>Table</th><th class="text-end">Refs</th><th>Engine</th><th class="text-end">Rows</th><th>Updated</th>
            </tr></thead>
            <tbody>
              <?php if ($dbInfo['present']): ?>
                <?php foreach ($dbInfo['present'] as $t => $meta): ?>
                  <tr>
                    <td><a href="#t-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></a></td>
                    <td class="text-end"><?= number_format((int)($meta['refs'] ?? 0)) ?></td>
                    <td><?= htmlspecialchars((string)($meta['engine'] ?? '')) ?></td>
                    <td class="text-end"><?= is_null($meta['rows']) ? '—' : number_format((int)$meta['rows']) ?></td>
                    <td><?= htmlspecialchars((string)($meta['updateTime'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="5" class="text-muted">— none —</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card bg-dark border-secondary">
        <div class="card-header">Referenced in Code but Missing in DB</div>
        <div class="card-body p-0">
          <table class="table table-dark table-striped table-sm mb-0">
            <thead><tr><th>Table</th><th class="text-end">Refs</th></tr></thead>
            <tbody>
              <?php if ($dbInfo['missing']): ?>
                <?php foreach ($dbInfo['missing'] as $t => $meta): ?>
                  <tr>
                    <td><a href="#t-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></a></td>
                    <td class="text-end"><?= number_format((int)($meta['refs'] ?? 0)) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="2" class="text-muted">— none —</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="unused-card" class="card bg-dark border-secondary mt-3">
        <div class="card-header">Present in DB but Unused in Code</div>
        <div class="card-body p-0">
          <table class="table table-dark table-striped table-sm mb-0">
            <thead><tr><th>Table</th><th>Engine</th><th class="text-end">Rows</th><th>Updated</th></tr></thead>
            <tbody>
              <?php if ($dbInfo['unused']): ?>
                <?php foreach ($dbInfo['unused'] as $t => $meta): ?>
                  <tr>
                    <td><?= htmlspecialchars($t) ?></td>
                    <td><?= htmlspecialchars((string)($meta['engine'] ?? '')) ?></td>
                    <td class="text-end"><?= is_null($meta['rows']) ? '—' : number_format((int)$meta['rows']) ?></td>
                    <td><?= htmlspecialchars((string)($meta['updateTime'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="text-muted">— none —</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>

  <div class="card bg-dark border-secondary mt-4">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span>Occurrences (by table)</span>
      <input id="ocsearch" class="form-control form-control-sm w-auto" placeholder="Filter…">
    </div>
    <div class="card-body">
      <?php
      if (!$occurs) {
        echo '<div class="text-muted">— none —</div>';
      } else {
        ksort($occurs, SORT_NATURAL);
        foreach ($occurs as $t => $list):
          $sev = $tableSeverity[$t] ?? 'INFO';
          $badgeClass = ($sev === 'CRITICAL') ? 'bg-danger' : (($sev === 'WRITE') ? 'bg-warning text-dark' : 'bg-secondary');
      ?>
        <div id="t-<?= htmlspecialchars($t) ?>" class="mb-4 oc-block" data-name="<?= htmlspecialchars($t) ?>">
          <h5 class="mb-2 d-flex align-items-center gap-2">
            <span><?= htmlspecialchars($t) ?></span>
            <span class="badge <?= $badgeClass ?>"><?= $sev ?></span>
            <small class="text-muted ms-2">(<?= count($list) ?>)</small>
          </h5>
          <div class="table-responsive">
            <table class="table table-dark table-striped table-sm">
              <thead><tr><th>File</th><th class="text-end">Line</th><th>Snippet</th></tr></thead>
              <tbody>
                <?php foreach ($list as $o): ?>
                  <?php
                    $k = strtoupper($o['kind'] ?? 'SELECT');
                    $kindClass = in_array($k, ['INSERT','REPLACE','UPDATE','DELETE','ALTER','CREATE'], true)
                      ? 'badge bg-warning text-dark'
                      : 'badge bg-secondary';
                  ?>
                  <tr>
                    <td><code><?= htmlspecialchars(str_replace($projectDir,'',$o['file'])) ?></code></td>
                    <td class="text-end"><?= (int)$o['line'] ?></td>
                    <td>
                      <span class="<?= $kindClass ?> me-2"><?= htmlspecialchars($k) ?></span>
                      <code><?= htmlspecialchars($o['snippet']) ?></code>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php
        endforeach;
      }
      ?>
    </div>
  </div>
</div>

<script>
  // Toggle unused tables card
  (function(){
    const btn  = document.getElementById('toggle-unused');
    const card = document.getElementById('unused-card');
    if (!btn || !card) return;
    btn.addEventListener('click', function(){
      const hidden = card.style.display === 'none';
      card.style.display = hidden ? '' : 'none';
      this.dataset.state = hidden ? 'show' : 'hide';
      this.textContent = hidden ? 'Hide unused' : 'Show unused';
    });
  })();

  // Client-side filter for occurrences
  (function(){
    const inp = document.getElementById('ocsearch');
    if (!inp) return;
    inp.addEventListener('input', function(){
      const q = (this.value || '').toLowerCase();
      document.querySelectorAll('.oc-block').forEach(div => {
        const name = (div.dataset.name || '').toLowerCase();
        div.style.display = name.includes(q) ? '' : 'none';
      });
    });
  })();
</script>
<?php
require_once PROJECT_FS . '/partials/footer.php';
