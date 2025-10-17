<?php
declare(strict_types=1);

// (optional during dev)
@ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Health Parameters Mapper</h1>
    <span class="ms-3 hc-chip">Group_ID (GUID) ↔ Display Name</span>
  </div>

  <div class="card shadow-soft">
    <div class="card-body">
      <div class="row g-2 align-items-center mb-3">
        <div class="col-md-6">
          <input id="grp-search" type="search" class="form-control" placeholder="Search by Group_ID (GUID) or parameter name…">
        </div>
        <div class="col-md-6 text-md-end">
          <button id="btn-refresh" class="btn btn-sm btn-outline-info">Refresh</button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-dark">
            <tr>
              <th style="min-width:260px">Group_ID (GUID)</th>
              <th style="min-width:260px">Display Name (MySQL)</th>
              <th class="text-end" style="width:120px">Params</th>
              <th style="width:200px"></th>
            </tr>
          </thead>
          <tbody id="groups-body">
            <tr><td colspan="4"><span class="muted">Loading…</span></td></tr>
          </tbody>
        </table>
      </div>

      <div id="mapper-results" class="small mt-2"></div>

      <div class="mt-4">
        <div class="p-2 border rounded bg-light-subtle mb-0">
          These names appear in the <b>Parameters by Category</b> dropdown.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal to view parameters of a group -->
<div class="modal fade" id="paramsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Group <span id="pm-group"></span> — Parameters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="pm-body" class="small">Loading…</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="/health_check/health_parameters_mapper_controller.js"></script>
<?php require_once __DIR__ . '/../partials/footer.php';
