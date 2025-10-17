(() => {
  const $ = (sel, root=document) => root.querySelector(sel);

  const form   = $('#inv-form');
  const qInput = $('#inv-query');
  const status = $('#search-status');

  const mEl    = $('#confirmModal');
  const modal  = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(mEl) : null;

  const elKod     = $('#cf-kod');
  const elKatalog = $('#cf-katalog');
  const elNazev   = $('#cf-nazev');
  const elEAN     = $('#cf-ean');
  const elPLU     = $('#cf-plu');
  const elQty     = $('#cf-qty');

  const btnConfirm  = $('#btn-confirm-insert');
  const btnClear    = $('#btn-clear');
  const btnClearAll = $('#btn-clear-all');

  // AUTO toggles
  const autoAddToggle   = $('#auto-add');
  const AUTO_ADD_KEY    = 'inv_auto_add_v1';
  const autoFireToggle  = $('#auto-fire');
  const AUTO_FIRE_KEY   = 'inv_auto_fire_v1';

  // ── Recently counted (now with pagination) ────────────────────────────────────
  const recentBody     = $('#recent-body');
  const recentLimit    = $('#recent-limit');
  const recentSummary  = $('#recent-summary');
  const recentPrev     = $('#recent-prev');
  const recentNext     = $('#recent-next');
  const btnRefresh     = $('#btn-refresh');
  const recentHead     = recentBody?.closest('table')?.querySelector('thead');

  let recentRows  = [];
  let recentSort  = { key: 'created', dir: 'desc' };
  let recentPage  = 1;
  let recentTotal = 0;

  // ── Items to check (MSSQL stock search) ──────────────────────────────────────
  const chkQ       = $('#chk-q');
  const chkField   = $('#chk-field');
  const chkLimit   = $('#chk-limit');
  const chkBody    = $('#chk-body');
  const chkPrev    = $('#chk-prev');
  const chkNext    = $('#chk-next');
  const chkRefresh = $('#chk-refresh');
  const chkSummary = $('#chk-summary');
  const chkHead    = chkBody?.closest('table')?.querySelector('thead');

  let lastFound = null;
  let editMode  = false;
  let editItem  = null;

  // state for “items to check”
  let chkPage   = 1;
  let chkTerm   = '';
  let chkTotal  = 0;
  let chkSort   = { key: 'name', dir: 'asc' };

  const esc = (s) => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

  const escAttr = (s) => String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  // ── Inject minimal CSS for status banner + copy flash ────────────────────────
  (function injectStyles(){
    if (document.getElementById('inv-status-styles')) return;
    const style = document.createElement('style');
    style.id = 'inv-status-styles';
    style.textContent = `
#search-status.status-banner{
  display:flex;align-items:center;gap:.5rem;
  padding:.625rem .75rem;border-radius:.5rem;
  font-size:1rem;line-height:1.3;font-weight:500;
  border:1px solid transparent
}
#search-status.status-banner .spinner{
  width:1rem;height:1rem;border:.15rem solid rgba(0,0,0,.15);
  border-top-color:currentColor;border-radius:50%;
  animation:invspin .75s linear infinite
}
@keyframes invspin{to{transform:rotate(360deg)}}
#search-status.is-success{color:#155724;background:rgba(25,135,84,.15);border-color:rgba(25,135,84,.25)}
#search-status.is-error{color:#842029;background:rgba(220,53,69,.15);border-color:rgba(220,53,69,.25)}
#search-status.is-warning{color:#664d03;background:rgba(255,193,7,.15);border-color:rgba(255,193,7,.30)}
#search-status.is-info{color:#084298;background:rgba(13,110,253,.12);border-color:rgba(13,110,253,.25)}
#search-status.is-progress{color:#055160;background:rgba(13,202,240,.13);border-color:rgba(13,202,240,.25)}
.copy-flash{ transition: background-color .15s ease; background-color: rgba(25,135,84,.35) !important; }
    `;
    document.head.appendChild(style);
  })();

  // ── Pretty status banner (emoji + spinner) ───────────────────────────────────
  status?.setAttribute('role','status');
  status?.setAttribute('aria-live','polite');

  function setStatus(type, msg) {
    if (!status) return;
    const types = {
      success:{cls:'is-success',emoji:'✅'},
      error:{cls:'is-error',emoji:'⛔'},
      warning:{cls:'is-warning',emoji:'⚠️'},
      info:{cls:'is-info',emoji:'ℹ️'},
      progress:{cls:'is-progress',emoji:'⏳'}
    };
    if (!msg) { status.className = 'mt-3 d-none'; status.innerHTML = ''; return; }
    const t = types[type] || types.info;
    status.className = `mt-3 status-banner ${t.cls}`;
    const spinner = (type === 'progress') ? '<span class="spinner" aria-hidden="true"></span>' : '';
    status.innerHTML = `${spinner}<span>${t.emoji} ${esc(msg)}</span>`;
  }

  async function api(action, bodyObj) {
    const res = await fetch(INV.logicUrl + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(bodyObj || {})
    });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {
      throw new Error(`Server did not return JSON (HTTP ${res.status}). Starts with: ${text.slice(0,120)}`);
    }
    if (!res.ok || !json.ok) throw new Error(json?.error || `HTTP ${res.status}`);
    return json.data;
  }

  // ── Persistent state for "Items to check" ─────────────────────────────────────
  const CHK_STORAGE_KEY = 'inv_chk_state_v1';
  function loadChkState() {
    try {
      const raw = localStorage.getItem(CHK_STORAGE_KEY);
      if (!raw) return null;
      const s = JSON.parse(raw);
      return (s && typeof s === 'object') ? s : null;
    } catch { return null; }
  }
  function saveChkState(extra = {}) {
    const state = {
      q: (chkQ?.value || '').trim(),
      field: (chkField?.value || 'name'),
      limit: Math.max(1, Math.min(100, parseInt(chkLimit?.value || '20', 10))),
      page: Math.max(1, chkPage),
      sort_key: chkSort.key,
      sort_dir: chkSort.dir,
      ...extra
    };
    try { localStorage.setItem(CHK_STORAGE_KEY, JSON.stringify(state)); } catch {}
  }

  // ── AUTO toggles helpers ─────────────────────────────────────────────────────
  function loadAutoAdd()  { try { return localStorage.getItem(AUTO_ADD_KEY)  === '1'; } catch { return false; } }
  function saveAutoAdd(v) { try { localStorage.setItem(AUTO_ADD_KEY,  v ? '1' : '0'); } catch {} }
  function loadAutoFire() { try { return localStorage.getItem(AUTO_FIRE_KEY) === '1'; } catch { return false; } }
  function saveAutoFire(v){ try { localStorage.setItem(AUTO_FIRE_KEY, v ? '1' : '0'); } catch {} }

  // Basic EAN checks
  function isDigits(s) { return /^[0-9]+$/.test(s); }
  function eanChecksumOk(s) {
    if (s.length === 13 && isDigits(s)) {
      const digits = s.split('').map(d => +d);
      const check = digits.pop();
      const sum = digits.reduce((acc, d, i) => acc + d * (i % 2 ? 3 : 1), 0);
      const calc = (10 - (sum % 10)) % 10;
      return calc === check;
    }
    if (s.length === 8 && isDigits(s)) {
      const digits = s.split('').map(d => +d);
      const check = digits.pop();
      const weights = [3,1,3,1,3,1,3];
      const sum = digits.reduce((acc, d, i) => acc + d * weights[i], 0);
      const calc = (10 - (sum % 10)) % 10;
      return calc === check;
    }
    return false;
  }
  function looksLikeEAN(s) {
    if (!isDigits(s)) return false;
    const len = s.length;
    if (len === 13 || len === 8) return eanChecksumOk(s);
    if (len === 12 || len === 14) return true;
    return false;
  }

  // ── AUTO-FIRE on EAN scan (guarded, debounced) ───────────────────────────────
  let scanDebounce = null;
  let lastTriggeredEAN = '';
  let submitInFlight = false;
  function okEANLength(len) { return len === 8 || len === 12 || len === 13 || len === 14; }

  qInput?.addEventListener('input', () => {
    const vRaw = (qInput.value || '').trim();
    if (!autoFireToggle?.checked) return;
    if (!vRaw) { lastTriggeredEAN = ''; return; }
    if (!/^\d+$/.test(vRaw)) return;
    if (!okEANLength(vRaw.length)) return;
    if ((vRaw.length === 8 || vRaw.length === 13) && !eanChecksumOk(vRaw)) return;

    if (scanDebounce) clearTimeout(scanDebounce);
    scanDebounce = setTimeout(() => {
      if (submitInFlight || vRaw === lastTriggeredEAN) return;
      lastTriggeredEAN = vRaw;
      submitInFlight = true;
      try {
        form?.requestSubmit?.();
      } finally {
        setTimeout(() => { submitInFlight = false; }, 100);
      }
    }, 150);
  });

  // ---- Search / confirm flow (ADD) ----
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = (qInput?.value || '').trim();
    if (!q) { setStatus('warning','Please enter a query.'); return; }

    const autoAdd = !!autoAddToggle?.checked;
    if (autoAdd && looksLikeEAN(q)) {
      try {
        setStatus('progress','Scanning EAN…');
        const data = await api('search', { query: q });
        const hit  = data?.hit || null;
        if (!hit) { setStatus('error','EAN not found. Nothing added.'); return; }
        if ((hit.CarovyKod || '').trim() !== q) {
          setStatus('warning','EAN mismatch (not primary EAN). Nothing added.');
          return;
        }
        const payload = {
          code: (hit.Kod || '').trim(),
          katalog: (hit.Katalog || '').trim(),
          ean: (hit.CarovyKod || '').trim(),
          name: (hit.Nazev || '').trim(),
          found_pieces: 1
        };
        await api('insert', payload);

        setStatus('success','Auto-added 1 piece.');
        qInput.value = '';
        lastTriggeredEAN = '';
        qInput.focus();
        await loadRecent();
        return;
      } catch (err) {
        console.error(err);
        setStatus('error','Auto-add failed: ' + err.message);
        return;
      }
    }

    // Manual path
    setStatus('progress','Searching…');
    try {
      const data = await api('search', { query: q });
      if (!data || !data.hit) { setStatus('error','No match in Artikly_Artikl (CarovyKod, Katalog, Kod).'); return; }

      editMode = false; editItem = null;

      lastFound = data.hit; // { Kod, Katalog, Nazev, CarovyKod, PLU }
      elKod.textContent     = lastFound.Kod || '—';
      elKatalog.textContent = lastFound.Katalog || '—';
      elNazev.textContent   = lastFound.Nazev || '—';
      elEAN.textContent     = lastFound.CarovyKod || '—';
      if (elPLU) elPLU.textContent = (lastFound.PLU ?? '') || '—';
      elQty.value = '';
      setStatus('info','Match found. Please confirm.');
      modal?.show();
    } catch (err) {
      console.error(err);
      setStatus('error','Search failed: ' + err.message);
    }
  });

  // ---- Confirm button handles both ADD and EDIT flows ----
  btnConfirm?.addEventListener('click', async () => {
    const qty = parseInt(elQty.value, 10);
    if (!Number.isFinite(qty) || qty < 0) { elQty.focus(); return; }

    try {
      if (editMode && editItem) {
        const payload = {
          code: (editItem.code || '').trim(),
          katalog: (editItem.katalog || '').trim(),
          ean: (editItem.ean || '').trim(),
          name: (editItem.name || '').trim(),
          found_pieces: qty
        };
        await api('replace_quantity', payload);
        setStatus('success','Updated: ' + (payload.code || payload.katalog || payload.ean) + ' → qty ' + qty + '.');
      } else {
        const payload = {
          code: (lastFound?.Kod || '').trim(),
          katalog: (lastFound?.Katalog || '').trim(),
          ean: (lastFound?.CarovyKod || '').trim(),
          name: (lastFound?.Nazev || '').trim(),
          found_pieces: qty
        };
        await api('insert', payload);
        setStatus('success','Saved: ' + (payload.code || payload.katalog || payload.ean) + ' (qty ' + qty + ').');
        qInput.select();
      }
      modal?.hide();
      await loadRecent();
    } catch (err) {
      console.error(err);
      setStatus('error', (editMode ? 'Update' : 'Save') + ' failed: ' + err.message);
    }
  });

  // ---- Clear-all button ----
  btnClearAll?.addEventListener('click', async () => {
    const msg = `This will DELETE ALL rows from inventory_checks.\n\nAre you sure?`;
    if (!confirm(msg)) return;
    btnClearAll.disabled = true;
    try {
      const data = await api('clear_all', {});
      const n = (data && typeof data.deleted === 'number') ? data.deleted : 0;
      setStatus('success', `Cleared all inventory checks (${n} row${n===1?'':'s'} removed).`);
      recentPage = 1;
      await loadRecent(true);
    } catch (err) {
      console.error(err);
      setStatus('error','Clear-all failed: ' + err.message);
    } finally {
      btnClearAll.disabled = false;
    }
  });

  btnClear?.addEventListener('click', () => {
    qInput.value = '';
    lastFound = null;
    lastTriggeredEAN = '';
    setStatus('', '');
    qInput.focus();
  });

  // ---- Difference renderer ----
  function renderDiff(found, stockTs) {
    const f = Number.isFinite(+found) ? +found : 0;
    const s = Number.isFinite(+stockTs) ? +stockTs : 0;
    const diff = f - s;
    if (!Number.isFinite(s)) return '<span class="text-secondary">—</span>';
    if (diff === 0) return '<span class="text-success" title="Match">✓</span>';
    if (diff < 0)   return `<span class="text-danger" title="Found less than stock">${diff}</span>`;
    return `<span class="text-warning" title="Found more than stock">+${diff}</span>`;
  }

  // metric helpers for sorting (client-side recent)
  const num = (val) => { const n = +val; return Number.isFinite(n) ? n : NaN; };
  const str = (val) => String(val ?? '').toLocaleLowerCase();

  // ===== Recently counted (client-side sort, server pagination) =====
  function sortRecent(rows) {
    const { key, dir } = recentSort;
    const sgn = dir === 'desc' ? -1 : 1;
    const cmp = (a, b) => {
      const stockA    = a.stock_ts ?? null;
      const stockB    = b.stock_ts ?? null;
      const reservedA = a.reserved_ts ?? null;
      const reservedB = b.reserved_ts ?? null;
      const diffA  = (Number.isFinite(+a.found_pieces) && Number.isFinite(+stockA)) ? (+a.found_pieces - +stockA) : NaN;
      const diffB  = (Number.isFinite(+b.found_pieces) && Number.isFinite(+stockB)) ? (+b.found_pieces - +stockB) : NaN;

      switch (key) {
        case 'katalog':  return sgn * str(a.katalog).localeCompare(str(b.katalog));
        case 'ean':      return sgn * str(a.ean).localeCompare(str(b.ean));
        case 'name':     return sgn * str(a.name).localeCompare(str(b.name));
        case 'plu':      return sgn * str(a.plu).localeCompare(str(b.plu));
        case 'found':    return sgn * (num(a.found_pieces) - num(b.found_pieces));
        case 'reserved': return sgn * (num(reservedA) - num(reservedB));
        case 'stock':    return sgn * (num(stockA) - num(stockB));
        case 'diff': {
          if (Number.isNaN(diffA) && Number.isNaN(diffB)) return 0;
          if (Number.isNaN(diffA)) return 1;
          if (Number.isNaN(diffB)) return -1;
          return sgn * (diffA - diffB);
        }
        case 'created':
        default:         return 0;
      }
    };
    return rows.slice().sort(cmp);
  }

  function renderRecent(rows) {
    recentRows = rows || [];
    rows = sortRecent(recentRows);

    if (!recentBody) return;
    if (!rows || rows.length === 0) {
      recentBody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No recent entries.</td></tr>';
      return;
    }

    const html = rows.map(r => {
      // raw values
      const rawKod     = r.code ?? '';
      const rawKatalog = r.katalog ?? '';
      const rawEAN     = r.ean ?? '';
      const rawName    = r.name ?? '';
      const rawPLU     = r.plu ?? '';
      const rawFound   = Number.isFinite(+r.found_pieces) ? +r.found_pieces : 0;

      // escaped for visible text
      const kod     = esc(rawKod);
      const katalog = esc(rawKatalog);
      const ean     = esc(rawEAN);
      const name    = esc(rawName);
      const plu     = esc(rawPLU);

      const reservedRaw = (r.reserved_ts == null) ? null : +r.reserved_ts;
      const reserved    = (reservedRaw == null || Number.isNaN(reservedRaw))
                            ? '<span class="text-secondary">—</span>'
                            : esc(String(reservedRaw));

      const stockRaw = (r.stock_ts == null) ? null : +r.stock_ts;
      const stock    = (stockRaw == null || Number.isNaN(stockRaw))
                          ? '<span class="text-secondary">—</span>'
                          : esc(String(stockRaw));

      const diffHtml = renderDiff(rawFound, stockRaw);

      return `<tr
        data-code="${escAttr(rawKod)}"
        data-katalog="${escAttr(rawKatalog)}"
        data-ean="${escAttr(rawEAN)}"
        data-name="${escAttr(rawName)}"
        data-plu="${escAttr(rawPLU)}"
        data-found="${escAttr(String(rawFound))}">
          <td class="copy" data-copy="${escAttr(rawKod)}" data-title="Kod" title="Click to copy" style="cursor:copy">${kod || '—'}</td>
          <td class="copy" data-copy="${escAttr(rawKatalog)}" data-title="Katalog" title="Click to copy" style="cursor:copy">${katalog || '—'}</td>
          <td class="copy" data-copy="${escAttr(rawEAN)}" data-title="EAN" title="Click to copy" style="cursor:copy">${ean || '—'}</td>
          <td>${plu || '<span class="text-secondary">—</span>'}</td>
          <td class="text-end copy" data-copy="${escAttr(String(rawFound))}" data-title="Found" title="Click to copy" style="cursor:copy">${rawFound}</td>
          <td class="text-end">${reserved}</td>
          <td class="text-end">${stock}</td>
          <td class="text-end">${diffHtml}</td>
          <td class="name-cell">${name}</td>
          <td class="text-end" style="width:12ch;">
            <button class="btn btn-sm btn-outline-primary me-1 js-edit" type="button">Edit</button>
            <button class="btn btn-sm btn-outline-danger js-delete" type="button">Delete</button>
          </td>
      </tr>`;
    }).join('');

    recentBody.innerHTML = html;

    // actions (stop propagation so copy doesn’t fire)
    recentBody.querySelectorAll('.js-edit').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        const tr = ev.currentTarget.closest('tr');
        const item = {
          code: tr.getAttribute('data-code') || '',
          katalog: tr.getAttribute('data-katalog') || '',
          ean: tr.getAttribute('data-ean') || '',
          name: tr.getAttribute('data-name') || '',
          plu: tr.getAttribute('data-plu') || '',
          found_pieces: parseInt(tr.getAttribute('data-found') || '0', 10) || 0
        };
        lastFound = null;
        editMode  = true;
        editItem  = item;
        elKod.textContent     = item.code || '—';
        elKatalog.textContent = item.katalog || '—';
        elNazev.textContent   = item.name || '—';
        elEAN.textContent     = item.ean || '—';
        if (elPLU) elPLU.textContent = item.plu || '—';
        elQty.value = String(item.found_pieces);
        setStatus('info','Editing item…');
        modal?.show();
      });
    });

    recentBody.querySelectorAll('.js-delete').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        const tr = ev.currentTarget.closest('tr');
        const code     = tr.getAttribute('data-code') || '';
        const katalog2 = tr.getAttribute('data-katalog') || '';
        const ean      = tr.getAttribute('data-ean') || '';
        const label    = code || katalog2 || ean || '(unknown)';
        if (!confirm(`Delete ALL rows for "${label}"? This cannot be undone.`)) return;
        try {
          await api('delete_item', { code, katalog: katalog2, ean });
          setStatus('success','Deleted: ' + label);
          await loadRecent();
        } catch (err) {
          console.error(err);
          setStatus('error','Delete failed: ' + err.message);
        }
      });
    });
  }

  // Delegated click-to-copy (class flash, no text changes)
  function flashCellBackground(td) {
    td.classList.add('copy-flash');
    setTimeout(() => td.classList.remove('copy-flash'), 600);
  }
  function legacyCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } finally { document.body.removeChild(ta); }
  }
  if (recentBody) {
    recentBody.addEventListener('click', async (ev) => {
      const td = ev.target.closest('td.copy');
      if (!td || !recentBody.contains(td)) return;
      if (ev.target.closest('button')) return;
      const value = td.getAttribute('data-copy') ?? '';
      try {
        if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(value);
        else legacyCopy(value);
      } catch { try { legacyCopy(value); } catch {} }
      flashCellBackground(td);
    });
  }

  async function loadRecent(force = false) {
    try {
      if (recentBody) recentBody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">Loading…</td></tr>';
      const limit = Math.max(1, Math.min(500, parseInt(recentLimit?.value || '20', 10)));
      const data = await api('list', { limit, page: recentPage });
      const total = Number.isFinite(+data?.total) ? +data.total : 0;
      const pages = Math.max(1, Math.ceil(total / limit));
      if (!force && recentPage > pages) {
        recentPage = pages;
        const data2 = await api('list', { limit, page: recentPage });
        renderRecent(data2?.rows || []);
        recentTotal = Number.isFinite(+data2?.total) ? +data2.total : 0;
      } else {
        renderRecent(data?.rows || []);
        recentTotal = total;
      }
      const pagesNow = Math.max(1, Math.ceil(recentTotal / limit));
      if (recentSummary) {
        recentSummary.textContent = recentTotal
          ? `Page ${recentPage} / ${pagesNow} • ${recentTotal.toLocaleString()} items`
          : 'No results';
      }
      if (recentPrev) recentPrev.disabled = (recentPage <= 1);
      if (recentNext) recentNext.disabled = (recentPage >= pagesNow);
    } catch (err) {
      console.error(err);
      if (recentBody) recentBody.innerHTML = `<tr><td colspan="10" class="text-danger">${esc(err.message)}</td></tr>`;
      if (recentSummary) recentSummary.textContent = '—';
      if (recentPrev) recentPrev.disabled = true;
      if (recentNext) recentNext.disabled = true;
    }
  }

  // ===== Items to Check (MSSQL stock search) — server-side sort + field =====
  function renderChecked(foundSum, stockTs) {
    if (foundSum === null || typeof foundSum === 'undefined') return '<span class="text-danger" title="Not counted yet">✕</span>';
    return renderDiff(foundSum, stockTs);
  }

  function renderCheckRows(rows, total, page, limit) {
    let html = '';
    const pages = Math.max(1, Math.ceil(total / limit));
    chkRows = rows || [];
    chkTotal = total;
    chkSummary.textContent = total ? `Page ${page} / ${pages} • ${total.toLocaleString()} items` : 'No results';
    chkPrev.disabled = (page <= 1);
    chkNext.disabled = (page >= pages);

    if (!rows || rows.length === 0) {
      chkBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No results.</td></tr>';
      return;
    }
    html = rows.map(r => {
      const kod      = esc(r.Kod ?? '');
      const katalog  = esc(r.Katalog ?? '');
      const plu      = esc(r.PLU ?? '');
      const name     = esc(r.Nazev ?? '');
      const stock    = Number.isFinite(+r.Stock)    ? +r.Stock    : 0;
      const reserved = Number.isFinite(+r.Reserved) ? +r.Reserved : 0;

      const foundSum = (r.FoundSum === null || typeof r.FoundSum === 'undefined')
        ? null
        : (Number.isFinite(+r.FoundSum) ? +r.FoundSum : null);

      const checkedHtml = renderChecked(foundSum, stock);

      return `<tr>
        <td class="text-start">${checkedHtml}</td>
        <td>${kod}</td>
        <td>${katalog}</td>
        <td>${plu || '<span class="text-secondary">—</span>'}</td>
        <td class="text-truncate" style="max-width: 520px;">${name}</td>
        <td class="text-end">${reserved}</td>
        <td class="text-end">${stock}</td>
      </tr>`;
    }).join('');
    chkBody.innerHTML = html;
  }

  let chkDebounceTimer = null;
  function scheduleCheckSearch(immediate=false) {
    if (chkDebounceTimer) clearTimeout(chkDebounceTimer);
    chkDebounceTimer = setTimeout(() => { void loadCheck(); }, immediate ? 0 : 450);
  }

  async function loadCheck() {
    const term  = (chkQ?.value || '').trim();
    const field = (chkField?.value || 'name');
    const minLen = field === 'plu' ? 2 : 3;
    const limit = Math.max(1, Math.min(100, parseInt(chkLimit?.value || '20', 10)));

    if (term.length < minLen) {
      chkBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted">Type at least ${minLen} character${minLen===1?'':'s'}…</td></tr>`;
      chkSummary.textContent = '—';
      chkPrev.disabled = true; chkNext.disabled = true;
      return;
    }

    if (term !== chkTerm) { chkTerm = term; chkPage = 1; }

    const page = Math.max(1, chkPage);
    const req = { q: term, field, page, limit, order_by: chkSort.key, order_dir: chkSort.dir };

    try {
      chkBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Searching…</td></tr>';
      const data = await api('find_stock', req);
      renderCheckRows(data.rows || [], data.total || 0, page, limit);

      const pages = Math.max(1, Math.ceil((data.total || 0) / limit));
      if (chkPage > pages) chkPage = pages;
      saveChkState({ page: chkPage, q: term, field, limit });
    } catch (err) {
      console.error(err);
      chkBody.innerHTML = `<tr><td colspan="7" class="text-danger">${esc(err.message)}</td></tr>`;
      chkSummary.textContent = '—';
      chkPrev.disabled = true; chkNext.disabled = true;
    }
  }

  // ===== Click-to-sort headers =====
  function installSortOnHead(theadEl, mapping, onChange) {
    if (!theadEl) return;
    const ths = Array.from(theadEl.querySelectorAll('th'));
    ths.forEach((th, idx) => {
      const key = mapping[idx];
      if (!key) return;
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => onChange(key, th));
    });
  }

  function paintSortIndicator(theadEl, mapping, sortState) {
    if (!theadEl) return;
    const ths = Array.from(theadEl.querySelectorAll('th'));
    ths.forEach((th, idx) => {
      const key = mapping[idx];
      const isActive = key && key === sortState.key;
      th.removeAttribute('aria-sort');
      const txt = th.textContent?.replace(/[▲▼]\s*$/u, '').trim() || '';
      th.textContent = txt;
      if (isActive) {
        th.setAttribute('aria-sort', sortState.dir === 'desc' ? 'descending' : 'ascending');
        th.insertAdjacentText('beforeend', sortState.dir === 'desc' ? ' ▼' : ' ▲');
      }
    });
  }

  const RECENT_MAP = ['kod','katalog','ean','plu','found','reserved','stock','diff','name', null];
  const CHK_MAP    = ['checked','kod','katalog','plu','name','reserved','stock'];

  installSortOnHead(recentHead, RECENT_MAP, (key) => {
    if (recentSort.key === key) {
      recentSort.dir = (recentSort.dir === 'asc') ? 'desc' : 'asc';
    } else {
      recentSort.key = key;
      recentSort.dir = (key === 'name' || key === 'katalog' || key === 'ean' || key === 'plu' || key === 'kod') ? 'asc' : 'desc';
    }
    paintSortIndicator(recentHead, RECENT_MAP, recentSort);
    renderRecent(recentRows);
  });

  installSortOnHead(chkHead, CHK_MAP, (key) => {
    if (chkSort.key === key) chkSort.dir = (chkSort.dir === 'asc') ? 'desc' : 'asc';
    else {
      chkSort.key = key;
      chkSort.dir = (key === 'name' || key === 'kod' || key === 'katalog' || key === 'plu') ? 'asc' : 'desc';
    }
    chkPage = 1;
    paintSortIndicator(chkHead, CHK_MAP, chkSort);
    saveChkState({ page: 1, sort_key: chkSort.key, sort_dir: chkSort.dir });
    scheduleCheckSearch(true);
  });

  function initIndicators() {
    paintSortIndicator(recentHead, RECENT_MAP, recentSort);
    paintSortIndicator(chkHead, CHK_MAP, chkSort);
  }

  // ── Restore toggles + “Items to check” state on load ─────────────────────────
  (function restoreUI() {
    const s = loadChkState();
    if (s) {
      if (chkField) chkField.value = s.field || 'name';
      if (chkLimit) chkLimit.value = String(s.limit || 20);
      if (chkQ)     chkQ.value     = s.q || '';
      if (s.sort_key) chkSort.key = s.sort_key;
      if (s.sort_dir) chkSort.dir = s.sort_dir;
      chkPage  = Math.max(1, parseInt(s.page || 1, 10));
      chkTerm  = s.q || '';
    }
    const autoAddEnabled  = loadAutoAdd();
    const autoFireEnabled = loadAutoFire();
    if (autoAddToggle)  autoAddToggle.checked  = autoAddEnabled;
    if (autoFireToggle) autoFireToggle.checked = autoFireEnabled;
    autoAddToggle?.addEventListener('change',  () => saveAutoAdd(!!autoAddToggle.checked));
    autoFireToggle?.addEventListener('change', () => saveAutoFire(!!autoFireToggle.checked));
  })();

  // Listeners: Items to check
  chkQ?.addEventListener('input', () => { saveChkState(); scheduleCheckSearch(false); });
  chkField?.addEventListener('change', () => { chkPage = 1; saveChkState({ page: 1 }); scheduleCheckSearch(true); });
  chkLimit?.addEventListener('change', () => {
    chkPage = 1;
    saveChkState({ page: 1, limit: Math.max(1, Math.min(100, parseInt(chkLimit?.value || '20', 10))) });
    scheduleCheckSearch(true);
  });
  chkRefresh?.addEventListener('click', () => { chkPage = 1; saveChkState({ page: 1 }); scheduleCheckSearch(true); });
  chkPrev?.addEventListener('click', () => { if (chkPage > 1) { chkPage--; saveChkState({ page: chkPage }); scheduleCheckSearch(true); } });
  chkNext?.addEventListener('click', () => { chkPage++; saveChkState({ page: chkPage }); scheduleCheckSearch(true); });

  // Listeners: Recently counted
  recentLimit?.addEventListener('change', () => { recentPage = 1; loadRecent(); });
  btnRefresh?.addEventListener('click', () => { recentPage = 1; loadRecent(true); });
  recentPrev?.addEventListener('click', () => { if (recentPage > 1) { recentPage--; loadRecent(); } });
  recentNext?.addEventListener('click', () => { recentPage++; loadRecent(); });

  // Initial loads
  initIndicators();
  loadRecent();
  (function initialChkSearch() {
    const term  = (chkQ?.value || '').trim();
    const field = (chkField?.value || 'name');
    const minLen = field === 'plu' ? 2 : 3;
    if (term.length >= minLen) { chkTerm = term; scheduleCheckSearch(true); }
  })();
})();
