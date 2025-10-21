// server-ts_bindings.js
// All event listeners, URL persistence, and initial boot.

(function () {
  if (window.__ts_bindings_installed) return;
  window.__ts_bindings_installed = true;

  const TS = window.TS;
  if (!TS) return;

  const { helpers = {} } = TS;
  const parseQs = helpers.parseQs || (() => {
    const url = new URL(location.href);
    return Object.fromEntries(url.searchParams.entries());
  });
  const pushQs = helpers.pushQs || ((obj) => {
    const url = new URL(location.href);
    url.search = '';
    for (const [k, v] of Object.entries(obj)) {
      if (v !== undefined && v !== null && String(v) !== '') {
        url.searchParams.set(k, String(v));
      }
    }
    history.pushState({}, '', url.toString());
  });

  // If TS.ui isn't provided by core, create a lightweight one here
  const $ = (sel, root = document) => root.querySelector(sel);
  const ui = TS.ui || {
    formDb: $('#form-db'),
    fServer: $('#f-server'),
    fQdb: $('#f-qdb'),
    fDb: $('#f-db'),

    formTableSearch: $('#form-table-search'),
    fQTable: $('#f-qtable'),
    tablesList: $('#tables-list'),
    tablesEmpty: $('#tables-empty'),
    tablesTotal: $('#tables-total'),
    tablesPager: $('#tables-pager'),

    filterAllEl: $('#filter-all'),
    filterFavsEl: $('#filter-favs'),
    favCountEl: $('#favorites-count'),

    formRowsPer: $('#form-rows-per'),
    fRper: $('#f-rper'),

    formValueSearch: $('#form-value-search'),
    fQVal: $('#f-qval'),
    fQValCol: $('#f-qval-col'),
    btnQValClear: $('#btn-qval-clear'),

    keepServer: $('#ts-server-keep'),
    keepDb: $('#ts-db-keep'),
    keepTper: $('#ts-tper-keep'),
    keepRpage: $('#ts-rpage-keep'),
    keepRper: $('#ts-rper-keep'),
  };

  // --- DB form
  ui.formDb?.addEventListener('submit', (e) => {
    e.preventDefault();
    const qs = parseQs();
    pushQs({
      ...qs,
      server: ui.fServer?.value || '',
      qdb: ui.fQdb?.value || '',
      db: ui.fDb?.value || '',
    });
    TS.reload();
  });

  // --- Tables search
  ui.formTableSearch?.addEventListener('submit', (e) => {
    e.preventDefault();
    const qs = parseQs();
    pushQs({
      ...qs,
      server: ui.keepServer?.value || '',
      db: ui.keepDb?.value || '',
      q: ui.fQTable ? (ui.fQTable.value || '') : '',
      tpage: 1,
      tper: ui.keepTper?.value || '25',
      rpage: ui.keepRpage?.value || '1',
      rper: ui.keepRper?.value || '25',
    });
    TS.reload();
  });

  // --- Rows per page
  ui.formRowsPer?.addEventListener('change', (e) => {
    if (e.target === ui.fRper) {
      const qs = parseQs();
      pushQs({ ...qs, rper: ui.fRper.value || '25', rpage: qs.rpage || '1' });
      TS.reload();
    }
  });

  // --- Value search
  ui.formValueSearch?.addEventListener('submit', (e) => {
    e.preventDefault();
    const qs = parseQs();
    pushQs({
      ...qs,
      qv: ui.fQVal ? (ui.fQVal.value || '') : '',
      qvcol: ui.fQValCol ? (ui.fQValCol.value || '') : '',
      rpage: 1,
    });
    TS.reload();
  });

  ui.btnQValClear?.addEventListener('click', () => {
    if (ui.fQVal) ui.fQVal.value = '';
    if (ui.fQValCol) ui.fQValCol.value = '';
    const qs = parseQs();
    pushQs({ ...qs, qv: '', qvcol: '', rpage: 1 });
    TS.reload();
  });

  // --- Favorites view toggle persists in URL
  ui.filterAllEl?.addEventListener('change', () => {
    if (!ui.filterAllEl.checked) return;
    const qs = parseQs();
    pushQs({ ...qs, view: 'all' });
    // No refetch necessary; left list re-renders from current state
    TS.renderTablesSection(TS.state);
  });

  ui.filterFavsEl?.addEventListener('change', () => {
    if (!ui.filterFavsEl.checked) return;
    const qs = parseQs();
    pushQs({ ...qs, view: 'favs' });
    TS.renderTablesSection(TS.state);
  });

  // --- SPA back/forward
  window.addEventListener('popstate', TS.reload);

  // --- Ensure view param exists, then initial load
  (function ensureViewParam() {
    const qs = parseQs();
    if (!qs.view) pushQs({ ...qs, view: 'all' });
  })();

  TS.reload();
})();
