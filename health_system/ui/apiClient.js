// /health_system/ui/apiClient.js  (classic script — no ESM exports)
(function () {
  const HSNS = (window.HS = window.HS || {});

  // Build controller URL
  HSNS.apiUrl = function (action, params = '') {
    const base = String(window.HS_ROUTES?.api || '/health_system/controllers/hs_logic.php');
    const qs = 'action=' + encodeURIComponent(action) + (params ? '&' + params : '');
    return `${base}?${qs}`;
  };

  // Toast helper
  HSNS.toast = HSNS.toast || function (msg) {
    if (window.Toastify) {
      Toastify({ text: msg, duration: 3000, gravity: "top", position: "right" }).showToast();
    } else {
      try {
        // tiny bootstrap toast fallback if available
        const el = document.createElement('div');
        el.className = 'toast align-items-center text-bg-dark border-0 position-fixed bottom-0 end-0 m-3';
        el.innerHTML = `<div class="d-flex"><div class="toast-body">${String(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
        document.body.appendChild(el);
        if (window.bootstrap?.Toast) { const t = new bootstrap.Toast(el, { delay: 2200, autohide: true }); el.addEventListener('hidden.bs.toast', () => el.remove()); t.show(); }
        else setTimeout(() => el.remove(), 2200);
      } catch { alert(msg); }
    }
  };

  // ---------- internal helpers ----------
  function isJsonContent(res) {
    const ct = res.headers.get('content-type') || '';
    return ct.includes('application/json');
  }

  function toErrorObject(res, data, textFallback) {
    const err = new Error(
      (data && (data.message || data.error)) ||
      textFallback ||
      `HTTP ${res.status}`
    );
    err.status = res.status;
    err.fields = (data && (data.fields || data.validation)) || null;
    err.payload = data || null;
    return err;
  }

  async function parseResponse(res) {
    if (isJsonContent(res)) {
      let data = null;
      try { data = await res.json(); }
      catch {
        if (!res.ok) throw new Error(`HTTP ${res.status} (invalid JSON)`);
        throw new Error('Invalid JSON response');
      }
      if (!res.ok || (data && data.ok === false)) {
        HSNS.lastErrorPayload = data ?? null;
        throw toErrorObject(res, data, null);
      }
      HSNS.lastPayload = data;
      return (data && data.data !== undefined) ? data.data : data;
    }

    let text = '';
    try { text = await res.text(); } catch {}
    const url = res.url || '';
    const isJobsRun = /\baction=jobs\.run\b/.test(url);
    if (res.ok && isJobsRun && (!text || /^\s*$/.test(text))) {
      return { __empty200: true };
    }
    if (!res.ok) throw new Error(`HTTP ${res.status}${text ? ` — ${String(text).slice(0, 200)}` : ''}`);
    throw new Error(text || `HTTP ${res.status} (unexpected non-JSON response)`);
  }

  function mergedHeaders(extra) {
    return { 'Accept': 'application/json', ...(extra || {}) };
  }

  function withDefaults(fetchOpts = {}) {
    return {
      cache: 'no-store',
      credentials: fetchOpts.credentials || 'same-origin',
      ...fetchOpts,
      headers: mergedHeaders(fetchOpts.headers)
    };
  }

  /**
   * Prepare request body & headers.
   * - If body is FormData: let browser set correct Content-Type.
   * - Else default to JSON body.
   * - If fetchOpts.form === true: send URL-encoded form.
   */
  function buildBodyAndHeaders(data, fetchOpts = {}) {
    if (fetchOpts.body !== undefined) {
      const headers = (fetchOpts.body instanceof FormData)
        ? mergedHeaders(fetchOpts.headers)
        : mergedHeaders({ 'Content-Type': (fetchOpts.headers && fetchOpts.headers['Content-Type']) || 'application/json; charset=UTF-8', ...fetchOpts.headers });
      return { body: fetchOpts.body, headers };
    }
    if (fetchOpts.form === true) {
      const body = new URLSearchParams(data || {});
      const headers = mergedHeaders({ 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', ...(fetchOpts.headers || {}) });
      return { body, headers };
    }
    if (data instanceof FormData) {
      const headers = mergedHeaders(fetchOpts.headers);
      return { body: data, headers };
    }
    const body = JSON.stringify(data || {});
    const headers = mergedHeaders({ 'Content-Type': 'application/json; charset=UTF-8', ...(fetchOpts.headers || {}) });
    return { body, headers };
  }

  async function probeActiveRun(jobId) {
    const statusUrl = HSNS.apiUrl('import.status', 'job_id=' + encodeURIComponent(jobId));
    try {
      const st = await HSNS.getJSON(statusUrl);
      if (st && st.active && st.run && st.run.id) return { run_id: st.run.id, upload_id: null };
    } catch {}
    return null;
  }

  async function latestRunForJob(jobId) {
    const url = HSNS.apiUrl('runs.latest_for_job', 'job_id=' + encodeURIComponent(jobId));
    try {
      const r = await HSNS.getJSON(url);
      if (r && r.run_id) return { run_id: r.run_id, upload_id: null };
    } catch {}
    return null;
  }

  // ---------- public helpers ----------
  HSNS.api = async function (action, data = {}, fetchOpts = {}) {
    const url = HSNS.apiUrl(action);
    const { body, headers } = buildBodyAndHeaders(data, fetchOpts);
    const res = await fetch(url, { method: fetchOpts.method || 'POST', body, ...withDefaults({ ...fetchOpts, headers }) });
    let parsed = await parseResponse(res);

    if (action === 'jobs.run' && (parsed?.__empty200 || parsed?.run_id == null)) {
      const jobId = Number((data && data.id) || 0);
      if (jobId > 0) {
        const p1 = await probeActiveRun(jobId); if (p1) return p1;
        const p2 = await latestRunForJob(jobId); if (p2) return p2;
        await new Promise(r => setTimeout(r, 300));
        const p3 = await latestRunForJob(jobId); if (p3) return p3;
      }
      throw new Error('No run_id returned');
    }
    return parsed;
  };

  HSNS.post = async function (url, opts = {}) {
    const { body, headers } = buildBodyAndHeaders(opts.data ?? {}, opts);
    const res = await fetch(url, { method: 'POST', body, ...withDefaults({ ...opts, headers }) });
    return parseResponse(res);
  };

  HSNS.getJSON = async function (url, fetchOpts = {}) {
    const res = await fetch(url, { method: 'GET', ...withDefaults(fetchOpts) });
    return parseResponse(res);
  };

  HSNS.apiForm = function (action, data = {}, fetchOpts = {}) {
    return HSNS.api(action, data, { ...fetchOpts, form: true });
  };

  // ---------- GLOBAL CLEAR HANDLER (works for inline + floating panes) ----------
  if (!HSNS.__logsClearAttached) {
    HSNS.__logsClearAttached = true;

    document.addEventListener('click', async (ev) => {
      const btn = ev.target?.closest?.('#hsLogsClear, [data-hs-logs-clear]');
      if (!btn) return;

      ev.preventDefault();

      // Find inline or floating logs container
      const pane = btn.closest?.('#hs-logs-pane') || btn.closest?.('#hsLogsPanel') || null;

      // Scope & IDs from pane first, then from button data-*
      const scope = pane?.dataset?.scope || btn.dataset.scope || ''; // 'run' | 'job' | ''
      const runId = pane?.dataset?.runId || btn.dataset.runId || '';
      const jobId = pane?.dataset?.jobId || btn.dataset.jobId || '';

      let payload = null;
      if (scope === 'run' && runId) payload = { run_id: Number(runId) };
      else if (scope === 'job' && jobId) payload = { job_id: Number(jobId) };
      else if (runId) payload = { run_id: Number(runId) };
      else if (jobId) payload = { job_id: Number(jobId) };

      // Output pre element (support both implementations)
      const out = pane?.querySelector?.('#hsLogsOut') || pane?.querySelector?.('#hsLogsBody') || null;

      // Reset known cursors
      const resetCursor = () => {
        if (typeof window.HS__logsSince === 'number') window.HS__logsSince = 0;
        if (pane && pane.__since !== undefined) pane.__since = 0;
      };

      try {
        btn.disabled = true;

        // Always try server clear when we have a target; otherwise just clear UI
        if (payload) {
          await HSNS.api('runs.logs.clear', payload); // <-- Ensures POST happens
        } else {
          console.warn('[HS] Clear: no run_id/job_id resolved — clearing UI only.');
        }

        if (out) out.textContent = '';
        resetCursor();
        HSNS.toast?.('Logs cleared.');
        console.info('[HS] runs.logs.clear sent with', payload || '(none)');
      } catch (err) {
        console.error('[HS] Clear failed:', err);
        HSNS.toast?.('Failed to clear logs.');
      } finally {
        btn.disabled = false;
      }
    }, true); // capture to win races with other handlers
  }

})();
