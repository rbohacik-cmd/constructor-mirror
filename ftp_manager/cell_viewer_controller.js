(() => {
  const modalEl = document.getElementById('cellModal');
  if (!modalEl) return; // page didn't include the modal
  const bodyEl  = document.getElementById('cellModalBody');
  const titleEl = document.getElementById('cellModalTitle');
  const copyBtn = document.getElementById('copyCellBtn');
  const dlBtn   = document.getElementById('downloadCellBtn');

  const bsModal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;

  function prettyMaybe(text){
    try { return JSON.stringify(JSON.parse(text), null, 2); }
    catch { return text ?? ''; }
  }

  document.addEventListener('click', (e) => {
    const el = e.target.closest('.cell-clip');
    if (!el) return;

    const full  = el.getAttribute('data-full') || '';
    const title = el.getAttribute('data-title') || 'Cell value';

    titleEl.textContent  = title;
    bodyEl.textContent   = prettyMaybe(full);
    bsModal && bsModal.show();
  });

  copyBtn?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(bodyEl.textContent || '');
      copyBtn.textContent = 'Copied';
      setTimeout(() => (copyBtn.textContent = 'Copy'), 900);
    } catch {/* ignore */}
  });

  dlBtn?.addEventListener('click', () => {
    const blob = new Blob([bodyEl.textContent || ''], { type: 'text/plain;charset=utf-8' });
    const a = document.createElement('a');
    const url = URL.createObjectURL(blob);
    a.href = url;
    a.download = (titleEl.textContent || 'cell') + '.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  });
})();
