// /health_system/ui/logsPanel.js
(function () {
  const HSNS = (window.HS = window.HS || {});
  let pane;

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
    document.getElementById('hsLogsClose').onclick = () => pane.remove();
    document.getElementById('hsLogsClear').onclick = () => { document.getElementById('hsLogsOut').textContent = ''; };
    return pane;
  }

  function tail(params, title) {
    ensurePane();
    const out = document.getElementById('hsLogsOut');
    const head = document.getElementById('hsLogsTitle');
    head.textContent = title || (params.run_id ? `Run #${params.run_id}` : `Job #${params.job_id}`);

    let since = 0;
    const timer = setInterval(async () => {
      try {
        const data = await HS.api('runs.logs', { ...params, since_id: since, limit: 500 });
        for (const row of (data.items || [])) {
          since = row.id;
          const ts = row.ts ? `[${row.ts}] ` : '';
          const lvl = row.level ? row.level.toUpperCase() : '';
          const ph = row.phase ? ` (${row.phase})` : '';
          out.textContent += `${ts}${lvl}${ph}: ${row.message}\n`;
          out.scrollTop = out.scrollHeight;
        }
      } catch { /* keep trying */ }
      if (!document.body.contains(pane)) clearInterval(timer);
    }, 1000);
  }

  HSNS.logs = HSNS.logs || {};
  HSNS.logs.openForRun = (runId/*, jobId*/) => tail({ run_id: runId }, `Run #${runId}`);
  HSNS.logs.openForJob = (jobId) => tail({ job_id: jobId }, `Job #${jobId}`);
})();
