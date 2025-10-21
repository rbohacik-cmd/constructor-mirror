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
      alert(msg);
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
    // Prefer JSON if content-type says so, else fall back to text
    if (isJsonContent(res)) {
      let data = null;
      try {
        data = await res.json();
      } catch {
        // Malformed JSON
        if (!res.ok) throw new Error(`HTTP ${res.status} (invalid JSON)`);
        throw new Error('Invalid JSON response');
      }

      // { ok:false, message?, error?, fields? }
      if (!res.ok || (data && data.ok === false)) {
        HSNS.lastErrorPayload = data ?? null;
        throw toErrorObject(res, data, null);
      }

      HSNS.lastPayload = data;
      return (data && data.data !== undefined) ? data.data : data;
    }

    // Non-JSON response: text fallback
    let text = '';
    try { text = await res.text(); } catch {}

    const url = res.url || '';
    const isJobsRun = /\baction=jobs\.run\b/.test(url);

    // SPECIAL: some Windows/XAMPP stacks can reply 200 with empty body for jobs.run
    if (res.ok && isJobsRun && (!text || /^\s*$/.test(text))) {
      return { __empty200: true };
    }

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}${text ? ` — ${String(text).slice(0, 200)}` : ''}`);
    }

    // Not JSON, not error — treat as error for API usage
    throw new Error(text || `HTTP ${res.status} (unexpected non-JSON response)`);
  }

  function mergedHeaders(extra) {
    return {
      'Accept': 'application/json',
      ...(extra || {})
    };
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
   * - Else default to JSON body: Content-Type: application/json; charset=UTF-8
   * - Legacy: if fetchOpts.form === true, send URLSearchParams instead.
   */
  function buildBodyAndHeaders(data, fetchOpts = {}) {
    // Allow raw body passthrough if caller already provided it.
    if (fetchOpts.body !== undefined) {
      const headers = (fetchOpts.body instanceof FormData)
        ? mergedHeaders(fetchOpts.headers) // don't set content-type for FormData
        : mergedHeaders({ 'Content-Type': (fetchOpts.headers && fetchOpts.headers['Content-Type']) || 'application/json; charset=UTF-8', ...fetchOpts.headers });
      return { body: fetchOpts.body, headers };
    }

    // Form mode: application/x-www-form-urlencoded
    if (fetchOpts.form === true) {
      const body = new URLSearchParams(data || {});
      const headers = mergedHeaders({ 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', ...(fetchOpts.headers || {}) });
      return { body, headers };
    }

    // FormData pass-through
    if (data instanceof FormData) {
      const headers = mergedHeaders(fetchOpts.headers); // no content-type
      return { body: data, headers };
    }

    // Default: JSON
    const body = JSON.stringify(data || {});
    const headers = mergedHeaders({ 'Content-Type': 'application/json; charset=UTF-8', ...(fetchOpts.headers || {}) });
    return { body, headers };
  }

  async function probeActiveRun(jobId) {
    const statusUrl = HSNS.apiUrl('import.status', 'job_id=' + encodeURIComponent(jobId));
    try {
      const st = await HSNS.getJSON(statusUrl);
      if (st && st.active && st.run && st.run.id) {
        return { run_id: st.run.id, upload_id: null };
      }
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

  /**
   * HS.api(action, dataObject, fetchOpts) -> resolves to `data`
   * - Sends JSON by default (matches backend hs_read_input).
   * - Set fetchOpts.form = true to send application/x-www-form-urlencoded.
   * - Set fetchOpts.body to override completely (raw body / FormData).
   */
  HSNS.api = async function (action, data = {}, fetchOpts = {}) {
    const url = HSNS.apiUrl(action);
    const { body, headers } = buildBodyAndHeaders(data, fetchOpts);

    const res = await fetch(url, {
      method: fetchOpts.method || 'POST',
      body,
      ...withDefaults({ ...fetchOpts, headers }),
    });

    let parsed = await parseResponse(res);

    // Resilience for jobs.run: if empty or missing run_id, probe server for the run
    if (action === 'jobs.run' && (parsed?.__empty200 || parsed?.run_id == null)) {
      const jobId = Number((data && data.id) || 0);
      if (jobId > 0) {
        // 1) active run probe
        const p1 = await probeActiveRun(jobId);
        if (p1) return p1;

        // 2) latest run probe
        const p2 = await latestRunForJob(jobId);
        if (p2) return p2;

        // 3) brief delay then final attempt (race with DB commit)
        await new Promise(r => setTimeout(r, 300));
        const p3 = await latestRunForJob(jobId);
        if (p3) return p3;
      }
      throw new Error('No run_id returned');
    }

    return parsed;
  };

  // Generic POST to arbitrary URL (JSON by default)
  HSNS.post = async function (url, opts = {}) {
    const { body, headers } = buildBodyAndHeaders(opts.data ?? {}, opts);
    const res = await fetch(url, { method: 'POST', body, ...withDefaults({ ...opts, headers }) });
    return parseResponse(res);
  };

  // Generic GET JSON
  HSNS.getJSON = async function (url, fetchOpts = {}) {
    const res = await fetch(url, { method: 'GET', ...withDefaults(fetchOpts) });
    return parseResponse(res);
  };

  /**
   * Convenience: run action with form-encoded body
   * HS.apiForm('jobs.save', data)
   */
  HSNS.apiForm = function (action, data = {}, fetchOpts = {}) {
    return HSNS.api(action, data, { ...fetchOpts, form: true });
  };

})();
