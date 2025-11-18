<?php
declare(strict_types=1);

// Bootstrap + core includes
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../../partials/bootstrap.php';
}
require_once PROJECT_FS . '/appcfg.php';
require_once PROJECT_FS . '/db.php';

// Tiny esc helper (UI-only)
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Pre-validate ?server if present — keep original friendly errors early
$server = (string)($_GET['server'] ?? '');
$serverError = '';
if ($server !== '') {
  if (!server_exists($server)) {
    $serverError = 'Unknown server key: ' . $server;
  } elseif (!is_mssql($server)) {
    $serverError = 'Server "' . $server . '" is not MSSQL.';
  }
}

require_once PROJECT_FS . '/partials/header.php';
?>
<style>
  .cell-clip{
    display:inline-block; max-width:28rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    vertical-align:bottom; cursor:pointer;
  }
  .cell-clip[data-long="1"] code::after{ content:" ⋯"; opacity:.6; font-size:.8em; }
  .cell-null{ opacity:.7; font-style:italic; cursor:default; }
  .modal pre{ max-height:70vh; overflow:auto; white-space:pre-wrap; word-break:break-word; }

  /* highlight for in-cell matches (client-side) */
  mark.ts-hit { background:#ffe08a; padding:0 .15em; border-radius:.2rem; color:inherit; }
</style>

<div class="container py-3" id="ts-app" data-server-error="<?= h($serverError) ?>">
  <h2 class="mb-3">MSSQL Browser <span class="text-secondary" id="db-title"></span></h2>

  <?php if ($serverError): ?>
    <div class="alert alert-danger"><?= h($serverError) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-12 col-lg-4">
      <!-- DB search + select -->
      <div class="card p-3 mb-3">
        <form class="row g-2" id="form-db">
          <input type="hidden" name="server" id="f-server">
          <div class="col-12">
            <label class="form-label small">Search Databases</label>
            <input class="form-control form-control-sm" name="qdb" id="f-qdb" placeholder="type to filter…">
          </div>
          <div class="col-12">
            <label class="form-label small">Database</label>
            <select class="form-select form-select-sm" name="db" id="f-db"></select>
          </div>
          <div class="col-12">
            <button class="btn btn-sm btn-outline-info w-100">Apply</button>
          </div>
        </form>
      </div>

      <!-- Tables search + list + pagination -->
      <div class="card p-3 h-100">
        <form class="mb-2" id="form-table-search">
          <input type="hidden" name="server" id="ts-server-keep">
          <input type="hidden" name="db" id="ts-db-keep">
          <input type="hidden" name="tpage" value="1">
          <input type="hidden" name="tper" id="ts-tper-keep">
          <!-- keep rows pager -->
          <input type="hidden" name="rpage" id="ts-rpage-keep">
          <input type="hidden" name="rper" id="ts-rper-keep">
          <div class="input-group input-group-sm">
            <input class="form-control" name="q" id="f-qtable" placeholder="Search tables…">
            <button class="btn btn-outline-info">Search</button>
          </div>
        </form>

        <!-- Controls: All / Favorites and counter -->
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="btn-group btn-group-sm" role="group" aria-label="Filter tables">
            <input type="radio" class="btn-check" name="favFilter" id="filter-all" autocomplete="off" checked>
            <label class="btn btn-outline-secondary" for="filter-all">All</label>
            <input type="radio" class="btn-check" name="favFilter" id="filter-favs" autocomplete="off">
            <label class="btn btn-outline-warning" for="filter-favs">Favorites</label>
          </div>
          <div class="small text-secondary"><span id="favorites-count">0</span> ★</div>
        </div>

        <div class="list-group small mb-2" id="tables-list"></div>
        <div id="tables-empty" class="text-secondary small d-none">No tables</div>

        <div id="tables-pager"></div>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <!-- Structure + rows-per selector -->
      <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Structure <span id="structure-table-name"></span></h6>
          <form class="d-flex align-items-center gap-2" id="form-rows-per">
            <label class="small text-secondary">Rows/page</label>
            <select class="form-select form-select-sm" name="rper" id="f-rper">
              <option>10</option><option>25</option><option>50</option><option>100</option><option>200</option>
            </select>
          </form>
        </div>
        <div class="table-responsive mt-2">
          <table class="table table-sm table-dark table-striped align-middle">
            <thead><tr>
              <th>Column</th><th>Type</th><th>Len</th><th>Prec</th><th>Scale</th><th>Nullable</th>
            </tr></thead>
            <tbody id="structure-body"></tbody>
          </table>
        </div>
      </div>

      <!-- Data + rows pager -->
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="d-flex align-items-center gap-2">
            <h6 class="mb-0">Data <span id="data-table-name"></span></h6>
            <!-- inline favorite toggle for the selected table -->
            <button
              id="fav-top-btn"
              class="btn btn-sm btn-outline-warning d-none"
              type="button"
              title="Add to favorites"
              aria-pressed="false">☆</button>
          </div>
          <span class="badge bg-secondary" id="row-count">0 rows</span>
        </div>

        <!-- Row value search (with column selector) -->
        <form class="mb-2" id="form-value-search" aria-label="Search values in current table">
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-7">
              <div class="input-group input-group-sm">
                <span class="input-group-text">Find</span>
                <input class="form-control" id="f-qval" placeholder="Search values…">
                <button class="btn btn-outline-info" type="submit">Search</button>
                <button class="btn btn-outline-secondary" type="button" id="btn-qval-clear" title="Clear search">Clear</button>
              </div>
            </div>
            <div class="col-12 col-md-5">
              <div class="input-group input-group-sm">
                <span class="input-group-text">in column</span>
                <select class="form-select" id="f-qval-col">
                  <option value="">(all columns)</option>
                  <!-- controller fills options -->
                </select>
              </div>
            </div>
          </div>
        </form>

        <div id="data-empty" class="text-secondary small d-none">Pick a table to preview rows.</div>
        <div id="data-none" class="text-secondary small d-none">No rows on this page.</div>

        <div class="table-responsive" id="data-wrap" style="display:none;">
          <table class="table table-sm table-dark table-striped align-middle">
            <thead><tr id="data-head"></tr></thead>
            <tbody id="data-body"></tbody>
          </table>
        </div>

        <div id="rows-pager"></div>
      </div>
    </div>
  </div>
</div>

<?php
// Page scripts via helpers (project-relative -> proper URLs)
$extraScripts = scripts_html([
  ['path' => 'apps/ts/server-ts_core.js'],
  ['path' => 'apps/ts/server-ts_render.js'],
  ['path' => 'apps/ts/server-ts_addons.js'],
  ['path' => 'apps/ts/server-ts_bindings.js'],
]);

require_once PROJECT_FS . '/partials/footer.php';
