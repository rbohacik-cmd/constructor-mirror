<?php
declare(strict_types=1);

// DEBUG (remove later)
@ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mysql.php';
require_once __DIR__ . '/../debug_sentinel.php';
require_once __DIR__ . '/../partials/header.php';

$MSSQL_SERVER_KEY = 'ts';
$TABLE = 'S4_Agenda_PCB.dbo.Artikly_KategorieArtiklu';

$pdoMs = db_for($MSSQL_SERVER_KEY);
$sent  = new debug_sentinel('hc_params_by_category_guid');

try {
  $sql = "
    SELECT
      CAST(ID AS NVARCHAR(36))              AS ID,
      CAST(ParentObject_ID AS NVARCHAR(36)) AS ParentID,
      CAST(Nazev AS NVARCHAR(255))          AS Nazev
    FROM {$TABLE} WITH (NOLOCK)
    ORDER BY Nazev ASC
  ";
  $rows = qall($sql, [], null, $MSSQL_SERVER_KEY);
  $sent->info('loaded_categories', ['count' => count($rows)]);
} catch (Throwable $e) {
  $sent->error('mssql_query_failed', ['error' => $e->getMessage()]);
  $rows = [];
}

/** Load current mappings (MySQL, GUID-safe) */
$maps = qall("SELECT mssql_category_id, mssql_param_group_id FROM hc_category_param_group_guid");
$mapByCat = [];
foreach ($maps as $m) $mapByCat[(string)$m['mssql_category_id']] = (string)$m['mssql_param_group_id'];

/** Load display names for groups (MySQL) */
$metaRows = qall("SELECT mssql_param_group_id, display_name FROM hc_param_group_meta_guid");
$nameByGroup = [];
foreach ($metaRows as $mr) $nameByGroup[(string)$mr['mssql_param_group_id']] = (string)$mr['display_name'];

/** Build index + children map with guards */
$nodes = []; $kids = []; $selfParents = [];
foreach ($rows as $r) {
  $id   = trim((string)($r['ID'] ?? ''));
  $pid0 = trim((string)($r['ParentID'] ?? ''));
  $name = (string)($r['Nazev'] ?? '');

  $isNullish = ($pid0 === '' || $pid0 === '00000000-0000-0000-0000-000000000000' || strcasecmp($pid0, 'NULL') === 0);
  $pid = $isNullish ? '' : $pid0;

  $nodes[$id] = ['id'=>$id, 'pid'=>$pid, 'name'=>$name];

  if ($id === $pid && $id !== '') { // self-parent → treat as root
    $selfParents[] = $id;
    $kids[''][] = $id;
    continue;
  }
  if (!isset($kids[$pid])) $kids[$pid] = [];
  $kids[$pid][] = $id;
}

