(() => {
  const wrap = document.getElementById('hc-tree-wrap');
  const q    = document.getElementById('hc-search');
  const btnExpand  = document.getElementById('btn-expand-all');
  const btnCollapse= document.getElementById('btn-collapse-all');
  const results = document.getElementById('results');

  // Endpoints
  const apiGroups  = '/health_check/controllers/health_parameters_mapper_logic.php';
  const apiMapping = '/health_check/controllers/health_check_parameters_by_category_logic.php';

  // Toggle
  wrap?.addEventListener('click', (e) => {
    const btn = e.target.closest('.hc-toggle');
    if (!btn) return;
    const id = btn.getAttribute('data-target');
    const ul = document.getElementById(id);
    if (!ul) return;
    const isShown = ul.classList.contains('show');
    ul.classList.toggle('show', !isShown);
    btn.textContent = isShown ? '▸' : '▾';
  });

  function setAll(open) {
    wrap?.querySelectorAll('.hc-children').forEach(ul => ul.classList.toggle('show', open));
    wrap?.querySelectorAll('.hc-toggle').forEach(b => b.textContent = open ? '▾' : '▸');
  }
  btnExpand?.addEventListener('click', () => setAll(true));
  btnCollapse?.addEventListener('click', () => setAll(false));

  // Filter
  let tId = null;
  q?.addEventListener('input', () => {
    clearTimeout(tId);
    tId = setTimeout(() => {
      const needle = (q.value || '').trim().toLowerCase();
      if (!needle) {
        wrap?.querySelectorAll('.hc-node').forEach(li => li.style.display = '');
        setAll(false);
        return;
      }
      const matches = [];
      wrap?.querySelectorAll('.hc-node').forEach(li => {
        const txt = li.getAttribute('data-text') || '';
        const on  = txt.includes(needle);
        li.style.display = on ? '' : 'none';
        if (on) matches.push(li);
      });
      matches.forEach(li => {
        let p = li.parentElement;
        while (p && p !== wrap) {
          if (p.classList.contains('hc-children')) {
            p.classList.add('show');
            const id = p.getAttribute('id');
            wrap.querySelectorAll(`.hc-toggle[data-target="${id}"]`).forEach(b => b.textContent = '▾');
          }
          if (p.tagName === 'LI') p.style.display = '';
          p = p.parentElement;
        }
      });
    }, 120);
  });

  // Load groups with names (GUID-safe)
  async function loadGroups() {
    const url = new URL(apiGroups, location.origin);
    url.searchParams.set('action','groups_index');
    const r = await fetch(url, {credentials:'same-origin'});
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'groups_index failed');
    return j.data || [];
  }

  // Populate selects using display_name || group_id and keep selection
  function populateSelects(groups) {
    const opts = ['<option value="">(select Group_ID)</option>']
      .concat(
        groups.map(g => {
          const label = (g.display_name && g.display_name.trim()) ? g.display_name : g.group_id;
          const suffix = ` · ${g.params_count} params`;
          // Keep GUID as value
          return `<option value="${g.group_id}">${label}${suffix}</option>`;
        })
      ).join('');

    document.querySelectorAll('.group-select').forEach(sel => {
      const wanted = sel.dataset.selected || sel.value || '';
      const wantedLabel = sel.dataset.selectedLabel || wanted;
      sel.innerHTML = opts;

      if (wanted) {
        sel.value = wanted;
        // If not found in freshly loaded list, append a fallback so it's still visible
        if (sel.value !== wanted) {
          const opt = document.createElement('option');
          opt.value = wanted;
          opt.textContent = wantedLabel || wanted;
          opt.selected = true;
          sel.appendChild(opt);
        }
      }
    });
  }

  // Save mapping
  async function doSave(catGuid, groupGuid) {
    const r = await fetch(`${apiMapping}?action=save`, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({category_id: catGuid, group_id: groupGuid || null})
    });
    return r.json();
  }

  // Validate
  async function doValidate(catGuid) {
    const r = await fetch(`${apiMapping}?action=validate&category_id=${encodeURIComponent(catGuid)}`, {credentials:'same-origin'});
    return r.json();
  }

  // Wire Save/Validate buttons
  wrap?.addEventListener('click', async (e) => {
    const save = e.target.closest('.btn-save');
    const val  = e.target.closest('.btn-validate');
    if (!save && !val) return;

    const cat = (save||val).getAttribute('data-cat');

    if (save) {
      const sel = wrap.querySelector(`.group-select[data-cat="${CSS.escape(cat)}"]`);
      const gid = sel?.value || '';
      const j = await doSave(cat, gid);
      if (j.ok) {
        const label = sel.options[sel.selectedIndex]?.text?.split(' · ')[0] || gid;
        sel.dataset.selected = gid || '';
        sel.dataset.selectedLabel = label || '';
        results.innerHTML = `<div class="text-success">Saved: Category <code>${cat}</code> → ${gid ? 'Group ' + label : '(cleared)'}.</div>`;
      } else {
        results.innerHTML = `<div class="text-danger">Save failed: ${j.error||'Unknown error'}</div>`;
      }
      return;
    }

    if (val) {
      const j = await doValidate(cat);
      if (!j.ok) {
        results.innerHTML = `<div class="text-danger">Validation failed: ${j.error||'Unknown error'}</div>`;
        return;
      }
      const d = j.data || {};
      if (!d.group_id) {
        results.innerHTML = `<div class="text-warning">No Group_ID assigned for category <code>${cat}</code>.</div>`;
        return;
      }
      const total = d?.summary?.total_products ?? 0;
      const ok    = d?.summary?.fully_ok ?? 0;
      const miss  = d?.summary?.with_missing ?? 0;
      let html = `<div class="mb-2">Validated Category <b>${cat}</b> with Group <b>${d.group_id}</b> → Products: ${total}, OK: ${ok}, Missing: ${miss}</div>`;
      if (d.missing && d.missing.length) {
        html += `<div class="border rounded p-2 bg-light-subtle"><b>Products with missing parameters:</b><ul class="mb-0">` +
          d.missing.slice(0, 200).map(it => `<li>Artikl_ID ${it.artikl_id} — missing params: ${it.missing_params.join(', ')}</li>`).join('') +
          `</ul>${d.missing.length>200?'<div class="small text-muted mt-1">(truncated)</div>':''}</div>`;
      } else {
        html += `<div class="text-success">All products contain required parameters.</div>`;
      }
      results.innerHTML = html;
    }
  });

  // boot: load groups then populate
  loadGroups()
    .then(populateSelects)
    .catch(err => { results.innerHTML = `<div class="text-danger">Failed to load groups: ${err?.message||err}</div>`; });
})();
