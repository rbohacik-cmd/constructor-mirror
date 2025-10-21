<?php
declare(strict_types=1);

/**
 * Health Check â€” Parameters by Category (Hierarchy Browser)
 * Location: /health_check/Health_check_parameters_by_category.php
 * - Reads MSSQL S4_Agenda_PCB.dbo.Artikly_KategorieArtiklu
 * - Displays Nazev with a small badge for ID
 * - Folder-like expandable tree, search filter, expand/collapse all
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mysql.php'; // safe to include; not strictly required here
require_once __DIR__ . '/../debug_sentinel.php';
require_once __DIR__ . '/../partials/header.php';

$MSSQL_SERVER_KEY = 'ts'; // from appcfg.php
$TABLE = 'S4_Agenda_PCB.dbo.Artikly_KategorieArtiklu';

$pdoMs = db_for($MSSQL_SERVER_KEY);
$sent  = new debug_sentinel('hc_params_by_category');

try {
  // Pull minimal fields; NOLOCK to avoid blocking (ok for readonly tree)
  $sql = "
    SELECT
      CAST(ID AS NVARCHAR(36))              AS ID,
      CAST(ParentObject_ID AS NVARCHAR(36)) AS ParentID,
      CAST(Nazev AS NVARCHAR(255))          AS Nazev
    FROM {$TABLE} WITH (NOLOCK)
    ORDER BY Nazev ASC
  ";
  $stmt = $pdoMs->query($sql);
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  $sent->info('loaded_categories', ['count' => count($rows)]);
} catch (Throwable $e) {
  $sent->error('mssql_query_failed', ['error' => $e->getMessage()]);
  $rows = [];
}

/** Build an index and a children map */
$nodes = [];
$kids  = [];
foreach ($rows as $r) {
  $id   = trim((string)($r['ID'] ?? ''));
  $pid  = trim((string)($r['ParentID'] ?? ''));
  $name = (string)($r['Nazev'] ?? '');

  // Normalize "no parent" GUIDs
  $isNullish = ($pid === '' || $pid === '00000000-0000-0000-0000-000000000000' || strcasecmp($pid, 'NULL') === 0);
  $pid = $isNullish ? '' : $pid;

  $nodes[$id] = ['id'=>$id, 'pid'=>$pid, 'name'=>$name];
  if (!isset($kids[$pid])) $kids[$pid] = [];
  $kids[$pid][] = $id;
}

/** Detect roots (ParentObject_ID empty or missing parent) */
$roots = [];
foreach ($nodes as $id => $n) {
  $pid = $n['pid'];
  if ($pid === '' || !isset($nodes[$pid])) {
    $roots[] = $id;
  }
}

/** Render helpers */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Render a node and its children as nested <ul>
 * $path accumulates ancestor IDs to form unique collapse keys
 */
function render_node(string $id, array $nodes, array $kids, array $path = []): string {
  $n = $nodes[$id] ?? null;
  if (!$n) return '';
  $hasChildren = !empty($kids[$id]);
  $key = implode('-', array_merge($path, [$id])); // unique-ish key for collapse
  $badge = '<span class="badge bg-secondary ms-2">' . h($n['id']) . '</span>';

  $toggle = $hasChildren
    ? '<button class="hc-toggle btn btn-sm btn-link px-1 py-0" data-target="node-'.$key.'" aria-label="toggle" title="Expand/Collapse">â–¶</button>'
    : '<span class="hc-spacer"></span>';

  $html  = '<li class="hc-node" data-text="'.h(mb_strtolower($n['name'])).'">';
  $html .=   '<div class="hc-row">';
  $html .=     $toggle.'<span class="hc-folder'.($hasChildren ? '' : ' hc-leaf').'">ðŸ“</span>';
  $html .=     '<span class="hc-name">'.h($n['name']).'</span>'.$badge;
  $html .=   '</div>';

  if ($hasChildren) {
    $html .= '<ul id="node-'.$key.'" class="hc-children collapse">';
    foreach ($kids[$id] as $cid) {
      $html .= render_node($cid, $nodes, $kids, array_merge($path, [$id]));
    }
    $html .= '</ul>';
  }

  $html .= '</li>';
  return $html;
}

