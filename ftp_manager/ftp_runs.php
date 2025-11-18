<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../appcfg.php';

// Prefer explicit 'blue' if valid MySQL; else first MySQL
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

require_once __DIR__ . '/../mysql.php';

// Safe output + clipped cell helper (uses the guarded h()/render_cell from mysql.php if present)
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('render_cell')) {
  function render_cell($v, string $col = ''): string {
    if ($v === null) return '<span class="text-danger small-mono">NULL</span>';
    if ($v instanceof DateTimeInterface) return '<span class="small-mono">'.h($v->format('Y-m-d H:i:s')).'</span>';
    if (is_bool($v))  return '<span class="small-mono">'.($v ? 'TRUE' : 'FALSE').'</span>';
    if (is_int($v) || is_float($v)) return '<span class="small-mono">'.h((string)$v).'</span>';
    if (is_resource($v)) return '<span class="badge bg-secondary">resource</span>';
    $s = (string)$v;
    if ($s === '') return '<span class="text-muted small-mono">(empty)</span>';
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)) {
      return '<span class="badge bg-secondary">binary</span> <span class="small-mono">'.number_format(strlen($s)).' B</span>';
    }
    $max = 120;
    if (mb_strlen($s, 'UTF-8') > $max) {
      $snippet = mb_substr($s, 0, $max, 'UTF-8');
      return '<span class="cell-clip" data-title="'.h($col).'" data-full="'.h($s).'">'.h($snippet).'…</span>';
    }
    return h($s);
  }
}

$limit = 500;
$rows = qall("
  SELECT r.id, r.job_id, j.name AS job_name, r.started_at, r.finished_at, r.status,
         r.files_found, r.files_saved, r.message
  FROM ftp_runs_log r
  LEFT JOIN ftp_jobs j ON j.id = r.job_id
  ORDER BY r.id DESC
  LIMIT {$limit}
");

include __DIR__ . '/../partials/header.php';

// Include the reusable cell viewer (for long messages)
include __DIR__ . '/cell_viewer.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h3 class="m-0">FTP Runs Log</h3>
  <div class="ms-auto small text-secondary">Showing latest <span class="small-mono"><?= (int)$limit ?></span> runs</div>
</div>

<div class="table-responsive scroll-x">
  <table class="table table-striped align-middle sticky-head">
    <thead>
      <tr>
        <th>ID</th>
        <th>Job</th>
        <th>Started</th>
        <th>Finished</th>
        <th>Status</th>
        <th>Found</th>
        <th>Saved</th>
        <th>Message</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $st = (string)($r['status'] ?? ''); ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td>
            <?= h((string)$r['job_name']) ?>
            <span class="text-secondary">#<?= (int)$r['job_id'] ?></span>
          </td>
          <td class="text-secondary small"><?= h((string)$r['started_at']) ?></td>
          <td class="text-secondary small"><?= h((string)$r['finished_at']) ?></td>
          <td>
            <?php
              $badgeClass = 'bg-secondary';
              if ($st === 'ok')   $badgeClass = 'bg-success';
              elseif ($st === 'warn') $badgeClass = 'bg-warning text-dark';
              elseif ($st === 'err' || $st === 'error' || $st === 'fail') $badgeClass = 'bg-danger';
            ?>
            <span class="badge <?= $badgeClass ?>"><?= h($st) ?></span>
          </td>
          <td><?= (int)$r['files_found'] ?></td>
          <td><?= (int)$r['files_saved'] ?></td>
          <td class="small"><?= render_cell($r['message'] ?? '', 'Run message') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
// Page JS (cell viewer) with cache-busting
$cellJs  = __DIR__ . '/cell_viewer_controller.js';
$cellVer = @filemtime($cellJs) ?: time();
$extraScripts = <<<HTML
<script src="/ftp_manager/cell_viewer_controller.js?v={$cellVer}"></script>
HTML;

include __DIR__ . '/../partials/footer.php';
