<?php
declare(strict_types=1);

// --- Bootstrap + includes (path policy) ---
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../../partials/bootstrap.php';
}
require_once PROJECT_FS . '/appcfg.php';
require_once PROJECT_FS . '/db.php';

// --- helpers ---
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// --- resolve server key (must be MySQL) ---
$dbKey = (string)($_GET['server'] ?? '');

$resolvedKey = null;
if ($dbKey !== '') {
  // ensure exists and is mysql
  if (!server_exists($dbKey)) {
    http_response_code(400);
    require_once PROJECT_FS . '/partials/header.php';
    echo '<div class="container py-3"><div class="alert alert-danger">Unknown server key: '.h($dbKey).'</div></div>';
    require_once PROJECT_FS . '/partials/footer.php';
    exit;
  }
  if (!is_mysql($dbKey)) {
    // try to fall back to first MySQL
    $fallback = first_mysql_key();
    if (!$fallback) {
      http_response_code(400);
      require_once PROJECT_FS . '/partials/header.php';
      echo '<div class="container py-3"><div class="alert alert-danger">Server "'.h($dbKey).'" is not MySQL and no MySQL server is configured.</div></div>';
      require_once PROJECT_FS . '/partials/footer.php';
      exit;
    }
    $resolvedKey = $fallback;
  } else {
    $resolvedKey = $dbKey;
  }
} else {
  // default: primary DB if it's MySQL; else first MySQL
  $primary = db_default_key();
  if (is_mysql($primary)) {
    $resolvedKey = $primary;
  } else {
    $fallback = first_mysql_key();
    if (!$fallback) {
      http_response_code(400);
      require_once PROJECT_FS . '/partials/header.php';
      echo '<div class="container py-3"><div class="alert alert-danger">No MySQL server available in config.</div></div>';
      require_once PROJECT_FS . '/partials/footer.php';
      exit;
    }
    $resolvedKey = $fallback;
  }
}

// ===== inputs & pagination =====
$qDb     = trim((string)($_GET['qdb']   ?? ''));   // search databases
$qTable  = trim((string)($_GET['q']     ?? ''));   // search tables
$table   = preg_replace('~[^a-zA-Z0-9_]+~','', (string)($_GET['table'] ?? ''));

// Tables pager
$tpage   = max(1, (int)($_GET['tpage'] ?? 1));
$tper    = min(200, max(5, (int)($_GET['tper'] ?? 25)));
$toffset = ($tpage - 1) * $tper;
// Rows pager
$rpage   = max(1, (int)($_GET['rpage'] ?? 1));
$rper    = min(200, max(5, (int)($_GET['rper'] ?? 25)));
$roffset = ($rpage - 1) * $rper;

// ===== databases =====
$databases = [];
try {
  $dbRows = qall("SHOW DATABASES", [], null, $resolvedKey);
  foreach ($dbRows as $x) {
    $vals = array_values($x); // take first value, key varies by driver
    if (isset($vals[0]) && $vals[0] !== '') $databases[] = (string)$vals[0];
  }
} catch (Throwable $e) {
  require_once PROJECT_FS . '/partials/header.php';
  echo '<div class="container py-3"><div class="alert alert-danger">Failed to list databases: '.h($e->getMessage()).'</div></div>';
  require_once PROJECT_FS . '/partials/footer.php';
  exit;
}

// ===== choose db =====
$selectedDb = (string)($_GET['db'] ?? '');
if ($selectedDb !== '' && in_array($selectedDb, $databases, true)) {
  try { qexec("USE `{$selectedDb}`", [], null, $resolvedKey); }
  catch (Throwable $e) { $selectedDb = ''; }
} else {
  $cur = qrow("SELECT DATABASE() AS db", [], null, $resolvedKey);
  $selectedDb = (string)($cur['db'] ?? '');
}

// filter DB list (search box)
$filteredDbs = $qDb === '' ? $databases : array_values(array_filter($databases, fn($d)=>stripos($d,$qDb)!==false));