/** Build the full tree */
ob_start();
echo '<ul class="hc-tree list-unstyled">';
foreach ($roots as $rid) {
  echo render_node($rid, $nodes, $kids, []);
}
echo '</ul>';
$treeHtml = ob_get_clean();
?>

<div class="container py-3">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">S4 Categories (Hierarchy)</h1>
    <span class="ms-3 badge bg-info">Total: <?= count($rows) ?></span>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="row g-2 align-items-center mb-3">
        <div class="col-md-6">
          <input id="hc-search" type="search" class="form-control" placeholder="Filter categories by nameâ€¦">
        </div>
        <div class="col-md-6 text-md-end">
          <button id="btn-expand-all" class="btn btn-outline-primary btn-sm me-2">Expand all</button>
          <button id="btn-collapse-all" class="btn btn-outline-secondary btn-sm">Collapse all</button>
        </div>
      </div>

      <div id="hc-tree-wrap" class="hc-wrap">
        <?= $treeHtml ?: '<div class="alert alert-warning mb-0">No categories loaded.</div>' ?>
      </div>
    </div>
  </div>
</div>

<style>
/* Minimal tree styling */
.hc-wrap { font-size: 0.95rem; }
.hc-row {
  display:flex; align-items:center; gap:.25rem; line-height:1.6;
  padding: .1rem .25rem;
}
.hc-row:hover { background: rgba(0,0,0,.03); border-radius: .25rem; }
.hc-toggle { text-decoration:none; font-size:.9rem; }
.hc-toggle:focus { box-shadow:none; }
.hc-spacer { display:inline-block; width:1.65rem; } /* aligns with toggle btn width */
.hc-folder { width:1.3rem; text-align:center; opacity:.9; }
.hc-folder.hc-leaf { opacity:.4; }
.hc-name { font-weight:500; }
.hc-children { margin-left: 1.5rem; padding-left: .5rem; border-left: 1px dashed rgba(0,0,0,.15); }
</style>

<script>
(function () {
  const wrap = document.getElementById('hc-tree-wrap');
  const q    = document.getElementById('hc-search');
  const btnExpand  = document.getElementById('btn-expand-all');
  const btnCollapse= document.getElementById('btn-collapse-all');

  // Toggle handlers
  wrap?.addEventListener('click', (e) => {
    const btn = e.target.closest('.hc-toggle');
    if (!btn) return;
    const id = btn.getAttribute('data-target');
    const ul = document.getElementById(id);
    if (!ul) return;
    const isShown = ul.classList.contains('show');
    ul.classList.toggle('show', !isShown);
    btn.textContent = isShown ? 'â–¶' : 'â–¼';
  });

  // Expand / Collapse all
  function setAll(open) {
    wrap?.querySelectorAll('.hc-children').forEach(ul => ul.classList.toggle('show', open));
    wrap?.querySelectorAll('.hc-toggle').forEach(b => b.textContent = open ? 'â–¼' : 'â–¶');
  }
  btnExpand?.addEventListener('click', () => setAll(true));
  btnCollapse?.addEventListener('click', () => setAll(false));

  // Filter by name (client-side, simple contains)
  let tId = null;
  q?.addEventListener('input', () => {
    clearTimeout(tId);
    tId = setTimeout(() => {
      const needle = (q.value || '').trim().toLowerCase();
      if (!needle) {
        // reset: show all
        wrap?.querySelectorAll('.hc-node').forEach(li => li.style.display = '');
        setAll(false);
        return;
      }
      // show matches and their ancestors
      const matches = [];
      wrap?.querySelectorAll('.hc-node').forEach(li => {
        const txt = li.getAttribute('data-text') || '';
        const on  = txt.includes(needle);
        li.style.display = on ? '' : 'none';
        if (on) matches.push(li);
      });
      // Expand to reveal matches
      matches.forEach(li => {
        // walk up parents: show and expand all ancestor ULs
        let p = li.parentElement; // ul
        while (p && p !== wrap) {
          if (p.classList.contains('hc-children')) {
            p.classList.add('show');
            // find its toggle and set to "open"
            const id = p.getAttribute('id');
            wrap.querySelectorAll(`.hc-toggle[data-target="${id}"]`).forEach(b => b.textContent = 'â–¼');
          }
          if (p.tagName === 'LI') p.style.display = '';
          p = p.parentElement;
        }
      });
    }, 120);
  });
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php';

