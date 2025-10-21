// Addons for MSSQL Browser
// - Favorites toggle (MySQL-backed)
// - Inline star next to the selected table name
// - Works with split controller (core/render/bindings)

(function () {
  function install(TS) {
    if (!TS || install._done) return;
    install._done = true;

    const $  = (sel, root=document) => root.querySelector(sel);
    const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));
    const app = $("#ts-app");
    if (!app) return;

    // Safe HTML escaper (supports both split + legacy single-file controllers)
    const eh =
      (TS.helpers && TS.helpers.escapeHtml) ||
      TS.escapeHtml ||
      ((s) => String(s)
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;")
        .replace(/'/g,"&#039;"));

    const favoritesCount = $("#favorites-count");
    const favTopBtn = $("#fav-top-btn");

    // Mirror of current-DB favorites
    let favSet = new Set();

    // List item with star button
    TS.hooks.decorateTableItem = (href, tableName, isActive) => {
      const safeName = eh(tableName);
      const isFav = favSet.has(tableName);
      const star  = isFav ? '★' : '☆';
      const btnCls = isFav ? 'btn-warning' : 'btn-outline-secondary';
      return `
        <div class="list-group-item d-flex justify-content-between align-items-center${isActive ? ' active' : ''}">
          <a class="stretched-link text-reset text-decoration-none" href="${href}">${safeName}</a>
          <button class="btn btn-sm ${btnCls} ms-2 js-fav-toggle"
                  data-table="${safeName}" title="Toggle favorite">${star}</button>
        </div>`;
    };

    // Controller handles view=all|favs; no extra filtering here
    TS.hooks.filterTables = (tables) => tables;

    // Inline top star next to Data header table name
    function renderTopStar(state) {
      if (!favTopBtn) return;
      const curDb = state.selectedDb || '';
      const table = state.table || '';
      if (!curDb || !table) {
        favTopBtn.classList.add('d-none');
        return;
      }
      favTopBtn.classList.remove('d-none');

      const isFav = favSet.has(table);
      favTopBtn.textContent = isFav ? '★' : '☆';
      favTopBtn.classList.toggle('btn-warning', isFav);
      favTopBtn.classList.toggle('btn-outline-warning', !isFav);
      favTopBtn.title = isFav ? 'Remove from favorites' : 'Add to favorites';
      favTopBtn.setAttribute('aria-pressed', isFav ? 'true' : 'false');

      favTopBtn.onclick = async (e) => {
        e.preventDefault();
        await toggleFavorite(curDb, table);
      };
    }

    // Toggle favorite (server + local state)
    async function toggleFavorite(dbName, table) {
      try {
        const url = new URL('server-ts_logic.php', location.href);
        url.searchParams.set('action', 'fav_toggle');
        const form = new FormData();
        form.set('db', dbName);
        form.set('table', table);

        const res = await fetch(url.toString(), { method: 'POST', body: form, cache: 'no-store' });
        const j = await res.json();
        if (!j.ok) throw new Error(j.error || 'Favorite toggle failed');

        // Local set
        if (j.fav) favSet.add(table); else favSet.delete(table);

        // Persist into TS.state so subsequent renders stay in sync
        const cur = Array.isArray(TS.state?.favorites) ? TS.state.favorites.slice() : [];
        TS.state.favorites = j.fav
          ? Array.from(new Set(cur.concat([table])))
          : cur.filter(t => t !== table);

        if (favoritesCount) favoritesCount.textContent = String(TS.state.favorites.length);

        // Re-render left list + top star
        if (typeof TS.renderTablesSection === 'function') TS.renderTablesSection(TS.state);
        renderTopStar(TS.state);
      } catch (err) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning mt-2';
        alert.textContent = String(err);
        app.prepend(alert);
      }
    }

    // After tables render: bind stars, update counter, top star
    TS.hooks.afterRenderTables = (state) => {
      favSet = new Set(state.favorites || []);
      if (favoritesCount) favoritesCount.textContent = String((state.favorites || []).length);

      const tablesList = document.getElementById('tables-list');
      if (tablesList && !tablesList._tsFavBound) {
        tablesList._tsFavBound = true;
        // Delegate so we don’t re-bind on every render
        tablesList.addEventListener('click', (e) => {
          const btn = e.target.closest('.js-fav-toggle');
          if (!btn || !tablesList.contains(btn)) return;
          e.preventDefault(); e.stopPropagation();
          const table = btn.getAttribute('data-table') || '';
          const dbName = state.selectedDb || '';
          if (!dbName || !table) return;
          toggleFavorite(dbName, table);
        }, { passive: false });
      }

      renderTopStar(state);
    };

    // Also sync after every successful load
    const prevAfterLoad = TS.hooks.afterLoadState;
    TS.hooks.afterLoadState = (state) => {
      favSet = new Set(state.favorites || []);
      if (favoritesCount) favoritesCount.textContent = String((state.favorites || []).length);
      renderTopStar(state);
      prevAfterLoad?.(state);
    };
  }

  // If controller fires after this file:
  document.addEventListener('ts:ready', (e) => install(e.detail?.TS));
  // If controller already exists:
  if (window.TS) install(window.TS);
})();
