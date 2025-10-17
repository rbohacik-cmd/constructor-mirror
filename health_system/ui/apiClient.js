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

  // -------- internal helpers --------
  async function parseResponse(res) {
    // Prefer JSON; on failure, fall back to raw text for debugging
    let data, text;
    try {
      data = await res.json();
    } catch {
      try { text = await res.text(); } catch { text = ''; }

      const url = res.url || '';
      const isJobsRun = /\baction=jobs\.run\b/.test(url);

      // SPECIAL: some Windows/XAMPP stacks can reply 200 with empty body for jobs.run
      if (res.ok && isJobsRun && (!text || /^\s*$/.test(text))) {
        return { __empty200: true };
      }

      if (!res.ok) {
        const msg = `HTTP ${res.status}${text ? ` — ${text.slice(0, 200)}` : ''}`;
        throw new Error(msg);
      }
      throw new Error(text || `HTTP ${res.status} (non-JSON response)`);
    }

    if (!res.ok || (data && data.ok === false)) {
      const err = (data && data.error) ? data.error : `HTTP ${res.status}`;
      HSNS.lastErrorPayload = data ?? null;
      throw new Error(err);
    }

    HSNS.lastPayload = data;
    return (data && data.data !== undefined) ? data.data : data;
  }

  function withDefaults(fetchOpts = {}) {
    const headers = {
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      ...(fetchOpts.headers || {})
    };
    return {
      cache: 'no-store',
      credentials: fetchOpts.credentials || 'same-origin',
      ...fetchOpts,
      headers
    };
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

  // -------- public helpers --------

  // HS.api(action, dataObject, fetchOpts) -> resolves to `data`
  HSNS.api = async function (action, data = {}, fetchOpts = {}) {
    const url = HSNS.apiUrl(action);
    const res = await fetch(url, {
      method: 'POST',
      body: new URLSearchParams(data),
      ...withDefaults(fetchOpts),
    });

    let parsed = await parseResponse(res);

    // Resilience for jobs.run: if empty or missing run_id, probe server for the run
    if (action === 'jobs.run' && (parsed?.__empty200 || parsed?.run_id == null)) {
      const jobId = Number(data?.id || 0);
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

  // Generic POST to arbitrary URL
  HSNS.post = async function (url, opts = {}) {
    const res = await fetch(url, { method: 'POST', ...withDefaults(opts) });
    return parseResponse(res);
  };

  // Generic GET JSON
  HSNS.getJSON = async function (url, fetchOpts = {}) {
    const res = await fetch(url, { method: 'GET', ...withDefaults(fetchOpts) });
    return parseResponse(res);
  };
})();
