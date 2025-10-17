// jobsTable.js
import { postJson } from './apiClient.js';

const badgeFor = (status) => {
  const s = String(status || '').toLowerCase();
  if (s === 'imported' || s === 'ok') return 'success';
  if (s === 'running' || s === 'started') return 'info';
  if (s === 'queued') return 'warning';
  if (s === 'error' || s === 'failed') return 'danger';
  return 'secondary';
};

export function initJobsTable({ routes }) {
  const jobsTbody   = document.getElementById('jobs-tbody');
  const btnJobsRef  = document.getElementById('btn-jobs-refresh');
  const btnJobAdd   = document.getElementById('btn-job-add');
  const jobModalEl  = document.getElementById('jobModal');
  const jobModal    = jobModalEl && window.bootstrap ? new bootstrap.Modal(jobModalEl) : null;
  const jobId       = document.getElementById('job_id');
  const jobMan      = document.getElementById('job_manufacturer');
  const jobPath     = document.getElementById('job_file_path');
  const jobNotes    = document.getElementById('job_notes');
  const jobEnabled  = document.getElementById('job_enabled');
  const btnJobSave  = document.getElementById('btn-job-save');
  const jobTitle    = document.getElementById('jobModalTitle');
  const btnPickFile = document.getElementById('btn-pick-file');  // Browse button
  const routePicker = routes?.file_picker;

  if (!jobsTbody) return;
  const esc = (v) => (v == null ? '' : String(v)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
  );

  // prevent multiple pollers per uploadId
  const activePollers = new Map(); // uploadId -> { stop: fn }

  // ---------- small helpers ----------
  const showRowNote = (tr, text, tone = 'secondary', autoHideMs = 3500) => {
    const cell = tr.querySelector('.job-progress-cell');
    const textEl = cell?.querySelector('.job-progress-text');
    const wrap = cell?.querySelector('.progress');
    if (!cell || !textEl) return;
    if (wrap) wrap.style.display = 'none';
    textEl.textContent = text;
    textEl.classList.remove('text-secondary','text-info','text-warning','text-danger','text-success');
    textEl.classList.add(`text-${tone}`);
    if (autoHideMs > 0) {
      setTimeout(() => {
        textEl.textContent = '—';
        textEl.classList.remove(`text-${tone}`);
        textEl.classList.add('text-secondary');
      }, autoHideMs);
    }
  };

  const setRowRunning = (tr, running) => {
    tr.dataset.running = running ? '1' : '0';
    const runBtn = tr.querySelector('.job-run');
    const editBtn = tr.querySelector('.job-edit');
    const delBtn  = tr.querySelector('.job-del');
    const toggle  = tr.querySelector('.job-toggle');
    const badge   = tr.querySelector('.job-last-status');

    [editBtn, delBtn, toggle].forEach(b => { if (b) b.disabled = !!running; });

    if (badge) {
      const st = running ? 'running' : (badge.textContent || '').toLowerCase();
      badge.className = `badge text-bg-${badgeFor(st)} job-last-status`;
      badge.textContent = running ? 'running' : badge.textContent;
    }

    if (runBtn) {
      runBtn.disabled = !!running;
      runBtn.dataset.label = runBtn.dataset.label || runBtn.textContent;
      runBtn.textContent = running ? 'Running…' : runBtn.dataset.label;
    }
  };

  // ---------- Server-side File Picker Modal ----------
  function ensurePickerHost() {
    let host = document.getElementById('hc-file-picker-modal');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'hc-file-picker-modal';
    host.innerHTML = `
      <div class="modal fade" id="hcFilePicker" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Pick a file from server import root</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="row g-2 align-items-center mb-2">
                <div class="col">
                  <input type="text" class="form-control" id="hcFileFilter" placeholder="Filter by name…">
                </div>
                <div class="col-auto">
                  <div class="form-check m-0">
                    <input class="form-check-input" type="checkbox" id="hcFileShowHints" checked>
                    <label class="form-check-label small" for="hcFileShowHints">Show stock hints</label>
                  </div>
                </div>
              </div>
              <div class="list-group small" id="hcFileList" style="max-height: 50vh; overflow:auto;"></div>
            </div>
            <div class="modal-footer">
              <span class="me-auto small muted">Formats: CSV, TSV, TXT, XLSX, XLS, XLSM</span>
              <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Close</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(host);
    return host;
  }

  function renderFilePickerModal(list, queryUsed = '') {
    const host     = ensurePickerHost();
    const modalEl  = host.querySelector('#hcFilePicker');
    const listEl   = host.querySelector('#hcFileList');
    const filterEl = host.querySelector('#hcFileFilter');
    const hintsEl  = host.querySelector('#hcFileShowHints');
    const bsModal  = new bootstrap.Modal(modalEl);

    const fmtSize = (n)=>{ if(!n) return '—'; const k=1024,u=['B','KB','MB','GB']; let i=0; while(n>=k&&i<u.length-1){n/=k;i++;} return n.toFixed(1)+' '+u[i]; };
    const fmtTime = (t)=>{ try{ return new Date(t*1000).toLocaleString(); }catch{ return '—'; } };

    function draw(items){
      const showHints = !!hintsEl?.checked;
      listEl.innerHTML = items.map(f => {
        const h = f.hints || null;
        const stockBit = (showHints && h && h.has_stock)
          ? `<span class="chip ms-2">stock${h.stock_header ? `: <code class="small-mono">${esc(h.stock_header)}</code>` : ''}</span>`
          : '';
        return `
          <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                  data-rel="${esc(f.rel)}">
            <span class="cell-clip">${esc(f.rel)}</span>
            <small class="text-secondary">${fmtSize(f.size)} • ${fmtTime(f.mtime)}${stockBit ? ' ' + stockBit : ''}</small>
          </button>
        `;
      }).join('') || `<div class="text-secondary">No files found.</div>`;
    }

    draw(list);
    if (queryUsed) filterEl.value = queryUsed;

    // Client-side filter; if a server route exists, we’ll also re-query for better accuracy
    let filterTimer = null;
    filterEl.oninput = () => {
      const q = filterEl.value.toLowerCase();
      clearTimeout(filterTimer);
      // quick local redraw
      const local = list.filter(f => f.rel.toLowerCase().includes(q));
      draw(local);
      // optional server-side refine
      if (routePicker) {
        filterTimer = setTimeout(async () => {
          try {
            const j = await postJson(routePicker, { dir: '', q: filterEl.value.trim(), limit: 300, hints: !!hintsEl?.checked });
            draw((j && j.files) ? j.files : local);
          } catch { /* ignore */ }
        }, 250);
      }
    };

    if (hintsEl) {
      hintsEl.onchange = () => {
        // toggle hint visibility without refetch; keep it snappy
        draw(listEl.querySelectorAll('button[data-rel]').length ? list : list);
      };
    }

    listEl.onclick = (ev) => {
      const btn = ev.target.closest('button[data-rel]');
      if (!btn) return;
      const rel = btn.getAttribute('data-rel');
      if (jobPath) jobPath.value = rel; // relative to server import root
      bsModal.hide();
    };

    bsModal.show();
  }

  async function openServerPicker() {
    if (!routePicker) {
      // Fallback to browser picker if server picker isn't configured
      if (window.showOpenFilePicker) {
        try {
          const [h] = await window.showOpenFilePicker({ multiple:false });
          const f = await h.getFile();
          if (jobPath) jobPath.value = f.name;
        } catch {}
      } else {
        alert('Server file picker not configured; please type the path manually.');
      }
      return;
    }

    try {
      // ask server for files + hints (non-breaking on server)
      const j = await postJson(routePicker, { dir: '', limit: 300, hints: true });
      if (j && j.ok !== false) {
        renderFilePickerModal(j.files || []);
      } else {
        throw new Error(j?.error || 'Picker failed');
      }
    } catch (e) {
      console.error(e);
      alert('Could not load server file list: ' + e.message);
    }
  }

  // ---------- Jobs table ----------
  const renderRow = (r) => {
    const id   = r.id;
    const en   = Number(r.enabled) === 1;
    const stat = esc(r.last_status || 'never');
    const when = r.last_import_at ? esc(r.last_import_at) : '—';
    const bcls = badgeFor(stat);
    return `
      <tr data-id="${id}">
        <td class="text-muted">${id}</td>
        <td><strong>${esc(r.manufacturer)}</strong></td>
        <td class="text-nowrap">${esc(r.file_path)}</td>
        <td>
          <div class="form-check form-switch m-0">
            <input class="form-check-input job-toggle" type="checkbox" ${en ? 'checked' : ''}>
          </div>
        </td>
        <td><span class="badge text-bg-${bcls} job-last-status">${stat}</span></td>
        <td class="text-nowrap job-progress-cell">
          <span class="job-progress-text">—</span>
          <div class="progress mt-1" style="height:6px; max-width:180px; display:none;">
            <div class="progress-bar" style="width:0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
          </div>
        </td>
        <td class="text-nowrap">${when}</td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-success job-run" type="button">Run</button>
          <button class="btn btn-sm btn-outline-secondary job-edit ms-1" type="button">Edit</button>
          <button class="btn btn-sm btn-outline-danger job-del ms-1" type="button">Delete</button>
        </td>
      </tr>`;
  };

  // debounce to avoid reload storms
  let loadJobsTimer = null;
  function scheduleLoadJobs(delay = 0) {
    clearTimeout(loadJobsTimer);
    loadJobsTimer = setTimeout(loadJobs, delay);
  }

  async function loadJobs() {
    try {
      const j = await postJson(routes.jobs_list);
      const rows = j.rows || [];
      jobsTbody.innerHTML = rows.length
        ? rows.map(renderRow).join('')
        : `<tr><td colspan="8" class="text-center text-secondary py-4">No jobs yet.</td></tr>`;
    } catch (e) {
      console.error(e);
      jobsTbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">Failed to load jobs</td></tr>`;
    }
  }

  function openJobModal(row = null) {
    if (!jobModal) return;
    if (row) {
      jobTitle.textContent = 'Edit Job';
      jobId.value = row.id;
      if (jobMan && jobMan.tagName === 'SELECT') {
        const val = row?.manufacturer || '';
        if (val && ![...jobMan.options].some(o => o.value === val)) {
          jobMan.add(new Option(val, val));
        }
        jobMan.value = val;
      } else {
        jobMan.value = row.manufacturer || '';
      }
      jobPath.value = row.file_path || '';
      jobNotes.value = row.notes || '';
      jobEnabled.checked = Number(row.enabled) === 1;
    } else {
      jobTitle.textContent = 'Add Job';
      jobId.value = '0';
      jobMan.value = '';
      jobPath.value = '';
      jobNotes.value = '';
      jobEnabled.checked = true;
    }
    jobModal.show();
  }

  const startRowPolling = (tr, uploadId) => {
    if (!uploadId) return;
    if (activePollers.has(uploadId)) return; // already polling

    const progressCell = tr.querySelector('.job-progress-cell');
    const textEl = progressCell?.querySelector('.job-progress-text');
    const wrap = progressCell?.querySelector('.progress');
    const bar  = progressCell?.querySelector('.progress-bar');
    const lastStatusBadge = tr.querySelector('.job-last-status');
    if (!wrap || !bar || !textEl) return;
    wrap.style.display = 'block';
    setRowRunning(tr, true);

    let stopped = false;
    const tick = async () => {
      if (stopped) return;
      try {
        const j = await postJson(routes.progress, { upload_id: uploadId });
        const p = j.progress || j;

        const total = typeof p.total_rows === 'number' ? p.total_rows : (p.total ?? 0);
        const processed = typeof p.processed === 'number' ? p.processed : 0;
        const status = String(p.status || '').toLowerCase();
        const pct = total > 0
          ? Math.min(100, Math.round((processed/total)*100))
          : (status === 'imported' ? 100 : Math.min(95, Math.max(10, processed % 90)));

        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
        textEl.textContent = total > 0 ? `${processed} / ${total} (${pct}%)` : `${processed}…`;
        lastStatusBadge.className = `badge text-bg-${badgeFor(status)} job-last-status`;
        lastStatusBadge.textContent = status || '…';

        if (status === 'imported' || status === 'failed') {
          stopped = true;
          setRowRunning(tr, false);
          setTimeout(() => { wrap.style.display = 'none'; }, 600);
          activePollers.delete(uploadId);
          scheduleLoadJobs(300); // brief delay to let server finalize timestamps
          return;
        }
      } catch {
        // ignore transient errors
      }
      setTimeout(tick, 900);
    };

    tick();
    activePollers.set(uploadId, { stop: () => { stopped = true; setRowRunning(tr, false); } });
  };

  // ---------- Events ----------
  if (btnJobAdd) btnJobAdd.addEventListener('click', () => openJobModal(null));
  if (btnJobsRef) btnJobsRef.addEventListener('click', () => loadJobs());

  if (btnJobSave) {
    btnJobSave.addEventListener('click', async () => {
      const id = Number(jobId.value || 0);
      const body = {
        id,
        manufacturer: jobMan.value.trim(),
        file_path: jobPath.value.trim(),
        enabled: jobEnabled.checked ? 1 : 0,
        notes: jobNotes.value.trim() || null,
      };
      if (!body.manufacturer || !body.file_path) return alert('Manufacturer and file path are required.');
      try {
        await postJson(routes.job_save, body);
        jobModal.hide();
        loadJobs();
      } catch (e) {
        alert('Save failed: ' + e.message);
      }
    });
  }

  if (btnPickFile) {
    btnPickFile.addEventListener('click', openServerPicker);
  }

  jobsTbody.addEventListener('click', async (ev) => {
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    const id = Number(tr.dataset.id);

    if (ev.target.classList.contains('job-edit')) {
      try {
        const j = await postJson(routes.job_get, { id });
        openJobModal(j.row);
      } catch (e) {
        alert('Load failed: ' + e.message);
      }
      return;
    }

    if (ev.target.classList.contains('job-del')) {
      if (!confirm('Delete this job?')) return;
      try { await postJson(routes.job_delete, { id }); loadJobs(); }
      catch (e) { alert('Delete failed: ' + e.message); }
      return;
    }

    if (ev.target.classList.contains('job-run')) {
      const btn = ev.target;
      if (tr.dataset.running === '1') return; // client-side guard
      setRowRunning(tr, true);

      try {
        const r = await postJson(routes.job_run, { id });
        const uploadId = r.upload_id;
        startRowPolling(tr, uploadId);
        // fire-and-forget import stage
        postJson(routes.import, { upload_id: uploadId, run_id: r.run_id }).catch(()=>{});
      } catch (e) {
        if (e?.status === 409) {
          // Another import already running for this manufacturer
          showRowNote(tr, e.message || 'Already running on server', 'warning');
          // Keep the row non-running (we didn't start it here)
          setRowRunning(tr, false);
          scheduleLoadJobs(250);
        } else {
          alert('Run failed: ' + (e?.message || 'Unknown error'));
          setRowRunning(tr, false);
        }
      }
      return;
    }
  });

  jobsTbody.addEventListener('change', async (ev) => {
    if (!ev.target.classList.contains('job-toggle')) return;
    const tr = ev.target.closest('tr[data-id]');
    const id = Number(tr.dataset.id);
    const enabled = ev.target.checked ? 1 : 0;
    try { await postJson(routes.job_save, { id, enabled }); }
    catch (e) { alert('Toggle failed: ' + e.message); ev.target.checked = !ev.target.checked; }
  });

  loadJobs();
}
