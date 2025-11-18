(function () {
  const grid = document.getElementById('servers-grid');
  if (!grid) return;

  function $(id) { return document.getElementById(id); }

  function setStatus(key, status, message) {
    const dot  = $('dot-' + key);
    const info = $('info-' + key);
    if (dot) {
      dot.classList.remove('status-pending','status-ok','status-fail');
      dot.classList.add(status === 'ok' ? 'status-ok' : status === 'fail' ? 'status-fail' : 'status-pending');
    }
    if (info) info.textContent = message || (status === 'ok' ? 'Connection OK' : status === 'fail' ? 'Connection failed' : 'checking…');
  }

  async function pingCard(cardEl) {
    const key = cardEl.dataset.server;
    const url = cardEl.dataset.pingEndpoint;
    if (!key || !url) return;

    setStatus(key, 'pending', 'checking…');

    // Hard client-side timeout so we never hang indefinitely
    const ac = new AbortController();
    const t = setTimeout(() => ac.abort(), 10000); // 10s

    try {
      const res = await fetch(url, { cache: 'no-store', signal: ac.signal });
      clearTimeout(t);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json(); // expect { ok:boolean, info?:string, error?:string }
      if (data && data.ok) {
        setStatus(key, 'ok', data.info || 'Connected');
      } else {
        setStatus(key, 'fail', (data && data.error) ? data.error : 'Connection failed');
      }
    } catch (e) {
      clearTimeout(t);
      setStatus(key, 'fail', (e && e.name === 'AbortError') ? 'Timed out' : String(e.message || e));
    }
  }

  window.retryPing = function (key) {
    const card = document.querySelector(`.server-card[data-server="${CSS.escape(key)}"]`);
    if (card) pingCard(card);
  };

  const retryAll = document.getElementById('retry-all');
  if (retryAll) retryAll.addEventListener('click', () => {
    document.querySelectorAll('.server-card').forEach(pingCard);
  });

  document.querySelectorAll('.server-card').forEach(pingCard);
})();
