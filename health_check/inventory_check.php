<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials/bootstrap.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<?php require_once __DIR__ . '/../partials/header.php'; ?>

<style>
  /* Items-to-Check toolbar responsiveness */
  .chk-bar .input-group > .input-group-text{padding:.25rem .5rem}
  .chk-bar #chk-field{max-width:110px;flex:0 0 auto}
  .chk-bar #chk-q{min-width:0;flex:1 1 auto}

  /* RIGHT-align "Per page + Refresh" on all sizes */
  .chk-bar .perpage-wrap{
    display:flex;
    align-items:center;
    gap:.5rem;
    margin-left:auto;
    justify-content:flex-end;
  }

  @media (max-width:576px){
    .chk-bar{row-gap:.5rem}
    .chk-bar .input-group{flex-wrap:nowrap}
    .chk-bar #chk-field{max-width:88px;font-size:.875rem;padding-right:1.5rem}
    .chk-bar .input-group > .input-group-text{font-size:.75rem;padding:.2rem .4rem}
    .chk-bar #chk-q{font-size:.95rem}
    .chk-bar .perpage-wrap{width:100%;justify-content:flex-end;gap:.5rem}
    .chk-bar .perpage-wrap label{display:none}
    .chk-bar #chk-limit{max-width:80px}
    .chk-bar #chk-refresh{flex:0 0 auto}
  }

  /* Recently Counted sizing: content-fit columns + flexible Name */
  .recent-table col.fit { width: 1%; }
  .recent-table col.flex { width: auto; }
  .recent-table th, .recent-table td { white-space: nowrap; }
  .recent-table td.name-cell { white-space: normal; } /* allow wrapping only for Name */
