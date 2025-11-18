<?php
declare(strict_types=1);
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/../../partials/header.php';

$jobs = xfer_job_all();
$e = fn($x)=>htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">DB Transfer Manager</h4>
    <a class="btn btn-sm btn-outline-info" href="job_edit.php">New job</a>
  </div>

  <?php if (!$jobs): ?>
    <div class="alert alert-secondary">No jobs yet. Create your first transfer job.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-dark align-middle">
        <thead>
          <tr>
            <th>Title</th>
            <th>Source</th>
            <th>Destination</th>
            <th>Mode</th>
            <th>Batch</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $j): ?>
            <?php $jid = (int)$j['id']; ?>
            <tr id="job-row-<?= $jid ?>">
              <td><?= $e($j['title']) ?></td>
              <td>
                <code><?= $e($j['src_type']) ?>://<?= $e($j['src_server_key']) ?>/<?= $e($j['src_db']) ?>.<?= $e($j['src_table']) ?></code>
              </td>
              <td>
                <code><?= $e($j['dest_type']) ?>://<?= $e($j['dest_server_key']) ?>/<?= $e($j['dest_db']) ?>.<?= $e($j['dest_table']) ?></code>
              </td>
              <td><?= $e($j['mode']) ?></td>
              <td><?= (int)$j['batch_size'] ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-info" href="job_edit.php?id=<?= $jid ?>">Edit</a>
                <a class="btn btn-sm btn-outline-secondary" href="runs.php?job_id=<?= $jid ?>">Runs</a>
                <button class="btn btn-sm btn-outline-success" data-run-id="<?= $jid ?>">Run</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('button[data-run-id]');
  if (!btn) return;

  const id = btn.getAttribute('data-run-id');
  if (!id) return;

  // optional confirm
  if (!confirm('Start this transfer now?')) return;

  const prevText = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Running…';

  try {
    const r = await fetch('/transfer_job_run.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(id)
    });

    let j;
    try { j = await r.json(); } catch {
      throw new Error('Invalid JSON from API');
    }

    if (j.ok) {
      alert('Run started. Run ID: ' + j.run_id);
      // optional: refresh to surface new run in a “Runs” page
      // location.reload();
    } else {
      const msg = j.err || j.error || j.message || 'Unknown error';
      alert('Run failed: ' + msg);
    }
  } catch (err) {
    alert('Run error: ' + (err && err.message ? err.message : 'Unexpected'));
  } finally {
    btn.disabled = false;
    btn.textContent = prevText;
  }
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
