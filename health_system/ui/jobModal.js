// /health_system/ui/jobModal.js
window.HS = window.HS || {};

HS.openJobModal = async function(id, onSaved){
  const data = id ? await HS.api('jobs.get', { id }) : { columns_map:{}, transforms:{}, mode:'replace', enabled:1 };

  // mount modal host
  const host = document.getElementById('modal-host');
  host.innerHTML = `
<div class="modal fade" id="hsJobModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">${id ? 'Edit' : 'New'} Import Job</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="hsJobForm">
          <input type="hidden" name="id" value="${id || ''}">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Manufacturer</label>
              <input class="form-control" name="manufacturer"
                     value="${HS.esc(data.manufacturer || data.manufacturer_name || data.slug || '')}"
                     placeholder="Inline / Lindy / EFB" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Title</label>
              <input class="form-control" name="title" value="${HS.esc(data.title || '')}" required>
            </div>
            <div class="col-12">
              <label class="form-label">Source file</label>
              <div class="input-group">
                <input class="form-control" name="file_path"
                       value="${HS.esc(data.file_path || '')}"
                       placeholder="rel://Inline/2025-10/inline.xlsx" required>
                <button class="btn btn-outline-secondary" type="button" id="btnPick">Pick…</button>
              </div>
              <div class="form-text">Use rel://… for paths under server import root; CSV/XLSX supported.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Mode</label>
              <select class="form-select" name="mode">
                <option value="replace" ${data.mode==='replace' ? 'selected' : ''}>Replace</option>
                <option value="merge" ${data.mode==='merge' ? 'selected' : ''}>Merge</option>
              </select>
            </div>
          </div>

          <hr>
          <h6>Column mapping</h6>
          <div class="row g-2">
            ${['code','ean','name','stock'].map(k => `
              <div class="col-md-3">
                <label class="form-label text-uppercase">${k}</label>
                <input class="form-control" name="map_${k}"
                  value="${HS.esc((data.columns_map || {})[k] || '')}"
                  placeholder="A / header name / 0"
                  ${k==='code' || k==='stock' ? 'required' : ''}>
              </div>`).join('')}
          </div>

          <div class="mt-3">
            <h6>Transforms</h6>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Code suffix</label>
                <input class="form-control" name="tr_code_suffix"
                       value="${HS.esc(((data.transforms||{}).code||{}).suffix || '')}">
              </div>
              <div class="col-md-6">
                <label class="form-label">Name suffix</label>
                <input class="form-control" name="tr_name_suffix"
                       value="${HS.esc(((data.transforms||{}).name||{}).suffix || '')}">
              </div>
            </div>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="tr_code_trim"
                     ${(((data.transforms||{}).code||{}).trim) ? 'checked' : ''}>
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
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('hsJobModal'));
  modal.show();

  // file picker
  document.getElementById('btnPick').addEventListener('click', async () => {
    const pick = await HS.openPicker();
    if (pick) document.querySelector('#hsJobForm [name=file_path]').value = pick;
  });

  // save
  document.getElementById('btnSave').addEventListener('click', async () => {
    const f  = document.getElementById('hsJobForm');
    const fd = new FormData(f);

    const columns_map = {
      code:  fd.get('map_code')  || '',
      ean:   fd.get('map_ean')   || '',
      name:  fd.get('map_name')  || '',
      stock: fd.get('map_stock') || ''
    };

    const transforms = {
      code: { trim: document.getElementById('tr_code_trim').checked, suffix: fd.get('tr_code_suffix') || '' },
      name: { suffix: fd.get('tr_name_suffix') || '' }
    };

    const payload = {
      id:           fd.get('id') || '',
      manufacturer: fd.get('manufacturer') || '',
      title:        fd.get('title') || '',
      file_path:    fd.get('file_path') || '',
      mode:         fd.get('mode') || 'replace',
      enabled:      1,
      columns_map:  JSON.stringify(columns_map),
      transforms:   JSON.stringify(transforms)
    };

    await HS.api('jobs.save', payload);
    modal.hide();
    if (onSaved) onSaved();
  });
};
