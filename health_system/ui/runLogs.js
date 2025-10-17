(function(){
  window.HS = window.HS || {};

  function ensurePanel() {
    let panel = document.getElementById('hsLogsPanel');
    if (!panel) {
      const tpl = `
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
      </div>`;
      const div = document.createElement('div');
      div.innerHTML = tpl;
      // Append just under the jobs table if present; else at end of body
      const jobsTable = document.getElementById('jobsTable') || document.body;
      jobsTable.parentElement?.appendChild(div.firstElementChild) || document.body.appendChild(div.firstElementChild);
    }
  }

  let pollTimer = null;
  let lastId = 0;
  let mode = 'run'; // 'run' or 'job'
  let current = { run_id: null, job_id: null };

  function colorFor(level){
    switch(String(level).toLowerCase()){
      case 'error': return '#fca5a5';
      case 'warn':  return '#fde68a';
      case 'info':  return '#93c5fd';
      default:      return '#a7f3d0';
    }
  }

  function line(item){
    const t  = item.ts?.replace('T',' ') || item.ts || '';
    const lv = (item.level || 'info').toUpperCase().padEnd(5,' ');
    const ph = (item.phase || '').padEnd(10,' ');
    const msg= item.message || '';
    return { text: `[${t}] ${lv} ${ph} — ${msg}`, level: item.level || 'info' };
  }

  function append(items){
    if (!items || !items.length) return;
    const pre = document.getElementById('hsLogsBody'); if (!pre) return;
    const autoscroll = Math.abs(pre.scrollHeight - pre.scrollTop - pre.clientHeight) < 8;
    const frag = document.createDocumentFragment();
    items.forEach(it => {
      const l = line(it);
      const div = document.createElement('div');
      div.textContent = l.text;
      div.style.color = colorFor(l.level);
      frag.appendChild(div);
    });
    pre.appendChild(frag);
    if (autoscroll) pre.scrollTop = pre.scrollHeight;
  }

  async function tick(){
    if (!current.run_id && !current.job_id) return;
    try {
      const payload = { limit: 500, since_id: lastId };
      if (mode === 'run') payload.run_id = current.run_id;
      if (mode === 'job') payload.job_id = current.job_id;

      const res = await HS.api('runs.logs', payload);
      const rows = res.items || (res.data && res.data.items) || [];
      append(rows);
      lastId = (res.last_id || (res.data && res.data.last_id) || lastId);
    } catch(e) {
      // ignore transient hiccups
    } finally {
      pollTimer = setTimeout(tick, 1200);
    }
  }

  function start(run_id, job_id){
    ensurePanel();
    const panel = document.getElementById('hsLogsPanel');
    const title = document.getElementById('hsLogsTitle');
    const body  = document.getElementById('hsLogsBody');
    panel.classList.remove('d-none');
    body.textContent = '';
    lastId = 0;
    current = { run_id, job_id };
    mode = 'run';
    title.textContent = `run #${run_id}${job_id ? ` (job #${job_id})` : ''} — live tail`;
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    tick();
  }

  function startForJob(job_id){
    ensurePanel();
    const panel = document.getElementById('hsLogsPanel');
    const title = document.getElementById('hsLogsTitle');
    const body  = document.getElementById('hsLogsBody');
    panel.classList.remove('d-none');
    body.textContent = '';
    lastId = 0;
    current = { run_id:null, job_id };
    mode = 'job';
    title.textContent = `job #${job_id} — live tail`;
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    tick();
  }

  function stop(){
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  }

  // public API
  HS.logs = {
    openForRun: start,
    openForJob: startForJob,
    hide(){ stop(); document.getElementById('hsLogsPanel')?.classList.add('d-none'); }
  };

  // toggle buttons inside panel
  document.addEventListener('click', (e) => {
    const t = e.target;
    if (!t) return;
    if (t.id === 'hsLogsToggleJob' && current.job_id) { mode='job'; lastId=0; document.getElementById('hsLogsBody').textContent=''; }
    if (t.id === 'hsLogsToggleRun' && current.run_id) { mode='run'; lastId=0; document.getElementById('hsLogsBody').textContent=''; }
    if (t.id === 'hsLogsClear') { document.getElementById('hsLogsBody').textContent=''; lastId=0; }
  });
})();
