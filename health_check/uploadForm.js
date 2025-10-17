import { postJson } from './apiClient.js';
import { startProgress, updateProgress, endProgress } from './progress.js';

export function initUploadForm({ routes }) {
  const form         = document.getElementById('hc-upload-form');
  if (!form) return;

  const statusEl     = document.getElementById('hc-status');
  const progressWrap = document.getElementById('hc-progress-wrap');
  const progressBar  = document.getElementById('hc-progress');
  const mappingBox   = document.getElementById('hc-mapping');
  const mfgSelect    = document.getElementById('mfgSelect');
  const mfgNew       = document.getElementById('mfgNew');
  const fileInput    = document.getElementById('fileInput');

  const STOCK_ALIASES = [
    // EN
    'stock','stock_level','stock_available','available','available_pieces','available_piece',
    'available_qty','available_quantity','qty','quantity','in_stock','instock','onhand','on_hand',
    'inventory','inventory_qty','pieces_available','stock_qty','stock_quantity',
    // DE
    'stock_available_de','stock_de','lager','lager_de','lagerbestand','verfuegbar','verfügbar',
    // CZ/SK
    'sklad','skladom','stav_skladu',
  ];

  const msg = (text, ok=true) => {
    statusEl.textContent = text;
    statusEl.className = ok ? 'small text-success' : 'small text-danger';
  };
  const setDisabled = (dis) => { [...form.elements].forEach(el => { try { el.disabled = dis; } catch(_){} }); };

  // Accept the extra formats (tsv, xlsm)
  const validExt = (name) => ['csv','tsv','txt','xlsx','xls','xlsm'].includes((name.split('.').pop()||'').toLowerCase());

  // --- Optional: quick header preflight for CSV/TSV to hint stock column
  const normKey = (s) => String(s || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  const guessDelimiter = (sample, def=',') => {
    const c = { ',': 0, ';': 0, '\t': 0, '|': 0 };
    for (const k in c) c[k] = (sample.match(new RegExp('\\' + k, 'g')) || []).length;
    return Object.entries(c).sort((a,b)=>b[1]-a[1])[0]?.[0] || def;
  };
  const preflightHeaders = (file) => {
    const ext = (file.name.split('.').pop()||'').toLowerCase();
    if (!['csv','tsv','txt'].includes(ext)) return; // skip binary sheets
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const buf = String(reader.result || '');
        const firstLine = buf.split(/\r?\n/)[0] || '';
        if (!firstLine) return;
        const delim = ext === 'tsv' ? '\t' : guessDelimiter(firstLine);
        // quick split (good enough for typical header lines)
        const headers = firstLine.split(delim).map(h => h.replace(/^["']|["']$/g,'').trim());
        const norm = headers.map(normKey);
        let found = null;
        norm.some((k, i) => {
          if (STOCK_ALIASES.includes(k)) { found = headers[i]; return true; }
          return false;
        });
        if (mappingBox) {
          const note = found
            ? `Header hint: stock column detected as “${found}”.`
            : 'Header hint: no obvious stock column found.';
          // show as muted preflight line; will be overwritten by server progress later
          const pre = document.createElement('div');
          pre.className = 'small muted';
          pre.textContent = note;
          // clear any older preflight hints
          [...mappingBox.querySelectorAll('.small.muted')].forEach(n => n.remove());
          mappingBox.prepend(pre);
        }
      } catch (_) {}
    };
    reader.readAsText(file.slice(0, 65536)); // read first 64KB
  };

  // When file is picked, run the optional preflight
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      const f = fileInput.files && fileInput.files[0];
      if (f) preflightHeaders(f);
    });
  }

  async function pollProgress(uploadId, tableName) {
    let timer;
    const tick = async () => {
      try {
        const j = await postJson(routes.progress, { upload_id: uploadId });
        const p = j.progress || j;
        const total = typeof p.total_rows === 'number' ? p.total_rows : (p.total ?? null);
        const processed = typeof p.processed === 'number' ? p.processed : 0;
        const status = String(p.status || '').toLowerCase();
        const note = p.note || null;
        let pct = 0;
        if (typeof total === 'number' && total > 0) pct = Math.min(100, Math.round((processed/total)*100));
        else pct = Math.min(95, Math.max(5, (processed % 90) + 5));
        updateProgress(progressBar, pct);
        if (mappingBox) {
          mappingBox.textContent =
            `Status: ${status}${note ? ` (${note})` : ''}\n` +
            `Processed: ${processed}${typeof total === 'number' ? ` / ${total}` : ''}\n` +
            `Rate: — rows/s, — KB/s\nElapsed: —`;
        }
        if (status === 'imported' || status === 'failed') {
          clearInterval(timer);
          endProgress(progressWrap, progressBar);
          setDisabled(false);
          msg(status === 'imported'
            ? `Imported ${processed}${total?` / ${total}`:''} rows into ${tableName}.`
            : 'Import failed. Check logs.', status === 'imported');
          setTimeout(() => location.reload(), 1200);
        }
      } catch { /* ignore transient */ }
    };
    timer = setInterval(tick, 700);
    tick();
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const manufacturer = (mfgNew.value.trim() !== '') ? mfgNew.value.trim() : (mfgSelect.value || '').trim();
    if (!manufacturer) { msg('Pick or enter a manufacturer name.', false); return; }
    if (!fileInput.files.length) { msg('Pick a CSV/TSV/TXT or Excel file.', false); return; }
    const file = fileInput.files[0];
    if (!validExt(file.name)) { msg('Unsupported type. Use .csv, .tsv, .txt, .xlsx, .xls, or .xlsm', false); return; }

    const fd = new FormData();
    fd.append('manufacturer', manufacturer);
    fd.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', routes.upload, true);

    xhr.upload.addEventListener('loadstart', () => {
      setDisabled(true);
      msg('Uploading…');
      if (mappingBox) mappingBox.textContent = '';
      startProgress(progressWrap, progressBar);
      updateProgress(progressBar, 5);
    });
    xhr.upload.addEventListener('progress', (ev) => {
      if (ev.lengthComputable) {
        const pct = (ev.loaded / ev.total) * 100;
        updateProgress(progressBar, Math.max(5, Math.min(90, pct)));
      }
    });

    xhr.onreadystatechange = async () => {
      if (xhr.readyState !== 4) return;

      let j;
      try { j = JSON.parse(xhr.responseText || '{}'); }
      catch { endProgress(progressWrap, progressBar); setDisabled(false); msg('Invalid response from server.', false); return; }
      if (!j.ok) { setDisabled(false); endProgress(progressWrap, progressBar); msg(j.error || 'Upload failed.', false); return; }

      const uploadId = j.upload_id;
      const tbl = j.manufacturer?.table || '(unknown table)';

      msg('Processing…', true);
      pollProgress(uploadId, tbl);

      // fire-and-forget import
      postJson(routes.import, { upload_id: uploadId }).catch(()=>{});

      fileInput.value = '';
      if (mfgNew.value.trim() !== '') mfgNew.value = '';
    };

    xhr.onerror = () => { endProgress(progressWrap, progressBar); setDisabled(false); msg('Upload failed due to a network error.', false); };
    xhr.send(fd);
  });
}
