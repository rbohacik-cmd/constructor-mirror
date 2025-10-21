<?php
// /local_constructor/ftp_file_picker_embed.php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

// No DB required here. The list comes from the logic endpoint via AJAX.
$connId = (int)($_GET['conn_id'] ?? 0);
$start  = (string)($_GET['start'] ?? '/');
if ($start === '') $start = '/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>FTP Picker</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#111; color:#eee; }
    .path-breadcrumb { font-family: ui-monospace, Menlo, Consolas, monospace; }
    .table-files td { vertical-align: middle; }
    .max-h-60vh { max-height: 60vh; overflow:auto; }
    .cursor-pointer { cursor: pointer; }
  </style>
</head>
<body>
<div class="container-fluid p-3">
  <div class="d-flex align-items-center gap-2">
    <div class="fw-semibold">Connection:</div>
    <div class="badge bg-info-subtle text-info">#<?= (int)$connId ?></div>
    <div class="ms-auto small text-muted" id="status"></div>
  </div>

  <div class="mt-2">
    <div class="small text-muted">Current path:</div>
    <div id="crumbs" class="path-breadcrumb"></div>
  </div>

  <div class="card bg-dark border-0 mt-2">
    <div class="card-body p-0">
      <div class="table-responsive max-h-60vh">
        <table class="table table-dark table-striped table-hover align-middle table-files m-0">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="checkAll"></th>
              <th>Name</th>
              <th style="width:100px">Type</th>
              <th style="width:120px" class="text-end">Size</th>
              <th style="width:170px">Modified</th>
            </tr>
          </thead>
          <tbody id="fileBody">
            <tr><td colspan="5" class="text-center py-4">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-2">
    <button id="btnUp" class="btn btn-outline-light">⬆️ Up</button>
    <button id="btnUse" class="btn btn-info">Use selected</button>
    <button id="btnRefresh" class="btn btn-secondary ms-auto">Refresh</button>
  </div>
</div>

<script>
const params = new URLSearchParams(location.search);
const connId = parseInt(params.get('conn_id') || '0', 10);

// Build absolute URL to the logic file at web root
const LOGIC_URL = new URL('/ftp_file_selector_logic.php?action=list', location.origin).href;

function normalizeDir(p){
  if (!p) return '/';
  p = String(p).replace(/^\/+|\/+$/g,''); // trim leading/trailing slashes
  return p ? `/${p}/` : '/';
}
let currentPath = normalizeDir(params.get('start') || '/');

const el = (id) => document.getElementById(id);

function renderBreadcrumb() {
  const parts = currentPath.replace(/^\/+|\/+$/g,'').split('/').filter(Boolean);
  let html = `<span class="cursor-pointer text-info" data-path="/">/</span>`;
  let accum = "";
  for (const p of parts) {
    accum += "/" + p;
    html += ` <span>/</span> <span class="cursor-pointer text-info" data-path="${accum}/">${p}</span>`;
  }
  el('crumbs').innerHTML = html;
  el('crumbs').querySelectorAll('[data-path]').forEach(a=>{
    a.addEventListener('click', (e)=> {
      currentPath = e.target.getAttribute('data-path') || "/";
      currentPath = normalizeDir(currentPath);
      listDir();
    });
  });
}

function fmtBytes(n) {
  if (n === null || n === undefined) return "";
  const units = ['B','KB','MB','GB','TB'];
  let i=0, x = Number(n);
  while (x >= 1024 && i<units.length-1) { x/=1024; i++; }
  return (x.toFixed(x<10 && i>0 ? 1 : 0))+' '+units[i];
}

async function listDir() {
  if (!connId) {
    el('status').textContent = 'Pick a connection first.';
    el('fileBody').innerHTML = `<tr><td colspan="5" class="text-warning text-center py-4">No connection selected.</td></tr>`;
    return;
  }
  el('status').textContent = `Listing ${currentPath}…`;
  const res = await fetch(LOGIC_URL, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ server_id: connId, path: currentPath })
  });
  let data = {};
  try { data = await res.json(); } catch(e) {}
  el('status').textContent = data.ok ? 'OK' : (data.error || 'Failed');
  if (!data.ok) {
    const detail = data.detail && data.detail.curl_error ? `<div class="small text-secondary mt-2">${data.detail.curl_error}</div>` : '';
    el('fileBody').innerHTML = `<tr><td colspan="5" class="text-danger text-center py-4">${data.error||'Failed to list'}${detail}</td></tr>`;
    return;
  }
  renderBreadcrumb();
  const rows = data.entries || [];
  if (!rows.length) {
    el('fileBody').innerHTML = `<tr><td colspan="5" class="text-center py-4">Empty.</td></tr>`;
    return;
  }
  el('fileBody').innerHTML = rows.map(item=>{
    const isDir = item.type === 'dir';
    const icon  = isDir ? '📁' : '📄';
    const click = isDir ? `data-dir="${item.name}"` : '';
    return `
      <tr>
        <td><input type="checkbox" class="pick" data-name="${item.name}" ${isDir?'disabled':''}></td>
        <td class="${isDir?'cursor-pointer text-info':''}" ${click} title="${item.name}">${icon} ${item.name}</td>
        <td>${item.type}</td>
        <td class="text-end">${isDir?'':fmtBytes(item.size)}</td>
        <td>${item.mtime || ''}</td>
      </tr>`;
  }).join('');

  // folder nav
  el('fileBody').querySelectorAll('[data-dir]').forEach(td=>{
    td.addEventListener('click', ()=>{
      const folder = td.getAttribute('data-dir');
      currentPath = normalizeDir(currentPath + '/' + folder);
      listDir();
    });
  });

  // master
  const master = el('checkAll');
  master.checked = false;
  master.onchange = ()=> {
    document.querySelectorAll('.pick:not(:disabled)').forEach(cb=> cb.checked = master.checked);
  };
}

function commonPrefix(arr) {
  if (!arr.length) return '';
  let prefix = arr[0];
  for (let i=1;i<arr.length;i++) {
    let j=0;
    while (j < prefix.length && j < arr[i].length && prefix[j] === arr[i][j]) j++;
    prefix = prefix.slice(0, j);
    if (!prefix) break;
  }
  return prefix;
}

function guessGlob(files) {
  if (files.length === 1) return files[0]; // exact filename
  const exts = files.map(f => (f.includes('.') ? f.split('.').pop().toLowerCase() : ''));
  const allSameExt = exts.every(e => e === exts[0]);
  if (allSameExt && exts[0]) return `*.${exts[0]}`;
  const pref = commonPrefix(files);
  if (pref && pref.length >= 3) return `${pref}*`;
  return '*';
}

el('btnRefresh').onclick = listDir;
el('btnUp').onclick = ()=>{
  if (currentPath === '/' ) return;
  const parts = currentPath.replace(/^\/+|\/+$/g,'').split('/');
  parts.pop();
  currentPath = normalizeDir(parts.join('/'));
  listDir();
};
el('btnUse').onclick = ()=>{
  const picks = Array.from(document.querySelectorAll('.pick:checked')).map(cb=> cb.getAttribute('data-name'));
  const payload = {
    type: 'ftp-file-picked',
    path: currentPath,
    files: picks,
    suggestedGlob: guessGlob(picks),
  };
  window.parent.postMessage(payload, '*');
};

// Initial render + list
renderBreadcrumb();
listDir();
</script>
</body>
</html>
