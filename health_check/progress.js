export function startProgress(wrap, bar) {
  if (!wrap || !bar) return;
  wrap.style.display = 'block';
  bar.style.width = '0%';
  bar.setAttribute('aria-valuenow', '0');
}
export function updateProgress(bar, pct) {
  if (!bar) return;
  const v = Math.max(0, Math.min(100, Math.round(pct)));
  bar.style.width = v + '%';
  bar.setAttribute('aria-valuenow', String(v));
}
export function endProgress(wrap, bar) {
  if (!wrap || !bar) return;
  bar.style.width = '100%';
  bar.setAttribute('aria-valuenow', '100');
  setTimeout(() => { wrap.style.display = 'none'; }, 800);
}