// ===== tables (filter + pagination) =====
$allTables = [];
$totalTables = 0;
$tables = [];

if ($selectedDb !== '') {
  try {
    qexec("USE `{$selectedDb}`", [], null, $resolvedKey);
    $resTables = qall("SHOW TABLES", [], null, $resolvedKey);
    foreach ($resTables as $r) {
      $vals = array_values($r);
      if (isset($vals[0]) && $vals[0] !== '') $allTables[] = (string)$vals[0];
    }
  } catch (Throwable $e) {
    // leave $allTables empty
  }

  if ($qTable !== '') {
    $allTables = array_values(array_filter($allTables, fn($t)=>stripos($t,$qTable)!==false));
  }

  $totalTables = count($allTables);
  $tmax = max(1, (int)ceil($totalTables / max(1, $tper)));
  if ($tpage > $tmax) { $tpage = $tmax; $toffset = ($tpage - 1) * $tper; }

  $tables = array_slice($allTables, $toffset, $tper);
}

// ===== structure + rows =====
$cols = [];
$rowCount = 0;
$rows = [];

if ($selectedDb !== '' && $table !== '' && in_array($table, $allTables, true)) {
  try {
    qexec("USE `{$selectedDb}`", [], null, $resolvedKey);

    // structure
    $cols = qall("SHOW COLUMNS FROM `{$table}`", [], null, $resolvedKey);

    // count
    $cnt = qrow("SELECT COUNT(*) AS c FROM `{$table}`", [], null, $resolvedKey);
    $rowCount = (int)($cnt['c'] ?? 0);

    // clamp rpage to max
    $rmax = max(1, (int)ceil($rowCount / max(1, $rper)));
    if ($rpage > $rmax) { $rpage = $rmax; $roffset = ($rpage - 1) * $rper; }

    // data page (ALL columns)
    $pick = array_map(fn($c)=>$c['Field'], $cols);
    $colList = $pick ? ('`' . implode('`,`',$pick) . '`') : '*';

    // LIMIT/OFFSET bound as ints
    $rows = qall("SELECT {$colList} FROM `{$table}` LIMIT ? OFFSET ?", [ (int)$rper, (int)$roffset ], null, $resolvedKey);
  } catch (Throwable $e) {
    // keep $rows empty, $cols maybe filled
  }
}

/**
 * Compact paginator with ellipses, prev/next, and wrapping.
 */
function page_links(int $total, int $per, int $page, array $base, string $pageKey = 'page', int $window = 2): string {
  $pages = (int)ceil(($total ?: 0) / max(1, $per));
  if ($pages <= 1) return '';

  $mk = function(int $p) use ($base, $pageKey): string {
    $q = $base; $q[$pageKey] = $p;
    return '?' . http_build_query($q);
  };

  $want = [];
  $want[] = 1;
  if ($pages >= 2) $want[] = 2;
  for ($p = $page - $window; $p <= $page + $window; $p++) {
    if ($p >= 1 && $p <= $pages) $want[] = $p;
  }
  if ($pages - 1 >= 1) $want[] = $pages - 1;
  if ($pages >= 1)    $want[] = $pages;

  $want = array_values(array_unique($want));
  sort($want);

  $html = '<nav><ul class="pagination pagination-sm m-0" style="flex-wrap:wrap;gap:.25rem;">';

  // Prev
  $prevDisabled = $page <= 1;
  $html .= '<li class="page-item'.($prevDisabled?' disabled':'').'"><a class="page-link" href="' . ($prevDisabled ? '#' : h($mk($page-1))) . '">&laquo;</a></li>';

  // Pages + ellipses
  $prevShown = 0;
  foreach ($want as $p) {
    if ($prevShown && $p > $prevShown + 1) {
      $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
    }
    $active = $p === $page;
    $html .= '<li class="page-item'.($active?' active':'').'"><a class="page-link" href="'.h($mk($p)).'">'.$p.'</a></li>';
    $prevShown = $p;
  }

  // Next
  $nextDisabled = $page >= $pages;
  $html .= '<li class="page-item'.($nextDisabled?' disabled':'').'"><a class="page-link" href="' . ($nextDisabled ? '#' : h($mk($page+1))) . '">&raquo;</a></li>';

  $html .= '</ul></nav>';
  return $html;
}

