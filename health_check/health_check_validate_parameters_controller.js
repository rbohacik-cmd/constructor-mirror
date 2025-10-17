(() => {
  const api   = '/health_check/controllers/health_check_validate_parameters_logic.php';
  const tbody = document.getElementById('hc-val-body');
  const global = document.getElementById('global-results');

  const esc = (s) => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');

  function rowEl(cat) {
    // IDs are like: res-<GUID> (hyphens are fine in IDs)
    return document.getElementById(`res-${cat}`);
  }

  function showRowState(cat, html) {
    const tr = rowEl(cat);
    if (!tr) return;
    tr.classList.remove('d-none');
    tr.querySelector('.p-2').innerHTML = html;
  }

  function hideRowState(cat) {
    const tr = rowEl(cat);
    if (tr) tr.classList.add('d-none');
  }

	function renderResult(cat, d) {
	  const total = d?.summary?.total_products ?? 0;
	  const ok    = d?.summary?.fully_ok ?? 0;
	  const missC = d?.summary?.with_missing ?? 0;
	  const label = d?.group_label || d?.group_id || '';

	  let html = `<div><b>Category</b> <code>${esc(cat)}</code> · <b>Group</b> <code>${esc(label)}</code> → Products: ${total}, OK: ${ok}, Missing: ${missC}</div>`;

	  if (Array.isArray(d.missing) && d.missing.length) {
		html += `<div class="mt-2 border rounded bg-light-subtle p-2">
		  <b>Products with missing parameters (first ${Math.min(200, d.missing.length)}):</b>
		  <ul class="mb-0">
			${d.missing.slice(0,200).map(it => {
			  const x = Number(it.missing ?? 0);
			  const y = Number(it.required_total ?? 0);
			  const kod = esc(it.kod || '');
			  const naz = esc(it.nazev || '');
			  const id  = esc(it.artikl_id || '');
			  return `<li><code>${kod}</code> — ${naz} <span class="text-muted">[${id}]</span> — <b>missing ${x}/${y}</b></li>`;
			}).join('')}
		  </ul>
		  ${d.missing.length>200?'<div class="small text-muted mt-1">(truncated)</div>':''}
		</div>`;
	  } else {
		html += `<div class="text-success mt-2">All products contain required parameters.</div>`;
	  }

	  showRowState(cat, html);
	}


  async function validate(cat, btn) {
    showRowState(cat, '<span class="text-muted">Working…</span>');
    if (btn) btn.disabled = true;
    try {
      const r = await fetch(`${api}?action=validate&category_id=${encodeURIComponent(cat)}`, { credentials:'same-origin' });
      const j = await r.json();
      if (!j.ok) {
        showRowState(cat, `<span class="text-danger">Failed: ${esc(j.error||'Unknown error')}</span>`);
        return;
      }
      renderResult(cat, j.data || {});
    } catch (err) {
      showRowState(cat, `<span class="text-danger">Failed: ${esc(err?.message || err)}</span>`);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  tbody?.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn-validate');
    if (!btn) return;
    const cat = btn.getAttribute('data-cat');
    if (!cat) return;
    validate(cat, btn);
  });
})();
