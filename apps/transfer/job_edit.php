<?php
declare(strict_types=1);
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/../../partials/header.php';

$id   = (int)($_GET['id'] ?? 0);
$job  = $id ? xfer_job_get($id) : null;
$e    = fn($x)=>htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$src_cols = $job ? json_decode((string)$job['src_cols_json'], true) : [];
$col_map  = $job ? json_decode((string)$job['column_map_json'], true) : [];
$map_lines = '';
if ($col_map) {
  if (array_is_list($col_map)) {
    foreach ($col_map as $m) { $map_lines .= ($m['from']??'').':'.($m['to']??'')."\n"; }
  } else {
    foreach ($col_map as $from=>$to) { $map_lines .= "$from:$to\n"; }
  }
}
$src_cols_line = $src_cols ? implode(',', $src_cols) : '';
?>
<style>
.small-note{opacity:.8}
.flex-gap{display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;}
.tag{display:inline-block; padding:.1rem .4rem; border-radius:.3rem; background:#1f2432; font-size:.75rem;}
pre code{white-space:pre-wrap}
</style>

<div class="container py-3">
  <h4 class="mb-3"><?= $id ? 'Edit job' : 'New job' ?></h4>

  <form id="jobForm" class="row g-3" onsubmit="return saveJob(event)">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="col-12">
      <label class="form-label">Title</label>
      <input class="form-control" name="title" required value="<?= $e($job['title'] ?? '') ?>">
    </div>

    <!-- SOURCE -->
    <div class="col-12 col-xxl-6">
      <div class="card p-3 h-100">
        <div class="flex-gap mb-1">
          <h6 class="mb-0">Source</h6>
          <span class="tag">read</span>
        </div>

        <div class="row g-2">
          <div class="col-4">
            <label class="form-label small">Type</label>
            <select class="form-select form-select-sm" name="src_type" id="src_type">
              <?php foreach (['mssql','mysql'] as $t): ?>
                <option value="<?= $t ?>" <?= (($job['src_type']??'mysql')===$t?'selected':'') ?>><?= strtoupper($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-8">
            <label class="form-label small">Server</label>
            <select class="form-select form-select-sm" name="src_server_key" id="src_server_key"></select>
          </div>

          <div class="col-6">
            <label class="form-label small">Database</label>
            <div class="input-group input-group-sm">
              <select class="form-select" name="src_db" id="src_db"></select>
              <button class="btn btn-outline-info" type="button" id="src_refresh_db">↻</button>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label small">Table</label>
            <div class="input-group input-group-sm">
              <select class="form-select" name="src_table" id="src_table"></select>
              <button class="btn btn-outline-info" type="button" id="src_refresh_tbl">↻</button>
              <button class="btn btn-outline-secondary" type="button" id="copy_src_to_dest" title="Use as destination table">→</button>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label small">Columns (multi-select; empty = all)</label>
            <select class="form-select form-select-sm" multiple size="6" id="src_cols_multi"></select>
            <div class="small text-secondary small-note mt-1">Hold Ctrl/Cmd to pick multiple. Use “Load columns” if empty.</div>
            <div class="mt-2 flex-gap">
              <button class="btn btn-sm btn-outline-info" type="button" id="btn_src_load_cols">Load columns</button>
              <button class="btn btn-sm btn-outline-secondary" type="button" id="btn_src_clear_cols">Clear</button>
              <button class="btn btn-sm btn-outline-secondary" type="button" id="btn_src_all_cols">Select all</button>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label small">WHERE (optional, raw)</label>
            <input class="form-control form-control-sm" name="where_clause" id="where_clause" value="<?= $e($job['where_clause'] ?? '') ?>" placeholder="e.g. created_at >= '2024-01-01'">
          </div>
        </div>
      </div>
    </div>

    <!-- DESTINATION -->
    <div class="col-12 col-xxl-6">
      <div class="card p-3 h-100">
        <div class="flex-gap mb-1">
          <h6 class="mb-0">Destination</h6>
          <span class="tag">write</span>
        </div>

        <div class="row g-2">
          <div class="col-4">
            <label class="form-label small">Type</label>
            <select class="form-select form-select-sm" name="dest_type" id="dest_type">
              <?php foreach (['mssql','mysql'] as $t): ?>
                <option value="<?= $t ?>" <?= (($job['dest_type']??'mysql')===$t?'selected':'') ?>><?= strtoupper($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-8">
            <label class="form-label small">Server</label>
            <select class="form-select form-select-sm" name="dest_server_key" id="dest_server_key"></select>
          </div>

          <div class="col-8">
            <label class="form-label small">Database</label>
            <div class="input-group input-group-sm">
              <select class="form-select" name="dest_db" id="dest_db"></select>
              <button class="btn btn-outline-info" type="button" id="dest_refresh_db">↻</button>
            </div>
          </div>

          <div class="col-4">
            <label class="form-label small">Table</label>
            <div class="input-group input-group-sm">
              <input class="form-control" name="dest_table" id="dest_table" value="<?= $e($job['dest_table'] ?? '') ?>" placeholder="target table">
            </div>
            <div class="mt-2 d-grid">
              <button class="btn btn-sm btn-outline-success" type="button" id="dest_create_table_from_src">
                Create table from source
              </button>
            </div>
          </div>

          <div class="col-6">
            <label class="form-label small">Mode</label>
            <select class="form-select form-select-sm" name="mode" id="mode">
              <?php foreach (['insert','truncate_insert'] as $m): ?>
                <option value="<?= $m ?>" <?= (($job['mode']??'insert')===$m?'selected':'') ?>><?= $m ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small">Batch size</label>
            <input class="form-control form-control-sm" type="number" min="1" name="batch_size" id="batch_size" value="<?= (int)($job['batch_size'] ?? 1000) ?>">
          </div>

          <div class="col-12">
            <label class="form-label small d-flex justify-content-between align-items-center">
              <span>Column map (one per line, FROM:TO)</span>
              <span class="flex-gap">
                <button class="btn btn-sm btn-outline-info" type="button" id="btn_auto_map">Auto map by name</button>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="btn_clear_map">Clear</button>
              </span>
            </label>
            <textarea class="form-control form-control-sm" name="column_map" id="column_map" rows="8" placeholder="id:id
name:full_name
created_at:created_at"><?= $e($map_lines) ?></textarea>
            <div class="small text-secondary small-note mt-1">Tip: build mapping after loading source columns.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-info" id="saveBtn">Save job</button>
      <?php if ($id): ?>
        <button class="btn btn-outline-success" type="button" id="runNowBtn" onclick="runSavedJob(<?= $id ?>)">Run now</button>
        <a class="btn btn-outline-secondary" href="runs.php?job_id=<?= $id ?>">View runs</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- DDL Preview Modal -->
<div class="modal fade" id="ddlModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header">
        <h6 class="modal-title">Create table — preview</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="mb-0"><code id="ddlPreview"></code></pre>
      </div>
      <div class="modal-footer">
        <span class="text-secondary me-auto small" id="ddlNotes"></span>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="ddlCreateBtn">Create table</button>
      </div>
    </div>
  </div>
</div>

<script>
const $ = (sel)=>document.querySelector(sel);
const $$= (sel)=>document.querySelectorAll(sel);

async function fetchJSON(url, opts) {
  const res  = await fetch(url, opts);
  const text = await res.text();

  let data;
  try { data = text ? JSON.parse(text) : null; }
  catch {
    const snippet = text.slice(0, 1000);
    throw new Error(`Non-JSON response (${res.status})\nURL: ${url}\n---\n${snippet}`);
  }

  if (!res.ok || (data && data.ok === false)) {
    const err = (data && (data.error || data.message)) || `HTTP ${res.status}`;
    const dump = text.slice(0, 1000);
    throw new Error(`${err}\n---\n${dump}`);
  }
  return data;
}

function opt(el, v, t, selected=false){ const o=document.createElement('option'); o.value=v; o.textContent=t; if(selected) o.selected=true; el.appendChild(o); }
function clear(el){ while(el.firstChild) el.removeChild(el.firstChild); }

async function loadServers() {
  const j = await fetchJSON('/api/xfer_list_servers.php');
  if(!j.ok) return alert('Failed to load servers');
  const srcType = $('#src_type').value;
  const dstType = $('#dest_type').value;
  const srcSel  = $('#src_server_key');
  const dstSel  = $('#dest_server_key');
  clear(srcSel); clear(dstSel);

  const srcWanted = j.servers.filter(s=>s.type===srcType);
  const dstWanted = j.servers.filter(s=>s.type===dstType);

  const savedSrc = '<?= $e($job['src_server_key'] ?? '') ?>';
  const savedDst = '<?= $e($job['dest_server_key'] ?? '') ?>';

  srcWanted.forEach(s=>opt(srcSel, s.key, `${s.title} (${s.key})`, s.key===savedSrc));
  dstWanted.forEach(s=>opt(dstSel, s.key, `${s.title} (${s.key})`, s.key===savedDst));

  if(!srcSel.value && srcSel.options.length) srcSel.selectedIndex = 0;
  if(!dstSel.value && dstSel.options.length) dstSel.selectedIndex = 0;

  await Promise.all([loadDatabases('src'), loadDatabases('dest')]);
}

async function loadDatabases(role){
  const type   = $('#'+role+'_type').value;
  const server = $('#'+role+'_server_key').value;
  if(!server) return;

  const j = await fetchJSON(`/api/xfer_list_databases.php?type=${encodeURIComponent(type)}&server=${encodeURIComponent(server)}`);
  if(!j.ok) return alert('DB list failed: '+(j.error||'')); 
  const sel = $('#'+role+'_db');
  clear(sel);
  const savedSrc = '<?= $e($job['src_db'] ?? '') ?>';
  const savedDst = '<?= $e($job['dest_db'] ?? '') ?>';
  const savedDb = role==='src' ? savedSrc : savedDst;

  j.databases.forEach(d=>opt(sel, d, d, d===savedDb));
  if(!sel.value && sel.options.length) sel.selectedIndex = 0;

  if(role === 'src'){ await loadTables('src'); }
}

async function loadTables(role){
  const type   = $('#'+role+'_type').value;
  const server = $('#'+role+'_server_key').value;
  const db     = $('#'+role+'_db').value;
  if(!server || !db) return;

  const j = await fetchJSON(`/api/xfer_list_tables.php?type=${encodeURIComponent(type)}&server=${encodeURIComponent(server)}&db=${encodeURIComponent(db)}`);
  if(!j.ok) return alert('Tables load failed: '+(j.error||'')); 
  const sel = $('#'+role+'_table');
  clear(sel);

  const savedSrcTable = '<?= $e($job['src_table'] ?? '') ?>';
  j.tables.forEach(t=>opt(sel, t, t, (role==='src' ? (t===savedSrcTable) : false)));
  if(!sel.value && sel.options.length) sel.selectedIndex = 0;
}

async function loadColumnsFromSource(){
  const type = $('#src_type').value;
  const server = $('#src_server_key').value;
  const db = $('#src_db').value;
  const table = $('#src_table').value;
  if(!server || !db || !table) return alert('Pick source server/db/table first');

  const j = await fetchJSON(`/api/xfer_list_columns.php?type=${encodeURIComponent(type)}&server=${encodeURIComponent(server)}&db=${encodeURIComponent(db)}&table=${encodeURIComponent(table)}`);
  if(!j.ok) return alert('Columns load failed: '+(j.error||'')); 

  const sel = $('#src_cols_multi');
  clear(sel);
  const savedCols = <?= json_encode($src_cols) ?>;
  j.columns.forEach(c=>opt(sel, c, c, Array.isArray(savedCols) && savedCols.includes(c)));

  // If no mapping yet, propose identity mapping
  const mapBox = $('#column_map');
  if (!mapBox.value.trim() && j.columns.length) {
    mapBox.value = j.columns.map(c => `${c}:${c}`).join('\n');
  }
}

function multiSelectGet(sel){ return Array.from(sel.selectedOptions).map(o=>o.value); }
function multiSelectSetAll(sel){ Array.from(sel.options).forEach(o=>o.selected=true); }
function multiSelectClear(sel){ Array.from(sel.options).forEach(o=>o.selected=false); }

function autoMapByName(){
  const sel = $('#src_cols_multi');
  const cols = Array.from(sel.options).map(o=>o.value);
  if(cols.length===0) return alert('Load/select source columns first.');
  $('#column_map').value = cols.map(c=>`${c}:${c}`).join('\n');
}
function clearMap(){ $('#column_map').value = ''; }

function parseMap(s){
  const m = {};
  s.split(/\r?\n/).forEach(line=>{
    line = line.trim();
    if(!line) return;
    const i = line.indexOf(':');
    if (i === -1) return;
    const from = line.slice(0,i).trim();
    const to   = line.slice(i+1).trim();
    if (from && to) m[from]=to;
  });
  return m;
}

async function saveJob(e){
  e.preventDefault();
  const btn = $('#saveBtn');
  btn.disabled = true;

  try{
    const f = e.target;
    const src_cols = multiSelectGet($('#src_cols_multi'));

    const payload = {
      id: f.id.value ? Number(f.id.value) : undefined,
      title: f.title.value.trim(),

      src_type: $('#src_type').value,
      src_server_key: $('#src_server_key').value,
      src_db: $('#src_db').value,
      src_table: $('#src_table').value,
      src_cols: src_cols,
      where_clause: $('#where_clause').value.trim() || null,

      dest_type: $('#dest_type').value,
      dest_server_key: $('#dest_server_key').value,
      dest_db: $('#dest_db').value,
      dest_table: $('#dest_table').value,

      mode: $('#mode').value,
      batch_size: Number($('#batch_size').value || 1000),

      column_map: parseMap($('#column_map').value)
    };

    if (!payload.title) throw new Error('Title is required.');
    if (!payload.src_server_key || !payload.src_db || !payload.src_table) throw new Error('Complete source selection.');
    if (!payload.dest_server_key || !payload.dest_db || !payload.dest_table) throw new Error('Complete destination selection.');
    if (!payload.column_map || Object.keys(payload.column_map).length === 0) throw new Error('Column map is empty.');

    const j = await fetchJSON('/transfer_job_save.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });

    if (j.ok) {
      location.href = 'index.php';
    } else {
      const msg = j.error || j.message || 'Save failed';
      alert(msg);
    }
  } catch(err){
    alert(err.message || 'Save failed');
  } finally{
    btn.disabled = false;
  }
}

async function runSavedJob(id){
  const btn = $('#runNowBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Starting…'; }
  try{
    const j = await fetchJSON('/transfer_job_run.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: 'id='+encodeURIComponent(id)
    });
    alert('Run started. Run ID: '+j.run_id);
  }catch(err){
    alert(`Run failed: ${err.message||'Unknown error'}`);
  }finally{
    if (btn) { btn.disabled = false; btn.textContent = 'Run now'; }
  }
}

/* ===== Create table from source (Preview + Execute) ===== */
async function generateDDL(execute = false){
  const createBtn = $('#ddlCreateBtn');
  if (execute && createBtn) { createBtn.disabled = true; createBtn.textContent = 'Creating…'; }

  try {
    const chosenCols = Array.from($('#src_cols_multi')?.selectedOptions || []).map(o=>o.value);

    const payload = {
      src_type:   $('#src_type').value,
      src_server: $('#src_server_key').value,
      src_db:     $('#src_db').value,
      src_table:  $('#src_table').value,
      only_cols:  chosenCols, // empty => all

      dest_type:   $('#dest_type').value,
      dest_server: $('#dest_server_key').value,
      dest_db:     $('#dest_db').value,
      dest_table:  $('#dest_table').value,

      execute: execute ? 1 : 0
    };

    if (!payload.src_server || !payload.src_db || !payload.src_table) {
      alert('Pick source server, database and table first.');
      return;
    }
    if (!payload.dest_server || !payload.dest_db || !payload.dest_table) {
      alert('Pick destination server/db and enter a destination table name.');
      return;
    }

    const j = await fetchJSON('/api/xfer_generate_table_sql.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });

    if (execute) {
      alert('Table created successfully.');
      return;
    }

    // preview mode
    $('#ddlPreview').textContent = j.ddl || '';
    const notes = j.notes || {};
    $('#ddlNotes').textContent =
      `Columns: ${notes.columnsCount ?? '?'}  |  PK: ${(notes.pkChosen||[]).join(', ') || '—'}  |  Identity: ${notes.identityDetected ? 'yes' : 'no'}`;

    const modal = new bootstrap.Modal(document.getElementById('ddlModal'));
    modal.show();

  } catch (err) {
    console.error(err);
    alert(`Failed: ${err.message}`);
  } finally {
    if (execute && createBtn) { createBtn.disabled = false; createBtn.textContent = 'Create table'; }
  }
}

// Wire up events
$('#src_type').addEventListener('change', loadServers);
$('#dest_type').addEventListener('change', loadServers);
$('#src_server_key').addEventListener('change', ()=>loadDatabases('src'));
$('#dest_server_key').addEventListener('change', ()=>loadDatabases('dest'));
$('#src_db').addEventListener('change', ()=>loadTables('src'));
$('#src_refresh_db').addEventListener('click', ()=>loadDatabases('src'));
$('#src_refresh_tbl').addEventListener('click', ()=>loadTables('src'));
$('#dest_refresh_db').addEventListener('click', ()=>loadDatabases('dest'));

$('#btn_src_load_cols').addEventListener('click', loadColumnsFromSource);
$('#btn_src_clear_cols').addEventListener('click', ()=>multiSelectClear($('#src_cols_multi')));
$('#btn_src_all_cols').addEventListener('click', ()=>multiSelectSetAll($('#src_cols_multi')));

$('#btn_auto_map').addEventListener('click', autoMapByName);
$('#btn_clear_map').addEventListener('click', clearMap);

// Copy source table name into destination box
$('#copy_src_to_dest').addEventListener('click', ()=>{
  const src = $('#src_table').value;
  if (src) $('#dest_table').value = src;
});

// Create table from source
$('#dest_create_table_from_src').addEventListener('click', ()=>generateDDL(false));
$('#ddlCreateBtn').addEventListener('click', async ()=>{
  await generateDDL(true);
  bootstrap.Modal.getInstance(document.getElementById('ddlModal'))?.hide();
});

// init
loadServers().then(()=> {
  const hasJob = <?= $id ? 'true' : 'false' ?>;
  if (hasJob) {
    setTimeout(async ()=>{
      await loadTables('src');
      const savedCols = <?= json_encode($src_cols) ?>;
      if (savedCols && savedCols.length) {
        await loadColumnsFromSource();
        const sel = $('#src_cols_multi');
        Array.from(sel.options).forEach(o=>o.selected = savedCols.includes(o.value));
      }
    }, 150);
  }
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
