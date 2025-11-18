<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

require_once __DIR__ . '/../appcfg.php';

// Prefer explicit 'blue' if valid MySQL; otherwise fall back to first MySQL
$prefer = 'blue';
if (function_exists('server_exists') && function_exists('is_mysql')) {
  if (server_exists($prefer) && is_mysql($prefer)) {
    current_server_key($prefer);
  } else {
    $auto = first_mysql_key();
    if ($auto) current_server_key($auto);
  }
} else {
  current_server_key($prefer);
}

// unified DB layer (qall/qrow/qscalar/etc.)
require_once __DIR__ . '/../db.php';

// Preload data for first render (UI JS will also reload via API)
$jobs = qall("
  SELECT j.*, c.name AS conn_name
  FROM ftp_jobs j
  JOIN ftp_connections c ON c.id = j.connection_id
  ORDER BY j.id DESC
");
$conns = qall("SELECT id, name, protocol FROM ftp_connections ORDER BY id DESC");

require_once __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h3 class="m-0">FTP Download Jobs</h3>
  <div class="ms-auto d-flex gap-2">
    <button id="btnNew" class="btn btn-sm btn-outline-info">New job</button>
    <button id="btnReload" class="btn btn-sm btn-outline-info">Reload</button>
  </div>
</div>

<div id="listWrap" class="table-responsive mb-4">
  <table class="table table-striped align-middle sticky-head">
    <thead>
      <tr>
        <th>ID</th><th>Job</th><th>Connection</th><th>Remote path</th>
        <th>Glob</th><th>Rec</th><th>Only newer</th><th>Enabled</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td><?= (int)$j['id'] ?></td>
          <td><?= e((string)$j['name']) ?></td>
          <td><?= e((string)$j['conn_name']) ?></td>
          <td><code class="code-wrap"><?= e((string)$j['remote_path']) ?></code></td>
          <td><code class="code-wrap"><?= e((string)$j['filename_glob']) ?></code></td>
          <td><?= ((int)$j['is_recursive'] ? 'yes' : 'no') ?></td>
          <td class="small text-secondary"><?= e((string)($j['only_newer_than'] ?? '')) ?></td>
          <td><?= ((int)$j['enabled'] ? 'yes' : 'no') ?></td>
          <td class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-outline-info"    data-act="edit" data-id="<?= (int)$j['id'] ?>">Edit</button>
            <button class="btn btn-sm btn-outline-info"    data-act="test" data-id="<?= (int)$j['id'] ?>">Test</button>
            <button class="btn btn-sm btn-outline-warning" data-act="run"  data-id="<?= (int)$j['id'] ?>">Run</button>
            <button class="btn btn-sm btn-outline-danger"  data-act="del"  data-id="<?= (int)$j['id'] ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div>
  <h6 class="text-secondary">Output</h6>
  <pre id="output" class="p-3 rounded" style="min-height:160px; white-space:pre-wrap;"></pre>
</div>

<!-- Job Modal -->
<div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Job</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="jobForm" class="row g-3">
          <input type="hidden" name="id" id="j_id" value="0">

          <div class="col-md-6">
            <label class="form-label fw-semibold">Name</label>
            <input type="text" class="form-control" id="j_name" name="name" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Connection</label>
            <select class="form-select" id="j_connection_id" name="connection_id" required>
              <option value="">â€“ select â€“</option>
              <?php foreach ($conns as $c): ?>
                <option value="<?= (int)$c['id'] ?>">
                  <?= e((string)$c['name']) ?> (<?= e((string)$c['protocol']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Remote path with Browse button -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Remote path</label>
            <div class="input-group">
              <input type="text" class="form-control" id="j_remote_path" name="remote_path" placeholder="/incoming" required>
              <button type="button" id="btnPickFiles" class="btn btn-outline-info">Browseâ€¦</button>
            </div>
            <div class="form-text">Pick files to auto-fill path &amp; glob.</div>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Filename glob</label>
            <input type="text" class="form-control" id="j_filename_glob" name="filename_glob" placeholder="*.csv" required>
            <div class="form-text">Filled by picker if you select files.</div>
          </div>

          <div class="col-md-3 form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" id="j_is_recursive" name="is_recursive">
            <label class="form-check-label" for="j_is_recursive">Recursive (SFTP only)</label>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Max size (MB)</label>
            <input type="number" class="form-control" id="j_max_size_mb" name="max_size_mb" min="1">
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Only newer than (YYYY-MM-DD HH:MM)</label>
            <input type="text" class="form-control" id="j_only_newer_than" name="only_newer_than">
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Target pipeline</label>
            <input type="text" class="form-control" id="j_target_pipeline" name="target_pipeline" placeholder="RAW_STAGE">
          </div>

          <div class="col-md-2 form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" id="j_enabled" name="enabled" checked>
            <label class="form-check-label" for="j_enabled">Enabled</label>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button id="btnJobTest" class="btn btn-outline-info">Test</button>
        <button id="btnJobSave" class="btn btn-info">Save</button>
        <button class="btn btn-outline-info" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- File Picker Modal -->
<div class="modal fade" id="pickerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header">
        <h5 class="modal-title">Select files on FTP</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="height:70vh;">
        <iframe id="pickerFrame" src="about:blank" style="border:0;width:100%;height:100%"></iframe>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
$ctrl = __DIR__ . '/ftp_jobs_manager_controller.js';
$ctrlVer = @filemtime($ctrl) ?: time();
?>
<script src="ftp_jobs_manager_controller.js?v=<?= (int)$ctrlVer ?>"></script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

