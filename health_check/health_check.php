<?php
declare(strict_types=1);

// --- Project deps
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../appcfg.php';

// --- Health-Check module bootstrap (single include for the whole module)
require_once __DIR__ . '/bootstrap.php';

$cfg = appcfg();
$siteTitle = $cfg['site_title'] ?? 'Health Check';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** @return PDO */
function hc_pdo(): PDO {
  if (function_exists('pdo')) return pdo();
  if (function_exists('db'))  return db();
  throw new RuntimeException('No PDO accessor (pdo()/db()) available.');
}

$pdo = null;
$manufacturers = [];
$recent = [];

/* --- URL bases (avoid relying on $baseUrl) --- */
$SCRIPT_DIR  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'); // e.g. /apps/health_check
$APP_BASE    = $SCRIPT_DIR . '/';                                     // e.g. /apps/health_check/

/* --- Health-Check router base (absolute) --- */
$HC_ROUTE = '/health_check/import_jobs_logic.php';

/* Pre-compute endpoints the JS will use */
$ROUTES = [
  'upload'         => $HC_ROUTE . '?action=upload',
  'import'         => $HC_ROUTE . '?action=import',
  'progress'       => $HC_ROUTE . '?action=status',

  'jobs_list'      => $HC_ROUTE . '?action=jobs_list',
  'job_get'        => $HC_ROUTE . '?action=job_get',
  'job_save'       => $HC_ROUTE . '?action=job_save',
  'job_delete'     => $HC_ROUTE . '?action=job_delete',
  'job_run'        => $HC_ROUTE . '?action=job_run',
  'job_run_status' => $HC_ROUTE . '?action=job_run_status',

  // optional (server-side file browser with hints):
  'file_picker'    => $HC_ROUTE . '?action=file_picker',
];

