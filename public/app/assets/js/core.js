/* ShopStack tenant app — core: theme, session, layout, router glue.
   Everything depends only on globals from api.js (api, $, $$, esc, money…). */

/* ---------- theme (light | dark | system, persisted) ---------- */
const Theme = {
  KEY: 'ss_theme',
  get() { return localStorage.getItem(this.KEY) || 'system'; },
  set(mode) {
    localStorage.setItem(this.KEY, mode);
    document.documentElement.dataset.theme = mode;
  },
  cycle() {
    const order = ['light', 'dark', 'system'];
    this.set(order[(order.indexOf(this.get()) + 1) % order.length]);
    return this.get();
  },
};
Theme.set(Theme.get());

/* ---------- session state ---------- */
const State = {
  me: null, tenant: null, settings: null, lifecycle: null,
  get perms() { return this.me?.permissions || ['*']; },
  can(perm) {
    if (this.me?.role === 'owner') return true;
    const p = this.me?.permissions;
    if (!p) return perm === 'dashboard.view';
    return p.includes('*') || p.includes(perm);
  },
  clear() { this.me = this.tenant = this.settings = this.lifecycle = null; api.token = null; },
};

/* ---------- layout ---------- */
const NAV = [
  { group: 'Sell', items: [
    ['#/dashboard', '📊', 'Dashboard', 'dashboard.view'],
    ['#/pos', '🛒', 'POS Terminal', 'sales.create'],
    ['#/sales', '🧾', 'Sales History', 'sales.view'],
    ['#/invoices', '📄', 'Invoices', 'invoices.view'],
  ]},
  { group: 'Catalog', items: [
    ['#/products', '📦', 'Products', 'products.view'],
    ['#/inventory', '🗄️', 'Inventory', 'inventory.view'],
  ]},
  { group: 'People', items: [
    ['#/customers', '🤝', 'Customers', 'customers.view'],
    ['#/suppliers', '🚚', 'Suppliers', 'purchases.view'],
    ['#/purchases', '📥', 'Purchases', 'purchases.view'],
  ]},
  { group: 'Money', items: [
    ['#/expenses', '💸', 'Expenses', 'expenses.view'],
    ['#/reports', '📈', 'Reports & P&L', 'reports.view'],
  ]},
  { group: 'Workspace', items: [
    ['#/users', '👥', 'Team', 'users.view'],
    ['#/billing', '💳', 'Billing', 'billing.view'],
    ['#/activity', '🔐', 'Activity Log', 'activity.view'],
    ['#/settings', '⚙️', 'Settings', null],
    ['#/tickets', '🆘', 'Support', 'support.use'],
  ]},
];

function layout(contentEl, activePath) {
  const bellCount = State.settings?.__unread ?? 0;
  mount(`
  <div class="layout">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-head"><span class="mark">S</span><div>
        <strong>${esc(State.tenant?.name || 'ShopStack')}</strong>
        <div style="font-size:.72rem;opacity:.7">${esc(State.tenant?.city || State.me?.name || '')}</div>
      </div></div>
      <nav class="nav">
        ${NAV.map(g => `
          ${g.items.some(([, , , p]) => !p || State.can(p)) ? `<div class="nav-group">${g.group}</div>` : ''}
          ${g.items.map(([href, ico, label, perm]) =>
            (!perm || State.can(perm))
              ? `<a href="${href}" class="${activePath === href ? 'active' : ''}"><span class="nav-ico">${ico}</span>${label}</a>`
              : '').join('')}
        `).join('')}
      </nav>
      <div class="sidebar-foot">
        <div>${esc(State.me?.name)} · <span style="text-transform:capitalize">${esc(State.me?.role)}</span></div>
        <a href="#" id="logout-link" class="muted" style="color:inherit">Sign out</a>
      </div>
    </aside>
    <div class="main">
      ${State.settings?.__impersonated ? `
        <div class="impers-bar">⚠ You are operating this workspace on behalf of a super admin session.
        <a href="#/impersonation" id="end-impers" style="color:#ffd; text-decoration:underline;">End impersonation</a></div>` : ''}
      ${lifecycleBanner()}
      <header class="topbar">
        <button class="btn btn-ghost burger" id="burger" aria-label="Menu">☰</button>
        <h1>${esc(document.title.split(' — ')[0])}</h1>
        <div class="spacer"></div>
        <div class="bell">
          <button class="icon-btn" id="bell" aria-label="Notifications">🔔${bellCount ? `<span class="dot">${bellCount}</span>` : ''}</button>
        </div>
        <button class="icon-btn" id="theme-toggle" title="Theme (light → dark → system)">${Theme.get() === 'dark' ? '🌙' : Theme.get() === 'light' ? '☀️' : '🖥'}</button>
      </header>
      <main class="content" id="view"></main>
    </div>
  </div>`);

  document.title = document.title + ' — ShopStack';
  $('#view').appendChild(contentEl);
  $('#burger').onclick = () => $('#sidebar').classList.toggle('open');
  $('#theme-toggle').onclick = () => { Theme.cycle(); layout(contentEl, activePath); };
  $('#logout-link').onclick = async (e) => {
    e.preventDefault();
    try { await api.post('/auth/logout'); } catch (_) {}
    State.clear(); location.hash = '#/login';
  };
  $('#bell').onclick = () => Notifications.toggle();
  const impers = $('#end-impers');
  if (impers) impers.onclick = async (e) => { e.preventDefault(); try { await api.post('/impersonation/end'); } catch (_) {} location.reload(); };
}

