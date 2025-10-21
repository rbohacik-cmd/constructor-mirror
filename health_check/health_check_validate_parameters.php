<?php
declare(strict_types=1);

// (dev) show errors
@ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../partials/header.php';

$serverKey = 'ts';

// Load mapped categories (MySQL)
$maps = qall("SELECT mssql_category_id AS cat_id, mssql_param_group_id AS group_id FROM hc_category_param_group_guid");

// Group labels (MySQL)
$metaRows = qall("SELECT mssql_param_group_id, display_name FROM hc_param_group_meta_guid");
$groupName = [];
foreach ($metaRows as $r) $groupName[(string)$r['mssql_param_group_id']] = (string)$r['display_name'];

// Resolve category names from MSSQL for only mapped categories
$catIds = array_values(array_unique(array_map(fn($m)=> (string)$m['cat_id'], $maps)));
$catName = [];
if ($catIds) {
  // fetch in chunks to avoid param limits
  for ($i=0; $i<count($catIds); $i+=300) {
    $slice = array_slice($catIds, $i, 300);
    $in = implode(',', array_fill(0, count($slice), 'CAST(? AS UNIQUEIDENTIFIER)'));
    $rows = qall("
      SELECT CAST(ID AS NVARCHAR(36)) AS ID, Nazev
      FROM S4_Agenda_PCB.dbo.Artikly_KategorieArtiklu WITH (NOLOCK)
      WHERE ID IN ($in)
      ORDER BY Nazev ASC
    ", $slice, null, $serverKey);
    foreach ($rows as $r) $catName[(string)$r['ID']] = (string)$r['Nazev'];
  }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?>
<div class="container py-3">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Health Check — Validate parameters</h1>
    <!-- visual-only change -->
    <span class="ms-3 hc-chip">Zkratka20: <code>eshop%</code> · Kategorie: pipe-separated GUIDs</span>
  </div>

  <?php if (!$maps): ?>
    <div class="alert alert-warning">No categories have a Group_ID mapping yet. Set mappings first on <a href="/health_check/health_check_parameters_by_category.php" class="alert-link">Parameters by Category</a>.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th style="min-width:280px">Category</th>
            <th>Category GUID</th>
            <th style="min-width:240px">Group</th>
            <th style="width:130px"></th>
          </tr>
        </thead>
        <tbody id="hc-val-body">
          <?php foreach ($maps as $m):
            $cat = (string)$m['cat_id'];
            $grp = (string)$m['group_id'];
            $label = trim($groupName[$grp] ?? '') ?: $grp;
            $cname = $catName[$cat] ?? '(unknown)';
          ?>
          <tr data-cat="<?=h($cat)?>">
            <td><?=h($cname)?></td>
            <td><code class="small-mono"><?=h($cat)?></code></td>
            <td><span class="badge bg-info-subtle text-info-emphasis" title="<?=h($grp)?>"><?=h($label)?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary btn-validate" data-cat="<?=h($cat)?>">Validate</button>
            </td>
          </tr>
          <!-- result row: keep original simple hook so controller can replace it -->
          <tr class="d-none" id="res-<?=h($cat)?>">
            <td colspan="4">
              <div class="p-2 border rounded bg-body-secondary small" id="res-body-<?=h($cat)?>">
                Working…
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div id="global-results" class="small mt-3"></div>
</div>

<script src="/health_check/health_check_validate_parameters_controller.js"></script>
<?php require_once __DIR__ . '/../partials/footer.php';
