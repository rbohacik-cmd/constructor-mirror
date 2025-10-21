// Controller for /ftp_manager/ftp_jobs_manager.php
(function () {
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  const listWrap = $('#listWrap tbody');
  const jobModalEl = $('#jobModal');
  const output = $('#output');

  const btnPick = $('#btnPickFiles');
  const connSel = $('#j_connection_id');
  const remote  = $('#j_remote_path');
  const globInp = $('#j_filename_glob');
  const pickerModalEl = $('#pickerModal');
  const pickerFrame   = $('#pickerFrame');

  let pickerModal;

  const f = {
    json: async (url, opts = {}) => {
      const r = await fetch(url, opts);
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Request failed');
      return j.data;
    },
    form: (formEl) => new FormData(formEl),
    openModal: () => {
      if (window.bootstrap && jobModalEl) {
        window.bootstrap.Modal.getOrCreateInstance(jobModalEl).show();
      }
    },
    closeModal: () => {
      if (window.bootstrap && jobModalEl) {
        window.bootstrap.Modal.getOrCreateInstance(jobModalEl).hide();
      }
    },
    setOutput: (txt) => { if (output) output.textContent = txt; },
  };

  async function reloadList() {
    const rows = await f.json('ftp_jobs_manager_logic.php?action=list');
    if (!Array.isArray(rows)) return;
    listWrap.innerHTML = rows.map(j => `
      <tr>
        <td>${Number(j.id) || 0}</td>
        <td>${escapeHtml(j.name || '')}</td>
        <td>${escapeHtml(j.conn_name || '')}</td>
        <td><code class="code-wrap">${escapeHtml(j.remote_path || '')}</code></td>
        <td><code class="code-wrap">${escapeHtml(j.filename_glob || '')}</code></td>
        <td>${Number(j.is_recursive) ? 'yes' : 'no'}</td>
        <td class="small text-secondary">${escapeHtml(j.only_newer_than || '')}</td>
        <td>${Number(j.enabled) ? 'yes' : 'no'}</td>
        <td class="d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-outline-info" data-act="edit" data-id="${Number(j.id)||0}">Edit</button>
          <button class="btn btn-sm btn-outline-info" data-act="test" data-id="${Number(j.id)||0}">Test</button>
          <button class="btn btn-sm btn-outline-warning" data-act="run"  data-id="${Number(j.id)||0}">Run</button>
          <button class="btn btn-sm btn-outline-danger"  data-act="del"  data-id="${Number(j.id)||0}">Delete</button>
        </td>
      </tr>
    `).join('');
  }

  // Event delegation for actions
  listWrap?.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-act]');
    if (!btn) return;
    const act = btn.getAttribute('data-act');
    const id = Number(btn.getAttribute('data-id') || '0');

    try {
      if (act === 'edit') {
        const j = await f.json('ftp_jobs_manager_logic.php?action=get&id=' + encodeURIComponent(id));
        // fill form
        $('#j_id').value = j.id || 0;
        $('#j_name').value = j.name || '';
        $('#j_connection_id').value = j.connection_id || '';
        $('#j_remote_path').value = j.remote_path || '';
        $('#j_filename_glob').value = j.filename_glob || '';
        $('#j_is_recursive').checked = !!Number(j.is_recursive);
        $('#j_max_size_mb').value = j.max_size_mb ?? '';
        $('#j_only_newer_than').value = j.only_newer_than || '';
        $('#j_target_pipeline').value = j.target_pipeline || '';
        $('#j_enabled').checked = !!Number(j.enabled);
        f.openModal();
      }

      if (act === 'del') {
        if (!confirm('Delete job #' + id + '?')) return;
        await f.json('ftp_jobs_manager_logic.php', {
          method: 'POST',
          body: new URLSearchParams({ action: 'delete', id: String(id) }),
        });
        await reloadList();
      }

      if (act === 'test') {
        const r = await f.json('ftp_jobs_manager_logic.php?action=test&id=' + encodeURIComponent(id));
        f.setOutput('Test result:\n' + JSON.stringify(r, null, 2));
      }

      if (act === 'run') {
        const r = await f.json('ftp_jobs_manager_logic.php?action=run&id=' + encodeURIComponent(id));
        f.setOutput('Run queued:\n' + JSON.stringify(r, null, 2));
      }
    } catch (e) {
      alert(e.message || String(e));
    }
  });

  // New / Save
  $('#btnNew')?.addEventListener('click', () => {
    $('#j_id').value = 0;
    $('#j_name').value = '';
    $('#j_connection_id').value = '';
    $('#j_remote_path').value = '';
    $('#j_filename_glob').value = '';
    $('#j_is_recursive').checked = false;
    $('#j_max_size_mb').value = '';
    $('#j_only_newer_than').value = '';
    $('#j_target_pipeline').value = '';
    $('#j_enabled').checked = true;
    f.openModal();
  });

  $('#btnReload')?.addEventListener('click', reloadList);

  $('#btnJobSave')?.addEventListener('click', async () => {
    const fd = f.form($('#jobForm'));
    fd.append('action', 'save');
    try {
      await fetch('ftp_jobs_manager_logic.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => { if (!j.ok) throw new Error(j.error || 'Save failed'); });
      f.closeModal();
      await reloadList();
    } catch (e) {
      alert(e.message || String(e));
    }
  });

  $('#btnJobTest')?.addEventListener('click', async () => {
    try {
      const id = Number($('#j_id').value || '0');
      if (!id) { alert('Save the job first, then test.'); return; }
      const r = await f.json('ftp_jobs_manager_logic.php?action=test&id=' + encodeURIComponent(id));
      f.setOutput('Test result:\n' + JSON.stringify(r, null, 2));
    } catch (e) {
      alert(e.message || String(e));
    }
  });

  // -------- File Picker glue (moved from inline script) --------
  if (window.bootstrap && pickerModalEl) {
    pickerModal = window.bootstrap.Modal.getOrCreateInstance(pickerModalEl, { backdrop: 'static' });
  }

  function openPicker() {
    const connId = parseInt(connSel?.value || '0', 10);
    if (!connId) { alert('Choose a Connection first.'); return; }
    const start = (remote?.value || '/').trim() || '/';
    const url = '../ftp_file_picker_embed.php?conn_id=' + encodeURIComponent(connId) + '&start=' + encodeURIComponent(start);
    pickerFrame.src = url;
    pickerModal?.show();
  }

  // Receive selection from iframe
  window.addEventListener('message', (ev) => {
    const data = ev.data || {};
    if (data.type !== 'ftp-file-picked') return;

    if (typeof data.path === 'string' && data.path) {
      remote.value = data.path; // directory
    }
    if (typeof data.suggestedGlob === 'string' && data.suggestedGlob) {
      globInp.value = data.suggestedGlob;
    }

    try {
      if (Array.isArray(data.files)) {
        f.setOutput(
          'Picked from ' + data.path +
          '\nFiles:\n- ' + data.files.join('\n- ') +
          '\nSuggested glob: ' + data.suggestedGlob
        );
      }
    } catch (e) {}

    pickerModal?.hide();
  });

  btnPick?.addEventListener('click', openPicker);
  // -------------------------------------------------------------

  // Utilities
  function escapeHtml(s) {
    return (s ?? '').toString()
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  // initial
  reloadList().catch(err => console.error(err));
})();