require_once PROJECT_FS . '/partials/header.php';
?>
<!-- Cell clipping styles (self-contained) -->
<style>
  .cell-clip {
    display:inline-block;
    max-width: 28rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: bottom;
    cursor: pointer;
  }
  .cell-clip[data-long="1"] code::after {
    content: " ⋯";
    opacity: .6;
    font-size: .8em;
  }
  .cell-null { opacity:.7; font-style: italic; cursor: default; }
  .modal pre {
    max-height: 70vh;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
  }
</style>

<div class="container py-3">
  <h2 class="mb-3">MySQL Browser</h2>

  <div class="row g-3">
    <div class="col-12 col-lg-4">
      <div class="card p-3 mb-3">
        <form class="row g-2">
          <input type="hidden" name="server" value="<?= h($dbKey) ?>">
          <div class="col-12">
            <label class="form-label small">Search Databases</label>
            <input class="form-control form-control-sm" name="qdb" value="<?= h($qDb) ?>" placeholder="type to filter…">
          </div>
          <div class="col-12">
            <label class="form-label small">Database</label>
            <select class="form-select form-select-sm" name="db" onchange="this.form.submit()">
              <option value="">(current)</option>
              <?php foreach ($filteredDbs as $d): ?>
                <option value="<?= h($d) ?>" <?= $d===$selectedDb?'selected':'' ?>><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <button class="btn btn-sm btn-outline-info w-100">Apply</button>
          </div>
        </form>
      </div>

      <div class="card p-3 h-100">
        <!-- Table search resets TABLE pager only -->
        <form class="mb-2">
          <input type="hidden" name="server" value="<?= h($dbKey) ?>">
          <input type="hidden" name="db"     value="<?= h($selectedDb) ?>">
          <input type="hidden" name="tpage"  value="1">
          <input type="hidden" name="tper"   value="<?= (int)$tper ?>">
          <!-- keep current rows pager as-is -->
          <input type="hidden" name="rpage"  value="<?= (int)$rpage ?>">
          <input type="hidden" name="rper"   value="<?= (int)$rper ?>">
          <div class="input-group input-group-sm">
            <input class="form-control" name="q" value="<?= h($qTable) ?>" placeholder="Search tables…">
            <button class="btn btn-outline-info">Search</button>
          </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Tables</h6>
          <div class="text-secondary small"><?= $totalTables ?> total</div>
        </div>

        <?php if (empty($tables)): ?>
          <div class="text-secondary small">No tables</div>
        <?php else: ?>
          <div class="list-group small mb-2">
            <?php foreach ($tables as $t): ?>
              <a class="list-group-item list-group-item-action<?= $t===$table?' active':'' ?>"
                 href="?<?= h(http_build_query([
                   'server'=>$dbKey,'db'=>$selectedDb,'q'=>$qTable,
                   'table'=>$t,
                   // keep tables pager
                   'tper'=>$tper,'tpage'=>$tpage,
                   // reset rows pager for new table, keep rper
                   'rper'=>$rper,'rpage'=>1
                 ])) ?>">
                <?= h($t) ?>
              </a>
            <?php endforeach; ?>
          </div>

          <?= page_links(
                $totalTables, $tper, $tpage,
                [
                  'server'=>$dbKey,'db'=>$selectedDb,'q'=>$qTable,'table'=>$table,
                  'tper'=>$tper,
                  'rper'=>$rper,'rpage'=>$rpage
                ],
                'tpage'
              ) ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Structure<?= $table?': '.h($table):'' ?></h6>
          <?php if ($table): ?>
            <!-- Rows/page selector (affects rows pager only) -->
            <form class="d-flex align-items-center gap-2">
              <?php foreach ([
                'server'=>$dbKey,'db'=>$selectedDb,'q'=>$qTable,'table'=>$table,
                // preserve table pager
                'tper'=>$tper,'tpage'=>$tpage,
                // keep current rows page when changing size
                'rpage'=>$rpage
              ] as $k=>$v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>">
              <?php endforeach; ?>
              <label class="small text-secondary">Rows/page</label>
              <select class="form-select form-select-sm" name="rper" onchange="this.form.submit()">
                <?php foreach ([10,25,50,100,200] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $pp===$rper?'selected':'' ?>><?= $pp ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>
        <?php if (!$table): ?>
          <div class="text-secondary small mt-2">Select a table to see columns.</div>
        <?php else: ?>
          <div class="table-responsive mt-2">
            <table class="table table-sm table-dark table-striped align-middle">
              <thead><tr>
                <th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th>
              </tr></thead>
              <tbody>
              <?php foreach ($cols as $c): ?>
                <tr>
                  <td><?= h($c['Field']) ?></td>
                  <td><?= h($c['Type']) ?></td>
                  <td><?= h($c['Null']) ?></td>
                  <td><?= h($c['Key']) ?></td>
                  <td><code class="small"><?= h((string)($c['Default'])) ?></code></td>
                  <td><?= h($c['Extra']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Data<?= $table?': '.h($table):'' ?></h6>
          <?php if ($table): ?>
            <span class="badge bg-secondary"><?= $rowCount ?> rows</span>
          <?php endif; ?>
        </div>

        <?php if (!$table): ?>
          <div class="text-secondary small">Pick a table to preview rows.</div>
        <?php elseif (empty($rows)): ?>
          <div class="text-secondary small">No rows on this page.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-dark table-striped align-middle">
              <thead>
                <tr>
                  <?php foreach (array_keys($rows[0]) as $c): ?>
                    <th><?= h((string)$c) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <?php foreach ($r as $colName => $v):
                      $isNull = is_null($v);
                      $full   = $isNull ? 'NULL' : (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
                      $limit  = 120;
                      $len    = function_exists('mb_strlen') ? mb_strlen($full, 'UTF-8') : strlen($full);
                      if ($len > $limit) {
                        $short = function_exists('mb_substr') ? mb_substr($full, 0, $limit, 'UTF-8') . '…' : substr($full, 0, $limit) . '…';
                        $isLong = 1;
                      } else {
                        $short = $full;
                        $isLong = 0;
                      }
                    ?>
                      <td>
                        <?php if ($isNull): ?>
                          <span class="cell-clip cell-null" data-col="<?= h((string)$colName) ?>" data-full="NULL" data-long="0" title="NULL">
                            <code class="small">NULL</code>
                          </span>
                        <?php else: ?>
                          <span class="cell-clip" data-col="<?= h((string)$colName) ?>" data-full="<?= h($full) ?>" data-long="<?= $isLong ?>" title="Click to view full value">
                            <code class="small"><?= h($short) ?></code>
                          </span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?= page_links(
                $rowCount, $rper, $rpage,
                [
                  'server'=>$dbKey,'db'=>$selectedDb,'q'=>$qTable,'table'=>$table,
                  'tper'=>$tper,'tpage'=>$tpage,
                  'rper'=>$rper
                ],
                'rpage'
              ) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Cell preview modal -->
<div class="modal fade" id="cellModal" tabindex="-1" aria-labelledby="cellModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header">
        <h5 class="modal-title" id="cellModalLabel">Cell value</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="mb-0" id="cellModalBody"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="cellCopyBtn">Copy</button>
        <button type="button" class="btn btn-info btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php require_once PROJECT_FS . '/partials/footer.php'; ?>
