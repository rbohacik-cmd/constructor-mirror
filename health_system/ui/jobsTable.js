// /health_system/ui/jobsTable.js

window.HS = window.HS || {};
HS.esc = HS.esc || (s => String(s ?? '')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;')
);

// Toggleable debug (flip at runtime with window.HS_DEBUG = true)
window.HS_DEBUG = window.HS_DEBUG ?? false;
const dlog = (...args) => { if (window.HS_DEBUG) console.log(...args); };

HS.fmtETA = (secs) => {
  const n = Number(secs);
  if (!Number.isFinite(n) || n <= 0) return '';
  const m = Math.floor(n / 60);
  if (m < 1) return '< 1m';
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60), rm = m % 60;
  return `${h}h ${rm}m`;
};

HS.statusBadge = (status, title) => {
  const s = String(status || '').toLowerCase();
  const map = {
    pending:'secondary', started:'primary', running:'primary',
    reading:'info', inserting:'primary',
    cancelling:'warning', cancelled:'warning',
    imported:'success', failed:'danger'
  };
  const cls = map[s] || 'secondary';
  const tip = title ? ` title="${HS.esc(title)}"` : '';
  return `<span class="badge text-bg-${cls} text-uppercase"${tip} aria-label="${HS.esc(status || 'pending')}">${HS.esc(status || 'pending')}</span>`;
};

HS.setBtnDisabled = (btn, disabled = true) => {
  if (!btn) return;
  if (disabled) { btn.setAttribute('disabled','disabled'); btn.classList.add('opacity-50','pe-none'); }
  else { btn.removeAttribute('disabled'); btn.classList.remove('opacity-50','pe-none'); }
};

// -------- SINGLETON + DIAGNOSTICS --------
HS._jobsInit = HS._jobsInit || false;
window.__HS_JOBS_INITS__   = window.__HS_JOBS_INITS__   || 0;
window.__HS_JOBS_RELOADS__ = window.__HS_JOBS_RELOADS__ || 0;

