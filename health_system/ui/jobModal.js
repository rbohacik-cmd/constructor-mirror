// /health_system/ui/jobModal.js
window.HS = window.HS || {};

(function () {
  // --- helpers --------------------------------------------------------------
  function ensureModalHost() {
    let host = document.getElementById('modal-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'modal-host';
      document.body.appendChild(host);
    }
    return host;
  }

  // unwrap HS.api responses (supports {ok,data} or direct object)
  function apiData(resp) {
    if (resp && typeof resp === 'object' && 'ok' in resp) {
      if (resp.ok === false) throw resp; // let caller handle structured error
      return resp.data ?? {};
    }
    return resp ?? {};
  }

  // safe esc if HS.esc is not present
  const esc = (s) => {
    s = (s ?? '').toString();
    const span = document.createElement('span');
    span.textContent = s;
    return span.innerHTML;
  };

  // --- public ---------------------------------------------------------------
  HS.openJobModal = async function (id, onSaved) {
    // Fetch existing job (or defaults for new)
    const raw = id ? await HS.api('jobs.get', { id }) : { columns_map: {}, transforms: {}, mode: 'replace', enabled: 1 };
    const data = apiData(raw);

    // Safe access helpers
    const tCode = (data.transforms && data.transforms.code) || { trim:false, prefix:'' };
    const tName = (data.transforms && data.transforms.name) || { prefix:'' };
    const cmap  = data.columns_map || {};

    // mount modal host (auto-create if missing)
    const host = ensureModalHost();
    host.innerHTML = `
<div class="modal fade" id="hsJobModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">${id ? 'Edit' : 'New'} Import Job</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="hsJobForm" novalidate>
          <input type="hidden" name="id" value="${id || ''}">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Manufacturer</label>
              <input class="form-control" name="manufacturer"
                     value="${esc(data.manufacturer || data.manufacturer_name || data.slug || '')}"
                     placeholder="Inline / Lindy / EFB" required>
              <div class="invalid-feedback">Please enter a manufacturer.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Title</label>
              <input class="form-control" name="title" value="${esc(data.title || '')}" required>
              <div class="invalid-feedback">Title is required.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Source file</label>
              <div class="input-group">
                <input class="form-control" name="file_path"
                       value="${esc(data.file_path || '')}"
                       placeholder="rel://Inline/2025-10/inline.xlsx" required>
                <button class="btn btn-outline-secondary" type="button" id="btnPick">Pick…</button>
              </div>
              <div class="invalid-feedback">File path is required.</div>
              <div class="form-text">Use rel://… for paths under server import root; CSV/XLSX supported.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Mode</label>
              <select class="form-select" name="mode">
                <option value="replace" ${data.mode==='replace' ? 'selected' : ''}>Replace</option>
                <option value="merge"   ${data.mode==='merge'   ? 'selected' : ''}>Merge</option>
              </select>
              <div class="invalid-feedback">Invalid mode.</div>
            </div>
          </div>

          <hr>
          <h6>Column mapping</h6>
          <div class="row g-2">
            ${['code','ean','name','stock'].map(k => `
              <div class="col-md-3">
                <label class="form-label text-uppercase">${k}</label>
                <input class="form-control" name="map_${k}"
                  value="${esc(cmap[k] || '')}"
                  placeholder="A / header name / 0"
                  ${k==='code' || k==='stock' ? 'required' : ''}>
                <div class="invalid-feedback">${k.toUpperCase()} is required.</div>
              </div>`).join('')}
            <!-- Optional ETA mapping -->
            <div class="col-md-3">
              <label class="form-label text-uppercase">eta <span class="text-muted">(optional)</span></label>
              <input class="form-control" name="map_eta"
                     value="${esc(cmap.eta || '')}"
                     placeholder="ETA / Delivery / Arrives">
              <div class="form-text">Exact header name in supplier file if present.</div>
            </div>
          </div>

          <div class="mt-3">
            <h6>Transforms</h6>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Code prefix</label>
                <input class="form-control" name="tr_code_prefix" value="${esc(tCode.prefix || '')}">
              </div>
              <div class="col-md-6">
                <label class="form-label">Name prefix</label>
                <input class="form-control" name="tr_name_prefix" value="${esc(tName.prefix || '')}">
              </div>
            </div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="tr_code_trim" ${tCode.trim ? 'checked' : ''}>
              <label class="form-check-label" for="tr_code_trim">Trim code</label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" id="btnSave">Save</button>
      </div>
    </div>
  </div>
</div>`;

    // show modal
    const modalEl = host.querySelector('#hsJobModal');
    let modal = null;
    if (window.bootstrap?.Modal) {
      modal = window.bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static' });
      modal.show();
    } else {
      // fallback if Bootstrap JS not loaded
      modalEl.style.display = 'block';
      modalEl.classList.add('show');
    }

    // cleanup on hide (remove from DOM to avoid id collisions)
    modalEl.addEventListener('hidden.bs.modal', () => {
      modalEl.parentElement?.removeChild(modalEl);
    }, { once: true });

    // helpers
    const $ = (sel, root = host) => root.querySelector(sel);
    const form = host.querySelector('#hsJobForm');
    const btnSave = host.querySelector('#btnSave');

    function clearInvalid(root) {
      root.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
      root.querySelectorAll('.invalid-feedback').forEach(fb => fb.style.display = '');
    }
    function markInvalid(el, msg) {
      if (!el) return;
      el.classList.add('is-invalid');
      const fb = el.closest('.mb-3, .col, .col-md-3, .col-12, .form-group')?.querySelector('.invalid-feedback');
      if (fb && msg) fb.textContent = msg;
    }

    // file picker
    const btnPick = host.querySelector('#btnPick');
    btnPick?.addEventListener('click', async () => {
      const pick = await HS.openPicker?.();
      if (pick) $('#hsJobForm [name=file_path]').value = pick;
    });

    // save
    btnSave.addEventListener('click', async () => {
      clearInvalid(form);

      const fd = new FormData(form);

      const jobId        = Number(fd.get('id') || 0) || 0;
      const manufacturer = (fd.get('manufacturer') || '').trim();
      const title        = (fd.get('title') || '').trim();
      const file_path    = (fd.get('file_path') || '').trim();
      const mode         = (fd.get('mode') || 'replace');

      // Client-side requireds
      if (!manufacturer) markInvalid($('[name="manufacturer"]', form), 'Please enter a manufacturer.');
      if (!title)        markInvalid($('[name="title"]', form), 'Title is required.');
      if (!file_path)    markInvalid($('[name="file_path"]', form), 'File path is required.');
      if (!manufacturer || !title || !file_path) return;

      // Build mapping object (eta optional)
      const columns_map = {
        code:  (fd.get('map_code')  || '').trim(),
        ean:   (fd.get('map_ean')   || '').trim(),
        name:  (fd.get('map_name')  || '').trim(),
        stock: (fd.get('map_stock') || '').trim()
      };
      const eta = (fd.get('map_eta') || '').trim();
      if (eta) columns_map.eta = eta;

      // Basic required map fields
      if (!columns_map.code)  markInvalid($('[name="map_code"]', form),  'code is required.');
      if (!columns_map.stock) markInvalid($('[name="map_stock"]', form), 'stock is required.');
      if (!columns_map.code || !columns_map.stock) return;

      // transforms
      const transforms = {
        code: { trim: host.querySelector('#tr_code_trim').checked,
                prefix: (fd.get('tr_code_prefix') || '').trim() },
        name: { prefix: (fd.get('tr_name_prefix') || '').trim() }
      };

      const payload = {
        id: jobId || undefined,
        manufacturer,
        title,
        file_path,
        mode,
        enabled: 1,
        columns_map,
        transforms
      };

      btnSave.disabled = true;
      try {
        // POST JSON explicitly (HS.api may default to form)
        const resp = await HS.post(HS.apiUrl('jobs.save'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        // normalize response and notify
        const out = apiData(resp);
        HS.toast?.('Job saved');

        if (modal) modal.hide();
        if (typeof onSaved === 'function') onSaved(out);
      } catch (err) {
        // Server-side validation: { ok:false, message, fields:{...} }
        const fields = (err && err.fields) || {};
        if (fields.title)            markInvalid($('[name="title"]', form), 'Required');
        if (fields.file_path)        markInvalid($('[name="file_path"]', form), 'Required');
        if (fields.mode)             markInvalid($('[name="mode"]', form), 'Invalid');
        if (fields.manufacturer)     markInvalid($('[name="manufacturer"]', form), 'Required');
        if (fields.manufacturer_id)  markInvalid($('[name="manufacturer"]', form), 'Unknown manufacturer');

        HS.toast?.(err?.message || err?.error || 'Save failed');
        console.error('jobs.save failed', err);
      } finally {
        btnSave.disabled = false;
      }
    });
  };
})();