try {
  $pdo = hc_pdo();

  // Manufacturers for dropdown (hc_manufacturers)
  $manufacturers = $pdo->query("
    SELECT id, name, slug
    FROM hc_manufacturers
    ORDER BY name
    LIMIT 1000
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Recent uploads with progress join (hc_uploads → hc_progress)
  $stmt = $pdo->query("
    SELECT
      u.id,
      m.name AS manufacturer_name,
      m.slug AS manufacturer_slug,
      u.filename,
      u.status AS u_status,
      u.rows_imported,
      u.uploaded_at,
      p.status AS p_status,
      p.total_rows,
      p.processed
    FROM hc_uploads u
    JOIN hc_manufacturers m ON m.id = u.manufacturer_id
    LEFT JOIN hc_progress p ON p.upload_id = u.id
    ORDER BY u.id DESC
    LIMIT 50
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    $status = $r['p_status'] ?: $r['u_status']; // prefer live progress status
    $total  = $r['total_rows'] ?? null;
    if ($total === null && $r['u_status'] === 'imported') $total = (int)$r['rows_imported'];
    $done   = $r['processed'] ?? null;
    if ($done === null) {
      $done = ($r['u_status'] === 'imported') ? (int)$r['rows_imported'] : 0;
    }
    $recent[] = [
      'id'            => (int)$r['id'],
      'manufacturer'  => (string)$r['manufacturer_name'],
      'file'          => (string)$r['filename'],
      'status'        => (string)$status,
      'rows_total'    => $total !== null ? (int)$total : null,
      'rows_done'     => (int)$done,
      'uploaded_at'   => (string)$r['uploaded_at'],
    ];
  }
} catch (Throwable $e) {
  // Soft-fail view; page still renders
}

include __DIR__ . '/../partials/header.php';
?>
<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="mb-0">Health Check Import</h1>
    <span class="ms-3 hc-chip">CSV / Excel importer</span>
  </div>

  <form
    id="hc-upload-form"
    class="card p-3"
    enctype="multipart/form-data"

    data-route-upload="<?= h($ROUTES['upload']) ?>"
    data-route-import="<?= h($ROUTES['import']) ?>"
    data-route-progress="<?= h($ROUTES['progress']) ?>"

    data-route-jobs-list="<?= h($ROUTES['jobs_list']) ?>"
    data-route-job-get="<?= h($ROUTES['job_get']) ?>"
    data-route-job-save="<?= h($ROUTES['job_save']) ?>"
    data-route-job-delete="<?= h($ROUTES['job_delete']) ?>"
    data-route-job-run="<?= h($ROUTES['job_run']) ?>"
    data-route-job-run-status="<?= h($ROUTES['job_run_status']) ?>"

    data-route-file-picker="<?= h($ROUTES['file_picker']) ?>"
  >
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Manufacturer (pick existing)</label>
        <select id="mfgSelect" class="form-select">
          <option value="">— choose —</option>
          <?php foreach ($manufacturers as $m): ?>
            <option value="<?= h($m['name']) ?>"><?= h($m['name']) ?> (<?= h(strtolower($m['slug'])) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">
          Tip: For <span class="badge bg-info-subtle text-info-emphasis">InLine</span> uploads, we auto-map columns and upsert by <code>Artikelnummer</code>.
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">…or create new</label>
        <input id="mfgNew" class="form-control" placeholder="e.g. ROLINE">
        <div class="form-text">If filled, this will be used instead of the dropdown.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">CSV / Excel file</label>
        <input id="fileInput" type="file" class="form-control" accept=".csv,.tsv,.txt,.xlsx,.xls,.xlsm">
        <div class="form-text">
          Accepted: <code>.csv</code>, <code>.tsv</code>, <code>.txt</code>, <code>.xlsx</code>, <code>.xls</code>, <code>.xlsm</code>
        </div>
      </div>
    </div>

    <div class="d-flex align-items-center gap-3 mt-3">
      <button class="btn btn-primary" type="submit">Upload &amp; Process</button>
      <div id="hc-status" class="small text-muted">Idle</div>
    </div>

    <div id="hc-progress-wrap" class="progress mt-3" style="display:none; height:10px;">
      <div id="hc-progress" class="progress-bar" role="progressbar" style="width:0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
    </div>

    <pre id="hc-mapping" class="mt-3 small text-body-secondary" style="min-height:3.2rem"></pre>
  </form>
  
  <!-- ===================== Saved Jobs ===================== -->
  <div class="card mt-4" id="hc-jobs-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Saved Jobs</span>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="btn-jobs-refresh" type="button" title="Reload">Reload</button>
        <button class="btn btn-sm btn-primary" id="btn-job-add" type="button">+ Add Job</button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0 align-middle">
        <thead class="table-dark">
          <tr>
            <th class="text-nowrap">#</th>
            <th>Manufacturer</th>
            <th>File path</th>
            <th class="text-nowrap">Enabled</th>
            <th class="text-nowrap">Last status</th>
            <th class="text-nowrap">Progress</th>
            <th class="text-nowrap">Last import</th>
            <th class="text-end text-nowrap">Actions</th>
          </tr>
        </thead>
        <tbody id="jobs-tbody">
          <tr><td colspan="8" class="text-center text-secondary py-4">Loading…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer small text-secondary">Manage reusable jobs and run them on demand.</div>
  </div>

  <!-- Recent uploads -->
  <div class="card mt-4">
    <div class="card-header">Recent Uploads</div>
    <div class="table-responsive">
      <table class="table table-striped table-hover mb-0 align-middle">
        <thead class="table-dark">
          <tr>
            <th class="text-nowrap">#</th>
            <th>Manufacturer</th>
            <th>File</th>
            <th>Status</th>
            <th class="text-end">Rows</th>
            <th class="text-nowrap">Progress</th>
            <th class="text-nowrap">Uploaded</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="7" class="text-center text-secondary py-4">No uploads yet.</td></tr>
        <?php else: foreach ($recent as $r):
          $rowsTot = $r['rows_total'];
          $rowsDone= $r['rows_done'];
          $pct = ($rowsTot && $rowsTot > 0) ? (int)round(($rowsDone / $rowsTot) * 100) : null;
          $badge = 'secondary';
          switch ($r['status']) {
            case 'imported':  $badge = 'success'; break;
            case 'failed':    $badge = 'danger';  break;
            case 'running':   $badge = 'info';    break;
            case 'queued':    $badge = 'warning'; break;
          }
        ?>
          <tr>
            <td class="text-muted"><?= h((string)$r['id']) ?></td>
            <td><strong><?= h($r['manufacturer']) ?></strong></td>
            <td class="text-nowrap"><?= h($r['file']) ?></td>
            <td><span class="badge text-bg-<?= h($badge) ?>"><?= h($r['status']) ?></span></td>
            <td class="text-end"><?= $rowsTot !== null ? (int)$rowsTot : '—' ?></td>
            <td class="text-nowrap">
              <?php if ($pct !== null): ?>
                <?= (int)$rowsDone ?> / <?= (int)$rowsTot ?> (<?= $pct ?>%)
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-nowrap"><?= h(date('Y-m-d H:i:s', strtotime($r['uploaded_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer small text-secondary">Showing last 50 uploads.</div>
  </div>
  
  <!-- ===================== Job Modal ===================== -->
  <div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><span id="jobModalTitle">Add Job</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" id="job_id" value="0">
          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label">Manufacturer</label>
              <select id="job_manufacturer" class="form-select">
                <option value="">— choose —</option>
                <?php foreach (($manufacturers ?? []) as $m): ?>
                  <option value="<?= h($m['name']) ?>">
                    <?= h($m['name']) ?> (<?= h(strtolower($m['slug'])) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Use the same label used in imports.</div>
            </div>

            <div class="col-md-7">
              <label class="form-label">File path (absolute or relative)</label>
              <div class="input-group">
                <input id="job_file_path" class="form-control"
                       placeholder="C:\xampp\htdocs\imports\inline.csv or inline.csv">
                <button class="btn btn-secondary" type="button" id="btn-pick-file">Browse</button>
              </div>
              <div class="form-text">Relative paths resolve under your configured import root.</div>
            </div>

            <div class="col-md-12">
              <label class="form-label">Notes (optional)</label>
              <input id="job_notes" class="form-control" placeholder="Short description">
            </div>

            <div class="col-md-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="job_enabled" checked>
                <label class="form-check-label" for="job_enabled">Enabled</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" id="btn-job-save" type="button">Save</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Controller: update to read data-route-* attributes -->
<script type="module" src="/health_check/health_check_controller.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/../partials/footer.php';