HS.initJobsTable = () => {
  if (HS._jobsInit) return;
  HS._jobsInit = true;
  dlog('%c[HS] initJobsTable()', 'color:#8a2be2', 'inits =', ++window.__HS_JOBS_INITS__);

  if (!document.getElementById('modal-host')) {
    const mh = document.createElement('div'); mh.id = 'modal-host'; document.body.appendChild(mh);
  }
  const tbody = document.getElementById('jobsBody');
  if (!tbody) return;

  const newBtn = document.getElementById('btnNewJob');
  if (newBtn && typeof HS.openJobModal === 'function') {
    newBtn.addEventListener('click', () => HS.openJobModal(null, reload));
  }

  const btnStopAll = document.getElementById('btnStopAll');

  // ---- State
  let pollTimer = null;
  let loading   = false;
  let firstRenderDone = false;
  let lastHtml  = '';
  let lastActive = false;
  let abortCtr  = null;

  const ACTIVE_STATUSES = ['started','running','reading','inserting','cancelling'];

  // Clean up on unload to avoid stray timers / in-flight fetches
  window.addEventListener('beforeunload', () => {
    try { abortCtr?.abort(); } catch {}
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  });

  // ---- Actions
  tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]'); if (!btn) return;
    const tr  = btn.closest('tr'); if (!tr) return;
    const id  = Number(tr.dataset.id);
    const act = btn.dataset.act;

    if (act === 'run') {
      if (btn.hasAttribute('disabled')) return;
      HS.setBtnDisabled(btn, true);
      try {
        const payload = await HS.api('jobs.run', { id });
        dlog('[jobs.run] payload:', payload);
        const runId    = payload?.run_id ?? payload?.runId;
        const uploadId = payload?.upload_id ?? payload?.uploadId;

        if (!runId) {
          console.error('jobs.run: unexpected response', payload, window.HS?.lastPayload);
          throw new Error('No run_id returned');
        }

        HS.openRunProgress?.(runId, uploadId, {
          onFinish: () => { HS.setBtnDisabled(btn, false); scheduleNext(0); }
        });
        HS.logs?.openForRun?.(runId, id);
        scheduleNext(500);
      } catch (err) {
        HS.setBtnDisabled(btn, false);
        HS.toast?.(err?.message || 'Failed to start job');
      }
    } else if (act === 'edit') {
      if (typeof HS.openJobModal === 'function') HS.openJobModal(id, reload);
    } else if (act === 'del') {
      if (!confirm('Delete this import job?')) return;
      try {
        await HS.api('jobs.delete', { id });
        scheduleNext(0);
      } catch (err) {
        HS.toast?.(err.message || 'Failed to delete');
      }
    } else if (act === 'logs') {
      HS.logs?.openForJob?.(id);
    }
  });

  // ----- Stop All / Resume toggle -----
  async function reflectStopAllButton() {
    const btn = btnStopAll; if (!btn) return;
    try {
      const st = await HS.getJSON(HS.apiUrl('control.status'));
      // support either {stop_all_at:...} or {data:{stop_all_at:...}}
      const v = (st && (st.stop_all_at ?? st.data?.stop_all_at)) || null;
      const active = !!v; // when set => stop is ACTIVE
      btn.dataset.active = active ? '1' : '0';
      btn.textContent = active ? 'Resume' : 'Stop All';
      btn.classList.toggle('btn-success', active);
      btn.classList.toggle('btn-outline-warning', !active);
      btn.title = active
        ? 'Resume imports (clear global stop)'
        : 'Send stop signal to all running imports';
    } catch (e) {
      // leave as-is on probe error
    }
  }

  if (btnStopAll) {
    btnStopAll.addEventListener('click', async () => {
      const active = btnStopAll.dataset.active === '1'; // true => currently stopped
      const prevHTML = btnStopAll.innerHTML;
      try {
        btnStopAll.innerHTML = active ? 'Resuming…' : 'Stopping…';
        HS.setBtnDisabled(btnStopAll, true);
        if (active) {
          await HS.api('imports.clear_stop', {});
          HS.toast?.('Global stop cleared. You can run imports again.');
        } else {
          await HS.api('imports.stop_all', {});
          HS.toast?.('Stop signal sent to all running imports.');
        }
        await reflectStopAllButton();
        scheduleNext(500);
      } catch (e) {
        HS.toast?.(e.message || 'Action failed');
      } finally {
        btnStopAll.innerHTML = prevHTML;
        HS.setBtnDisabled(btnStopAll, false);
      }
    });
  }

  // Visibility/focus -> do nothing until after first render
  document.addEventListener('visibilitychange', () => {
    if (!firstRenderDone) return;
    if (document.visibilityState === 'visible') scheduleNext(0);
    else { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
  });
  window.addEventListener('focus', () => { if (firstRenderDone) scheduleNext(0); });

  // --- STRICT SINGLE-SHOT FIRST LOAD ---
  (async function firstLoad() {
    await reloadOnce();             // exactly one call here
    await reflectStopAllButton();   // show Stop All / Resume correctly on first paint
    firstRenderDone = true;         // now events can trigger
    scheduleNext(getCadence());
  })();

  function getCadence(active = lastActive) {
    return active ? 2000 : 12000;
  }

  function renderRow(r) {
    // jobs.list row fields: last_status, progress_status, run_status, percent, rows_done, rows_total, eta_seconds, last_error, file_path
    const status = String(r.progress_status || r.last_status || r.run_status || 'pending').toLowerCase();
    const done   = Number(r.rows_done || 0);
    const total  = Number(r.rows_total || 0);
    const pctRaw = (typeof r.percent !== 'undefined') ? Number(r.percent) : (total ? (done/total*100) : 0);
    const pct    = Math.max(0, Math.min(100, pctRaw));
    const eta    = r.eta_seconds ? HS.fmtETA(Number(r.eta_seconds)) : '';
    const errTip = (status === 'failed' && r.last_error) ? String(r.last_error) : '';

    const bar = (total > 0 || pct > 0) ? `
      <div class="progress" style="height: 8px;" aria-hidden="false" role="progressbar"
           aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct.toFixed(0)}">
        <div class="progress-bar" style="width:${pct.toFixed(0)}%;"></div>
      </div>` : '';

    const meta = (total > 0 || pct > 0 || eta) ? `
      <div class="small text-secondary mt-1">
        ${total ? `${done}/${total} • ` : ''}${pct.toFixed(0)}%${eta ? ` • ETA ${eta}` : ''}
      </div>` : '';

    const file = String(r.file_path || '');
    const fileCell = file
      ? `<span class="text-truncate d-inline-block" style="max-width:220px" title="${HS.esc(file)}">${HS.esc(file)}</span>`
      : `<span class="text-muted">—</span>`;

    return `
      <tr data-id="${r.id}">
        <td>${r.id}</td>
        <td>${HS.esc(r.manufacturer_name || r.slug || '')}</td>
        <td>${HS.esc(r.title || '')}</td>
        <td>${fileCell}</td>
        <td style="min-width:240px" aria-live="polite">
          ${HS.statusBadge(status, errTip)}
          ${bar}
          ${meta}
        </td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" data-act="run">Run</button>
          <button class="btn btn-sm btn-outline-secondary me-1" data-act="edit">Edit</button>
          <button class="btn btn-sm btn-outline-danger me-1" data-act="del">Delete</button>
          <button class="btn btn-sm btn-outline-dark" data-act="logs">Logs</button>
        </td>
      </tr>`;
  }

  function renderEmpty() {
    return `
      <tr class="text-center text-secondary">
        <td colspan="6" class="py-4">No jobs yet. Click <strong>New Job</strong> to create one.</td>
      </tr>`;
  }

  async function reloadOnce() {
    if (loading) return;
    loading = true;
    try { abortCtr?.abort(); } catch {}
    abortCtr = new AbortController();

    try {
      dlog('%c[HS] reloadOnce()', 'color:#1e90ff', 'count =', ++window.__HS_JOBS_RELOADS__);
      const rows = await HS.api('jobs.list', {}, { signal: abortCtr.signal });

      const html = (rows && rows.length)
        ? rows.map(renderRow).join('')
        : renderEmpty();

      if (tbody.isConnected && html !== lastHtml) { tbody.innerHTML = html; lastHtml = html; }

      await prelockActiveButtons();
    } catch (e) {
      if (e?.name !== 'AbortError') console.error(e);
    } finally {
      loading = false;
    }
  }

  async function reload() {
    if (!firstRenderDone) return;
    if (loading || document.visibilityState === 'hidden') return;
    loading = true;
    try { abortCtr?.abort(); } catch {}
    abortCtr = new AbortController();

    try {
      dlog('%c[HS] reload()', 'color:#1e90ff', 'count =', ++window.__HS_JOBS_RELOADS__);
      const rows = await HS.api('jobs.list', {}, { signal: abortCtr.signal });

      const html = (rows && rows.length)
        ? rows.map(renderRow).join('')
        : renderEmpty();

      if (tbody.isConnected && html !== lastHtml) { tbody.innerHTML = html; lastHtml = html; }

      await prelockActiveButtons();

      const anyActive = (rows || []).some(r => ACTIVE_STATUSES.includes(String(
        (r.progress_status || r.last_status || r.run_status || 'pending')
      ).toLowerCase()));

      lastActive = anyActive;
      await reflectStopAllButton();   // keep Stop All / Resume in sync during polling
      scheduleNext(getCadence(anyActive));
    } catch (e) {
      if (e?.name !== 'AbortError') {
        console.error(e);
        scheduleNext(5000);
      }
    } finally {
      loading = false;
    }
  }

  function scheduleNext(ms) {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    pollTimer = setTimeout(reload, ms);
  }

  // Disable only "Run" buttons for active jobs (fast probe using import.status)
  async function prelockActiveButtons() {
    const btns = Array.from(tbody.querySelectorAll('tr[data-id] button[data-act="run"]'));
    if (!btns.length) return;

    await Promise.all(btns.map(async (btn) => {
      const tr = btn.closest('tr'); const jobId = Number(tr?.dataset.id || 0);
      if (!jobId) return;
      try {
        const url = (HS.apiUrl ? HS.apiUrl('import.status', 'job_id=' + encodeURIComponent(jobId))
                               : `/health_system/controllers/hs_logic.php?action=import.status&job_id=${jobId}`);
        const res = await HS.getJSON(url);
        const active = !!(res && (res.active ?? res.data?.active));
        HS.setBtnDisabled(btn, active);
      } catch {
        // ignore probe errors; leave button as-is
      }
    }));
  }
};

// DOM-ready handled by orchestrator in index.php; no self-init here.
