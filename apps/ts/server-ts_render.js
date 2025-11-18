// server-ts_render.js
// Pure rendering (no event bindings). Safe fallbacks if TS.helpers isn't present.

(function () {
  const TS = window.TS;
  if (!TS) return;

  // ---------- Safe helpers (prefer TS.helpers, else local) ----------
  const H = TS.helpers || {};

  const $  = H.$  || ((sel, root = document) => root.querySelector(sel));
  const $$ = H.$$ || ((sel, root = document) => Array.from(root.querySelectorAll(sel)));

  const escapeHtml = H.escapeHtml || ((s) =>
    String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;")
  );

  const renderPager = H.renderPager || ((total) => (total ? "" : ""));

  const parseQs = H.parseQs || (() => {
    const url = new URL(location.href);
    return Object.fromEntries(url.searchParams.entries());
  });

  const buildQs = H.buildQs || ((obj) => {
    const url = new URL(location.href);
    url.search = "";
    for (const [k, v] of Object.entries(obj)) {
      if (v !== undefined && v !== null && String(v) !== "") {
        url.searchParams.set(k, String(v));
      }
    }
    return url;
  });

  // highlight helpers (fallbacks; if core set them, use those)
  const escRe = H.escRe || ((s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  const highlightHtml = H.highlightHtml || ((escapedStr, q) => {
    if (!q) return escapedStr;
    return escapedStr.replace(new RegExp(escRe(q), 'ig'), m => `<mark class="ts-hit">${m}</mark>`);
  });

  // ---------- UI refs (read-only; bindings live elsewhere) ----------
  const ui = TS.ui || {
    dbTitle: $("#db-title"),
    formDb: $("#form-db"),
    fServer: $("#f-server"),
    fQdb: $("#f-qdb"),
    fDb: $("#f-db"),

    formTableSearch: $("#form-table-search"),
    fQTable: $("#f-qtable"),
    tablesList: $("#tables-list"),
    tablesEmpty: $("#tables-empty"),
    tablesTotal: $("#tables-total"),
    tablesPager: $("#tables-pager"),

    filterAllEl: $("#filter-all"),
    filterFavsEl: $("#filter-favs"),
    favCountEl: $("#favorites-count"),

    structureName: $("#structure-table-name"),
    structureBody: $("#structure-body"),

    formRowsPer: $("#form-rows-per"),
    fRper: $("#f-rper"),

    dataTitle: $("#data-table-name"),
    rowCountBadge: $("#row-count"),
    dataWrap: $("#data-wrap"),
    dataHead: $("#data-head"),
    dataBody: $("#data-body"),
    dataEmpty: $("#data-empty"),
    dataNone: $("#data-none"),
    rowsPager: $("#rows-pager"),

    formValueSearch: $("#form-value-search"),
    fQVal: $("#f-qval"),
    fQValCol: $("#f-qval-col"),
    btnQValClear: $("#btn-qval-clear"),
  };

  // tiny safe setter
  const setText = (el, text) => { if (el) el.textContent = text; };

  // ---------- EXPORT: renderAll ----------
  TS.renderAll = function renderAll(state) {
    const {
      server, qdb, q, qv, qvcol, dbs, filteredDbs, selectedDb,
      tables, totalTables, table,
      tpage, tper, rpage, rper,
      cols, rows, rowCount, colNames, favorites
    } = state;

    // Keepers (if present)
    if (ui.fServer) ui.fServer.value = server || "";
    if (ui.keepServer) ui.keepServer.value = server || "";
    if (ui.keepDb) ui.keepDb.value = selectedDb || "";
    if (ui.keepTper) ui.keepTper.value = String(tper);
    if (ui.keepRper) ui.keepRper.value = String(rper);
    if (ui.keepRpage) ui.keepRpage.value = String(rpage);

    // Title / DB selects
    setText(ui.dbTitle, selectedDb ? `(${selectedDb})` : '');
    if (ui.fQdb) ui.fQdb.value = qdb || '';
    if (ui.fDb) {
      ui.fDb.innerHTML = '<option value="">(current)</option>' + (filteredDbs || []).map(d =>
        `<option value="${d}" ${d===selectedDb?'selected':''}>${d}</option>`).join('');
    }

    // Table search input (q)
    if (ui.fQTable) ui.fQTable.value = q || '';

    // Value search inputs
    if (ui.fQVal) ui.fQVal.value = qv || '';
    if (ui.btnQValClear) ui.btnQValClear.disabled = !qv;
    if (ui.fQValCol && typeof qvcol !== 'undefined') ui.fQValCol.value = qvcol || '';

    // Favorites counter
    if (ui.favCountEl) setText(ui.favCountEl, String((favorites || []).length));

    // Sync favorites radio from URL (?view=favs|all)
    const view = (parseQs().view || 'all').toLowerCase();
    if (ui.filterFavsEl) ui.filterFavsEl.checked = (view === 'favs');
    if (ui.filterAllEl)  ui.filterAllEl.checked  = (view !== 'favs');

    // Left column
    TS.renderTablesSection(state);

    // Structure + Data
    TS.renderStructureAndData(state);
  };

  // ---------- EXPORT: renderTablesSection ----------
  TS.renderTablesSection = function renderTablesSection(state) {
    const { tpage, tper, rper } = state;
    const isFavOnly = (parseQs().view === 'favs') || !!ui.filterFavsEl?.checked;

    // Choose source:
    // - Favorites: ALL favorites (no pagination), apply 'q' client-side
    // - All: paginated list from server
    let sourceTables = [];
    if (isFavOnly) {
      const favs = (state.favorites || []).slice();
      const q = (state.q || '').trim();
      sourceTables = q ? favs.filter(t => t.toLowerCase().includes(q.toLowerCase())) : favs;
      setText(ui.tablesTotal, String(sourceTables.length));
    } else {
      sourceTables = state.tables || [];
      setText(ui.tablesTotal, String(state.totalTables || 0));
    }

    // IMPORTANT: do NOT apply hooks in favorites view (prevents double-filtering to empty)
    const finalTables = isFavOnly ? sourceTables : (TS.hooks.filterTables(sourceTables));

    if (!finalTables.length) {
      if (ui.tablesList) ui.tablesList.innerHTML = '';
      if (ui.tablesEmpty) ui.tablesEmpty.classList.remove('d-none');
    } else {
      if (ui.tablesEmpty) ui.tablesEmpty.classList.add('d-none');
      const base = parseQs();
      if (ui.tablesList) {
        ui.tablesList.innerHTML = finalTables.map(t => {
          const href = buildQs({
            ...base,
            table: t,
            // preserve current pagers (even though favs view doesn’t show the left pager)
            tper: tper, tpage: tpage,
            rper: rper, rpage: 1
          }).toString();
          const isActive = (t === state.table);
          return TS.hooks.decorateTableItem(href, t, isActive, state);
        }).join('');
      }
    }

    // Hide the left pager in favorites view
    if (ui.tablesPager) {
      ui.tablesPager.innerHTML = isFavOnly
        ? ''
        : renderPager(state.totalTables || 0, tper, tpage, { ...parseQs(), tper }, 'tpage');
    }

    TS.hooks.afterRenderTables(state);
  };

  // ---------- EXPORT: renderStructureAndData ----------
  TS.renderStructureAndData = function renderStructureAndData(state) {
    const { table, cols, rowCount, rper, rpage, rows, colNames, qv, qvcol } = state;

    // Structure
    if (ui.structureName) ui.structureName.textContent = table ? `: ${table}` : '';
    if (ui.structureBody) {
      ui.structureBody.innerHTML = (cols || []).map(c => `
        <tr>
          <td>${c.Column ?? ''}</td>
          <td>${c.Type ?? ''}</td>
          <td>${c.max_length ?? ''}</td>
          <td>${c.precision ?? ''}</td>
          <td>${c.scale ?? ''}</td>
          <td>${(c.is_nullable ? 'YES' : 'NO')}</td>
        </tr>
      `).join('');
    }

    // Fill column dropdown for value search
    if (ui.fQValCol) {
      const names = (state.colNames && state.colNames.length)
        ? state.colNames
        : (Array.isArray(state.cols) ? state.cols.map(c => c.Column) : []);
      const selected = qvcol || '';
      const opts = ['<option value="">(all columns)</option>'].concat(
        names.map(n => `<option value="${escapeHtml(n)}"${selected===n?' selected':''}>${escapeHtml(n)}</option>`)
      );
      ui.fQValCol.innerHTML = opts.join('');
    }

    // Rows per selector (sync) — avoid $$; iterate select.options
    const rperVal = String(rper || 25);
    if (ui.fRper && ui.fRper.options) {
      Array.from(ui.fRper.options).forEach(opt => { opt.selected = (opt.value === rperVal); });
    }

    // Data preview
    setText(ui.dataTitle, table ? `: ${table}` : '');
    setText(ui.rowCountBadge, `${rowCount || 0} rows`);

    if (!table) {
      if (ui.dataEmpty) ui.dataEmpty.classList.remove('d-none');
      if (ui.dataNone) ui.dataNone.classList.add('d-none');
      if (ui.dataWrap) ui.dataWrap.style.display = 'none';
      if (ui.dataHead) ui.dataHead.innerHTML = '';
      if (ui.dataBody) ui.dataBody.innerHTML = '';
    } else if (!rows || !rows.length) {
      if (ui.dataEmpty) ui.dataEmpty.classList.add('d-none');
      if (ui.dataNone) ui.dataNone.classList.remove('d-none');
      if (ui.dataWrap) ui.dataWrap.style.display = 'none';
      if (ui.dataHead) ui.dataHead.innerHTML = (colNames || []).map(c => `<th>${c}</th>`).join('');
      if (ui.dataBody) ui.dataBody.innerHTML = '';
    } else {
      if (ui.dataEmpty) ui.dataEmpty.classList.add('d-none');
      if (ui.dataNone) ui.dataNone.classList.add('d-none');
      if (ui.dataWrap) ui.dataWrap.style.display = '';
      const headCols = Object.keys(rows[0] || {});
      if (ui.dataHead) ui.dataHead.innerHTML = headCols.map(c => `<th>${c}</th>`).join('');
      const limit = 120;
      if (ui.dataBody) {
        ui.dataBody.innerHTML = rows.map(r => {
          const tds = headCols.map(cn => {
            const v = r[cn];
            if (v === null || v === undefined) {
              return `<td><span class="cell-clip cell-null" data-col="${cn}" data-full="NULL" data-long="0" title="NULL"><code class="small">NULL</code></span></td>`;
            }
            let full = (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean')
                        ? String(v) : JSON.stringify(v);
            const isLong = full.length > limit ? 1 : 0;
            const short = isLong ? (full.slice(0, limit) + '…') : full;

            const shouldMark = qv && (!qvcol || qvcol === cn);
            const dispEsc = escapeHtml(short);
            const maybeMarked = shouldMark ? highlightHtml(dispEsc, qv) : dispEsc;

            return `<td><span class="cell-clip" data-col="${cn}" data-full="${escapeHtml(full)}" data-long="${isLong}" title="Click to view full value"><code class="small">${maybeMarked}</code></span></td>`;
          }).join('');
          return `<tr>${tds}</tr>`;
        }).join('');
      }
    }

    // Rows pagination
    if (ui.rowsPager) {
      ui.rowsPager.innerHTML = renderPager(rowCount || 0, rper, rpage, {
        ...parseQs(),
        rper
      }, 'rpage');
    }
  };
})();
