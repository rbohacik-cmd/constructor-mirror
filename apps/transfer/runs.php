<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../partials/header.php';

$e = fn($x)=>htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

function fmtDuration(?string $start, ?string $end): string {
  if (!$start) return 'â€”';
  $t1 = strtotime($start);
  $t2 = $end ? strtotime($end) : time();
  if (!$t1 || !$t2) return 'â€”';
  $sec = max(0, $t2 - $t1);
  $h = intdiv($sec, 3600);
  $m = intdiv($sec % 3600, 60);
  $s = $sec % 60;
  if ($h) return sprintf('%dh %dm %ds', $h, $m, $s);
  if ($m) return sprintf('%dm %ds', $m, $s);
  return sprintf('%ds', $s);
}

function statusClass(string $st): string {
  return match ($st) {
    'ok','done','success'   => 'bg-success',
    'error','failed'        => 'bg-danger',
    'running','started'     => 'bg-warning text-dark',
    'stopping'              => 'bg-warning text-dark',
    'stopped'               => 'bg-secondary',
    default                 => 'bg-secondary',
  };
}

$job_id = (int)($_GET['job_id'] ?? ($_GET['id'] ?? 0));   // prefer job_id
$run_id = (int)($_GET['run'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? ''));
// include more statuses in filter
$allowedStatuses = ['ok','error','running','stopping','stopped'];
$statusFilter = in_array($statusFilter, $allowedStatuses, true) ? $statusFilter : '';

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 200;
$offset = ($page - 1) * $limit;

// If we got a run_id but no job_id, resolve the job from the run
if (!$job_id && $run_id) {
  $r = qrow("SELECT job_id FROM xfer_runs_log WHERE id = ?", [$run_id]);
  if ($r) $job_id = (int)$r['job_id'];
}

$job  = $job_id ? xfer_job_get($job_id) : null;
$runs = [];
$total = 0;

