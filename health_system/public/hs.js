// /health_system/public/hs.js
(function () {
  // --- Route resolution ------------------------------------------------------
  const explicitBase = window.HS_BASE || '';
  const isPublicPath =
    (document.currentScript && document.currentScript.src.includes('/health_system/public/')) ||
    window.location.pathname.includes('/health_system/public/');

  const apiUrl = isPublicPath
    ? '../controllers/hs_logic.php'
    : (explicitBase ? (explicitBase.replace(/\/+$/, '') + '/controllers/hs_logic.php')
                    : '/health_system/controllers/hs_logic.php');

  window.HS_ROUTES = { api: apiUrl };

  // --- Namespace -------------------------------------------------------------
  const HS = (window.HS = window.HS || {});

  // --- Small helpers ---------------------------------------------------------
  function buildUrlWithAction(action, base) {
    const u = new URL(base, window.location.origin);
    u.searchParams.set('action', action);
    return u.toString();
  }

  async function parseApiResponse(res, action) {
    let payload;
    try {
      payload = await res.json();
    } catch {
      const txt = await res.text().catch(() => '');
      throw new Error(`API ${action} HTTP ${res.status} (non-JSON): ${txt}`);
    }
    if (res.ok !== true) {
      throw new Error(`API ${action} HTTP ${res.status}: ${JSON.stringify(payload)}`);
    }
    if (!payload || payload.ok !== true) {
      throw new Error(`API ${action} failed: ${JSON.stringify(payload)}`);
    }
    return payload.data ?? {};
  }

  // --- Public API helpers ----------------------------------------------------
  HS.api = async function (action, payload = {}, opts = {}) {
    const url = buildUrlWithAction(action, window.HS_ROUTES.api);
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=UTF-8',
        'Accept': 'application/json',
      },
      body: JSON.stringify(payload),
      credentials: 'omit',
      cache: 'no-store',
      ...opts,
    });
    return parseApiResponse(res, action);
  };

  HS.get = async function (action, query = {}, opts = {}) {
    const u = new URL(window.HS_ROUTES.api, window.location.origin);
    u.searchParams.set('action', action);
    for (const [k, v] of Object.entries(query || {})) {
      if (v !== undefined && v !== null) u.searchParams.set(k, String(v));
    }
    const res = await fetch(u.toString(), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'omit',
      cache: 'no-store',
      ...opts,
    });
    return parseApiResponse(res, action);
  };

  // --- Tiny toast (Bootstrap optional) ---------------------------------------
  HS.toast = function (msg, delayMs = 2200) {
    try {
      const el = document.createElement('div');
      el.className = 'toast align-items-center text-bg-dark border-0 position-fixed bottom-0 end-0 m-3';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'true');
      el.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">${String(msg)}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
      document.body.appendChild(el);
      if (window.bootstrap && window.bootstrap.Toast) {
        const t = new window.bootstrap.Toast(el, { delay: delayMs, autohide: true });
        el.addEventListener('hidden.bs.toast', () => el.remove());
        t.show();
      } else {
        el.style.opacity = '0.95';
        setTimeout(() => el.remove(), delayMs);
      }
    } catch { console.log('[HS.toast]', msg); }
  };

  // --- Global delegated handler for Clear button -----------------------------
  (function attachGlobalLogsClear() {
    if (HS.__logsClearAttached) return;
    HS.__logsClearAttached = true;

    document.addEventListener('click', async (ev) => {
      const btn = ev.target && ev.target.closest
        ? ev.target.closest('#hsLogsClear, [data-hs-logs-clear]')
        : null;
      if (!btn) return;

      ev.preventDefault();

      // Resolve scope and ids from nearest pane or button data attributes
      const pane = btn.closest ? btn.closest('#hs-logs-pane') : null;
      const scope = (pane?.dataset?.scope) || btn.dataset.scope || '';
      const runId = (pane?.dataset?.runId) || btn.dataset.runId || '';
      const jobId = (pane?.dataset?.jobId) || btn.dataset.jobId || '';

      let payload = null;
      if (scope === 'run' && runId) payload = { run_id: Number(runId) };
      else if (scope === 'job' && jobId) payload = { job_id: Number(jobId) };
      else if (runId) payload = { run_id: Number(runId) };
      else if (jobId) payload = { job_id: Number(jobId) };

      const out = pane ? pane.querySelector('#hsLogsOut') : null;

      // Provide a couple of reset cursors used by different implementations
      const resetCursor = () => {
        if (typeof window.HS__logsSince === 'number') window.HS__logsSince = 0;
        if (pane && pane.__since !== undefined) pane.__since = 0;
      };

      try {
        btn.disabled = true;
        if (payload) {
          await HS.api('runs.logs.clear', payload); // <-- ensures POST happens
        } else {
          console.warn('[HS] Clear: no run_id/job_id resolved.');
        }
        if (out) out.textContent = '';
        resetCursor();
        HS.toast?.('Logs cleared.');
        console.info('[HS] runs.logs.clear sent with', payload);
      } catch (err) {
        console.error('[HS] Clear failed:', err);
        HS.toast?.('Failed to clear logs.');
      } finally {
        btn.disabled = false;
      }
    }, true); // capture to win races with other handlers
  })();

  // --- Safe init hook (doesn’t throw if missing) -----------------------------
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof HS.initJobsTable === 'function') {
      try { HS.initJobsTable(); } catch (e) { console.error(e); }
    }
  });
})();
