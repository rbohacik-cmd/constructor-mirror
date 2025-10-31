<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '1');

/** Bootstrap: constants + helpers */
require_once __DIR__ . '/partials/bootstrap.php';

/** App config (cross-module include via PROJECT_FS) */
if (!function_exists('appcfg')) {
  require_once PROJECT_FS . '/appcfg.php';
}

/** Safe loader for project-relative PHP config files */
function load_cfg_safe(string $rel): array {
  $full = project_path($rel);               // e.g. 'config/db_mssql.php'
  return is_file($full) ? (require $full) : [];
}

$cfg_mssql = load_cfg_safe('config/db_mssql.php');
$cfg_mysql = load_cfg_safe('config/db_mysql.php');

/** Build a normalized card list for the dashboard */
$cards = [];

// MSSQL servers
foreach ((array)($cfg_mssql['servers'] ?? []) as $key => $s) {
  $keyStr = (string)$key;
  $cards[] = [
    'key'     => $keyStr,
    'title'   => (string)($s['title'] ?? $keyStr),
    'type'    => 'mssql',
    'viewer'  => 'apps/ts/server-ts.php?server=' . rawurlencode($keyStr),
    'viewer2' => 'apps/ts/server-ts.php?server=' . rawurlencode($keyStr) . '&tpage=1&tper=25',
    'ping'    => 'api/ping_mssql.php?server=' . rawurlencode($keyStr),
  ];
}

// MySQL servers
foreach ((array)($cfg_mysql['servers'] ?? []) as $key => $s) {
  $keyStr = (string)$key;
  $cards[] = [
    'key'     => $keyStr,
    'title'   => (string)($s['title'] ?? $keyStr),
    'type'    => 'mysql',
    'viewer'  => 'apps/mysql/index.php?server=' . rawurlencode($keyStr),
    'viewer2' => null,
    'ping'    => 'api/ping_mysql.php?server=' . rawurlencode($keyStr),
  ];
}

// Optional: stable ordering by type then title
usort($cards, fn($a,$b) => [$a['type'],$a['title']] <=> [$b['type'],$b['title']]);

/** Header include via project root */
require_once PROJECT_FS . '/partials/header.php';

// tiny esc helper (kept local)
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Connections</h4>
  <?php if (!empty($cards)): ?>
    <button class="btn btn-sm btn-outline-info" id="retry-all" type="button" aria-label="Retry all connections">
      Retry all
    </button>
  <?php endif; ?>
</div>

<?php if (empty($cards)): ?>
  <div class="alert alert-warning">
    No servers configured.<br>
    Add definitions to <code>/config/db_mssql.php</code> and/or <code>/config/db_mysql.php</code>.
  </div>
<?php endif; ?>

<div class="row g-3" id="servers-grid">
  <?php foreach ($cards as $c): ?>
    <?php
      $key     = $e($c['key']);
      $title   = $e($c['title']);
      $type    = $e($c['type']);
      $badge   = strtoupper($type);

      // Resolve web URLs at render time (obeys BASE_URL)
      $viewer  = $e(url_rel($c['viewer']));
      $viewer2 = $c['viewer2'] ? $e(url_rel($c['viewer2'])) : null;
      $ping    = $e(url_rel($c['ping']));
    ?>
    <div class="col-12 col-lg-6">
      <div class="card p-3 h-100 shadow-soft server-card"
           id="card-<?= $key ?>"
           data-server="<?= $key ?>"
           data-type="<?= $type ?>"
           data-ping-endpoint="<?= $ping ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="d-flex align-items-center">
              <span class="status-dot status-pending me-2" id="dot-<?= $key ?>" aria-live="polite" aria-label="Status"></span>
              <h5 class="mb-0"><?= $title ?></h5>
              <span class="badge rounded-pill bg-secondary ms-2"><?= $badge ?></span>
            </div>
            <div class="mt-2 small text-secondary" id="info-<?= $key ?>">checking…</div>
          </div>
          <div class="text-end">
            <button class="btn btn-sm btn-outline-info" onclick="retryPing('<?= $key ?>')" id="btn-<?= $key ?>" type="button">
              Retry
            </button>
          </div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-outline-info" href="<?= $viewer ?>">Open viewer</a>
          <?php if ($viewer2 && $type === 'mssql'): ?>
            <a class="btn btn-sm btn-outline-info" href="<?= $viewer2 ?>">Browse tables</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php
// Page scripts (project-relative → proper URLs via helper)
// Choose your actual controller file name here:
$extraScripts = scripts_html([
  ['path' => 'index_controller.js',                 'attrs' => ['type' => 'module']],
  // If you also need the dashboard helper, uncomment:
  // ['path' => 'assets/js/index_dashboard_dual.js',   'attrs' => ['defer' => true]],
]);

require_once PROJECT_FS . '/partials/footer.php';
