/* ShopStack tenant app — boot + client router. */
(function () {
  api.setScope('tenant');

  const PREFIX_ROUTES = ['#/sales/', '#/customers/', '#/invoices/', '#/purchases/'];
  const PUBLIC_HASHES = ['#/login', '#/forgot', '#/reset'];

  async function boot() {
    const root = $('#app');

    if (!api.token) {
      if (!PUBLIC_HASHES.includes(location.hash)) location.hash = '#/login';
      return renderRoute();
    }

    try {
      const [me, settings] = await Promise.all([api.get('/me'), api.get('/settings')]);
      State.me = me.user;
      State.tenant = me.tenant;
      State.settings = { ...settings.settings, __impersonated: !!me.impersonated };
      State.lifecycle = settings.lifecycle;
      refreshUnread();
    } catch (e) {
      if (e.status === 401) { State.clear(); location.hash = '#/login'; return renderRoute(); }
      root.innerHTML = `<div class="boot-splash"><div class="empty"><span class="empty-icon">⚠️</span>
        <p>${esc(e.message)}</p><button class="btn" onclick="location.reload()">Retry</button></div></div>`;
      return;
    }

    // First-run onboarding for brand new workspaces.
    let onboardingDone = true;
    try { onboardingDone = (await api.get('/settings')).onboarding_completed; } catch (_) {}
    if (!onboardingDone && !['#/onboarding'].includes(location.hash)) {
      location.hash = '#/onboarding';
    } else if (['#/', '#/login'].includes(location.hash) || location.hash === '') {
      location.hash = '#/dashboard';
    }

    const path = currentPath();
    const el = page(titleFor(path));
    if (!PUBLIC_HASHES.some((h) => path.startsWith(h))) layout(el, '/' + path.split('/')[1]);
    else $('#app').innerHTML = '';

    await dispatch(path, el);
  }

  function currentPath() {
    return (location.hash || '#/dashboard').replace(/^#/, '');
  }

  function titleFor(path) {
    if (path.startsWith('/pos')) return 'POS Terminal';
    if (path.startsWith('/products')) return 'Products';
    if (path.startsWith('/inventory')) return 'Inventory';
    if (path.startsWith('/customers')) return 'Customers';
    if (path.startsWith('/suppliers')) return 'Suppliers';
    if (path.startsWith('/purchases')) return 'Purchases';
    if (path.startsWith('/sales')) return 'Sales';
    if (path.startsWith('/invoices')) return 'Invoices';
    if (path.startsWith('/expenses')) return 'Expenses';
    if (path.startsWith('/reports')) return 'Reports';
    if (path.startsWith('/users')) return 'Team';
    if (path.startsWith('/billing')) return 'Billing';
    if (path.startsWith('/activity')) return 'Activity Log';
    if (path.startsWith('/settings')) return 'Settings';
    if (path.startsWith('/tickets')) return 'Support';
    if (path.startsWith('/onboarding')) return 'Setup';
    return 'Dashboard';
  }

  async function renderRoute() {
    const path = currentPath();
    const route = Pages[path.startsWith('/login') || path.startsWith('/forgot') ? '#' + path : '#/login'];
    const el = page(path.startsWith('/forgot') ? 'Reset password' : 'Sign in');
    $('#app').innerHTML = '';
    $('#app').appendChild(el);
    await (route)(el);
  }

  async function dispatch(path, el) {
    // Wildcard detail routes: /sales/123 → Pages['#/sales/*'](el, '123')
    for (const prefix of PREFIX_ROUTES) {
      const p = '/' + path.split('/')[1];
      if (path.startsWith(prefix.slice(1)) && path.split('/')[2]) {
        const id = path.split('/')[2];
        return Pages[prefix.slice(0, -1) + '/*'] && Pages[prefix.slice(0, -1) + '/*'](el, id);
      }
    }
    const fn = Pages['#' + path.split('?')[0]];
    if (fn) return fn(el);
    el.innerHTML = emptyBlock('🧭', 'Page not found.', '<a class="btn" href="#/dashboard">Go to dashboard</a>');
  }

  let booting = null;
  function guard() {
    const path = currentPath();
    if (!api.token && !PUBLIC_HASHES.some((h) => path.startsWith(h.slice(2)))) {
      location.hash = '#/login';
    }
  }

  window.addEventListener('hashchange', async () => {
    guard();
    const path = currentPath();
    if (!api.token) return renderRoute();
    if (PUBLIC_HASHES.some((h) => path.startsWith(h.slice(2)))) return renderRoute();
    const el = page(titleFor(path));
    layout(el, '/' + path.split('/')[1]);
    await dispatch(path, el);
  });

  boot();
})();
