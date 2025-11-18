<?php
// /local_constructor/ftp_file_selector.php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

// Local Constructor conventions:
require_once __DIR__ . '/../db.php';   // your local DB connection helper (not the online one)
require_once __DIR__ . '/../mysql.php';

// Optional sentinel (won't fatal if missing)
$sentinel = null;
if (file_exists(__DIR__ . '/../debug_sentinel.php')) {
  require_once __DIR__ . '/../debug_sentinel.php';
  $sentinel = function_exists('debug_sentinel') ? debug_sentinel(...): null;
}

// Load available FTP connections (adjust table/name to your setup)
$pdo = db(); // from db.php
$rows = $pdo->query("SELECT id, internal_name, host, protocol, port FROM ftp_connections ORDER BY internal_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>FTP File Selector</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .path-breadcrumb { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .table-files td { vertical-align: middle; }
    .cursor-pointer { cursor: pointer; }
    .max-h-60vh { max-height: 60vh; overflow:auto; }
  </style>
</head>
<body class="bg-dark text-light">
<div class="container py-4">
  <h1 class="h3 mb-3">FTP File Selector</h1>

  <div class="row g-3 align-items-end">
    <div class="col-md-6">
      <label class="form-label">FTP Connection</label>
      <select id="serverSelect" class="form-select">
        <?php foreach ($rows as $r): ?>
          <option value="<?= (int)$r['id'] ?>">
            <?= htmlspecialchars($r['internal_name'].' â€” '.$r['protocol'].'://'.$r['host'].':'.$r['port']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6 text-end">
      <button id="btnRefresh" class="btn btn-secondary">Refresh</button>
    </div>
  </div>

  <div class="mt-3">
    <div class="small text-muted">Current path:</div>
    <div id="crumbs" class="path-breadcrumb"></div>
  </div>

  <div class="card bg-secondary-subtle border-0 mt-3">
    <div class="card-body p-0">
      <div class="table-responsive max-h-60vh">
        <table class="table table-dark table-striped table-hover align-middle table-files m-0">
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" id="checkAll"></th>
              <th>Name</th>
              <th style="width:120px">Type</th>
              <th style="width:140px" class="text-end">Size</th>
              <th style="width:180px">Modified</th>
            </tr>
          </thead>
          <tbody id="fileBody">
          <tr><td colspan="5" class="text-center py-4">Select a server, then Refresh.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-3">
    <button id="btnUp" class="btn btn-outline-light">â¬†ï¸ Up</button>
    <button id="btnDownload" class="btn btn-success">Download Selected</button>
    <div id="status" class="ms-auto small text-info"></div>
  </div>
</div>

<script>
const el = (id) => document.getElementById(id);
let currentPath = "/";

function renderBreadcrumb() {
  const parts = currentPath.replace(/^\/+|\/+$/g,'').split('/').filter(Boolean);
  let html = `<span class="cursor-pointer text-info" data-path="/">/</span>`;
  let accum = "";
  for (const p of parts) {
    accum += "/" + p;
    html += ` <span>/</span> <span class="cursor-pointer text-info" data-path="${accum}">${p}</span>`;
  }
  el('crumbs').innerHTML = html;
  el('crumbs').querySelectorAll('[data-path]').forEach(a=>{
    a.addEventListener('click', (e)=> {
      currentPath = e.target.getAttribute('data-path') || "/";
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
  el('status').textContent = 'Listing...';
  const server = el('serverSelect').value;
  const res = await fetch('ftp_file_selector_logic.php?action=list', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ server_id: server, path: currentPath })
  });
  const data = await res.json().catch(()=> ({}));
  el('status').textContent = data.message || '';
  if (!data.ok) {
    el('fileBody').innerHTML = `<tr><td colspan="5" class="text-danger text-center py-4">${data.error||'Failed to list directory.'}</td></tr>`;
    return;
  }
  // rows
  renderBreadcrumb();
  const rows = data.entries || [];
  if (!rows.length) {
    el('fileBody').innerHTML = `<tr><td colspan="5" class="text-center py-4">Empty folder.</td></tr>`;
    return;
  }
  el('fileBody').innerHTML = rows.map(item=>{
    const isDir = item.type === 'dir';
    const icon  = isDir ? 'ðŸ“' : 'ðŸ“„';
    const click = isDir ? `data-dir="${item.name}"` : '';
    return `
      <tr>
        <td><input type="checkbox" class="pick" data-name="${item.name}" ${isDir?'disabled':''}></td>
        <td class="${isDir?'cursor-pointer text-info':''}" ${click}>${icon} ${item.name}</td>
        <td>${item.type}</td>
        <td class="text-end">${isDir?'':fmtBytes(item.size)}</td>
        <td>${item.mtime || ''}</td>
      </tr>`;
  }).join('');

  // folder navigation
  el('fileBody').querySelectorAll('[data-dir]').forEach(td=>{
    td.addEventListener('click', ()=>{
      const folder = td.getAttribute('data-dir');
      if (!currentPath.endsWith('/')) currentPath += '/';
      currentPath += folder + '/';
      listDir();
    });
  });

  // master checkbox
  const master = el('checkAll');
  master.checked = false;
  master.addEventListener('change', ()=>{
    document.querySelectorAll('.pick:not(:disabled)').forEach(cb=> cb.checked = master.checked);
  });
}

el('btnRefresh').addEventListener('click', ()=> listDir());
el('btnUp').addEventListener('click', ()=>{
  if (currentPath === '/' ) return;
  const p = currentPath.replace(/\/+$/,'').split('/'); p.pop();
  currentPath = p.length ? p.join('/')+'/' : '/';
  listDir();
});
el('btnDownload').addEventListener('click', async ()=>{
  const server = el('serverSelect').value;
  const picks  = Array.from(document.querySelectorAll('.pick:checked')).map(cb=> cb.getAttribute('data-name'));
  if (!picks.length) { el('status').textContent = 'Nothing selected.'; return; }
  el('status').textContent = 'Downloading...';
  const res = await fetch('ftp_file_selector_logic.php?action=download', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ server_id: server, path: currentPath, files: picks })
  });
  const data = await res.json().catch(()=> ({}));
  if (!data.ok) {
    el('status').textContent = data.error || 'Download failed.';
    return;
  }
  el('status').textContent = `Done: ${data.results.filter(r=>r.ok).length} ok, ${data.results.filter(r=>!r.ok).length} failed. Saved under ${data.save_base || '/downloads/ftp/...'}.`;
});

renderBreadcrumb();
</script>
</body>
</html>


