// /health_system/ui/logsPanel.js
(function () {
  const HSNS = (window.HS = window.HS || {});
  let pane, timer = null, since = 0;

  function ensurePane() {
    if (pane && document.body.contains(pane)) return pane;
    pane = document.createElement('div');
    pane.id = 'hs-logs-pane';
    pane.style.cssText = 'position:fixed; right:12px; bottom:12px; width:520px; max-height:40vh; z-index:1040;';
    pane.innerHTML = `
      <div class="card shadow-lg">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong id="hsLogsTitle">Logs</strong>
          <div>
            <button id="hsLogsClear" class="btn btn-sm btn-outline-secondary me-1">Clear</button>
            <button id="hsLogsClose" class="btn btn-sm btn-outline-dark">Close</button>
          </div>
        </div>
        <div class="card-body p-2">
          <pre id="hsLogsOut" style="white-space:pre-wrap; background:#111; color:#ddd; padding:8px; border-radius:6px; max-height:30vh; overflow:auto; margin:0;"></pre>
        </div>
      </div>`;
    document.body.appendChild(pane);

    // Close kills the polling timer
    document.getElementById('hsLogsClose').onclick = () => {
      if (timer) { clearInterval(timer); timer = null; }
      pane.remove();
    };
    return pane;
  }

  function tail(params, title) {
    ensurePane();
    const out  = document.getElementById('hsLogsOut');
    const head = document.getElementById('hsLogsTitle');
    head.textContent = title || (params.run_id ? `Run #${params.run_id}` : `Job #${params.job_id}`);

    // Remember current scope for CLEAR
    pane.dataset.scope = params.run_id ? 'run' : 'job';
    pane.dataset.runId = params.run_id || '';
    pane.dataset.jobId = params.job_id || '';

    // Fresh cursor; also stop any previous poller to avoid duplicates
    since = 0;
    if (timer) { clearInterval(timer); timer = null; }

    // Wire CLEAR → POST runs.logs.clear + local reset
    const btnClear = document.getElementById('hsLogsClear');
    btnClear.onclick = async (e) => {
      e.preventDefault();
      try {
        btnClear.disabled = true;

        const payload =
          (pane.dataset.scope === 'run' && pane.dataset.runId)
            ? { run_id: Number(pane.dataset.runId) }
            : (pane.dataset.jobId ? { job_id: Number(pane.dataset.jobId) } : null);

        if (payload) {
          // NOTE: HSNS.api must POST JSON to controllers/hs_logic.php?action=runs.logs.clear
          await HSNS.api('runs.logs.clear', payload);
        }

        // Local reset
        out.textContent = '';
        since = 0;
        HSNS.toast?.('Logs cleared.');
      } catch (err) {
        console.error(err);
        HSNS.toast?.('Failed to clear logs.');
      } finally {
        btnClear.disabled = false;
      }
    };

    // Start polling loop (uses same API style you already use for runs.logs)
    timer = setInterval(async () => {
      try {
        const data = await HSNS.api('runs.logs', { ...params, since_id: since, limit: 500 });
        const items = data.items || [];
        if (items.length) {
          for (const row of items) {
            since = row.id;
            const ts  = row.ts ? `[${row.ts}] ` : '';
            const lvl = row.level ? row.level.toUpperCase() : '';
            const ph  = row.phase ? ` (${row.phase})` : '';
            out.textContent += `${ts}${lvl}${ph}: ${row.message}\n`;
          }
          out.scrollTop = out.scrollHeight;
        }
      } catch {
        // keep trying silently
      }

      // Safety: auto-stop if pane was removed
      if (!document.body.contains(pane)) {
        clearInterval(timer);
        timer = null;
      }
    }, 1000);
  }

  HSNS.logs = HSNS.logs || {};
  HSNS.logs.openForRun = (runId) => tail({ run_id: runId }, `Run #${runId}`);
  HSNS.logs.openForJob = (jobId) => tail({ job_id: jobId }, `Job #${jobId}`);
})();
