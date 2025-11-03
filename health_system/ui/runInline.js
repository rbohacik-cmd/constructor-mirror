// /health_system/ui/runInline.js
(function () {
  window.HS = window.HS || {};

  HS.bindInlineRunButtons = function () {
    document.querySelectorAll('[data-hs-run-job]').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        const jobId = parseInt(btn.getAttribute('data-hs-run-job'), 10);
        HS.runJobInline(jobId, btn);
      });
    });
  };

  function slots(jobId) {
    return {
      row:      document.querySelector(`[data-hs-job-row="${jobId}"]`),
      status:   document.querySelector(`[data-hs-job-status="${jobId}"]`),
      finished: document.querySelector(`[data-hs-job-finished="${jobId}"]`),
      progress: document.querySelector(`[data-hs-job-progress="${jobId}"]`),
      logs:     document.querySelector(`[data-hs-job-logs="${jobId}"]`)
    };
  }

	// Replace the whole startJob with this version
	async function startJob(jobId, { inline = 0 } = {}) {
	  const payload = inline ? { id: jobId, inline: 1 } : { id: jobId };
	  const resp = await HS.api('jobs.run', payload);

	  // HS.api may return either:
	  // 1) wrapped: { ok:true, data:{ run_id, upload_id, ... } }
	  // 2) unwrapped: { run_id, upload_id, ... }
	  let ok, data, error;

	  if (resp && typeof resp === 'object' && 'ok' in resp) {
		ok    = !!resp.ok;
		data  = resp.data || {};
		error = resp.error || (data && data.error) || null;
	  } else {
		// unwrapped mode → treat as ok if run_id present
		ok    = !!(resp && resp.run_id);
		data  = resp || {};
		error = resp && resp.error ? resp.error : null;
	  }

	  // pull run_id from either data.run_id or direct run_id
	  const run_id = data.run_id ?? data.result?.run_id ?? resp?.run_id ?? resp?.result?.run_id;

	  if (ok && run_id) {
		return { ok: true, run_id, inlineUsed: !!inline, raw: resp };
	  }

	  // Surface backend diag if controller provided it (wrapped or unwrapped)
	  const diag = (data && data.diag) || resp?.data?.diag || resp?.diag;

	  return { ok: false, error: error || 'Failed to start', diag, raw: resp };
	}


  HS.runJobInline = async function (jobId, btn) {
    const s = slots(jobId);
    btn?.setAttribute('disabled', 'disabled');
    if (s.status)   s.status.textContent = 'starting…';
    if (s.progress) s.progress.textContent = '0%';
    if (s.logs)     s.logs.innerHTML = '';

    try {
      // 1) Try normal detached spawn
      let start = await startJob(jobId, { inline: 0 });

      // 2) If spawn failed, auto-fallback to inline mode for diagnostics
      if (!start.ok) {
        console.warn('[HS] spawn failed, trying inline fallback', start);
        if (s.status) s.status.textContent = 'starting (inline)…';
        const inlineStart = await startJob(jobId, { inline: 1 });
        if (!inlineStart.ok) {
          // bubble the richer error
          const err = new Error(inlineStart.error || 'Failed to start');
          err.diag = inlineStart.diag || inlineStart.raw?.data?.diag;
          throw err;
        }
        start = inlineStart;
      }

      const run_id = start.run_id;
      let lastLogId = 0;
      let done = false;

      // 3) Poll status + logs
      while (!done) {
        const st = await HS.api('runs.status', { run_id });
        const status    = st?.data?.run?.status || 'pending';
        const rowsDone  = st?.data?.progress?.rows_done  ?? 0;
        const rowsTotal = st?.data?.progress?.rows_total ?? 0;
        const pct = rowsTotal > 0 ? Math.floor((rowsDone / rowsTotal) * 100) : (status === 'imported' ? 100 : 0);

        if (s.status)   s.status.textContent = status + (start.inlineUsed ? ' (inline)' : '');
        if (s.progress) s.progress.textContent = rowsTotal ? `${rowsDone}/${rowsTotal} (${pct}%)` : `${pct}%`;

        // NOTE: API expects since_id, not after_id
        const lg = await HS.api('runs.logs', { run_id, since_id: lastLogId });
        const items = lg?.data?.items || [];
        if (items.length && s.logs) {
          const frag = document.createDocumentFragment();
          for (const it of items) {
            lastLogId = Math.max(lastLogId, it.id);
            const div = document.createElement('div');
            div.className = 'small text-body-secondary';
            // API returns ts, not created_at
            const ts = it.ts || it.created_at || '';
            const phase = it.phase ? ` [${it.phase}]` : '';
            div.textContent = `[${ts}]${phase} ${it.message}`;
            frag.appendChild(div);
          }
          s.logs.appendChild(frag);
          s.logs.scrollTop = s.logs.scrollHeight;
        }

        if (['imported','failed','cancelled'].includes(status)) {
          done = true;
          // refresh this row’s “last finished”
          const jl = await HS.api('jobs.list', {});
          const row = jl?.data?.items?.find(x => x.id === jobId);
          if (row && s.finished) s.finished.textContent = row.last_finished_at || '—';
          break;
        }

        await new Promise(r => setTimeout(r, 900));
      }
    } catch (err) {
      console.error('[HS] inline run error', err);
      if (s.status) s.status.textContent = 'failed';
      // Surface backend diagnostics if present
      if (err?.diag) {
        console.groupCollapsed('[HS] spawn diagnostics');
        console.log(err.diag);
        console.groupEnd();
      }
    } finally {
      btn?.removeAttribute('disabled');
    }
  };
})();
