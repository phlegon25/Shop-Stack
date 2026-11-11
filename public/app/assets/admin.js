/* ============================================================
   ShopStack — Super-admin console
   Scoped plane: /api/v1/super-admin, isolated ss_sa_token.
   ============================================================ */
api.setScope('sa');

const SA = {
  me: null,
  can(p) { return (this.me?.permissions || []).includes(p); },
};

const TITLES = {
  '#/sa': 'Overview',
  '#/sa/tenants': 'Tenants',
  '#/sa/plans': 'Plans',
  '#/sa/subscriptions': 'Subscriptions',
  '#/sa/payments': 'Payment review',
  '#/sa/broadcasts': 'Broadcasts',
  '#/sa/tickets': 'Support tickets',
  '#/sa/impersonations': 'Impersonations',
  '#/sa/revenue': 'Revenue',
  '#/sa/analytics': 'Analytics',
  '#/sa/status': 'API status',
  '#/sa/audits': 'Audit trail',
  '#/sa/admins': 'Administrators',
  '#/sa/settings': 'Platform settings',
};

const SA_NAV = [
  ['group', 'Platform'],
  ['#/sa', '📊', 'Overview', 'tenants.view', 'Platform'],
  ['#/sa/revenue', '💰', 'Revenue', 'analytics.view', 'Platform'],
  ['#/sa/analytics', '📈', 'Analytics', 'analytics.view', 'Platform'],
  ['#/sa/status', '📡', 'API status', 'admins.manage', 'Platform'],
  ['group', 'Customers'],
  ['#/sa/tenants', '🏪', 'Tenants', 'tenants.view', 'Customers'],
  ['#/sa/subscriptions', '🔄', 'Subscriptions', 'subscriptions.manage', 'Customers'],
  ['#/sa/payments', '💳', 'Payment review', 'payments.review', 'Customers'],
  ['#/sa/plans', '🏷️', 'Plans', 'plans.manage', 'Customers'],
  ['group', 'Operations'],
  ['#/sa/broadcasts', '📣', 'Broadcasts', 'tenants.suspend', 'Operations'],
  ['#/sa/tickets', '🆘', 'Support', 'payments.review', 'Operations'],
  ['#/sa/impersonations', '🕶', 'Impersonations', 'impersonate', 'Operations'],
  ['group', 'System'],
  ['#/sa/audits', '🔐', 'Audits', 'audits.view', 'System'],
  ['#/sa/admins', '👤', 'Admins', 'admins.manage', 'System'],
  ['#/sa/settings', '⚙️', 'Settings', 'settings.manage', 'System'],
];

/* ---------- shell ---------- */