function lifecycleBanner() {
  const lc = State.lifecycle;
  if (!lc) return '';
  if (lc.stage === 'GRACE') {
    return `<div class="banner banner-amber">⏳ <strong>Grace period.</strong> ${esc(lc.detail || '')}
      <a href="#/billing" class="btn btn-sm btn-primary" style="margin-left:auto">Renew now</a></div>`;
  }
  if (lc.stage === 'EXPIRING' || lc.stage === 'TRIAL') {
    const days = lc.days_left ?? lc.days_left === 0 ? lc.days_left : null;
    if (days !== null && days <= 7) {
      return `<div class="banner banner-blue">💳 <strong>${lc.stage === 'TRIAL' ? 'Trial' : 'Subscription'} ends in ${days} day(s).</strong>
        <a href="#/billing" class="btn btn-sm" style="margin-left:auto">View billing</a></div>`;
    }
  }
  if (lc.stage === 'EXPIRED') {
    return `<div class="banner banner-red">🔒 <strong>Subscription expired.</strong> Your data is safe. Renew to continue working.
      <a href="#/billing" class="btn btn-sm btn-danger" style="margin-left:auto">Renew</a></div>`;
  }
  return '';
}

/* ---------- page scaffolding ---------- */
function page(title) {
  const el = document.createElement('div');
  el.innerHTML = '';
  document.title = title;
  return el;
}

function loadingBlock(rows = 3) {
  return `<div class="grid" style="gap:.7rem">${'<div class="skeleton" style="height:60px"></div>'.repeat(rows)}</div>`;
}

function emptyBlock(icon, text, actionHtml = '') {
  return `<div class="empty"><span class="empty-icon">${icon}</span><p>${esc(text)}</p>${actionHtml}</div>`;
}

function errBlock(e) {
  return `<div class="empty"><span class="empty-icon">⚠️</span><p>${esc(e.message || 'Something went wrong.')}</p></div>`;
}

function pagerHtml(paginator, onPage) {
  if (!paginator || paginator.last_page <= 1) return '';
  const pages = [];
  for (let i = 1; i <= paginator.last_page; i++) pages.push(i);
  return `<div class="pager">
    <span>${paginator.from}–${paginator.to} of ${paginator.total}</span>
    <button class="btn btn-sm" ${paginator.current_page <= 1 ? 'disabled' : ''} data-page="${paginator.current_page - 1}">‹</button>
    <span>Page ${paginator.current_page}/${paginator.last_page}</span>
    <button class="btn btn-sm" ${paginator.current_page >= paginator.last_page ? 'disabled' : ''} data-page="${paginator.current_page + 1}">›</button>
  </div>`;
}

function wirePager(root, load) {
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-page]');
    if (!b) return;
    load(parseInt(b.dataset.page, 10));
  });
}

/** Standard server-side table: returns [container, refresh(params)] */
async function renderList(el, {
  url, params = {}, columns, rowActions = null, emptyIcon = '🔍', emptyText = 'Nothing here yet.',
  transform = (r) => r, perPage = 15,
}) {
  const state = { page: params.page || 1 };
  const wrap = document.createElement('div');
  el.appendChild(wrap);

  async function load(page = state.page) {
    state.page = page;
    wrap.innerHTML = loadingBlock(2);
    try {
      const data = await api.get(url + qs({ ...params, page, per_page: perPage }));
      const rows = (data.data || []).map(transform);
      if (!rows.length) {
        wrap.innerHTML = `<div class="panel">${emptyBlock(emptyIcon, emptyText)}</div>`;
        return;
      }
      wrap.innerHTML = `<div class="panel"><table><thead><tr>
          ${columns.map(([h]) => `<th>${esc(h)}</th>`).join('')}${rowActions ? '<th></th>' : ''}
        </tr></thead>
        <tbody>${rows.map((r) => `<tr>${columns.map(([, fn]) => `<td>${fn(r)}</td>`).join('')}
          ${rowActions ? `<td class="nowrap">${rowActions(r) || ''}</td>` : ''}</tr>`).join('')}</tbody></table>
        ${pagerHtml(data)}</div>`;
    } catch (e) {
      wrap.innerHTML = errBlock(e);
    }
  }

  wirePager(wrap, load);
  await load(state.page);
  return load;
}

