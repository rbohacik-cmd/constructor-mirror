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

// Escape + clip helpers (guards if already defined)
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('render_cell')) {
  function render_cell($v, string $col = '', int $max = 100): string {
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
    if (mb_strlen($s, 'UTF-8') > $max) {
      $snippet = mb_substr($s, 0, $max, 'UTF-8');
      return '<span class="cell-clip" data-title="'.h($col).'" data-full="'.h($s).'">'.h($snippet).'…</span>';
    }
    return h($s);
  }
}

// Optional: human-readable size
if (!function_exists('format_bytes')) {
  function format_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? (string)$bytes : number_format($bytes, 2)).' '.$units[$i];
  }
}

$limit = 1000;
$rows = qall("
  SELECT m.id, m.job_id, j.name AS job_name, m.remote_dir, m.filename,
         m.size_bytes, m.mtime, m.downloaded_at, m.checksum_md5, m.run_id
  FROM ftp_file_manifest m
  LEFT JOIN ftp_jobs j ON j.id = m.job_id
  ORDER BY m.id DESC
  LIMIT {$limit}
");

include __DIR__ . '/../partials/header.php';
// Include the modal for clipped cells
include __DIR__ . '/cell_viewer.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h3 class="m-0">FTP File Manifest</h3>
  <div class="ms-auto small text-secondary">
    Showing latest <span class="small-mono"><?= (int)$limit ?></span> files
  </div>
</div>

<div class="table-responsive scroll-x">
  <table class="table table-striped align-middle sticky-head">
    <thead>
      <tr>
        <th>ID</th>
        <th>Job</th>
        <th>Remote dir</th>
        <th>File</th>
        <th>Size</th>
        <th>Remote mtime</th>
        <th>Downloaded</th>
        <th>Run</th>
        <th>Checksum (MD5)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td>
            <?= h((string)$r['job_name']) ?>
            <span class="text-secondary">#<?= (int)$r['job_id'] ?></span>
          </td>
          <td><code class="code-wrap"><?= render_cell($r['remote_dir'] ?? '', 'Remote dir', 80) ?></code></td>
          <td><code class="code-wrap"><?= render_cell($r['filename'] ?? '', 'Filename', 80) ?></code></td>
          <td>
            <span class="small-mono" title="<?= number_format((int)($r['size_bytes'] ?? 0)) ?> bytes">
              <?= format_bytes((int)($r['size_bytes'] ?? 0)) ?>
            </span>
          </td>
          <td class="text-secondary small"><?= h((string)($r['mtime'] ?? '')) ?></td>
          <td class="text-secondary small"><?= h((string)($r['downloaded_at'] ?? '')) ?></td>
          <td class="small">
            <?php $rid = (int)($r['run_id'] ?? 0); ?>
            <?php if ($rid): ?>
              <a class="btn btn-sm btn-outline-info" href="ftp_runs.php#run-<?= $rid ?>">#<?= $rid ?></a>
            <?php else: ?>
              <span class="text-secondary small">(n/a)</span>
            <?php endif; ?>
          </td>
          <td class="small"><code class="code-wrap"><?= render_cell($r['checksum_md5'] ?? '', 'Checksum MD5', 120) ?></code></td>
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
