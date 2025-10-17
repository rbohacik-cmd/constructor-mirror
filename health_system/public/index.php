<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap_hs.php';

$SCRIPT = $_SERVER['SCRIPT_NAME'] ?? '/health_system/public/index.php';
$BASE   = rtrim(str_replace('/public/index.php','', $SCRIPT), '/');
$PAGE_TITLE = 'Health System';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Resolve partials path robustly:
 * - /health_system/partials (current project root)
 * - /partials (server web root)
 * - one level up from project root (if health_system is nested)
 */
function hs_find_partials_dir(): string {
  $candidates = [
    realpath(__DIR__ . '/../partials'),                       // C:\xampp\htdocs\health_system\partials
    realpath($_SERVER['DOCUMENT_ROOT'] . '/partials'),        // C:\xampp\htdocs\partials
    realpath(dirname(__DIR__, 2) . '/partials'),              // one level above project root
  ];
  foreach ($candidates as $p) {
    if ($p && is_dir($p)) return $p;
  }
  return '';
}

$PARTIALS_DIR = hs_find_partials_dir();

/** Safe include helper that throws a clear error */
function hs_require_partial(string $partialsDir, string $file): void {
  $path = $partialsDir ? ($partialsDir . DIRECTORY_SEPARATOR . $file) : '';
  if (!$path || !is_file($path)) {
    // Fallback: simple minimal header/footer if partials are missing
    if ($file === 'header.php') {
      echo "<!doctype html>\n<html lang=\"en\" data-bs-theme=\"dark\"><head><meta charset=\"utf-8\">".
           "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">".
           "<title>Health System</title>".
           "<link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\">".
           "<link href=\"hs.css\" rel=\"stylesheet\"></head><body class=\"bg-body\">";
      return;
    }
    if ($file === 'footer.php') {
      echo "</body></html>";
      return;
    }
    // For any other partial, throw
    throw new \RuntimeException("Partial not found: {$file} (searched in: {$partialsDir})");
  }
  require_once $path;
}

// Use partials (or fallback)
hs_require_partial($PARTIALS_DIR, 'header.php');
?>


<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 m-0">Health System</h1>
    <div class="d-flex align-items-center gap-2">
      <!-- NEW: Reset HS controls -->
      <div class="d-flex align-items-center gap-2 me-2">
        <button id="btn-hs-reset" class="btn btn-outline-danger btn-sm">
          🔄 Reset HS
        </button>
        <div class="form-check form-check-inline mb-0">
          <input class="form-check-input" type="checkbox" id="hs-reset-dry" checked>
          <label class="form-check-label" for="hs-reset-dry" title="Preview only — no changes applied">Dry run</label>
        </div>
      </div>

      <button id="btnStopAll" class="btn btn-outline-warning">Stop All</button>
      <button id="btnNewJob" class="btn btn-primary">New Job</button>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header">Import Jobs</div>
    <div class="card-body p-0">
      <table id="jobsTable" class="table align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Manufacturer</th>
            <th>Title</th>
            <th>Source</th>
            <th>Status</th>
            <th style="width:130px">Actions</th>
          </tr>
        </thead>
        <tbody id="jobsBody"></tbody>
      </table>
    </div>
  </div>

  <!-- Optional in-page log area (kept for compatibility). Floating logs pane comes from logsPanel.js -->
  <div id="hsLogsPanel" class="card mt-3 d-none" style="max-height: 320px;">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div>
        <strong>Import logs</strong>
        <small class="text-muted ms-2" id="hsLogsTitle"></small>
      </div>
      <div>
        <button class="btn btn-sm btn-outline-secondary me-2" id="hsLogsToggleJob">Job</button>
        <button class="btn btn-sm btn-outline-secondary me-2" id="hsLogsToggleRun">Run</button>
        <button class="btn btn-sm btn-outline-secondary" id="hsLogsClear">Clear</button>
      </div>
    </div>
    <pre id="hsLogsBody" class="mb-0 p-3" style="white-space:pre-wrap; overflow:auto; height: 260px; background:#0f172a; color:#e5e7eb; border-radius:0 0 .5rem .5rem;"></pre>
  </div>
</div>

<!-- Modal host for runProgress.js -->
<div id="modal-host"></div>

<!-- Global config + page-double-run detector -->
<script defer>
(function () {
  if (window.__HS_INDEX_LOADED__) {
    console.warn('[HS] index.php script block executed twice — check duplicate <script> tags.');
  }
  window.__HS_INDEX_LOADED__ = true;

  window.HS_BASE = "<?=h($BASE)?>";
  window.HS_ROUTES = {
    base: "<?=h($BASE)?>",
    api:  "<?=h($BASE)?>/controllers/hs_logic.php"
  };
})();
</script>