/* ---------- notifications (shared overlay) ---------- */
const Notifications = {
  open: false,
  async toggle() {
    if (this.open) { $('.notif-panel')?.remove(); this.open = false; return; }
    this.open = true;
    const panel = document.createElement('div');
    panel.className = 'notif-panel';
    panel.innerHTML = `<div style="padding:.8rem .9rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line)">
      <strong>Notifications</strong><button class="btn btn-ghost btn-sm" data-all>Mark all read</button></div>
      <div class="n-list">${loadingBlock(3)}</div>`;
    document.querySelector('.topbar').appendChild(panel);
    panel.querySelector('[data-all]').onclick = async () => { await api.post('/notifications/read-all'); this.render(panel); refreshUnread(); };
    document.addEventListener('click', this._outside = (e) => {
      if (!e.target.closest('.bell') && !e.target.closest('.notif-panel')) { panel.remove(); this.open = false; document.removeEventListener('click', this._outside); }
    });
    await this.render(panel);
  },
  async render(panel) {
    const d = await api.get('/notifications?per_page=20');
    const list = panel.querySelector('.n-list');
    if (!d.data.length) { list.innerHTML = emptyBlock('🔕', 'All clear. No notifications.'); return; }
    list.innerHTML = d.data.map((n) => `
      <div class="notif-item ${n.read_at ? '' : 'unread'}" data-id="${n.id}">
        <div class="n-title">${esc(n.title)}</div>
        <div class="n-body">${esc(n.body)}</div>
        <div class="n-time">${fmtDate(n.created_at)}</div>
      </div>`).join('');
    $$('.notif-item', list).forEach((it) => {
      it.onclick = async () => { await api.post(`/notifications/${it.dataset.id}/read`); it.classList.remove('unread'); refreshUnread(); };
    });
  },
};

let __unreadTimer = null;
async function refreshUnread() {
  try {
    const d = await api.get('/notifications/unread-count');
    if (State.settings) State.settings.__unread = d.unread_count;
    const bell = $('.bell .icon-btn');
    if (bell) bell.innerHTML = `🔔${d.unread_count ? `<span class="dot">${d.unread_count}</span>` : ''}`;
  } catch (_) {}
}

/* ---------- shared mini-builders ---------- */
function paymentStatusBadge(status) {
  return { paid: badge('Paid', 'green'), partial: badge('Partial', 'amber'), unpaid: badge('Owes', 'red'),
           overdue: badge('Overdue', 'red'), cancelled: badge('Cancelled', 'gray'), voided: badge('Voided', 'gray'),
           completed: badge('Completed', 'green'), received: badge('Received', 'blue'), open: badge('Open', 'blue'),
           in_progress: badge('In progress', 'amber'), waiting: badge('Waiting', 'gray'),
           resolved: badge('Resolved', 'green'), closed: badge('Closed', 'gray'),
  }[status] || badge(status, 'gray');
}

function barsHtml(series, key = 'total') {
  if (!series?.length) return emptyBlock('📉', 'No data yet.');
  const max = Math.max(...series.map((s) => s[key] || 0), 1);
  return `<div class="bars" title="${series.map((s) => `${s.date || s.month}: ${s[key]}`).join(' | ')}">
    ${series.map((s) => `<div class="bar" style="height:${Math.max(2, (s[key] / max) * 100)}%"></div>`).join('')}</div>`;
}

function formValues(form) {
  const data = {};
  new FormData(form).forEach((v, k) => { data[k] = v === '' ? null : v; });
  $$('input[type=checkbox]', form).forEach((c) => { data[c.name] = c.checked; });
  return data;
}

/* Print a receipt from the .print-host template (thermal or A4). */
function printArea(html) {
  let host = $('.print-host');
  if (!host) { host = document.createElement('div'); host.className = 'print-host'; document.body.appendChild(host); }
  const isThermal = confirm('OK = thermal receipt (80mm)   |   Cancel = A4 invoice');
  host.className = 'print-host' + (isThermal ? ' pages-receipt-thermal' : '');
  host.innerHTML = html;
  window.print();
}
