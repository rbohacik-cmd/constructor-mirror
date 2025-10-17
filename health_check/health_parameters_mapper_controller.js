(() => {
  const api        = '/health_check/controllers/health_parameters_mapper_logic.php';
  const tbody      = document.getElementById('groups-body');
  const searchBox  = document.getElementById('grp-search');
  const btnRefresh = document.getElementById('btn-refresh');
  const results    = document.getElementById('mapper-results');

  // --- helpers ---
  const esc = (s) => String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const setRowBusy = (tr, busy) => {
    tr.querySelectorAll('button, input').forEach(el => el.disabled = !!busy);
    if (busy) tr.classList.add('opacity-50'); else tr.classList.remove('opacity-50');
  };

  // Cancel stale searches to avoid race conditions
  let lastSearchCtrl = null;

  async function fetchGroups(q) {
    if (lastSearchCtrl) lastSearchCtrl.abort();
    lastSearchCtrl = new AbortController();

    const url = new URL(api, location.origin);
    url.searchParams.set('action', 'groups_index');
    if (q) url.searchParams.set('q', q);

    const r = await fetch(url, { credentials: 'same-origin', signal: lastSearchCtrl.signal });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'groups_index failed');
    return j.data || [];
  }

  function rowHtml(g) {
    const gid  = g.group_id;                    // GUID string
    const name = g.display_name || '';
    const cnt  = g.params_count ?? 0;
    return `
      <tr data-gid="${esc(gid)}">
        <td style="max-width:520px">
          <code class="small">${esc(gid)}</code>
        </td>
        <td>
          <input class="form-control form-control-sm grp-name"
                 placeholder="e.g. Cables â€“ Base Specs"
                 value="${esc(name)}">
        </td>
        <td class="text-end">${cnt}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-info me-2 btn-view">View params</button>
          <button class="btn btn-sm btn-primary btn-save">Save name</button>
        </td>
      </tr>
    `;
  }

  async function render(q) {
    tbody.innerHTML = `<tr><td colspan="4"><em class="text-muted">Loadingâ€¦</em></td></tr>`;
    try {
      const groups = await fetchGroups(q);
      if (!groups.length) {
        tbody.innerHTML = `<tr><td colspan="4"><em class="text-muted">No groups found.</em></td></tr>`;
        return;
      }
      tbody.innerHTML = groups.map(rowHtml).join('');
    } catch (e) {
      if (e.name === 'AbortError') return; // user typed again; ignore
      tbody.innerHTML = `<tr><td colspan="4" class="text-danger">${esc(e.message || e)}</td></tr>`;
    }
  }

  async function saveName(groupId, displayName) {
    const r = await fetch(`${api}?action=save_meta`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ group_id: groupId, display_name: displayName }) // GUID + label
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'save failed');
    return j.data || {};
  }

  async function fetchParams(groupId) {
    const url = new URL(api, location.origin);
    url.searchParams.set('action', 'group_params');
    url.searchParams.set('group_id', groupId);
    const r = await fetch(url, { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'group_params failed');
    return j.data || [];
  }

  function showParamsModal(gid, list) {
    const body  = document.getElementById('pm-body');
    const title = document.getElementById('pm-group');
    if (!body || !title) return;
    title.textContent = gid;

    if (!list.length) {
      body.innerHTML = `<em class="text-muted">No parameters in this group.</em>`;
    } else {
      body.innerHTML = `
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr><th style="width:260px">Param ID (GUID)</th><th style="width:160px">Kod</th><th>Nazev</th></tr>
            </thead>
            <tbody>
              ${list.map(p => `
                <tr>
                  <td><code>${esc(p.id)}</code></td>
                  <td>${esc(p.kod || '')}</td>
                  <td>${esc(p.nazev || '')}</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    // bootstrap modal safety
    const modalEl = document.getElementById('paramsModal');
    if (modalEl && window.bootstrap?.Modal) {
      new bootstrap.Modal(modalEl).show();
    }
  }

  // Row actions
  tbody?.addEventListener('click', async (e) => {
    const tr = e.target.closest('tr[data-gid]');
    if (!tr) return;
    const gid = tr.getAttribute('data-gid');

    // Save name
    if (e.target.closest('.btn-save')) {
      const input = tr.querySelector('.grp-name');
      const name = (input?.value || '').trim();
      if (!name) {
        results.innerHTML = `<div class="text-danger">Please enter a display name first.</div>`;
        return;
      }
      try {
        setRowBusy(tr, true);
        await saveName(gid, name);
        results.innerHTML = `<div class="text-success">Saved name for Group <code>${esc(gid)}</code>: <b>${esc(name)}</b>.</div>`;
      } catch (err) {
        results.innerHTML = `<div class="text-danger">Save failed: ${esc(err?.message || err)}</div>`;
      } finally {
        setRowBusy(tr, false);
      }
      return;
    }

    // View params
    if (e.target.closest('.btn-view')) {
      try {
        setRowBusy(tr, true);
        const list = await fetchParams(gid);
        showParamsModal(gid, list);
      } catch (err) {
        results.innerHTML = `<div class="text-danger">Load params failed: ${esc(err?.message || err)}</div>`;
      } finally {
        setRowBusy(tr, false);
      }
    }
  });

  // Enter-to-save in the name input; Ctrl/Cmd+S also saves
  tbody?.addEventListener('keydown', async (e) => {
    const input = e.target.closest('.grp-name');
    if (!input) return;

    const tr  = input.closest('tr[data-gid]');
    const gid = tr?.getAttribute('data-gid');
    if (!gid) return;

    const wantSave = (e.key === 'Enter') || ((e.key === 's' || e.key === 'S') && (e.ctrlKey || e.metaKey));
    if (!wantSave) return;

    e.preventDefault();
    const name = input.value.trim();
    if (!name) {
      results.innerHTML = `<div class="text-danger">Please enter a display name first.</div>`;
      return;
    }
    try {
      setRowBusy(tr, true);
      await saveName(gid, name);
      results.innerHTML = `<div class="text-success">Saved name for Group <code>${esc(gid)}</code>: <b>${esc(name)}</b>.</div>`;
    } catch (err) {
      results.innerHTML = `<div class="text-danger">Save failed: ${esc(err?.message || err)}</div>`;
    } finally {
      setRowBusy(tr, false);
    }
  });

  // Search + refresh
  let tId = null;
  searchBox?.addEventListener('input', () => {
    clearTimeout(tId);
    tId = setTimeout(() => render(searchBox.value.trim()), 250);
  });
  btnRefresh?.addEventListener('click', () => render(searchBox?.value?.trim() || ''));

  // Boot
  render('');
})();