</style>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 m-0">Inventory Check</h1>
    <div class="text-muted small">Lookup: <b>CarovyKod</b> → <b>Katalog</b> → <b>Kod</b></div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form id="inv-form" class="row g-2" autocomplete="off">
        <div class="col-12 col-md-8">
          <label class="form-label">Scan or type (EAN / Katalog / Kod)</label>
          <input id="inv-query" name="query" class="form-control"
                 placeholder="CarovyKod → Katalog → Kod" inputmode="numeric" autofocus />
          <div class="form-text">We’ll try exact match by CarovyKod, then Katalog, then Kod.</div>
        </div>
        <div class="col-12 col-md-4 d-grid d-md-flex align-items-end gap-2">
          <button id="btn-search" class="btn btn-primary" type="submit">Search</button>
          <button id="btn-clear" class="btn btn-secondary" type="button">Clear</button>
        </div>

        <!-- Toggles row -->
        <div class="col-12">
          <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="form-check form-switch m-0" title="When ON, scanning a valid EAN inserts 1 piece automatically.">
              <input class="form-check-input" type="checkbox" id="auto-add">
              <label class="form-check-label" for="auto-add">Auto Add 1 piece</label>
            </div>

            <div class="form-check form-switch m-0" title="When ON, a valid EAN scan auto-submits the search.">
              <input class="form-check-input" type="checkbox" id="auto-fire">
              <label class="form-check-label" for="auto-fire">Auto Fire on EAN</label>
            </div>

            <small class="text-muted">
              EAN-8/12/13/14 accepted (checksum-validated for 8/13).
            </small>
          </div>
        </div>
      </form>

      <!-- Status banner (controller will manage classes/content) -->
      <div id="search-status" class="mt-3 d-none"></div>
    </div>
  </div>

  <!-- Recent counted items -->
  <div class="card shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span class="fw-bold">Recently Counted</span>
      <div class="d-flex align-items-center gap-2">
        <label class="form-label m-0 small">Per page</label>
        <select id="recent-limit" class="form-select form-select-sm" style="width:100px">
          <option>10</option><option selected>20</option><option>50</option><option>100</option>
        </select>
        <button id="btn-refresh" class="btn btn-sm btn-outline-secondary">Refresh</button>
        <button id="btn-clear-all" class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="Delete ALL rows in inventory_checks">Clear All</button>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0 recent-table">
          <!-- shrink-to-fit for data cols; Name flexes -->
          <colgroup>
            <col class="fit"><col class="fit"><col class="fit"><col class="fit"><col class="fit">
            <col class="fit"><col class="fit"><col class="fit"><col class="flex"><col class="fit">
          </colgroup>
          <thead class="table-dark">
            <tr>
              <th>Kod</th>
              <th>Katalog</th>
              <th>EAN</th>
              <th>PLU</th>
              <th class="text-end">Found</th>
              <th class="text-end">Reserved (TS)</th>
              <th class="text-end">On Stock (TS)</th>
              <th class="text-end">Difference</th>
              <th>Name</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody id="recent-body">
            <tr><td colspan="10" class="text-center text-muted">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="small text-secondary" id="recent-summary">—</div>
      <div class="btn-group">
        <button id="recent-prev" class="btn btn-sm btn-outline-secondary" disabled>&laquo; Prev</button>
        <button id="recent-next" class="btn btn-sm btn-outline-secondary" disabled>Next &raquo;</button>
      </div>
    </div>
  </div>

  <!-- Items to Check (search in MSSQL stock) -->
  <div class="card shadow-sm mt-3">
    <div class="card-header">
      <div class="row g-2 align-items-center chk-bar">
        <div class="col-lg-4">
          <div class="fw-bold">Items to Check</div>
          <div class="small text-secondary">Min 3 chars (PLU: 2) • Only items with stock &gt; 0</div>
        </div>

        <div class="col-12 col-md-6 col-lg-5">
          <div class="input-group input-group-sm">
            <span class="input-group-text">By</span>
            <select id="chk-field" class="form-select">
              <option value="name" selected>Name</option>
              <option value="plu">PLU</option>
              <option value="kod">Kod</option>
              <option value="katalog">Katalog</option>
              <option value="ean">EAN</option>
            </select>
            <input id="chk-q" class="form-control" placeholder="Type to search…" autocapitalize="off" autocorrect="off" />
          </div>
          <div class="form-text">Tip: click table headers to sort (name, stock, …)</div>
        </div>

        <div class="col-12 col-md-2 col-lg-3 d-flex perpage-wrap justify-content-md-end align-items-center">
          <label class="form-label m-0 small me-2">Per page</label>
          <select id="chk-limit" class="form-select form-select-sm" style="width:100px">
            <option>10</option><option selected>20</option><option>50</option><option>100</option>
          </select>
          <button id="chk-refresh" class="btn btn-sm btn-outline-secondary ms-2">Refresh</button>
        </div>
      </div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th class="text-start" style="width:10ch;">Checked</th>
              <th style="width:12ch;">Kod</th>
              <th style="width:16ch;">Katalog</th>
              <th style="width:12ch;">PLU</th>
              <th>Name</th>
              <th class="text-end" style="width:12ch;">Reserved</th>
              <th class="text-end" style="width:14ch;">On Stock (TS)</th>
            </tr>
          </thead>
          <tbody id="chk-body">
            <tr><td colspan="7" class="text-center text-muted">Type at least 3 characters…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="small text-secondary" id="chk-summary">—</div>
      <div class="btn-group">
        <button id="chk-prev" class="btn btn-sm btn-outline-secondary" disabled>&laquo; Prev</button>
        <button id="chk-next" class="btn btn-sm btn-outline-secondary" disabled>Next &raquo;</button>
      </div>
    </div>
  </div>

</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Product</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-3">
          <dt class="col-4">Kod</dt>     <dd class="col-8" id="cf-kod">—</dd>
          <dt class="col-4">Katalog</dt> <dd class="col-8" id="cf-katalog">—</dd>
          <dt class="col-4">Název</dt>   <dd class="col-8" id="cf-nazev">—</dd>
          <dt class="col-4">EAN</dt>     <dd class="col-8" id="cf-ean">—</dd>
          <dt class="col-4">PLU</dt>     <dd class="col-8" id="cf-plu">—</dd>
        </dl>

        <label class="form-label">Found pieces</label>
        <input id="cf-qty" type="number" min="0" step="1" class="form-control" placeholder="0" />
        <div class="form-text">Enter the counted quantity and confirm to save to MySQL.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="btn-confirm-insert" class="btn btn-success" type="button">Confirm &amp; Save</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.INV = { logicUrl: "inventory_check_logic.php" };
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Optional: enable tooltips for the Clear All button
  const ttEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  [...ttEls].forEach(el => new bootstrap.Tooltip(el));
</script>
<script src="inventory_check_controller.js"></script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
