<?php
// partials/cell_viewer.php
// Reusable modal; include on pages that render .cell-clip elements.
?>
<!-- Cell viewer modal -->
<div class="modal fade" id="cellModal" tabindex="-1" aria-hidden="true" aria-labelledby="cellModalTitle">
  <div class="modal-dialog modal-dialog-scrollable modal-xl">
    <div class="modal-content" style="background:#111a30;border:1px solid var(--border, #2a2f40);">
      <div class="modal-header">
        <h5 class="modal-title" id="cellModalTitle">Cell value</h5>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-info" id="copyCellBtn">Copy</button>
          <button type="button" class="btn btn-sm btn-outline-info" id="downloadCellBtn">Download</button>
          <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>
      <div class="modal-body">
        <pre id="cellModalBody" class="mb-0" style="white-space:pre-wrap;word-wrap:break-word;"></pre>
      </div>
    </div>
  </div>
</div>
