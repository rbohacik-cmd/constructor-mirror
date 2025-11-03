// server-ts_core.js
// Core bootstrapping: TS namespace, DOM refs, helpers, network state loader

(function () {
  // Local DOM helpers
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  const app = $("#ts-app");
  if (!app) return;

  const serverError = app.getAttribute("data-server-error") || "";

  // ---- UI refs (shared by all modules) ----
  const ui = {
    app,
    // top
    dbTitle: $("#db-title"),
    formDb: $("#form-db"),
    fServer: $("#f-server"),
    fQdb: $("#f-qdb"),
    fDb: $("#f-db"),

    // tables
    formTableSearch: $("#form-table-search"),
    fQTable: $("#f-qtable"),
    tablesList: $("#tables-list"),
    tablesEmpty: $("#tables-empty"),
    tablesTotal: $("#tables-total"),
    tablesPager: $("#tables-pager"),

    // favorites filter
    filterAllEl:  $("#filter-all"),
    filterFavsEl: $("#filter-favs"),
    favCountEl:   $("#favorites-count"),

    // structure
    structureName: $("#structure-table-name"),
    structureBody: $("#structure-body"),

    // rows-per
    formRowsPer: $("#form-rows-per"),
    fRper: $("#f-rper"),

    // data
    dataTitle: $("#data-table-name"),
    rowCountBadge: $("#row-count"),
    dataWrap: $("#data-wrap"),
    dataHead: $("#data-head"),
    dataBody: $("#data-body"),
    dataEmpty: $("#data-empty"),
    dataNone: $("#data-none"),
    rowsPager: $("#rows-pager"),

    // value search
    formValueSearch: $("#form-value-search"),
    fQVal: $("#f-qval"),
    btnQValClear: $("#btn-qval-clear"),
    fQValCol: $("#f-qval-col"),

    // keepers
    keepServer: $("#ts-server-keep"),
    keepDb: $("#ts-db-keep"),
    keepTper: $("#ts-tper-keep"),
    keepRpage: $("#ts-rpage-keep"),
    keepRper: $("#ts-rper-keep"),

    // modal
    modalEl: $("#cellModal"),
    modalTitle: $("#cellModalLabel"),
    modalBody: $("#cellModalBody"),
    modalCopy: $("#cellCopyBtn"),
  };

  // ---- Helpers (shared) ----
  function parseQs() {
    const url = new URL(location.href);
    return Object.fromEntries(url.searchParams.entries());
  }
  function buildQs(obj) {
    const url = new URL(location.href);
    url.search = "";
    for (const [k,v] of Object.entries(obj)) {
      if (v !== undefined && v !== null && String(v) !== "") {
        url.searchParams.set(k, String(v));
      }
    }
    return url;
  }
  function pushQs(obj) {
    const url = buildQs(obj);
    history.pushState({}, "", url.toString());
  }
  function renderPager(total, per, page, baseQs, pageKey) {
    const pages = Math.ceil((total || 0) / Math.max(1, per));
    if (pages <= 1) return "";
    const mk = (p) => buildQs({ ...baseQs, [pageKey]: p }).toString();
    const want = new Set([1, 2, pages-1, pages].filter(x => x >= 1 && x <= pages));
    const W = 2;
    for (let p = page - W; p <= page + W; p++) if (p >=1 && p<=pages) want.add(p);
    const list = Array.from(want).sort((a,b)=>a-b);
    let html = '<nav><ul class="pagination pagination-sm m-0" style="flex-wrap:wrap;gap:.25rem;">';
    const prevDisabled = page <= 1;
    html += `<li class="page-item${prevDisabled?' disabled':''}"><a class="page-link" href="${prevDisabled?'#':mk(page-1)}">&laquo;</a></li>`;
    let prevShown = 0;
    for (const p of list) {
      if (prevShown && p > prevShown + 1) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
      const active = p === page;
      html += `<li class="page-item${active?' active':''}"><a class="page-link" href="${mk(p)}">${p}</a></li>`;
      prevShown = p;
    }
    const nextDisabled = page >= pages;
    html += `<li class="page-item${nextDisabled?' disabled':''}"><a class="page-link" href="${nextDisabled?'#':mk(page+1)}">&raquo;</a></li>`;
    html += '</ul></nav>';
    return html;
  }
  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }
  function escRe(s){ return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function highlightHtml(escapedStr, q){
    if(!q) return escapedStr;
    return escapedStr.replace(new RegExp(escRe(q), 'ig'), m => `<mark class="ts-hit">${m}</mark>`);
  }
  function setText(el, text) { if (el) el.textContent = text; }

  function hookInternalLinks(containers) {
    containers.forEach(c => {
      c?.addEventListener('click', (e) => {
        const a = e.target.closest('a[href]');
        if (!a) return;
        const href = a.getAttribute('href');
        if (!href || href.startsWith('http')) return;
        e.preventDefault();
        history.pushState({}, "", href);
        TS.reload();
      }, { passive: false });
    });
  }

  // ------- Modal helpers -------
  let bsModal = null;
  function showModal(title, text) {
    if (!ui.modalEl) return;
    ui.modalTitle.textContent = title || "Cell value";
    ui.modalBody.textContent = text ?? "";
    bsModal = bsModal || new bootstrap.Modal(ui.modalEl);
    bsModal.show();
  }
  ui.modalCopy?.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(ui.modalBody.textContent || "");
      ui.modalCopy.textContent = "Copied!";
      setTimeout(() => ui.modalCopy.textContent = "Copy", 900);
    } catch {
      ui.modalCopy.textContent = "Copy failed";
      setTimeout(() => ui.modalCopy.textContent = "Copy", 1200);
    }
  });
  document.addEventListener("click", (e) => {
    const el = e.target.closest('.cell-clip');
    if (!el) return;
    const full = el.getAttribute('data-full') || '';
    const col  = el.getAttribute('data-col') || 'Cell value';
    showModal(col, full);
  });

  // ---- TS namespace (core) ----
  const TS = (window.TS = {
    state: null,
    ui,
    // Export ALL helpers, including $ and $$ for consumers
    helpers: { $, $$, parseQs, buildQs, pushQs, renderPager, escapeHtml, highlightHtml, setText, hookInternalLinks },
    hooks: {
      filterTables: (tables)=>tables,
      decorateTableItem: (href, tableName, isActive) =>
        `<a class="list-group-item list-group-item-action${isActive?' active':''}" href="${href}">${tableName}</a>`,
      afterRenderTables: ()=>{},
      beforeLoadState: ()=>{},
      afterLoadState: ()=>{}
    },
    // to be filled by render module
    renderAll: null,
    renderTablesSection: null,
    renderStructureAndData: null,
    reload: loadState
  });

  // ---- Back-compat aliases (old addons expect these) ----
  TS.escapeHtml = escapeHtml;
  TS.$ = $;
  TS.$$ = $$;
  // (Optional) only set globals if not already defined (helps very old modules)
  if (!window.$)  window.$  = $;
  if (!window.$$) window.$$ = $$;

  // signal ready (addons can subscribe early)
  document.dispatchEvent(new CustomEvent('ts:ready', { detail: { TS } }));

  // -------- Network loader --------
  async function loadState() {
    if (serverError) return;
    TS.hooks.beforeLoadState?.();

    const url = new URL(location.href);
    const logicUrl = new URL("server-ts_logic.php", location.href);
    logicUrl.search = url.search;

    let j = null, rawText = '';
    try {
      const res = await fetch(logicUrl.toString(), { cache: 'no-store' });
      rawText = await res.text();
      try { j = JSON.parse(rawText); }
      catch { j = { ok: false, error: `HTTP ${res.status} ${res.statusText}`, raw: rawText }; }
    } catch (netErr) {
      j = { ok: false, error: `Network error: ${netErr}` };
    }

    // Minimal safe clear on error
    if (!j || !j.ok) {
      const { tablesList, tablesEmpty, tablesTotal, tablesPager,
              structureName, structureBody, dataTitle, rowCountBadge,
              dataWrap, dataEmpty, dataNone } = ui;
      setText(ui.dbTitle, '');
      if (tablesList) tablesList.innerHTML = '';
      tablesEmpty?.classList.add('d-none');
      setText(tablesTotal, '0');
      if (tablesPager) tablesPager.innerHTML = '';
      setText(structureName, '');
      if (structureBody) structureBody.innerHTML = '';
      setText(dataTitle, '');
      setText(rowCountBadge, '0 rows');
      if (dataWrap) dataWrap.style.display = 'none';
      dataEmpty?.classList.remove('d-none');
      dataNone?.classList.add('d-none');

      if (j && j.state && typeof TS.renderAll === 'function') {
        TS.state = j.state;
        TS.renderAll(j.state);
      }

      const old = ui.app.querySelector('.alert.alert-warning.ts-net');
      if (old) old.remove();
      const err = document.createElement('div');
      err.className = 'alert alert-warning mt-2 ts-net';
      err.textContent = (j && j.error) ? j.error : 'Unknown error';
      ui.app.prepend(err);
      return;
    }

    TS.state = j.state;
    if (typeof TS.renderAll === 'function') TS.renderAll(j.state);
    TS.hooks.afterLoadState?.(j.state);
  }
})();
