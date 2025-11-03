const out = document.getElementById('output');
const listWrap = document.getElementById('listWrap');
const connModalEl = document.getElementById('connModal');
const connModal = new bootstrap.Modal(connModalEl);
const form = document.getElementById('connForm');

const f_id        = document.getElementById('f_id');
const f_name      = document.getElementById('f_name');
const f_protocol  = document.getElementById('f_protocol');
const f_port      = document.getElementById('f_port');
const f_host      = document.getElementById('f_host');
const f_username  = document.getElementById('f_username');
const f_password  = document.getElementById('f_password');
const f_passive   = document.getElementById('f_passive');
const f_root_path = document.getElementById('f_root_path');

function escapeHtml(s){ return (s ?? '').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function api(action, data = {}) {
  const body = new URLSearchParams({action, ...data});
  const resp = await fetch('ftp_connections_logic.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: body.toString()
  });
  return resp.json();
}

async function loadList() {
  const r = await api('list');
  if (!r.ok) {
    listWrap.innerHTML = '<div class="alert alert-danger">Load failed</div>';
    return;
  }
  const rows = r.items || [];
  const t = [];
  t.push('<table class="table table-dark table-striped align-middle">');
  t.push('<thead><tr><th>ID</th><th>Name</th><th>Proto</th><th>Host</th><th>Port</th><th>User</th><th>Passive</th><th>Root</th><th>Created</th><th>Actions</th></tr></thead><tbody>');
  for (const x of rows) {
    t.push(`<tr>
      <td>${x.id}</td>
      <td>${escapeHtml(x.name)}</td>
      <td>${escapeHtml(x.protocol)}</td>
      <td>${escapeHtml(x.host)}</td>
      <td>${x.port}</td>
      <td>${escapeHtml(x.username)}</td>
      <td>${String(x.passive) === '1' ? 'yes':'no'}</td>
      <td><code>${escapeHtml(x.root_path ?? '')}</code></td>
      <td class="text-secondary small">${escapeHtml(x.created_at ?? '')}</td>
      <td class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-info" data-act="edit" data-id="${x.id}">Edit</button>
        <button class="btn btn-sm btn-outline-success" data-act="test" data-id="${x.id}">Test</button>
        <button class="btn btn-sm btn-outline-danger" data-act="del" data-id="${x.id}">Delete</button>
      </td>
    </tr>`);
  }
  t.push('</tbody></table>');
  listWrap.innerHTML = t.join('');
}

function resetForm() {
  form.reset();
  f_id.value = '0';
  f_protocol.value = 'SFTP';
  f_port.value = '';
  // choose your default here; set to false so it doesn't silently force passive
  f_passive.checked = false;
}

document.getElementById('btnNew').addEventListener('click', () => {
  resetForm();
  connModal.show();
});

document.getElementById('btnReload').addEventListener('click', loadList);

listWrap.addEventListener('click', async (e) => {
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  const act = btn.dataset.act;
  const id  = btn.dataset.id;

  if (act === 'edit') {
    const r = await fetch(`ftp_connections_logic.php?action=get&id=${encodeURIComponent(id)}`);
    const j = await r.json();
    if (!j.ok) { out.textContent = 'Load failed: ' + j.error; return; }
    const x = j.item;
    resetForm();
    f_id.value = x.id;
    f_name.value = x.name || '';
    f_protocol.value = x.protocol || 'SFTP';
    f_port.value = x.port || '';
    f_host.value = x.host || '';
    f_username.value = x.username || '';
    f_root_path.value = x.root_path || '';
    // important: reflect DB value exactly ('1' => true, else false)
    f_passive.checked = String(x.passive) === '1';
    connModal.show();
  }

  if (act === 'del') {
    if (!confirm('Delete this connection?')) return;
    const j = await api('delete', {id});
    out.textContent = j.ok ? 'Deleted.' : ('Delete failed: ' + j.error);
    await loadList();
  }

  if (act === 'test') {
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing...';
    try {
      const r = await fetch('ftp_connections_logic.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'test', id}).toString()
      });
      const j = await r.json();
      if (j.ok) {
        out.textContent = 'OK: ' + (j.msg || 'Login OK') +
          (j.sample?.length ? `\nSample: ${JSON.stringify(j.sample, null, 2)}` : '');
      } else {
        out.textContent = 'Test failed: ' + (j.error || 'unknown error');
      }
    } catch (err) {
      out.textContent = 'Test error: ' + err;
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  }
});

document.getElementById('btnTest')?.addEventListener('click', async () => {
  // Modal test: send explicit passive 1/0
  const data = Object.fromEntries(new FormData(form).entries());
  data.passive = f_passive.checked ? '1' : '0';
  const j = await api('test', data);
  if (j.ok) {
    out.textContent = 'OK: ' + (j.msg || 'Login OK') +
      (j.sample?.length ? `\nSample: ${JSON.stringify(j.sample, null, 2)}` : '');
  } else {
    out.textContent = 'Test failed: ' + (j.error || 'unknown error');
  }
});

document.getElementById('btnSave')?.addEventListener('click', async () => {
  const data = Object.fromEntries(new FormData(form).entries());
  // Force explicit 1/0 instead of missing/empty string
  data.passive = f_passive.checked ? '1' : '0';
  const j = await api('save', data);
  out.textContent = j.ok ? j.msg + ' (id ' + j.id + ')' : ('Save failed: ' + j.error);
  if (j.ok) {
    bootstrap.Modal.getInstance(connModalEl)?.hide();
    await loadList();
  }
});

// init
loadList();
