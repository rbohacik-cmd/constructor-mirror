// runProgress.js
(function () {
  window.HS = window.HS || {};

  // Simple modal host bootstrap (one per page)
  function ensureModalHost() {
    if (!document.getElementById('modal-host')) {
      const mh = document.createElement('div');
      mh.id = 'modal-host';
      document.body.appendChild(mh);
    }
  }

  // Open progress UI and poll until terminal status.
  // Accepts optional { onFinish(run) } callback to re-enable button or refresh UI.
  HS.openRunProgress = function(run_id, upload_id, opts = {}) {
    ensureModalHost();
    const host = document.getElementById('modal-host');
    host.innerHTML = `
<div class="modal fade" id="hsRunModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title mb-0">Import progress #${run_id}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="progress mb-2" style="height: 10px;">
          <div class="progress-bar" role="progressbar" style="width:0%">0%</div>
        </div>
        <div class="small text-secondary" id="hsRunInfo">Starting…</div>
        <div class="mt-2 small text-danger d-none" id="hsRunErr"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>`;

    const modalEl = document.getElementById('hsRunModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    const barEl  = modalEl.querySelector('.progress-bar');
    const infoEl = document.getElementById('hsRunInfo');
    const errEl  = document.getElementById('hsRunErr');
	
	const link = document.createElement('a');
	link.href = '#';
	link.className = 'small';
	link.textContent = 'Open logs panel';
	link.addEventListener('click', (ev) => { ev.preventDefault(); HS.logs?.openForRun(run_id, null); });
	infoEl.parentElement.appendChild(link);

    let stop = false;
    const stopPolling = () => { stop = true; };

    // stop polling when modal is hidden
    modalEl.addEventListener('hidden.bs.modal', () => {
      stopPolling();
      if (typeof opts.onFinish === 'function') {
        // we don't know terminal status if user closed; let caller decide
        try { opts.onFinish(null); } catch {}
      }
    }, { once: true });

    async function tick() {
      if (stop) return;
      try {
        // backend returns { ok:true, data:{ run, progress, status, error_message } }
        const res = await HS.api('runs.status', { run_id });
        const data = res || {};
        const run = data.run || {};
        const p   = data.progress || {};
        const phase = String(p.status || run.status || data.status || 'pending').toLowerCase();
        const errorMsg = run.error_message || data.error_message || '';

        // derive numbers safely
        const done  = Number(p.rows_done || 0);
        const total = Math.max(1, Number(p.rows_total || 0));
        // use server percent if provided, else compute client-side
        let pct = (typeof p.percent !== 'undefined')
          ? Math.round(Number(p.percent))
          : Math.floor((done / total) * 100);

        const isFinal = (phase === 'imported' || phase === 'failed' || phase === 'cancelled' || phase === 'canceled');

        // avoid premature 100% display before terminal state
        if (!isFinal && pct >= 100) pct = 99;
        pct = Math.max(0, Math.min(100, pct));

        // render bar
        barEl.style.width = pct + '%';
        barEl.textContent = pct + '%';

        // striped animation near the end of inserting
        if (phase === 'inserting' && pct >= 95 && !isFinal) {
          barEl.classList.add('progress-bar-striped', 'progress-bar-animated');
        } else {
          barEl.classList.remove('progress-bar-striped', 'progress-bar-animated');
        }

        // info line
        const label = phase.toUpperCase();
        infoEl.textContent = `${label} — ${done}/${total}`;

        // error (if any)
        if (errorMsg && (phase === 'failed' || run.status === 'failed')) {
          errEl.textContent = errorMsg;
          errEl.classList.remove('d-none');
        } else {
          errEl.textContent = '';
          errEl.classList.add('d-none');
        }

        // stop only on terminal run status
        if (run.status === 'imported' || run.status === 'failed' || run.status === 'cancelled' || run.status === 'canceled') {
          stopPolling();
          // ensure bar shows 100% on success
          if (run.status === 'imported') {
            barEl.style.width = '100%';
            barEl.textContent = '100%';
          }
          if (typeof opts.onFinish === 'function') {
            try { opts.onFinish(run); } catch {}
          }
          return;
        }

        setTimeout(tick, 1000);
      } catch (e) {
        // transient hiccup: keep polling
        setTimeout(tick, 1500);
      }
    }

    // Important: do NOT call import.start here; jobs.run already started it.
    tick();
  };

})();
