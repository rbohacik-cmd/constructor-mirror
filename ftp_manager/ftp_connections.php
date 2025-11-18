<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

require_once __DIR__ . '/../appcfg.php';
require_once __DIR__ . '/../db.php';   // <-- primary DB (BlueHost) via db()

// html escape helper (guarded, in case not included elsewhere)
if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// Preload rows for first render (JS will also reload via API)
$rows = qall("
  SELECT id, name, protocol, host, port, username, passive, root_path, created_at
  FROM ftp_connections
  ORDER BY id DESC
");

include __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h3 class="m-0">FTP Connections</h3>
  <div class="ms-auto d-flex gap-2">
    <button id="btnNew" class="btn btn-sm btn-outline-info">New connection</button>
    <button id="btnReload" class="btn btn-sm btn-outline-info">Reload</button>
  </div>
</div>

<div id="listWrap" class="table-responsive mb-4">
  <table class="table table-striped align-middle sticky-head">
    <thead>
      <tr>
        <th>ID</th><th>Name</th><th>Proto</th><th>Host</th><th>Port</th>
        <th>User</th><th>Passive</th><th>Root</th><th>Created</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $x): ?>
        <tr>
          <td><?= (int)$x['id'] ?></td>
          <td><?= h((string)$x['name']) ?></td>
          <td><?= h((string)$x['protocol']) ?></td>
          <td><?= h((string)$x['host']) ?></td>
          <td><?= (int)$x['port'] ?></td>
          <td><?= h((string)$x['username']) ?></td>
          <td><?= ((int)$x['passive'] ? 'yes' : 'no') ?></td>
          <td><code class="code-wrap"><?= h((string)($x['root_path'] ?? '')) ?></code></td>
          <td class="text-secondary small"><?= h((string)($x['created_at'] ?? '')) ?></td>
          <td class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-outline-info" data-act="edit" data-id="<?= (int)$x['id'] ?>">Edit</button>
            <button class="btn btn-sm btn-outline-info" data-act="test" data-id="<?= (int)$x['id'] ?>">Test</button>
            <button class="btn btn-sm btn-outline-danger" data-act="del"  data-id="<?= (int)$x['id'] ?>">Delete</button>
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

<!-- Modal -->
<div class="modal fade" id="connModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Connection</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="connForm" class="row g-3">
          <input type="hidden" name="id" id="f_id" value="0">

          <div class="col-md-6">
            <label class="form-label fw-semibold">Name</label>
            <input type="text" class="form-control" id="f_name" name="name" required>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Protocol</label>
            <select class="form-select" id="f_protocol" name="protocol" required>
              <option value="SFTP">SFTP</option>
              <option value="FTPS">FTPS</option>
              <option value="FTP">FTP</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Port</label>
            <input type="number" class="form-control" id="f_port" name="port" min="1" max="65535" placeholder="22/21">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Host</label>
            <input type="text" class="form-control" id="f_host" name="host" required placeholder="example.com">
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">Username</label>
            <input type="text" class="form-control" id="f_username" name="username" required>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-semibold">
              Password <span class="text-secondary">(leave blank to keep)</span>
            </label>
            <input type="password" class="form-control" id="f_password" name="password" autocomplete="new-password" placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Root path</label>
            <input type="text" class="form-control" id="f_root_path" name="root_path" placeholder="/">
          </div>

          <div class="col-md-3 form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" id="f_passive" name="passive">
            <label class="form-check-label" for="f_passive">Use passive (FTP/FTPS)</label>
          </div>

          <div class="col-12">
            <div class="small text-secondary">
              Passwords are stored encrypted (AES-256-CBC) using your local key in
              <code class="small">.secrets/ftp_kms.key</code>.
            </div>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button id="btnTest" class="btn btn-outline-info">Test connection</button>
        <button id="btnSave" class="btn btn-info">Save</button>
        <button class="btn btn-outline-info" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php
// Page JS (controller) with cache-busting
$ctrl = __DIR__ . '/ftp_connections_controller.js';
$ctrlVer = @filemtime($ctrl) ?: time();
$extraScripts = <<<HTML
<script src="/ftp_manager/ftp_connections_controller.js?v={$ctrlVer}"></script>
HTML;

include __DIR__ . '/../partials/footer.php';