function saLayout(contentEl, active) {
  let html = '';
  SA_NAV.forEach((item) => {
    if (item[0] === 'group') {
      const entries = SA_NAV.filter((n) => n[0] !== 'group' && n[4] === item[1] && SA.can(n[3]));
      if (entries.some((e) => SA.can(e[3]))) html += `<div class="group">${item[1]}</div>`;
      return;
    }
    const [href, icon, label, perm] = item;
    if (perm && !SA.can(perm)) return;
    html += `<a href="${href}" class="${active === href ? 'active' : ''}"><span>${icon}</span>${label}</a>`;
  });

  mount(`
  <div class="sa-shell">
    <aside class="sa-side" id="sa-sidebar">
      <div class="sa-brand"><span class="mark">S</span><div>ShopStack<small>Platform Console</small></div></div>
      <nav class="sa-nav">${html}</nav>
      <div class="foot">
        <button class="btn btn-ghost btn-sm" id="sa-theme" title="Cycle theme (light → dark → system)">🎨 Theme</button>
        <button class="btn btn-ghost btn-sm" id="sa-2fa">🛡 Two-factor</button>
        <button class="btn btn-ghost btn-sm" id="sa-pass">🔑 Password</button>
        <button class="btn btn-ghost btn-sm" id="sa-logout">⏻ Sign out</button>
      </div>
    </aside>
    <main class="sa-main">
      <div class="sa-top">
        <button class="btn btn-ghost burger" id="sa-burger" aria-label="Menu">☰</button>
        <h1>${esc(TITLES[active] || (location.hash.match(/tickets\/\d+/) ? 'Ticket' : 'Tenant dossier'))}</h1>
        <div class="spacer"></div>
        <div class="sa-search">
          <input id="sa-global-search" placeholder="Search tenants, payments, users…" autocomplete="off">
          <div class="sa-results hidden" id="sa-search-results"></div>
        </div>
        <div class="bell"><button class="icon-btn" id="sa-bell" aria-label="Notifications">🔔</button></div>
        <span class="muted small nowrap">🛡 ${esc(SA.me?.name || '')}</span>
      </div>
      <div id="sa-content"></div>
    </main>
  </div>`);

  $('#sa-content').appendChild(contentEl);
  $('#sa-burger').onclick = () => $('#sa-sidebar').classList.toggle('open');
  $('#sa-theme').onclick = () => { Theme.cycle(); };
  $('#sa-logout').onclick = async () => {
    try { await api.post('/logout'); } catch (_) {}
    api.token = null; SA.me = null; location.hash = '#/sa-login';
  };
  $('#sa-pass').onclick = () => {
    const m = openModal('Change password', `<form id="cp-form" novalidate>
      <div id="cp-err"></div>
      <label>Current password</label><input name="current_password" type="password" required>
      <label>New password (min 10)</label><input name="password" type="password" minlength="10" required>
      <label>Confirm</label><input name="password_confirmation" type="password" required>
      <button class="btn btn-primary mt2">Update password</button></form>`);
    $('#cp-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try { await api.post('/change-password', formValues(e.target)); toast('Password changed. Other sessions revoked.', 'ok'); closeModal(); }
      catch (err) { $('#cp-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
    };
  };
  $('#sa-2fa').onclick = () => {
    const m = openModal('Two-factor authentication', `<div id="tf-body">
      ${SA.me?.two_factor_enabled
        ? '<div class="alert alert-green">Two-factor authentication is <strong>enabled</strong> on this account.</div>'
        : '<button class="btn btn-primary" id="tf-start">Set up an authenticator app</button>'}
    </div>`);
    $('#tf-start', m)?.addEventListener('click', async () => {
      try {
        const r = await api.post('/two-factor/setup');
        $('#tf-body', m).innerHTML = `
          <div class="alert alert-blue">Add this account to your authenticator (scan or type):</div>
          <p class="center"><code style="font-size:1.15rem;letter-spacing:.08em">${esc(r.secret)}</code></p>
          <p class="muted small" style="word-break:break-all">${esc(r.otpauth_url)}</p>
          <form id="tf-verify" class="mt1" novalidate><label>Enter the 6-digit code from the app</label>
          <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
          <button class="btn btn-primary mt1">Enable 2FA</button></form>`;
        $('#tf-verify', m).onsubmit = async (e) => {
          e.preventDefault();
          try {
            await api.post('/two-factor/confirm', formValues(e.target));
            toast('2FA enabled.', 'ok'); closeModal(); SA.me.two_factor_enabled = true;
          } catch (err) { toast(err.message, 'err'); }
        };
      } catch (e) { toast(e.message, 'err'); }
    });
  };
  $('#sa-bell').onclick = (e) => { e.stopPropagation(); SaNotifications.toggle(); };

  wireSaSearch();
  SaNotifications.refreshUnread();
}

/* ---------- global search ---------- */
let __searchTimer;
function wireSaSearch() {
  const input = $('#sa-global-search'), box = $('#sa-search-results');
  if (!input) return;
  input.addEventListener('input', () => {
    clearTimeout(__searchTimer);
    const q = input.value.trim();
    if (q.length < 2) { box.classList.add('hidden'); return; }
    __searchTimer = setTimeout(async () => {
      try {
        const d = await api.get('/search?q=' + encodeURIComponent(q));
        if (input.value.trim() !== q) return;
        const rows = [];
        if (d.tenants.length) {
          rows.push('<div class="label">Tenants</div>');
          d.tenants.forEach((t) => rows.push(`<div class="hit" data-go="tenants/${t.id}"><span>🏪 ${esc(t.name)} <span class="muted">· ${esc(t.slug)}</span></span>${subBadge({ plan: t.lifecycle?.stage || '—', days_left: t.lifecycle?.days_left }, t.status)}</div>`));
        }
        if (d.users.length) {
          rows.push('<div class="label">Users</div>');
          d.users.forEach((u) => rows.push(`<div class="hit" data-go="tenants/${u.tenant_id}"><span>👤 ${esc(u.name)} <span class="muted">· ${esc(u.email)} · ${esc(u.tenant?.name || '')}</span></span>${badge(u.role, 'gray')}</div>`));
        }
        if (d.payments.length) {
          rows.push('<div class="label">Payments</div>');
          d.payments.forEach((p) => rows.push(`<div class="hit" data-go="tenants/${p.tenant_id}"><span>💳 ${esc(p.reference)} <span class="muted">· ${esc(p.tenant?.name || '')}</span></span>${paymentStatusBadge(p.status)}</div>`));
        }
        box.innerHTML = rows.join('') || '<div class="hit muted">No matches</div>';
        box.classList.remove('hidden');
        box.querySelectorAll('.hit[data-go]').forEach((h) => {
          h.onclick = () => {
            box.classList.add('hidden'); input.value = '';
            location.hash = '#/sa/' + h.dataset.go;
          };
        });
      } catch (_) { /* silent */ }
    }, 280);
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.sa-search')) box.classList.add('hidden'); });
}

/* ---------- SA notifications ---------- */
const SaNotifications = {
  open: false,
  async toggle() {
    if (this.open) { $('.notif-panel')?.remove(); this.open = false; return; }
    this.open = true;
    const panel = document.createElement('div');
    panel.className = 'notif-panel';
    panel.innerHTML = `<div style="padding:.8rem .9rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line)">
      <strong>Platform notifications</strong><button class="btn btn-ghost btn-sm" data-all>Mark all read</button></div>
      <div class="n-list">${loadingBlock(3)}</div>`;
    $('.sa-top').appendChild(panel);
    panel.querySelector('[data-all]').onclick = async () => {
      try { await api.post('/notifications/read-all'); } catch (_) {}
      this.render(panel); this.refreshUnread();
    };
    const outside = (e) => {
      if (!e.target.closest('.bell') && !e.target.closest('.notif-panel')) { panel.remove(); this.open = false; document.removeEventListener('click', outside); }
    };
    setTimeout(() => document.addEventListener('click', outside), 0);
    await this.render(panel);
  },
  async render(panel) {
    try {
      const d = await api.get('/notifications');
      const list = panel.querySelector('.n-list');
      const rows = d.data?.data || d.data || [];
      if (!rows.length) { list.innerHTML = emptyBlock('🔕', 'All clear.'); return; }
      list.innerHTML = rows.slice(0, 20).map((n) => `
        <div class="notif-item ${n.read_at ? '' : 'unread'}" data-id="${n.id}">
          <div class="n-title">${esc(n.title || n.type)}</div>
          <div class="n-body">${esc(n.body || '')}</div>
          <div class="n-time">${fmtDate(n.created_at)}</div>
        </div>`).join('');
      $$('.notif-item', list).forEach((it) => {
        it.onclick = async () => {
          try { await api.post(`/notifications/${it.dataset.id}/read`); } catch (_) {}
          it.classList.remove('unread'); this.refreshUnread();
        };
      });
    } catch (e) { panel.querySelector('.n-list').innerHTML = errBlock(e); }
  },
  async refreshUnread() {
    try {
      const d = await api.get('/notifications');
      const count = d.unread_count ?? (d.data || []).filter?.((n) => !n.read_at)?.length ?? 0;
      const bell = $('#sa-bell');
      if (bell) bell.innerHTML = `🔔${count ? `<span class="dot">${count}</span>` : ''}`;
    } catch (_) {}
  },
};

/* ---------- router ---------- */

const SA_ROUTES = {
  '#/sa': saOverview,
  '#/sa/tenants': saTenants,
  '#/sa/plans': saPlans,
  '#/sa/subscriptions': saSubscriptions,
  '#/sa/payments': (el) => SaPayments.list(el),
  '#/sa/broadcasts': saBroadcasts,
  '#/sa/tickets': saTickets,
  '#/sa/impersonations': saImpersonations,
  '#/sa/revenue': saRevenue,
  '#/sa/analytics': saAnalytics,
  '#/sa/status': saApiStatus,
  '#/sa/audits': saAudits,
  '#/sa/admins': saAdmins,
  '#/sa/settings': saSettings,
};

const saHash = () => location.hash || '#/sa';

async function saBoot() {
  if (!api.token || saHash() === '#/sa-login') {
    if (!api.token && saHash() !== '#/sa-login') location.hash = '#/sa-login';
    return saLoginPage();
  }
  if (!SA.me) {
    try {
      const r = await api.get('/me');
      SA.me = r.admin || r.data || r;
    } catch (e) { api.token = null; location.hash = '#/sa-login'; return saLoginPage(); }
  }
  if (['#', '#/sa-login', ''].includes(saHash())) location.hash = '#/sa';
  return saRoute();
}

async function saRoute() {
  const h = saHash();
  const tDetail = h.match(/^#\/sa\/tenants\/(\d+)/);
  const kDetail = h.match(/^#\/sa\/tickets\/(\d+)/);
  const el = document.createElement('div');
  const active = tDetail ? '#/sa/tenants' : kDetail ? '#/sa/tickets' : (SA_ROUTES[h] ? h : '#/sa');
  saLayout(el, active);
  try {
    if (tDetail) return await saTenantDetail(el, +tDetail[1]);
    if (kDetail) return await saTicketDetail(el, +kDetail[1]);
    if (SA_ROUTES[h] && SA.canNavCheckMissing(SA_NAV.find((n) => n[0] === h))) { /* perm-guard button-less nav still guarded by API */ }
    return await (SA_ROUTES[h] || saOverview)(el);
  } catch (e) {
    el.innerHTML = errBlock(e);
  }
}
/* nav perm check: admins without perms see hidden nav; API still rejects. */
SA.canNavCheckMissing = () => false;

window.addEventListener('hashchange', () => {
  if (saHash() === '#/sa-login') return saLoginPage();
  if (!api.token) { location.hash = '#/sa-login'; return; }
  SA.me ? saRoute() : saBoot();
});

/* ================= LOGIN + 2FA CHALLENGE ================= */

function saLoginPage() {
  document.title = 'ShopStack Platform Console';
  mount(`
  <div class="auth-wrap sa"><div class="auth-card">
    <div class="auth-logo"><span class="mark">S</span><div><strong>ShopStack</strong><small class="muted" style="display:block">Platform Console</small></div></div>
    <h1>Administrator sign-in</h1>
    <p class="auth-sub">Restricted area. Every session is audited; 2FA is mandatory after first sign-in.</p>
    <div id="sa-login-step"></div>
  </div></div>`);
  renderPwStep();

  function renderPwStep() {
    $('#sa-login-step').innerHTML = `
      <form id="sa-login-form" novalidate>
        <div id="sa-login-err"></div>
        <label>Email</label><input name="email" type="email" required autofocus>
        <label>Password</label><input name="password" type="password" required>
        <button class="btn btn-primary btn-lg mt1" style="width:100%">Sign in</button>
      </form>`;
    $('#sa-login-form').onsubmit = async (e) => {
      e.preventDefault();
      const v = formValues(e.target);
      const errHost = $('#sa-login-err');
      errHost.innerHTML = '';
      try {
        const r = await api.post('/login', { email: v.email, password: v.password });
        if (r.two_factor_required) return renderOtpStep(r.challenge);
        if (r.two_factor_setup_required) {
          api.token = r.token;
          return renderSetupStep();
        }
        api.token = r.token;
        SA.me = r.admin || null;
        location.hash = '#/sa';
        saBoot();
      } catch (err) {
        errHost.innerHTML = `<div class="alert alert-red">${esc(err.message)}${err.retryAfter ? ` Retry in ${err.retryAfter}s.` : ''}</div>${fieldErrors(err)}`;
      }
    };
  }

  async function renderSetupStep() {
    $('#sa-login-step').innerHTML = '<div class="alert alert-blue">Setting up your authenticator…</div>';

    try {
      const setup = await api.post('/two-factor/setup');
      $('#sa-login-step').innerHTML = `
        <div class="alert alert-blue">Add this account to your authenticator app, then enter the current 6-digit code.</div>
        <label>Secret key</label>
        <p><code style="font-size:1.05rem;letter-spacing:.08em;word-break:break-all">${esc(setup.secret)}</code></p>
        <p class="muted small" style="word-break:break-all">${esc(setup.provisioning_uri || '')}</p>
        <form id="sa-setup-form" novalidate><div id="sa-setup-err"></div>
          <label>Authenticator code</label>
          <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
          <button class="btn btn-primary btn-lg mt1" style="width:100%">Complete setup and sign in</button>
        </form>`;
      $('#sa-setup-form').onsubmit = async (e) => {
        e.preventDefault();
        try {
          const confirmed = await api.post('/two-factor/confirm', { code: e.target.code.value });
          SA.me = confirmed.admin || null;
          location.hash = '#/sa';
          saBoot();
        } catch (err) {
          $('#sa-setup-err').innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>`;
        }
      };
    } catch (err) {
      api.token = null;
      $('#sa-login-step').innerHTML = `<div class="alert alert-red">${esc(err.message)}</div><button class="btn btn-ghost mt1" id="sa-setup-retry">Try again</button>`;
      $('#sa-setup-retry').onclick = renderPwStep;
    }
  }

  function renderOtpStep(challenge) {
    $('#sa-login-step').innerHTML = `
      <div class="alert alert-blue">Two-factor challenge — enter the 6-digit code from your authenticator app.</div>
      <form id="sa-tf-form" novalidate><div id="sa-tf-err"></div>
        <label>Authenticator code</label><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
        <button class="btn btn-primary btn-lg mt1" style="width:100%">Verify & sign in</button>
        <button class="btn btn-ghost mt1" type="button" id="sa-tf-back">← Back</button>
      </form>`;
    $('#sa-tf-back').onclick = renderPwStep;
    $('#sa-tf-form').onsubmit = async (e) => {
      e.preventDefault();
      try {
        const r = await api.post('/two-factor/challenge', { challenge, code: e.target.code.value });
        api.token = r.token;
        SA.me = r.admin || null;
        location.hash = '#/sa';
        saBoot();
      } catch (err) {
        $('#sa-tf-err').innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>`;
      }
    };
  }
}

/* ================= OVERVIEW ================= */

async function saOverview(el) {
  el.innerHTML = loadingBlock(4);
  const d = await api.get('/dashboard');
  const cur = d.currency || 'GHS';

  el.innerHTML = `
    <div class="grid grid-4 mb2">
      <div class="kpi kpi-accent"><div class="kpi-label">MRR</div><div class="kpi-value">${money(d.money.mrr, cur)}</div>
        <div class="kpi-sub">ARR ${money(d.money.arr, cur)}</div></div>
      <div class="kpi"><div class="kpi-label">Collected this month</div><div class="kpi-value">${money(d.money.collected_this_month, cur)}</div>
        <div class="kpi-sub">expected 30d: ${money(d.money.expected_30d, cur)}</div></div>
      <div class="kpi ${d.money.pending_count > 0 ? 'kpi-amber' : ''}"><div class="kpi-label">Pending payments</div>
        <div class="kpi-value">${d.money.pending_count}</div>
        <div class="kpi-sub">${d.money.pending_count ? '<a href="#/sa/payments">review queue →</a> ' : ''}${money(d.money.pending_amount, cur)}</div></div>
      <div class="kpi"><div class="kpi-label">Tenants</div><div class="kpi-value">${d.tenants.total}</div>
        <div class="kpi-sub">${d.tenants.active} active · ${d.tenants.suspended} suspended · +${d.tenants.new_this_month} this month</div></div>
    </div>
    <div class="chips mb2">${Object.entries(d.tenants.by_stage || {}).map(([s, c]) => `<span class="chip">${esc(s)}: <strong>${c}</strong></span>`).join('')}</div>
    <div class="grid grid-2 mb2">
      <div class="card"><h3>Revenue — last 6 months</h3>${barsHtml((d.revenue_series || []).map((s) => ({ date: s.month, total: s.total })))}</div>
      <div class="card"><h3>Expiring soon</h3>
        ${(d.expiring_soon || []).length ? d.expiring_soon.map((t) => `<div class="list-row">
          <span><strong>${esc(t.name)}</strong></span>
          <a class="btn btn-sm" href="#/sa/tenants/${t.tenant_id}">${esc(t.stage)}${t.days_left !== null && t.days_left !== undefined ? ' · ' + t.days_left + 'd' : ''}${t.plan ? ' · ' + esc(t.plan) : ''}</a></div>`).join('')
        : '<div class="muted small">Nothing expiring in the reminder window.</div>'}
      </div>
    </div>
    <div class="card"><h3>Newest stores</h3>
      ${(d.recent_registration || []).map((t) => `<div class="list-row"><span><strong>${esc(t.name)}</strong></span>
        <span class="muted small nowrap">${badge(t.status, t.status === 'active' ? 'green' : 'red')} ${fmtDate(t.created_at)}</span></div>`).join('') || '<div class="muted small">None yet.</div>'}
    </div>`;
}

/* ================= TENANTS ================= */

async function saTenants(el) {
  el.innerHTML = `
    <div class="toolbar">
      <input class="search-input" id="t-search" placeholder="Search name, slug, email…">
      <select id="t-status" style="max-width:150px"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option></select>
      ${SA.can('tenants.create') ? '<button class="btn btn-primary" id="t-new">＋ Provision tenant</button>' : ''}
    </div>
    <div id="t-list">${loadingBlock(3)}</div>`;

  let search = '';
  async function load(page = 1) {
    const host = $('#t-list', el); host.innerHTML = '';
    await renderList(host, {
      url: '/tenants',
      params: { search, status: $('#t-status', el).value, page },
      columns: [
        ['Store', (t) => `<strong>${esc(t.name)}</strong><div class="muted small">${esc(t.slug)} · ${esc(t.email || '')}</div>`],
        ['Users', (t) => String(t.users_count ?? 0)],
        ['Lifecycle', (t) => subBadge({ plan: t.lifecycle?.stage || '—', days_left: t.lifecycle?.days_left }, t.status)],
        ['Joined', (t) => `<span class="nowrap">${fmtDate(t.created_at)}</span>`],
      ],
      rowActions: (t) => `<a class="btn btn-sm" href="#/sa/tenants/${t.id}">Dossier</a>`,
    });
  }
  $('#t-search', el).oninput = debounce(() => { search = $('#t-search', el).value.trim(); load(); }, 300);
  $('#t-status', el).onchange = () => load();

  $('#t-new', el)?.addEventListener('click', () => {
    const m = openModal('Provision new tenant', `<form id="tn-form" novalidate>
      <div id="tn-err"></div>
      <label>Business name *</label><input name="name" required maxlength="120">
      <label>Owner email * (also the sign-in)</label><input name="email" type="email" required>
      <label>Owner display name *</label><input name="owner_name" required maxlength="120">
      <label>Phone</label><input name="phone" maxlength="30">
      <label>Temp password (min 10) *</label><input name="password" type="password" minlength="10" required>
      <label><input type="checkbox" name="grant_trial" checked> Start free trial now</label>
      <button class="btn btn-primary mt2">Provision workspace</button></form>`);
    $('#tn-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const v = formValues(e.target);
      v.grant_trial = !!v.grant_trial;
      try {
        const r = await api.post('/tenants', v);
        toast('Tenant provisioned.', 'ok'); closeModal();
        location.hash = '#/sa/tenants/' + r.data.id;
      } catch (err) {
        $('#tn-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`;
      }
    };
  });

  await load();
}

async function saTenantDetail(el, id) {
  el.innerHTML = loadingBlock(4);
  const d = await api.get('/tenants/' + id);
  const t = d.data, lc = d.lifecycle || {};
  const usage = d.usage || {};

  el.innerHTML = `
    <div class="btn-row mb2"><a class="btn btn-ghost" href="#/sa/tenants">← Tenants</a></div>
    <div class="grid" style="grid-template-columns:2fr 1fr">
      <div>
        <div class="card mb2">
          <h3>${esc(t.name)} ${subBadge({ plan: lc.stage || '—', days_left: lc.days_left }, t.status)}</h3>
          <div class="muted small mb1">Slug <code>${esc(t.slug)}</code> · ${esc(t.email || '')} · ${esc(t.phone || '')} · joined ${fmtDate(t.created_at)}</div>
          ${t.suspension_reason ? `<div class="alert alert-red">Suspension reason: ${esc(t.suspension_reason)}</div>` : ''}
          ${lc.detail ? `<div class="muted small">${esc(lc.detail)}</div>` : ''}
          <div class="grid grid-4 mt2" style="gap:.5rem">
            ${[['Users', usage.users], ['Products', usage.products], ['Completed sales', usage.sales], ['Revenue (all time)', money(usage.sales_total)], ['Customers', usage.customers]]
              .map(([k, v]) => `<div class="kpi"><div class="kpi-label">${k}</div><div class="kpi-value" style="font-size:1.1rem">${v ?? 0}</div></div>`).join('')}
          </div>
        </div>
        <div class="card mb2"><h3>Users (${(d.users || []).length})</h3>
          ${(d.users || []).map((u) => `<div class="list-row"><span><strong>${esc(u.name)}</strong> <span class="muted">${esc(u.email)}</span></span>
            <span class="muted small nowrap">${badge(u.role, u.role === 'owner' ? 'blue' : 'gray')} ${u.status === 'active' ? '' : badge('disabled', 'red')} ${u.last_login_at ? '· seen ' + new Date(u.last_login_at).toLocaleDateString() : ''}</span></div>`).join('') || '<div class="muted small">No users.</div>'}
        </div>
        <div class="card mb2"><h3>Subscription history</h3>
          ${(d.subscriptions || []).length ? `<table><thead><tr><th>Plan</th><th>Status</th><th>Ends</th><th></th></tr></thead><tbody>
            ${d.subscriptions.map((s) => `<tr><td><strong>${esc(s.plan?.name || '—')}</strong> <span class="muted small">${money(s.plan?.price)}</span></td>
              <td>${paymentStatusBadge(s.status)}</td><td class="nowrap">${s.ends_at ? new Date(s.ends_at).toLocaleDateString() : '—'}</td>
              <td>${SA.can('subscriptions.manage') ? `<button class="btn btn-sm" data-ext="${s.id}">Extend…</button>` : ''}</td></tr>`).join('')}</tbody></table>`
          : '<div class="muted small">No subscriptions.</div>'}
        </div>
        <div class="card mb2"><h3>Payment submissions</h3>
          ${(d.payments || []).length ? `<table><thead><tr><th>When</th><th>Amount</th><th>Ref</th><th>Status</th></tr></thead><tbody>
            ${d.payments.map((p) => `<tr><td class="nowrap">${fmtDate(p.submitted_at)}</td>
              <td class="nowrap">${money(p.amount)}</td><td><code>${esc(p.reference)}</code></td>
              <td>${paymentStatusBadge(p.status)}</td></tr>`).join('')}</tbody></table>`
          : '<div class="muted small">No payments submitted.</div>'}
        </div>
        <div class="card"><h3>Recent activity</h3>
          ${(d.recent_activity || []).map((a) => `<div class="stat-row">
            <span><code>${esc(a.action)}</code> ${a.impersonation_session_id ? badge('impersonated', 'dark') : ''}<div class="muted small">${esc(a.actor_email || a.actor_label || '')}</div></span>
            <span class="nowrap muted small">${fmtDate(a.created_at)}</span></div>`).join('') || '<div class="muted small">No tenant activity.</div>'}
        </div>
      </div>
      <div>
        <div class="card mb2"><h3>Actions</h3>
          <div class="btn-row" style="flex-direction:column;align-items:stretch">
            ${SA.can('impersonate') ? '<button class="btn" id="act-imp">🕶 Impersonate owner</button>' : ''}
            ${SA.can('tenants.suspend') && t.status === 'active' ? '<button class="btn btn-danger" id="act-suspend">Suspend</button>' : ''}
            ${SA.can('tenants.suspend') && t.status === 'suspended' ? '<button class="btn btn-success" id="act-restore">Restore</button>' : ''}
            ${SA.can('subscriptions.manage') ? '<button class="btn" id="act-adjust">Adjust subscription…</button>' : ''}
          </div>
        </div>
        <div class="card"><h3>Current subscription</h3>
          ${t.current_subscription ? `
            <div class="stat-row"><span>Plan</span><strong>${esc(t.current_subscription.plan?.name || '—')}</strong></div>
            <div class="stat-row"><span>Status</span><span>${paymentStatusBadge(t.current_subscription.status)}</span></div>
            <div class="stat-row"><span>Ends</span><strong>${t.current_subscription.ends_at ? new Date(t.current_subscription.ends_at).toLocaleDateString() : '—'}</strong></div>
            ${t.current_subscription.grace_ends_at ? `<div class="stat-row"><span>Grace until</span><strong>${new Date(t.current_subscription.grace_ends_at).toLocaleDateString()}</strong></div>` : ''}`
          : '<div class="muted">No active subscription.</div>'}
        </div>
      </div>
    </div>`;

  $('#act-imp', el)?.addEventListener('click', () => {
    const m = openModal('Impersonate — ' + t.name, `<form id="imp-form" novalidate>
      <div class="alert alert-amber">⚠ You will act as this store's owner. The session is time-limited, bannered in orange, and every action is attributed to you in the platform audit trail.</div>
      <label>Reason (min 8 chars) *</label><textarea name="reason" minlength="8" maxlength="500" rows="3" required placeholder="Support ticket #42 — owner requested help with stock reconciliation"></textarea>
      <button class="btn btn-primary mt2">Start impersonation session</button></form>`);
    $('#imp-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        const r = await api.post(`/tenants/${id}/impersonate`, formValues(e.target));
        closeModal();
        localStorage.setItem('ss_imp_token', r.token);
        const sm = openModal('Impersonation session started', `
          <p>${esc(r.banner?.text || '')}</p>
          <p class="muted small">Session #${r.session.id} expires ${fmtDate(r.session.expires_at)}. The workspace opens in a new tab; the orange banner marks impersonated mode.</p>
          <button class="btn btn-primary" id="imp-go">Open store workspace ↗</button>
          <button class="btn" data-close>Stay here</button>`);
        $('#imp-go', sm).onclick = () => { closeModal(); window.open('/app/#/dashboard', '_blank'); };
      } catch (err) { toast(err.message, 'err'); }
    };
  });

  $('#act-suspend', el)?.addEventListener('click', () => {
    const m = openModal('Suspend — ' + t.name, `<form id="sus-form" novalidate>
      <div class="alert alert-red">Suspending freezes sign-in and the entire API for this store's users. Data is untouched and can be restored.</div>
      <label>Reason (audited, told to the store) *</label><textarea name="reason" minlength="10" maxlength="500" rows="3" required></textarea>
      <label>Type the store name to confirm</label><input name="confirm" placeholder="${esc(t.name)}" required>
      <button class="btn btn-danger mt2">Suspend tenant</button></form>`);
    $('#sus-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const v = formValues(e.target);
      if (v.confirm !== t.name) { toast('Confirmation name does not match.', 'err'); return; }
      try { await api.post(`/tenants/${id}/suspend`, { reason: v.reason, confirm: true }); toast('Tenant suspended.', 'ok'); closeModal(); saRoute(); }
      catch (err) { toast(err.message, 'err'); }
    };
  });

  $('#act-restore', el)?.addEventListener('click', async () => {
    if (!confirm(`Restore ${t.name}? Everyone can sign in again.`)) return;
    try { await api.post(`/tenants/${id}/restore`); toast('Tenant restored.', 'ok'); saRoute(); }
    catch (err) { toast(err.message, 'err'); }
  });

  $('#act-adjust', el)?.addEventListener('click', async () => {
    const plans = (await api.get('/plans')).data || [];
    const m = openModal('Adjust subscription — ' + t.name, `<form id="adj-form" novalidate>
      <div id="adj-err"></div>
      <label>Plan *</label><select name="plan_id">${plans.map((p) => `<option value="${p.id}" ${t.current_subscription?.plan_id === p.id ? 'selected' : ''}>${esc(p.name)} — ${money(p.price)}/${p.duration_days}d</option>`).join('')}</select>
      <label>Why (audited) *</label><textarea name="note" minlength="3" maxlength="500" rows="2" required></textarea>
      <button class="btn btn-primary mt2">Apply plan change</button></form>`);
    $('#adj-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try { await api.post(`/tenants/${id}/subscription`, formValues(e.target)); toast('Subscription updated.', 'ok'); closeModal(); saRoute(); }
      catch (err) { $('#adj-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
    };
  });

  $$('[data-ext]', el).forEach((b) => {
    b.onclick = () => {
      const m = openModal('Extend subscription #' + b.dataset.ext, `<form id="ext-form" novalidate>
        <div id="ext-err"></div>
        <label>Days to add (1–365) *</label><input name="days" type="number" min="1" max="365" required value="30">
        <label>Note (audited) *</label><textarea name="note" minlength="3" maxlength="300" rows="2" required></textarea>
        <button class="btn btn-primary mt2">Extend</button></form>`);
      $('#ext-form', m).onsubmit = async (e) => {
        e.preventDefault();
        try { await api.post(`/subscriptions/${b.dataset.ext}/extend`, formValues(e.target)); toast('Extended.', 'ok'); closeModal(); saRoute(); }
        catch (err) { $('#ext-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
      };
    };
  });
}

/* ================= PLANS ================= */

async function saPlans(el) {
  el.innerHTML = loadingBlock(3);
  const d = await api.get('/plans');
  const plans = d.data || d;

  el.innerHTML = `
    <div class="grid grid-3 mb2">
      ${plans.map((p) => `<div class="card" style="border:2px solid ${p.is_active ? 'var(--line)' : 'var(--red-bg)'}">
        <div class="btn-row" style="justify-content:space-between"><h3>${esc(p.name)}</h3>${p.is_active ? badge('active', 'green') : badge('retired', 'red')}</div>
        <div style="font-size:1.7rem;font-weight:800">${money(p.price)}<span class="muted small">/${p.duration_days}d</span></div>
        <div class="muted small">slug ${esc(p.slug)} · ${p.trial_days || 0}d trial · sort ${p.sort_order ?? 0}</div>
        <div class="muted small">limits: ${p.product_limit || '∞'} products · ${p.user_limit || '∞'} users · ${p.storage_mb || '∞'}MB · <strong>${p.active_subscriptions || 0}</strong> active subs</div>
        ${(p.features || []).length ? `<ul class="small mt1" style="padding-left:1.1rem">${(p.features || []).map((f) => `<li>${esc(f)}</li>`).join('')}</ul>` : ''}
        ${p.description ? `<div class="muted small mt1">${esc(p.description)}</div>` : ''}
        <div class="btn-row mt1"><button class="btn btn-sm" data-edit="${p.id}">Edit</button></div>
      </div>`).join('')}
      <div class="card" style="display:grid;place-items:center;border-style:dashed" id="plan-new">
        <button class="btn btn-primary">＋ Create plan</button>
      </div>
    </div>`;

  function planForm(p) {
    const isNew = !p;
    const m = openModal(isNew ? 'Create plan' : 'Edit — ' + p.name, `<form id="pl-form" novalidate>
      <div id="pl-err"></div>
      <div class="form-grid">
        <div><label>Name *</label><input name="name" required maxlength="80" value="${esc(p?.name || '')}"></div>
        <div><label>Slug *</label><input name="slug" required maxlength="80" pattern="[a-z0-9\\-]+" value="${esc(p?.slug || '')}"></div>
      </div>
      <label>Description</label><input name="description" maxlength="300" value="${esc(p?.description || '')}">
      <div class="form-grid">
        <div><label>Price (GHS) *</label><input name="price" type="number" min="0" step="0.01" required value="${p?.price ?? ''}"></div>
        <div><label>Duration (days) *</label><input name="duration_days" type="number" min="1" max="3660" required value="${p?.duration_days ?? 30}"></div>
      </div>
      <div class="form-grid">
        <div><label>Trial days</label><input name="trial_days" type="number" min="0" max="365" value="${p?.trial_days ?? 0}"></div>
        <div><label>Sort order</label><input name="sort_order" type="number" min="0" value="${p?.sort_order ?? 0}"></div>
      </div>
      <div class="form-grid grid-3">
        <div><label>Product limit (blank = ∞)</label><input name="product_limit" type="number" min="1" value="${p?.product_limit ?? ''}"></div>
        <div><label>User limit</label><input name="user_limit" type="number" min="1" value="${p?.user_limit ?? ''}"></div>
        <div><label>Storage MB</label><input name="storage_mb" type="number" min="1" value="${p?.storage_mb ?? ''}"></div>
      </div>
      <label>Features (one per line)</label><textarea name="features" rows="3">${esc((p?.features || []).join('\n'))}</textarea>
      <label><input type="checkbox" name="is_active" ${!p || p.is_active ? 'checked' : ''}> Publicly active (visible on landing page)</label>
      <div class="btn-row mt2"><button class="btn btn-primary">${isNew ? 'Create' : 'Save'}</button>
      ${!isNew && !(p.active_subscriptions > 0) ? '<button class="btn btn-danger" type="button" id="pl-del">Delete</button>' : ''}</div>
    </form>`, true);
    $('#pl-del', m)?.addEventListener('click', async () => {
      if (!confirm('Delete this plan for good? Only possible while it has no subscriptions.')) return;
      try { await api.del('/plans/' + p.id); toast('Plan deleted.', 'ok'); closeModal(); saRoute(); }
      catch (err) { toast(err.message, 'err'); }
    });
    $('#pl-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const v = formValues(e.target);
      ['product_limit', 'user_limit', 'storage_mb'].forEach((k) => { if (v[k] === null) delete v[k]; });
      v.features = (v.features ? v.features.split('\n').map((x) => x.trim()).filter(Boolean) : []);
      v.is_active = !!v.is_active;
      v.price = parseFloat(v.price);
      ['duration_days', 'trial_days', 'sort_order'].forEach((k) => { v[k] = parseInt(v[k], 10) || 0; });
      try {
        if (isNew) await api.post('/plans', v); else await api.put('/plans/' + p.id, v);
        toast('Plan saved.', 'ok'); closeModal(); saRoute();
      } catch (err) { $('#pl-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
    };
  }

  $('#plan-new', el).onclick = () => planForm(null);
  $$('[data-edit]', el).forEach((b) => { b.onclick = () => planForm(plans.find((p) => p.id == b.dataset.edit)); });
}

/* ================= SUBSCRIPTIONS ================= */

async function saSubscriptions(el) {
  el.innerHTML = `
    <div class="toolbar">
      <select id="sub-status" style="max-width:170px">
        <option value="">All statuses</option><option value="active">Active</option><option value="trialing">Trialing</option>
        <option value="expired">Expired</option><option value="cancelled">Cancelled</option>
      </select>
      <input class="search-input" id="sub-tenant" placeholder="Tenant name…">
      <select id="sub-expiring" style="max-width:210px">
        <option value="">Any end date</option><option value="7">Ending ≤ 7 days</option><option value="14">Ending ≤ 14 days</option><option value="30">Ending ≤ 30 days</option>
      </select>
    </div>
    <div id="sub-list">${loadingBlock(3)}</div>`;

  async function load(page = 1) {
    const host = $('#sub-list', el); host.innerHTML = '';
    await renderList(host, {
      url: '/subscriptions',
      params: {
        status: $('#sub-status', el).value,
        tenant: $('#sub-tenant', el).value,
        expiring_days: $('#sub-expiring', el).value,
        page,
      },
      columns: [
        ['Tenant', (s) => `<a href="#/sa/tenants/${s.tenant_id}"><strong>${esc(s.tenant?.name || '#' + s.tenant_id)}</strong></a>`],
        ['Plan', (s) => `${esc(s.plan?.name || '—')} <span class="muted small">${money(s.plan?.price)}</span>`],
        ['Status', (s) => paymentStatusBadge(s.status)],
        ['Started', (s) => `<span class="nowrap">${s.starts_at ? new Date(s.starts_at).toLocaleDateString() : '—'}</span>`],
        ['Ends', (s) => {
          const days = s.ends_at ? Math.ceil((new Date(s.ends_at) - Date.now()) / 864e5) : null;
          return `<span class="nowrap">${s.ends_at ? new Date(s.ends_at).toLocaleDateString() : '—'} ${days !== null ? badge(days + 'd', days <= 3 ? 'red' : days <= 7 ? 'amber' : 'green') : ''}</span>`; }],
      ],
      rowActions: (s) => SA.can('subscriptions.manage') ? `<button class="btn btn-sm" data-ext="${s.id}">Extend…</button>` : '',
    });
    $$('[data-ext]', host).forEach((b) => {
      b.onclick = () => {
        const m = openModal('Extend subscription #' + b.dataset.ext, `<form id="x-form" novalidate>
          <div id="x-err"></div>
          <label>Days (1–365)</label><input name="days" type="number" min="1" max="365" value="30" required>
          <label>Note (audited)</label><textarea name="note" minlength="3" maxlength="300" rows="2" required></textarea>
          <button class="btn btn-primary mt2">Extend</button></form>`);
        $('#x-form', m).onsubmit = async (e) => {
          e.preventDefault();
          try { await api.post(`/subscriptions/${b.dataset.ext}/extend`, formValues(e.target)); toast('Extended.', 'ok'); closeModal(); load(); }
          catch (err) { $('#x-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
        };
      };
    });
  }

  ['sub-status', 'sub-expiring'].forEach((i) => { $('#' + i, el).onchange = () => load(); });
  $('#sub-tenant', el).oninput = debounce(() => load(), 350);
  await load();
}

/* ================= PAYMENT REVIEW ================= */

const SaPayments = {
  async list(el) {
    el.innerHTML = `
      <div class="chips mb2" id="pay-chips">
        ${['pending', 'approved', 'rejected', ''].map((s) => `<button class="chip ${s === 'pending' ? 'active' : ''}" data-s="${s}">${s === '' ? 'All' : s[0].toUpperCase() + s.slice(1)}</button>`).join('')}
      </div>
      <div id="pay-list">${loadingBlock(3)}</div>`;

    let status = 'pending';
    async function load(page = 1) {
      const host = $('#pay-list', el); host.innerHTML = '';
      await renderList(host, {
        url: '/payments', params: { status, page },
        emptyIcon: '💳', emptyText: 'The review queue is clear.',
        columns: [
          ['Reference', (p) => `<code>${esc(p.reference)}</code>`],
          ['Tenant', (p) => `<a href="#/sa/tenants/${p.tenant_id}"><strong>${esc(p.tenant?.name || '—')}</strong></a>`],
          ['Amount', (p) => `<strong class="nowrap">${money(p.amount)}</strong>`],
          ['Method', (p) => esc(p.method)],
          ['Submitted', (p) => `<span class="nowrap">${fmtDate(p.submitted_at)}</span>`],
          ['Status', (p) => paymentStatusBadge(p.status)],
        ],
        rowActions: (p) => `<button class="btn btn-sm" data-open="${p.id}">Open</button>`,
      });
      $$('[data-open]', host).forEach((b) => { b.onclick = () => SaPayments.detail(b.dataset.open, load); });
    }

    $$('#pay-chips .chip', el).forEach((c) => {
      c.onclick = () => { $$('#pay-chips .chip', el).forEach((x) => x.classList.remove('active')); c.classList.add('active'); status = c.dataset.s; load(); };
    });
    await load();
  },

  async detail(id, onChange) {
    let p;
    try { p = (await api.get('/payments/' + id)).data; } catch (e) { toast(e.message, 'err'); return; }

    const m = openModal(`Payment ${p.reference}${p.plan?.name ? ' — ' + p.plan.name : ''}`, `
      <div class="grid grid-2 mb1">
        <div>
          <div class="stat-row"><span>Tenant</span><strong>${esc(p.tenant?.name || '—')}</strong></div>
          <div class="stat-row"><span>Amount</span><strong>${money(p.amount)}</strong></div>
          <div class="stat-row"><span>Method</span><span>${esc(p.method)}</span></div>
          <div class="stat-row"><span>Sender</span><span>${esc(p.sender_number || '—')}</span></div>
          <div class="stat-row"><span>Submitted</span><span>${fmtDate(p.submitted_at)}</span></div>
          <div class="stat-row"><span>Status</span><span>${paymentStatusBadge(p.status)}</span></div>
          ${p.reviewer ? `<div class="stat-row"><span>Reviewed by</span><span>${esc(p.reviewer.name)} · ${fmtDate(p.reviewed_at)}</span></div>` : ''}
          ${p.review_note ? `<div class="alert alert-amber mt1">Review note: ${esc(p.review_note)}</div>` : ''}
        </div>
        <div>${p.proof_path ? `<img alt="payment proof" style="max-width:100%;border-radius:10px;border:1px solid var(--line)" id="proof-img">` : '<div class="muted small">No proof attached.</div>'}</div>
      </div>
      ${p.status === 'pending' && SA.can('payments.review') ? `<div class="btn-row">
        <button class="btn btn-success" id="pay-approve">✓ Approve & activate plan</button>
        <button class="btn btn-danger" id="pay-reject">✕ Reject</button>
      </div>` : ''}`, true);

    if (p.proof_path) {
      fetch(`${api.base}/payments/${p.id}/proof`, { headers: { Authorization: 'Bearer ' + api.token, Accept: 'application/json' } })
        .then((r) => r.ok ? r.blob() : null)
        .then((b) => { const img = $('#proof-img', m); if (b && img) img.src = URL.createObjectURL(b); })
        .catch(() => {});
    }

    $('#pay-approve', m)?.addEventListener('click', async () => {
      if (!confirm(`Approve ${money(p.amount)} from ${p.tenant?.name}? Their plan activates immediately.`)) return;
      try {
        const r = await api.post(`/payments/${p.id}/review`, { action: 'approve' });
        toast(r.message, 'ok'); closeModal(); if (onChange) onChange();
      } catch (e) { toast(e.message, 'err'); }
    });
    $('#pay-reject', m)?.addEventListener('click', () => {
      const rm = openModal('Reject payment', `<form id="rej-form" novalidate>
        <p class="muted small">The reason is surfaced to the store on their billing page.</p>
        <label>Reason (min 5 chars) *</label><textarea name="reason" minlength="5" maxlength="500" rows="3" required></textarea>
        <button class="btn btn-danger mt2">Reject payment</button></form>`);
      $('#rej-form', rm).onsubmit = async (e) => {
        e.preventDefault();
        try {
          await api.post(`/payments/${p.id}/review`, { action: 'reject', reason: e.target.reason.value });
          toast('Payment rejected.', 'ok'); closeModal(); if (onChange) onChange();
        } catch (err) { toast(err.message, 'err'); }
      };
    });
  },
};

/* ================= BROADCASTS ================= */

async function saBroadcasts(el) {
  let tenants = [];
  try { tenants = (await api.get('/tenants?per_page=200')).data; } catch (_) {}
  el.innerHTML = `
    <div class="card" style="max-width:680px">
      <h3>Send an in-app broadcast</h3>
      <p class="muted small">Delivers to the tenant's notification bell immediately — no email required.</p>
      <form id="bc-form" novalidate><div id="bc-err"></div>
        <label>Audience</label>
        <select name="scope"><option value="all">All active stores</option><option value="tenant">One store…</option></select>
        <select name="tenant_id" id="bc-tenant" class="hidden mt1">${tenants.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select>
        <label class="mt1">Title *</label><input name="title" maxlength="160" required placeholder="e.g. Scheduled maintenance Sunday 2am">
        <label>Message *</label><textarea name="body" maxlength="1000" rows="4" required></textarea>
        <label>Type (lowercase + underscores)</label><input name="type" maxlength="60" pattern="[a-z_]+" value="broadcast">
        <button class="btn btn-primary mt2">Send broadcast</button>
      </form>
    </div>`;
  const f = $('#bc-form', el);
  f.scope.onchange = () => $('#bc-tenant', el).classList.toggle('hidden', f.scope.value !== 'tenant');
  f.onsubmit = async (e) => {
    e.preventDefault();
    const v = formValues(f);
    if (v.scope === 'all') delete v.tenant_id;
    try { const r = await api.post('/broadcasts', v); toast(r.message, 'ok'); f.reset(); }
    catch (err) { $('#bc-err', el).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
  };
}

/* ================= SUPPORT TICKETS ================= */

async function saTickets(el) {
  el.innerHTML = `
    <div class="toolbar">
      <select id="tk-status" style="max-width:180px"><option value="">All statuses</option>
        ${['open', 'in_progress', 'waiting', 'resolved', 'closed'].map((s) => `<option value="${s}">${s.replace('_', ' ')}</option>`).join('')}</select>
      <input class="search-input" id="tk-tenant" placeholder="Tenant name…">
    </div>
    <div id="tk-list">${loadingBlock(3)}</div>`;

  async function load(page = 1) {
    const host = $('#tk-list', el); host.innerHTML = '';
    await renderList(host, {
      url: '/tickets',
      params: { status: $('#tk-status', el).value, tenant: $('#tk-tenant', el).value, page },
      columns: [
        ['Subject', (t) => `<strong>${esc(t.subject)}</strong>`],
        ['Tenant', (t) => `<a href="#/sa/tenants/${t.tenant_id}">${esc(t.tenant?.name || '—')}</a>`],
        ['Status', (t) => paymentStatusBadge(t.status)],
        ['Assigned', (t) => esc(t.assignee?.name || '—')],
        ['Updated', (t) => `<span class="nowrap">${fmtDate(t.updated_at)}</span>`],
      ],
      rowActions: (t) => `<a class="btn btn-sm" href="#/sa/tickets/${t.id}">Open</a>`,
    });
  }
  $('#tk-status', el).onchange = () => load();
  $('#tk-tenant', el).oninput = debounce(() => load(), 350);
  await load();
}

async function saTicketDetail(el, id) {
  el.innerHTML = loadingBlock(2);
  const t = (await api.get('/tickets/' + id)).data;

  el.innerHTML = `
    <div class="btn-row mb2"><a class="btn btn-ghost" href="#/sa/tickets">← Tickets</a>
      <select id="tk-set-status" style="max-width:180px">
        ${['open', 'in_progress', 'waiting', 'resolved', 'closed'].map((s) => `<option value="${s}" ${t.status === s ? 'selected' : ''}>${s.replace('_', ' ')}</option>`).join('')}
      </select>
      <button class="btn btn-sm" id="tk-assign">${t.assignee ? 'Assigned: ' + esc(t.assignee.name) : 'Assign to me'}</button>
    </div>
    <div class="card mb2" style="max-width:820px">
      <h3>${esc(t.subject)} ${paymentStatusBadge(t.status)}</h3>
      <div class="muted small mb1">${esc(t.tenant?.name || '')} · opened ${fmtDate(t.created_at)}</div>
      <div style="max-height:380px;overflow-y:auto" class="mb1">
        ${(t.replies || []).map((r) => `<div class="card mb1" style="background:${r.author_type === 'super_admin' ? 'var(--blue-bg)' : 'var(--panel)'}">
          <div class="small muted">${esc(r.author_name)} · ${r.author_type === 'super_admin' ? 'Platform support' : 'Store'}${r.is_internal ? ' · ' + badge('internal note', 'dark') : ''} · ${fmtDate(r.created_at)}</div>
          <div style="white-space:pre-wrap">${esc(r.body)}</div></div>`).join('') || '<div class="muted">No replies yet.</div>'}
      </div>
      ${t.status !== 'closed' ? `<form id="tk-reply" novalidate><textarea name="body" rows="3" maxlength="5000" placeholder="Reply to the store…" required></textarea>
        <label class="mt1"><input type="checkbox" name="is_internal"> Internal note (hidden from the store)</label>
        <button class="btn btn-primary mt1">Send</button></form>` : ''}
    </div>`;

  $('#tk-set-status', el).onchange = async (e) => {
    try { await api.post(`/tickets/${id}/status`, { status: e.target.value }); toast('Status updated.', 'ok'); }
    catch (err) { toast(err.message, 'err'); }
  };
  $('#tk-assign', el).onclick = async () => {
    try { await api.post(`/tickets/${id}/assign`, { super_admin_id: SA.me.id }); toast('Assigned to you.', 'ok'); saRoute(); }
    catch (err) { toast(err.message, 'err'); }
  };
  $('#tk-reply', el)?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const v = formValues(e.target);
    v.is_internal = !!v.is_internal;
    try { await api.post(`/tickets/${id}/replies`, v); saRoute(); }
    catch (err) { toast(err.message, 'err'); }
  });
}

/* ================= IMPERSONATIONS REGISTRY ================= */

async function saImpersonations(el) {
  el.innerHTML = `<div id="imp-list">${loadingBlock(3)}</div>`;
  async function load(page = 1) {
    const host = $('#imp-list', el); host.innerHTML = '';
    await renderList(host, {
      url: '/impersonations', params: { page },
      emptyIcon: '🕶', emptyText: 'No impersonation sessions on record.',
      columns: [
        ['Started', (s) => `<span class="nowrap">${fmtDate(s.started_at)}</span>`],
        ['Tenant', (s) => `<a href="#/sa/tenants/${s.tenant_id}">${esc(s.tenant?.name || '#' + s.tenant_id)}</a>`],
        ['Admin', (s) => esc(s.super_admin?.name || s.admin?.name || '—')],
        ['Reason', (s) => `<span class="muted small">${esc((s.reason || '').slice(0, 80))}</span>`],
        ['Expires', (s) => `<span class="nowrap">${fmtDate(s.expires_at)}</span>`],
        ['Status', (s) => s.ended_at ? badge('ended', 'gray') : (new Date(s.expires_at) < new Date() ? badge('expired', 'amber') : badge('live', 'green'))],
      ],
      rowActions: (s) => !s.ended_at && new Date(s.expires_at) >= new Date() && SA.can('impersonate')
        ? `<button class="btn btn-sm btn-danger" data-stop="${s.id}">Revoke</button>` : '',
    });
    $$('[data-stop]', host).forEach((b) => {
      b.onclick = async () => {
        if (!confirm('Revoke this impersonation session? The admin is signed out of the tenant workspace.')) return;
        try { await api.post(`/impersonations/${b.dataset.stop}/stop`); toast('Session revoked.', 'ok'); load(); }
        catch (e) { toast(e.message, 'err'); }
      };
    });
  }
  await load();
}

/* ================= REVENUE ================= */

async function saRevenue(el) {
  el.innerHTML = loadingBlock(4);
  const d = await api.get('/revenue');
  const cur = d.currency || 'GHS';

  el.innerHTML = `
    <div class="grid grid-4 mb2">
      <div class="kpi kpi-accent"><div class="kpi-label">MRR</div><div class="kpi-value">${money(d.mrr, cur)}</div>
        <div class="kpi-sub">${d.active_paying_tenants} paying stores</div></div>
      <div class="kpi"><div class="kpi-label">ARR</div><div class="kpi-value">${money(d.arr, cur)}</div>
        <div class="kpi-sub">ARPU ${d.arpu !== null ? money(d.arpu, cur) : 'N/A'}</div></div>
      <div class="kpi"><div class="kpi-label">Collected — 30 days</div><div class="kpi-value">${money(d.sums.last_30_days, cur)}</div>
        <div class="kpi-sub">lifetime ${money(d.sums.lifetime, cur)}</div></div>
      <div class="kpi ${d.churn.this_month > 0 ? 'kpi-red' : ''}"><div class="kpi-label">Churn this month</div>
        <div class="kpi-value">${d.churn.this_month}</div><div class="kpi-sub">${d.churn.rate_percent}% of base</div></div>
    </div>
    <div class="grid grid-2 mb2">
      <div class="card"><h3>Approved collections — 12 months</h3>${barsHtml((d.monthly_series || []).map((s) => ({ date: s.month, total: s.collected })))}
        <button class="btn btn-sm mt1" id="rev-csv">⬇ Export CSV</button></div>
      <div class="card"><h3>Subscribers by plan</h3>
        ${(d.by_plan || []).map((p) => `<div class="stat-row"><span><strong>${esc(p.name)}</strong> <span class="muted small">${money(p.price, cur)}</span></span>
          <span>${p.subscribers} subs · <strong>${money(p.mrr, cur)}/mo</strong></span></div>`).join('') || '<div class="muted">No paying subscribers yet.</div>'}
      </div>
    </div>`;

  $('#rev-csv', el).onclick = () => csvExport(`revenue-${todayStr()}.csv`, ['Month', 'Collected'], (d.monthly_series || []).map((s) => [s.month, s.collected]));
}

/* ================= ANALYTICS ================= */

async function saAnalytics(el) {
  el.innerHTML = loadingBlock(3);
  const d = await api.get('/analytics');

  el.innerHTML = `
    <div class="grid grid-4 mb2">
      <div class="kpi"><div class="kpi-label">Total stores</div><div class="kpi-value">${d.conversion.tenants_total}</div></div>
      <div class="kpi kpi-green"><div class="kpi-label">Ever paid</div><div class="kpi-value">${d.conversion.ever_paid}</div>
        <div class="kpi-sub">trial→paid ${d.conversion.trial_to_paid !== null ? d.conversion.trial_to_paid + '%' : 'N/A'}</div></div>
      <div class="kpi"><div class="kpi-label">Currently trialing</div><div class="kpi-value">${d.conversion.currently_trialing}</div></div>
      <div class="kpi"><div class="kpi-label">Status split</div><div class="kpi-value" style="font-size:.95rem">${Object.entries(d.tenant_status || {}).map(([s, c]) => `${badge(s, s === 'active' ? 'green' : 'red')} ${c}`).join(' ')}</div></div>
    </div>
    <div class="grid grid-2">
      <div class="card"><h3>Signups — 12 months</h3>${barsHtml((d.signup_series || []).map((s) => ({ date: s.month, total: s.signups })))}</div>
      <div class="card"><h3>Most popular plans</h3>
        ${(d.popular_plans || []).map((p) => `<div class="stat-row"><span>${esc(p.name)}</span><strong>${p.subs} subscription(s)</strong></div>`).join('') || '<div class="muted">No subscriptions yet.</div>'}
      </div>
    </div>`;
}

/* ================= API STATUS ================= */

async function saApiStatus(el) {
  el.innerHTML = loadingBlock(2);
  const d = await api.get('/api-status');
  el.innerHTML = `
    <div class="grid grid-3 mb2">
      <div class="kpi kpi-green"><div class="kpi-label">Status</div><div class="kpi-value" style="text-transform:capitalize">${esc(d.status)}</div>
        <div class="kpi-sub">checked ${fmtDate(d.checked_at)}</div></div>
      <div class="kpi"><div class="kpi-label">v1 endpoints</div><div class="kpi-value">${d.endpoint_count}</div></div>
      <div class="kpi"><div class="kpi-label">Base URL</div><div class="kpi-value" style="font-size:.85rem;word-break:break-all">${esc(d.base_url)}</div></div>
    </div>
    <div class="card mb2"><h3>Rate limits</h3>
      ${Object.entries(d.rate_limits || {}).map(([k, v]) => `<div class="stat-row"><span><code>${esc(k)}</code></span><span class="muted">${esc(v)}</span></div>`).join('')}
    </div>
    <div class="card"><div class="btn-row" style="justify-content:space-between"><h3>Endpoint catalogue</h3>
      <button class="btn btn-sm" id="ep-csv">⬇ CSV</button></div>
      <div class="code-scroll">${(d.endpoints || []).map(esc).join('\n')}</div></div>`;
  $('#ep-csv', el).onclick = () => csvExport(`endpoints-${todayStr()}.csv`, ['Endpoint'], (d.endpoints || []).map((e) => [e]));
}

/* ================= AUDITS ================= */

async function saAudits(el) {
  el.innerHTML = `
    <div class="toolbar">
      <input class="search-input" id="au-action" placeholder="Action prefix (e.g. payment.)">
      <input class="search-input" id="au-actor" placeholder="Actor email">
      <input class="search-input" id="au-tenant" placeholder="Tenant id" style="max-width:120px">
    </div>
    <div id="au-list">${loadingBlock(3)}</div>`;

  async function load(page = 1) {
    const host = $('#au-list', el); host.innerHTML = '';
    await renderList(host, {
      url: '/audits',
      params: { action: $('#au-action', el).value, actor: $('#au-actor', el).value, tenant_id: $('#au-tenant', el).value, page },
      columns: [
        ['When', (a) => `<span class="nowrap">${fmtDate(a.created_at)}</span>`],
        ['Actor', (a) => `${esc(a.actor_label || '')}<div class="muted small">${esc(a.actor_email || '')}</div>`],
        ['Action', (a) => `<code>${esc(a.action)}</code>${a.impersonation_session_id ? ' ' + badge('impersonated', 'dark') : ''}`],
        ['Tenant', (a) => a.tenant_id ? `<a href="#/sa/tenants/${a.tenant_id}">#${a.tenant_id}</a>` : '—'],
        ['Context', (a) => `<span class="muted small">${esc(JSON.stringify(a.context || {})).slice(0, 110)}</span>`],
      ],
      perPage: 15,
    });
  }
  ['au-action', 'au-actor', 'au-tenant'].forEach((i) => { $('#' + i, el).oninput = debounce(() => load(), 350); });
  await load();
}

/* ================= ADMINS ================= */

const SA_PERMISSIONS = [
  ['tenants.view', 'View tenants'], ['tenants.create', 'Create tenants'], ['tenants.suspend', 'Suspend / restore'],
  ['subscriptions.manage', 'Adjust subscriptions'], ['payments.review', 'Review payments'],
  ['impersonate', 'Impersonate tenants'], ['admins.manage', 'Manage admins'],
  ['analytics.view', 'Revenue & analytics'], ['settings.manage', 'Platform settings'], ['audits.view', 'Audit trail'],
  ['plans.manage', 'Manage plans'],
];

async function saAdmins(el) {
  el.innerHTML = loadingBlock(2);
  const d = await api.get('/admins');
  const admins = d.data || d;

  el.innerHTML = `
    <div class="toolbar"><div class="toolbar-title">Platform administrators</div>
      <button class="btn btn-primary" id="adm-new">＋ New administrator</button></div>
    <div class="panel"><table><thead><tr><th>Name</th><th>Email</th><th>Permissions</th><th>2FA</th><th>Status</th><th></th></tr></thead><tbody>
      ${admins.map((a) => `<tr><td><strong>${esc(a.name)}</strong></td><td>${esc(a.email)}</td>
        <td><span class="muted small">${esc((a.permissions || []).join(', ') || '—')}</span></td>
        <td>${a.two_factor_enabled ? badge('on', 'green') : badge('setup pending', 'amber')}</td>
        <td>${a.is_active !== false ? badge('active', 'green') : badge('deactivated', 'red')}</td>
        <td class="nowrap">${a.id !== SA.me.id
          ? `<button class="btn btn-sm btn-danger" data-deact="${a.id}">Deactivate</button>`
          : '<span class="muted small">you</span>'}</td>
      </tr>`).join('')}</tbody></table></div>`;

  $('#adm-new', el).onclick = () => {
    const m = openModal('New administrator', `<form id="adm-form" novalidate><div id="adm-err"></div>
      <div class="form-grid">
        <div><label>Name *</label><input name="name" required maxlength="120"></div>
        <div><label>Email *</label><input name="email" type="email" required></div>
      </div>
      <div class="form-grid">
        <div><label>Password (min 10) *</label><input name="password" type="password" minlength="10" required></div>
        <div><label>Confirm *</label><input name="password_confirmation" type="password" required></div>
      </div>
      <label>Permissions</label>
      <div class="perm-grid">${SA_PERMISSIONS.map(([p, l]) => `<label><input type="checkbox" name="perm__${p}"> ${esc(l)}</label>`).join('')}</div>
      <button class="btn btn-primary mt2">Create administrator</button></form>`, true);
    $('#adm-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const v = formValues(e.target);
      const permissions = SA_PERMISSIONS.map(([p]) => p).filter((p) => v['perm__' + p]);
      Object.keys(v).forEach((k) => { if (k.startsWith('perm__')) delete v[k]; });
      v.permissions = permissions;
      try { await api.post('/admins', v); toast('Administrator created — they must enrol 2FA on first sign-in.', 'ok'); closeModal(); saRoute(); }
      catch (err) { $('#adm-err', m).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
    };
  };

  $$('[data-deact]', el).forEach((b) => {
    b.onclick = async () => {
      const a = admins.find((x) => x.id == b.dataset.deact);
      if (!confirm(`Deactivate ${a.name}? All their sessions are revoked immediately.`)) return;
      try { await api.post(`/admins/${a.id}/deactivate`); toast('Deactivated and sessions revoked.', 'ok'); saRoute(); }
      catch (err) { toast(err.message, 'err'); }
    };
  });
}

/* ================= PLATFORM SETTINGS ================= */

async function saSettings(el) {
  el.innerHTML = loadingBlock(2);
  const s = (await api.get('/settings/payment')).data;

  el.innerHTML = `
    <form id="st-form" class="card" style="max-width:720px" novalidate><div id="st-err"></div>
      <h3>Where tenants send subscription money</h3>
      <div class="alert alert-blue">Shown verbatim on each store's Billing page and the landing page. Details are never written to the audit log — only which fields changed.</div>
      <h4>MoMo provider 1</h4>
      <div class="form-grid grid-3">
        <div><label>Provider</label><select name="momo_provider">${['MTN MoMo', 'Telecel Cash', 'AirtelTigo Money', 'Other'].map((p) => `<option ${s.momo_provider === p ? 'selected' : ''}>${p}</option>`).join('')}</select></div>
        <div><label>Registered name</label><input name="momo_name" maxlength="120" value="${esc(s.momo_name || '')}"></div>
        <div><label>Number</label><input name="momo_number" maxlength="30" value="${esc(s.momo_number || '')}"></div>
      </div>
      <h4>MoMo provider 2</h4>
      <div class="form-grid grid-3">
        <div><label>Provider</label><select name="momo_provider_2"><option value="">—</option>${['MTN MoMo', 'Telecel Cash', 'AirtelTigo Money', 'Other'].map((p) => `<option ${s.momo_provider_2 === p ? 'selected' : ''}>${p}</option>`).join('')}</select></div>
        <div><label>Registered name</label><input name="momo_name_2" maxlength="120" value="${esc(s.momo_name_2 || '')}"></div>
        <div><label>Number</label><input name="momo_number_2" maxlength="30" value="${esc(s.momo_number_2 || '')}"></div>
      </div>
      <h4>Bank account</h4>
      <div class="form-grid grid-3">
        <div><label>Bank</label><input name="bank_name" maxlength="120" value="${esc(s.bank_name || '')}"></div>
        <div><label>Account name</label><input name="bank_account_name" maxlength="120" value="${esc(s.bank_account_name || '')}"></div>
        <div><label>Account #</label><input name="bank_account_number" maxlength="60" value="${esc(s.bank_account_number || '')}"></div>
      </div>
      <label>Payment instructions (shown to stores)</label>
      <textarea name="payment_instructions" maxlength="2000" rows="3">${esc(s.payment_instructions || '')}</textarea>
      <div class="form-grid mt1">
        <div><label>Grace period when a subscription lapses (days)</label><input name="grace_period_days" type="number" min="0" max="60" value="${s.grace_period_days ?? 3}"></div>
      </div>
      <button class="btn btn-primary mt2">Save platform settings</button>
    </form>`;

  $('#st-form', el).onsubmit = async (e) => {
    e.preventDefault();
    try { await api.put('/settings/payment', formValues(e.target)); toast('Platform settings saved.', 'ok'); }
    catch (err) { $('#st-err', el).innerHTML = `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`; }
  };
}

/* ---------- go ---------- */
document.title = 'ShopStack Platform Console';
saBoot();