$roots = [];
foreach ($nodes as $id => $n) {
  $pid = $n['pid'];
  if ($pid === '' || !isset($nodes[$pid])) $roots[] = $id;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** render node (shows mapped Display Name if available; persists selection on reload) */
function render_node(string $id, array $nodes, array $kids, array $mapByCat, array $nameByGroup, array $path = []): string {
  static $renderedCount = 0;
  $MAX_NODES = 20000;

  $n = $nodes[$id] ?? null;
  if (!$n) return '';
  if (++$renderedCount > $MAX_NODES) return '<li class="text-warning">Render cap reached.</li>';

  $hasChildren = !empty($kids[$id]);
  $key = implode('-', array_merge($path, [$id]));
  $badge = '<span class="badge bg-secondary ms-2">'.h($n['id']).'</span>';

  // current mapping (GUID)
  $selGuid  = $mapByCat[$n['id']] ?? '';
  $selLabel = $selGuid ? ($nameByGroup[$selGuid] ?? $selGuid) : '';

  $toggle = $hasChildren
    ? '<button class="hc-toggle btn btn-sm btn-link px-1 py-0" data-target="node-'.$key.'" aria-label="toggle" title="Expand/Collapse">▸</button>'
    : '<span class="hc-spacer"></span>';

  // Provisional selected option (visible before JS loads full list)
  $selectedOpt = $selGuid
    ? '<option selected value="'.h($selGuid).'">'.h($selLabel).'</option>'
    : '';

  $dropdown = '<select class="form-select form-select-sm group-select" '
            .          'data-cat="'.h($n['id']).'" '
            .          'data-selected="'.h($selGuid).'" '
            .          'data-selected-label="'.h($selLabel).'" '
            .          'style="max-width:280px">'
            .   '<option value="">(select Group_ID)</option>'
            .   $selectedOpt
            . '</select>'
            . '<button class="btn btn-sm btn-primary ms-2 btn-save" data-cat="'.h($n['id']).'">Save</button>'
            . '<button class="btn btn-sm btn-outline-info ms-2 btn-validate" data-cat="'.h($n['id']).'">Validate</button>';

  $html  = '<li class="hc-node" data-text="'.h(mb_strtolower($n['name'])).'">';
  $html .=   '<div class="hc-row">';
  $html .=     $toggle.'<span class="hc-folder'.($hasChildren ? '' : ' hc-leaf').'">📁</span>';
  $html .=     '<span class="hc-name">'.h($n['name']).'</span>'.$badge;
  $html .=     '<div class="ms-auto d-flex align-items-center">'.$dropdown.'</div>';
  $html .=   '</div>';

  if ($hasChildren) {
    $html .= '<ul id="node-'.$key.'" class="hc-children collapse">';
    foreach ($kids[$id] as $cid) {
      $html .= render_node($cid, $nodes, $kids, $mapByCat, $nameByGroup, array_merge($path, [$id]));
    }
    $html .= '</ul>';
  }

  $html .= '</li>';
  return $html;
}

/** Tree HTML */
ob_start();
echo '<ul class="hc-tree list-unstyled">';
foreach ($roots as $rid) echo render_node($rid, $nodes, $kids, $mapByCat, $nameByGroup, []);
echo '</ul>';
$treeHtml = ob_get_clean();
?>
<div class="container py-3">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">S4 Categories (Hierarchy)</h1>
    <span class="ms-3 badge bg-info">Total: <?= count($rows) ?></span>
  </div>

  <?php if (!empty($selfParents)): ?>
    <div class="alert alert-danger">Self-parented categories: <?= h(implode(', ', array_slice($selfParents, 0, 10))) ?><?= count($selfParents)>10? ' …':'' ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="row g-2 align-items-center mb-3">
        <div class="col-md-6">
          <input id="hc-search" type="search" class="form-control" placeholder="Filter categories by name…">
        </div>
        <div class="col-md-6 text-md-end">
          <button id="btn-expand-all" class="btn btn-outline-primary btn-sm me-2">Expand all</button>
          <button id="btn-collapse-all" class="btn btn-outline-secondary btn-sm">Collapse all</button>
        </div>
      </div>

      <div id="hc-tree-wrap" class="hc-wrap">
        <?= $treeHtml ?: '<div class="alert alert-warning mb-0">No categories loaded.</div>' ?>
      </div>

      <div class="mt-3">
        <div id="results" class="small"></div>
      </div>
    </div>
  </div>
</div>

<style>
.hc-wrap { font-size: 0.95rem; }
.hc-row { display:flex; align-items:center; gap:.25rem; line-height:1.6; padding: .1rem .25rem; }
.hc-row:hover { background: rgba(0,0,0,.03); border-radius: .25rem; }
.hc-toggle { text-decoration:none; font-size:.9rem; }
.hc-toggle:focus { box-shadow:none; }
.hc-spacer { display:inline-block; width:1.65rem; }
.hc-folder { width:1.3rem; text-align:center; opacity:.9; }
.hc-folder.hc-leaf { opacity:.4; }
.hc-name { font-weight:500; }
.hc-children { margin-left: 1.5rem; padding-left: .5rem; border-left: 1px dashed rgba(0,0,0,.15); }
</style>

<script src="/health_check/health_check_parameters_by_category_controller.js"></script>
<?php require_once __DIR__ . '/../partials/footer.php';
