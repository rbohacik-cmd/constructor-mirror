// apiClient.js
export async function parseJsonResponse(res) {
  const text = await res.text();
  if (!text) {
    // If server gave no body and it wasn't OK, surface an error-ish shape.
    if (!res.ok) {
      return { ok: false, error: `HTTP ${res.status} ${res.statusText || ''}`.trim() };
    }
    return {}; // empty success body
  }

  try {
    return JSON.parse(text);
  } catch {
    // Not JSON (e.g., HTML error page) — return a shaped object for callers
    return {
      ok: false,
      error: text.trim().slice(0, 2000) || `HTTP ${res.status}`,
      raw: text,
    };
  }
}

export async function postJson(url, body = undefined) {
  const isFormData =
    typeof FormData !== 'undefined' && body instanceof FormData;

  const headers = { Accept: 'application/json' };
  if (!isFormData && body !== undefined && body !== null) {
    headers['Content-Type'] = 'application/json';
  }

  let res;
  try {
    res = await fetch(url, {
      method: 'POST',
      headers,
      body: isFormData
        ? body
        : (body === undefined || body === null ? undefined : JSON.stringify(body)),
    });
  } catch (e) {
    const err = new Error('Network error');
    err.status = 0;
    err.cause = e;
    err.url = url;
    throw err;
  }

  const j = await parseJsonResponse(res);

  if (!res.ok || j?.ok === false) {
    // Friendly default for 409, if backend didn’t send JSON
    const defaultMsg =
      res.status === 409
        ? 'Another import is already running for this manufacturer.'
        : `HTTP ${res.status}`;

    const err = new Error(j?.error || defaultMsg);
    err.status   = res.status;
    err.payload  = j;
    err.response = res;
    err.url      = url;
    throw err;
  }

  return j;
}
