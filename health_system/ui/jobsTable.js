// /health_system/ui/jobsTable.js
window.HS = window.HS || {};
HS.esc = HS.esc || (s => String(s ?? '')
  .replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;')
);

// Toggleable debug
window.HS_DEBUG = window.HS_DEBUG ?? false;
const dlog = (...args) => { if (window.HS_DEBUG) console.log(...args); };

// --- helpers ---
HS.fmtETA = (secs) => {
  const n = Number(secs);
  if (!Number.isFinite(n) || n <= 0) return '';
  const m = Math.floor(n / 60);
  if (m < 1) return '< 1m';
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60), rm = m % 60;
  return `${h}h ${rm}m`;
};
HS.fmtDateTime = (s) => {
  if (!s) return '';
  try {
    const d = new Date(String(s).replace(' ', 'T'));
    if (Number.isNaN(+d)) return HS.esc(s);
    return d.toLocaleString();
  } catch { return HS.esc(s); }
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

// -------- SINGLETON --------
HS._jobsInit = HS._jobsInit || false;
window.__HS_JOBS_INITS__   = window.__HS_JOBS_INITS__   || 0;
window.__HS_JOBS_RELOADS__ = window.__HS_JOBS_RELOADS__ || 0;

HS.initJobsTable = () => {
  if (HS._jobsInit) return;
  HS._jobsInit = true;
  dlog('%c[HS] initJobsTable()', 'color:#8a2be2', 'inits =', ++window.__HS_JOBS_INITS__);

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

  window.addEventListener('beforeunload', () => {
    try { abortCtr?.abort(); } catch {}
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  });

  // Actions
  tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-act]'); if (!btn) return;
    const tr  = btn.closest('tr'); if (!tr) return;
    const id  = Number(tr.dataset.id);
    const act = btn.dataset.act;

    if (act === 'run') {
      if (btn.hasAttribute('disabled')) return;
      try {
        HS.setBtnDisabled(btn, true);
        if (typeof HS.runJobInline === 'function') {
          await HS.runJobInline(id, btn);         // inline start
          scheduleNext(500);
        } else {
          throw new Error('Inline runner not available (HS.runJobInline missing).');
        }
      } catch (err) {
        HS.toast?.(err?.message || 'Failed to start job');
        HS.setBtnDisabled(btn, false);
      }
      return;
    }

    if (act === 'edit') {
      if (typeof HS.openJobModal === 'function') HS.openJobModal(id, reload);
      return;
    }

    if (act === 'del') {
      if (!confirm('Delete this import job?')) return;
      try { await HS.api('jobs.delete', { id }); scheduleNext(0); }
      catch (err) { HS.toast?.(err.message || 'Failed to delete'); }
      return;
    }

    if (act === 'logs') {
      HS.logs?.openForJob?.(id);
      return;
    }
  });

  // ----- Stop All / Resume toggle -----
  async function reflectStopAllButton() {
    const btn = btnStopAll; if (!btn) return;
    try {
      const st = await HS.getJSON(HS.apiUrl('control.status'));
      const v = (st && (st.stop_all_at ?? st.data?.stop_all_at)) || null;
      const active = !!v;
      btn.dataset.active = active ? '1' : '0';
      btn.textContent = active ? 'Resume' : 'Stop All';
      btn.classList.toggle('btn-success', active);
      btn.classList.toggle('btn-outline-warning', !active);
      btn.title = active ? 'Resume imports (clear global stop)' : 'Send stop signal to all running imports';
    } catch {}
  }

  if (btnStopAll) {
    btnStopAll.addEventListener('click', async () => {
      const active = btnStopAll.dataset.active === '1';
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

  document.addEventListener('visibilitychange', () => {
    if (!firstRenderDone) return;
    if (document.visibilityState === 'visible') scheduleNext(0);
    else { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
  });
  window.addEventListener('focus', () => { if (firstRenderDone) scheduleNext(0); });

  // --- FIRST LOAD ---
  (async function firstLoad() {
    await reloadOnce();
    await reflectStopAllButton();
    firstRenderDone = true;
    scheduleNext(getCadence());
  })();

  function getCadence(active = lastActive) { return active ? 2000 : 12000; }

  function renderRow(r) {
    // API fields include: last_status/progress_status/run_status, percent, rows_done, rows_total,
    // eta_seconds, last_error, file_path, last_finished_at, manufacturer_name/slug, title, id …
    const status = String(r.progress_status || r.last_status || r.run_status || 'pending').toLowerCase();
    const done   = Number(r.rows_done || 0);
    const total  = Number(r.rows_total || 0);
    const pctRaw = (typeof r.percent !== 'undefined') ? Number(r.percent) : (total ? (done/total*100) : 0);
    const pct    = Math.max(0, Math.min(100, pctRaw));
    const eta    = r.eta_seconds ? HS.fmtETA(Number(r.eta_seconds)) : '';
    const errTip = (status === 'failed' && r.last_error) ? String(r.last_error) : '';

    // status column: badge only
    const statusCell = HS.statusBadge(status, errTip);

    // progress column: progress bar + small meta
    const progBar = (total > 0 || pct > 0) ? `
      <div class="progress" style="height:8px;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct.toFixed(0)}">
        <div class="progress-bar" style="width:${pct.toFixed(0)}%;"></div>
      </div>` : '';
    const progMeta = (total > 0 || pct > 0 || eta) ? `
      <div class="small text-secondary mt-1">
        ${total ? `${done}/${total} • ` : ''}${pct.toFixed(0)}%${eta ? ` • ETA ${eta}` : ''}
      </div>` : '';

    const file = String(r.file_path || '');
    const fileCell = file
      ? `<span class="text-truncate d-inline-block" style="max-width:300px" title="${HS.esc(file)}">${HS.esc(file)}</span>`
      : `<span class="text-muted">—</span>`;

    // IMPORTANT: use last_finished_at (that’s what the API returns)
    const lastFinished = r.last_finished_at || r.finished_at || '';

    return `
      <tr data-id="${r.id}">
        <td>${r.id ?? ''}</td>
        <td>${HS.esc(r.manufacturer_name || r.slug || '')}</td>
        <td>${HS.esc(r.title || '')}</td>
        <td>${fileCell}</td>
        <td>${statusCell}</td>
        <td style="min-width:260px">${progBar}${progMeta}</td>
        <td>${HS.esc(HS.fmtDateTime(lastFinished))}</td>
        <td><button class="btn btn-sm btn-outline-dark" data-act="logs">Logs</button></td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" data-act="run">Run</button>
          <button class="btn btn-sm btn-outline-secondary me-1" data-act="edit">Edit</button>
          <button class="btn btn-sm btn-outline-danger" data-act="del">Delete</button>
        </td>
      </tr>`;
  }
  HS.renderJobRow = renderRow;

  function renderEmpty() {
    return `
      <tr class="text-center text-secondary">
        <td colspan="9" class="py-4">No jobs yet. Click <strong>New Job</strong> to create one.</td>
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

      const html = (rows && rows.length) ? rows.map(renderRow).join('') : renderEmpty();

      if (tbody.isConnected && html !== lastHtml) {
        tbody.innerHTML = html;
        lastHtml = html;
        if (typeof HS.bindInlineRunButtons === 'function') HS.bindInlineRunButtons();
      }

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

      const html = (rows && rows.length) ? rows.map(renderRow).join('') : renderEmpty();

      if (tbody.isConnected && html !== lastHtml) {
        tbody.innerHTML = html;
        lastHtml = html;
        if (typeof HS.bindInlineRunButtons === 'function') HS.bindInlineRunButtons();
      }

      await prelockActiveButtons();

      const anyActive = (rows || []).some(r => ACTIVE_STATUSES.includes(String(
        (r.progress_status || r.last_status || r.run_status || 'pending')
      ).toLowerCase()));

      lastActive = anyActive;
      await reflectStopAllButton();
      scheduleNext(anyActive ? 2000 : 12000);
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

  // Disable Run buttons for jobs already active
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
      } catch {}
    }));
  }
};

// init is orchestrated by public/index.php