if ($job) {
  // Mode A: show runs for a specific job
  $title = 'Runs â€” ' . $e($job['title']);
  $where = "WHERE r.job_id = ?";
  $args  = [$job_id];

  if ($statusFilter) {
    $where .= " AND r.status = ?";
    $args[] = $statusFilter;
  }

  $total = (int)qcell("SELECT COUNT(*) FROM xfer_runs_log r $where", $args);
  $runs  = qall("
    SELECT r.*, j.title
    FROM xfer_runs_log r
    LEFT JOIN xfer_jobs j ON j.id = r.job_id
    $where
    ORDER BY r.id DESC
    LIMIT $limit OFFSET $offset
  ", $args);

} else {
  // Mode B: no job â†’ show latest runs across all jobs
  $title = 'All runs (latest)';
  $where = "WHERE 1=1";
  $args = [];

  if ($statusFilter) {
    $where .= " AND r.status = ?";
    $args[] = $statusFilter;
  }

  $total = (int)qcell("SELECT COUNT(*) FROM xfer_runs_log r $where", $args);
  $runs  = qall("
    SELECT r.*, j.title
    FROM xfer_runs_log r
    LEFT JOIN xfer_jobs j ON j.id = r.job_id
    $where
    ORDER BY r.id DESC
    LIMIT $limit OFFSET $offset
  ", $args);
}

$pages = max(1, (int)ceil($total / $limit));
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><?= $title ?></h4>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="index.php">Back to jobs</a>
      <a class="btn btn-sm btn-outline-info" href="runs.php">All runs</a>
      <?php if ($job): ?>
        <button class="btn btn-sm btn-outline-info" id="btnRefresh">Refresh</button>
        <button class="btn btn-sm btn-outline-success" id="btnRunNow">Run now</button>
      <?php endif; ?>
    </div>
  </div>

  <form method="get" class="row g-2 align-items-end mb-3">
    <?php if ($job): ?>
      <input type="hidden" name="job_id" value="<?= (int)$job_id ?>">
    <?php endif; ?>
    <div class="col-auto">
      <label class="form-label mb-0 small">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">(any)</option>
        <?php foreach ($allowedStatuses as $opt): ?>
          <option value="<?= $opt ?>" <?= $statusFilter===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label mb-0 small">Page</label>
      <input type="number" min="1" name="page" value="<?= (int)$page ?>" class="form-control form-control-sm" style="width:6rem">
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary">Apply</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= $job ? 'runs.php?job_id='.(int)$job_id : 'runs.php' ?>">Reset</a>
    </div>
  </form>

  <?php if (empty($runs)): ?>
    <div class="alert alert-secondary">No runs found.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark align-middle">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <?php if (!$job): ?><th>Job</th><?php endif; ?>
            <th>Started</th>
            <th>Finished</th>
            <th>Duration</th>
            <th>Status</th>
            <th>Action</th>
            <th class="text-end">Read</th>
            <th class="text-end">Written</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($runs as $r): ?>
            <?php
              $st   = (string)($r['status'] ?? '');
              $cls  = statusClass($st);
              $sid  = (int)$r['id'];
              $sAt  = (string)($r['started_at']  ?? '');
              $fAt  = (string)($r['finished_at'] ?? '');
              $dur  = fmtDuration($sAt, $fAt);
              $msg  = (string)($r['message'] ?? '');
            ?>
            <tr>
              <td><?= $sid ?></td>
              <?php if (!$job): ?>
                <td>
                  <?php if (!empty($r['job_id'])): ?>
                    <a href="runs.php?job_id=<?= (int)$r['job_id'] ?>"><?= $e($r['title'] ?? ('Job #'.(int)$r['job_id'])) ?></a>
                  <?php else: ?>
                    <span class="text-secondary">â€”</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td title="<?= $e($sAt) ?>"><?= $e($sAt ?: 'â€”') ?></td>
              <td title="<?= $e($fAt) ?>"><?= $e($fAt ?: 'â€”') ?></td>
              <td><?= $e($dur) ?></td>
              <td><span class="badge <?= $cls ?>"><?= $e($st ?: 'â€”') ?></span></td>

              <td>
                <?php if ($st === 'running'): ?>
                  <button class="btn btn-sm btn-outline-danger btnStopRun" data-run-id="<?= $sid ?>">Stop</button>
                <?php else: ?>
                  <span class="text-secondary">â€”</span>
                <?php endif; ?>
              </td>

              <td class="text-end"><?= number_format((int)($r['rows_read'] ?? 0)) ?></td>
              <td class="text-end"><?= number_format((int)($r['rows_written'] ?? 0)) ?></td>
              <td>
                <?php if ($msg !== ''): ?>
                  <code class="small" title="<?= $e($msg) ?>"><?= $e(mb_strimwidth($msg, 0, 160, 'â€¦')) ?></code>
                <?php else: ?>
                  <span class="text-secondary">â€”</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm">
          <?php
            $mk = function(int $p) use ($job_id,$statusFilter) {
              $q = [];
              if ($job_id) $q['job_id'] = $job_id;
              if ($statusFilter) $q['status'] = $statusFilter;
              $q['page'] = $p;
              return 'runs.php?' . http_build_query($q);
            };
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= $mk(max(1,$page-1)) ?>">Prev</a>
          </li>
          <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="<?= $mk(min($pages,$page+1)) ?>">Next</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  const jobId = <?= (int)$job_id ?>;
  const btnRefresh = document.getElementById('btnRefresh');
  const btnRunNow  = document.getElementById('btnRunNow');

  btnRefresh?.addEventListener('click', ()=>{
    const u = new URL(location.href);
    if (jobId) u.searchParams.set('job_id', String(jobId));
    u.searchParams.delete('id'); u.searchParams.delete('run'); u.searchParams.delete('page');
    location.href = u.toString();
  });

  btnRunNow?.addEventListener('click', async ()=>{
    btnRunNow.disabled = true; btnRunNow.textContent = 'Startingâ€¦';
    try{
      const r = await fetch('/transfer_job_run.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'id='+encodeURIComponent(jobId)
      });
      const text = await r.text();
      let j;
      try { j = JSON.parse(text); }
      catch(parseErr){ throw new Error('Bad response: ' + text.slice(0,200)); }

      if (j && j.ok) {
        alert('Run started. Run ID: '+(j.run_id ?? '(unknown)'));
        const u = new URL(location.href);
        u.searchParams.set('job_id', String(jobId));
        u.searchParams.delete('page');
        location.href = u.toString();
      } else {
        throw new Error((j && (j.error || j.message)) || 'Failed to start run');
      }
    } catch(err){
      alert('Run failed: ' + (err?.message || 'unknown error'));
    } finally {
      btnRunNow.disabled = false; btnRunNow.textContent = 'Run now';
    }
  });

  // Stop buttons
  document.addEventListener('click', async (ev)=>{
    const btn = ev.target.closest('.btnStopRun');
    if (!btn) return;

    const runId = btn.getAttribute('data-run-id');
    if (!runId) return;

    if (!confirm('Stop this run?')) return;

    btn.disabled = true; const old = btn.textContent; btn.textContent = 'Stoppingâ€¦';
    try {
      const r = await fetch('/api/transfer_run_stop.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'run_id=' + encodeURIComponent(runId)
      });
      const text = await r.text();
      let j; try { j = JSON.parse(text); } catch(e){ throw new Error('Bad response: ' + text.slice(0,200)); }

      if (!j.ok) throw new Error(j.error || 'Stop failed');
      // Reload to reflect status=stopping (and shortly 'stopped' once runner catches it)
      location.reload();
    } catch(err){
      alert('Failed to stop: ' + (err?.message || 'unknown error'));
      btn.disabled = false; btn.textContent = old;
    }
  });
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>