<!-- HS UI modules (order matters; defer preserves order) -->
<script src="../ui/apiClient.js"    defer onload="(window.__HS_LOADED__ ??= {}).apiClient=true"   onerror="console.error('apiClient.js failed')"></script>
<script src="../ui/logsPanel.js"    defer onload="(window.__HS_LOADED__ ??= {}).logsPanel=true"    onerror="console.error('logsPanel.js failed')"></script>
<script src="../ui/runProgress.js"  defer onload="(window.__HS_LOADED__ ??= {}).runProgress=true"  onerror="console.error('runProgress.js failed')"></script>
<script src="../ui/jobModal.js"     defer onload="(window.__HS_LOADED__ ??= {}).jobModal=true"     onerror="console.error('jobModal.js failed')"></script>
<script src="../ui/filePicker.js"   defer onload="(window.__HS_LOADED__ ??= {}).filePicker=true"   onerror="console.error('filePicker.js failed')"></script>
<script src="../ui/jobsTable.js"    defer onload="(window.__HS_LOADED__ ??= {}).jobsTable=true"    onerror="console.error('jobsTable.js failed')"></script>

<!-- Orchestrator: call init exactly once -->
<script defer>
(function () {
  if (window.__HS_ORCHESTRATED__) return;
  window.__HS_ORCHESTRATED__ = true;

  function boot() {
    const dups = Array.from(document.querySelectorAll('script[src]'))
      .map(s => s.getAttribute('src'))
      .filter(Boolean)
      .reduce((acc, src) => (acc[src]=(acc[src]||0)+1, acc), {});
    Object.entries(dups).forEach(([src, n]) => { if (n>1) console.warn('[HS] Duplicate script include:', src, 'x', n); });

    if (typeof HS?.initJobsTable === 'function') {
      HS.initJobsTable();
    } else {
      console.error('[HS] HS.initJobsTable() not found. Check script load order.');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
</script>

<!-- Stop-All pulse checker -->
<script defer>
(async function(){
  try {
    const url = (window.HS_ROUTES?.api || '/health_system/controllers/hs_logic.php') + '?action=control.status';
    const r = await fetch(url, { headers: { 'Accept':'application/json' }, cache:'no-store' });
    const j = await r.json().catch(()=>({}));
    const pulse = j?.data?.stop_all_at || j?.stop_all_at;
    if (pulse) {
      console.warn('[HS] Stop-All is active since:', pulse);
      (window.HS && HS.toast) && HS.toast('Stop-All is active — click “Stop All” again to clear.');
      // Optional: gray out Run buttons right away
      document.querySelectorAll('button[data-act="run"]').forEach(b => b.setAttribute('disabled','disabled'));
    }
  } catch {}
})();
</script>

<!-- NEW: Reset HS action wiring -->
<script defer>
(() => {
  const btn = document.getElementById('btn-hs-reset');
  const dry = document.getElementById('hs-reset-dry');
  if (!btn) return;

  async function postForm(url, data) {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams(data)
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Request failed');
    return j.data;
  }

  btn.addEventListener('click', async () => {
    const isDry = !!dry?.checked;
    const msg = isDry
      ? 'Preview reset? (Dry run — no changes will be applied.)'
      : 'This will CLEAR logs and FINISH all open runs. Are you sure?';
    if (!confirm(msg)) return;

    const api = (window.HS_ROUTES?.api || '/health_system/controllers/hs_logic.php') + '?action=admin_reset';
    btn.disabled = true;
    try {
      const data = await postForm(api, { dry: isDry ? 1 : 0, scope: 'all' });
      const a = data?.affected || {};
      const text =
        (isDry ? 'Dry-run summary:\n' : 'Reset complete:\n') +
        `Runs finished: ${a.runs_finished ?? 0}\n` +
        `Progress cleared: ${a.progress_cleared ?? 0}\n` +
        `HS logs deleted: ${a.hs_logs_deleted ?? 0}\n` +
        `Sentinel logs deleted: ${a.sentinel_deleted ?? 0}`;

      if (window.HS?.toast) HS.toast(text); else alert(text);

      // Optional: soft refresh jobs table after reset
      if (window.HS?.reloadJobsTable) HS.reloadJobsTable();
    } catch (e) {
      if (window.HS?.toast) HS.toast('Reset failed: ' + e.message); else alert('Reset failed: ' + e.message);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>

<?php
/**
 * Shared footer (root/partials/footer.php)
 * Expectation: closes </body></html> or includes any site-wide footer markup.
 */
hs_require_partial($PARTIALS_DIR, 'footer.php');
