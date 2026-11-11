/* ShopStack — tiny API client.
 * Tokens are held in sessionStorage (per-tab) — see README note: a hardened
 * production SPA should pair this token API with an in-memory + BFF pattern.
 */
const api = {
  tokenKey: 'ss_token',
  base: '/api/v1',

  get token() { return sessionStorage.getItem(this.tokenKey); },
  set token(v) { v ? sessionStorage.setItem(this.tokenKey, v) : sessionStorage.removeItem(this.tokenKey); },

  /** Switch between the tenant plane and the isolated super-admin plane. */
  setScope(kind) {
    if (kind === 'sa') { this.tokenKey = 'ss_sa_token'; this.base = '/api/v1/super-admin'; }
    else { this.tokenKey = 'ss_token'; this.base = '/api/v1'; }
  },

  async req(method, url, body = null, isForm = false) {
    const headers = { 'Accept': 'application/json' };
    if (this.token) headers['Authorization'] = 'Bearer ' + this.token;
    if (body && !isForm) headers['Content-Type'] = 'application/json';

    const res = await fetch(this.base + url, {
      method,
      headers,
      body: body ? (isForm ? body : JSON.stringify(body)) : null,
    });

    let data = null;
    try { data = await res.json(); } catch (e) { /* non-JSON */ }

    if (res.status === 401) {
      this.token = null;
      const err = new Error((data && data.message) || 'Unauthenticated');
      err.status = 401; err.code = 'unauthenticated';
      throw err;
    }

    const err = new Error((data && data.message) || ('Request failed (' + res.status + ')'));
    if (!res.ok) {
      err.status = res.status;
      err.code = (data && data.code) || null;
      err.fields = (data && data.errors) || null;
      err.retryAfter = (data && data.retry_after) || res.headers.get('Retry-After');
      throw err;
    }
    return data;
  },

  get(u)          { return this.req('GET', u); },
  post(u, b)      { return this.req('POST', u, b); },
  put(u, b)       { return this.req('PUT', u, b); },
  del(u)          { return this.req('DELETE', u); },
  postForm(u, f)  { return this.req('POST', u, f, true); },
};

/* ---------- small DOM helpers ---------- */
const $  = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = (n, currency = 'GHS') => currency + ' ' + Number(n || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
const fmtDate = (iso) => iso ? new Date(iso).toLocaleString() : '—';

function mount(html) { $('#app').innerHTML = html; }

function toast(msg, kind = 'ok') {
  let host = $('#toast-host');
  if (!host) { host = document.createElement('div'); host.id = 'toast-host'; document.body.appendChild(host); }
  const t = document.createElement('div');
  t.className = 'toast ' + kind;
  t.textContent = msg;
  host.appendChild(t);
  setTimeout(() => t.classList.add('show'), 10);
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 4200);
}

function fieldErrors(err) {
  if (!err.fields) return '';
  return Object.entries(err.fields).map(([f, msgs]) => `<div class="field-err">${esc(f)}: ${esc(msgs[0])}</div>`).join('');
}

/* Modal helper: returns element; caller wires events; close on backdrop/× */
function openModal(title, bodyHtml, wide = false) {
  closeModal();
  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  wrap.innerHTML = `
    <div class="modal ${wide ? 'modal-wide' : ''}" role="dialog" aria-modal="true">
      <div class="modal-head"><h3>${esc(title)}</h3><button class="modal-x" data-close aria-label="Close">×</button></div>
      <div class="modal-body">${bodyHtml}</div>
    </div>`;
  wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-close]')) closeModal(); });
  document.body.appendChild(wrap);
  return wrap;
}
function closeModal() { $$('.modal-backdrop').forEach(m => m.remove()); }

function badge(text, kind = 'gray') { return `<span class="badge badge-${kind}">${esc(text)}</span>`; }

function subBadge(sub, tenantStatus) {
  if (tenantStatus === 'suspended') return badge('SUSPENDED', 'red');
  if (!sub) return badge('NO ACTIVE SUBSCRIPTION', 'red');
  const days = Math.floor(sub.days_left ?? 0);
  const kind = days <= 3 ? 'red' : (days <= 7 ? 'amber' : 'green');
  return badge(`${sub.plan} · ${days}d left`, kind);
}

/* ---------- more helpers ---------- */
const qs = (obj) => {
  const p = new URLSearchParams();
  Object.entries(obj || {}).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') p.set(k, v); });
  const s = p.toString();
  return s ? '?' + s : '';
};

const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); };
const todayStr = () => new Date().toISOString().slice(0, 10);

function csvExport(filename, headers, rows) {
  const escCsv = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const csv = [headers.map(escCsv).join(',')] 
    .concat(rows.map(r => r.map(escCsv).join(','))).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = filename; a.click();
  URL.revokeObjectURL(a.href);
}
