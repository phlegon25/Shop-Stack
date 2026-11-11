/* Workspace plane: team, activity log, billing, settings + wizard, support. */
Pages = window.Pages || {};

/* ================= TEAM ================= */
Pages['#/users'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">👥 Team</div>
    <button class="btn btn-primary" id="add-user" ${State.can('users.manage') ? '' : 'disabled title="Manager+ only"'}>＋ Invite staff</button></div>
    <div class="alert alert-blue mb2">Roles control exactly what each person can do. Changes revoke the affected person's sessions.</div>
    <div id="team-list">${loadingBlock(2)}</div>`;

  const ROLE_LABELS = { owner: 'Owner', admin: 'Administrator', manager: 'Manager', cashier: 'Cashier', inventory: 'Inventory Staff', accountant: 'Accountant' };

  async function load() {
    const host = $('#team-list', el); host.innerHTML = loadingBlock(2);
    try {
      const d = await api.get('/users');
      host.innerHTML = `<div class="panel"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
        <tbody>${d.data.map((u) => `<tr>
          <td><strong>${esc(u.name)}</strong></td><td>${esc(u.email)}</td>
          <td>${badge(ROLE_LABELS[u.role] || u.role, u.role === 'owner' ? 'blue' : 'gray')}</td>
          <td>${u.status === 'active' ? badge('Active', 'green') : badge('Disabled', 'red')}</td>
          <td>${State.can('users.manage') && u.role !== 'owner' && u.id !== State.me.id
            ? `<button class="btn btn-sm" data-u="${u.id}">Edit</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
      $$('[data-u]', host).forEach((b) => {
        b.onclick = () => editUser(d.data.find((u) => u.id == b.dataset.u));
      });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  function userForm(user) {
    const u = user || {};
    const roles = ['admin', 'manager', 'cashier', 'inventory', 'accountant'];
    const m = openModal(user ? 'Edit — ' + u.name : 'Invite staff', `
      <form id="u-form">
        <label>Full name *</label><input name="name" required maxlength="120" value="${esc(u.name || '')}">
        <label>Email *</label><input name="email" type="email" required value="${esc(u.email || '')}" ${user ? 'disabled' : ''}>
        <label>Role *</label><select name="role">${roles.map((r) => `<option value="${r}" ${u.role === r ? 'selected' : ''}>${ROLE_LABELS[r]}</option>`).join('')}</select>
        ${user ? `<label>Status</label><select name="status"><option value="active" ${u.status === 'active' ? 'selected' : ''}>Active</option>
          <option value="disabled" ${u.status === 'disabled' ? 'selected' : ''}>Disabled</option></select>` : ''}
        ${!user ? `<label>Temporary password *</label><input name="password" required minlength="10" type="password" autocomplete="new-password">
        <label>Confirm password *</label><input name="password_confirmation" required type="password" autocomplete="new-password">` : ''}
        <div class="btn-row mt2"><button class="btn btn-primary">${user ? 'Save changes' : 'Create account'}</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`);
    $('#u-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        if (user) await api.put('/users/' + u.id, formValues(e.target));
        else await api.post('/users', formValues(e.target));
        toast('Saved.', 'ok'); closeModal(); load();
      } catch (err) { e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`); }
    };
  }
  const editUser = (u) => userForm(u);

  $('#add-user', el).onclick = () => userForm(null);
  await load();
};

/* ================= ACTIVITY LOG ================= */
Pages['#/activity'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🔐 Activity Log</div>
    <input class="search-input" id="act-action" placeholder="Filter action (e.g. sale.)">
    <input type="date" id="act-from"><input type="date" id="act-to"></div>
    <div class="alert alert-blue mb2">This log is immutable — nobody can edit or delete entries, not even the owner.</div>
    <div id="act-list">${loadingBlock(3)}</div>`;

  async function load(page = 1) {
    const host = $('#act-list', el); host.innerHTML = '';
    await renderList(host, {
      url: '/activity',
      params: { action: $('#act-action', el).value, from: $('#act-from', el).value, to: $('#act-to', el).value, page },
      emptyIcon: '🔐', emptyText: 'No matching events.',
      columns: [
        ['When', (a) => `<span class="nowrap">${fmtDate(a.created_at)}</span>`],
        ['Actor', (a) => `${esc(a.actor_label || a.actor_type || 'system')}<div class="muted small">${esc(a.actor_email || '')}</div>`],
        ['Action', (a) => `<code>${esc(a.action)}</code>${a.impersonation_session_id ? ' ' + badge('impersonated', 'dark') : ''}`],
        ['Context', (a) => `<span class="muted small">${esc(JSON.stringify(a.context || {})).slice(0, 120)}</span>`],
      ],
      perPage: 15,
    });
  }

  ['act-action', 'act-from', 'act-to'].forEach((id) => { $('#' + id, el).onchange = load; });
  $('#act-action', el).oninput = debounce(load, 400);
  await load();
};

/* ================= BILLING ================= */
Pages['#/billing'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">💳 Billing & Subscription</div></div>
    <div id="bill-body">${loadingBlock(3)}</div>`;

  const host = $('#bill-body', el);
  try {
    const [status, plansRes, payInfo, history] = await Promise.all([
      api.get('/billing/status'),
      api.get('/billing/plans'),
      api.get('/billing/platform-payment-info'),
      api.get('/billing/payments'),
    ]);
    const sub = status.subscription;

    host.innerHTML = `
      <div class="grid" style="grid-template-columns: 2fr 1fr">
        <div class="card mb2">
          <h3>Current subscription ${sub ? subBadge({ plan: sub.plan, days_left: sub.days_left }, State.tenant?.status) : badge('NONE', 'red')}</h3>
          ${sub ? `<p class="muted">Ends ${new Date(sub.ends_at).toLocaleDateString()} (${sub.days_left} day(s) left)</p>` : '<p>No active subscription.</p>'}
          <h3 class="mt2">Choose a plan</h3>
          <div class="grid grid-3">
            ${plansRes.data.map((p) => `
              <div class="card" style="border:2px solid ${sub?.plan === p.name ? 'var(--brand)' : 'var(--line)'}">
                <h3>${esc(p.name)}</h3>
                <div style="font-size:1.6rem;font-weight:800">${money(p.price, plansRes.currency)}<span class="muted small">/${p.duration_days}d</span></div>
                <ul class="small" style="padding-left:1.1rem;margin:.6rem 0">
                  ${(p.features || []).map((f) => `<li>${esc(f)}</li>`).join('')}
                </ul>
                <button class="btn btn-primary btn-sm" data-plan="${p.id}" data-price="${p.price}" data-name="${esc(p.name)}">Pay ${money(p.price, plansRes.currency)}</button>
              </div>`).join('')}
          </div>
        </div>
        <div>
          <div class="card mb2"><h3>How to pay</h3>
            ${payInfo.momo.map((mm) => `<div class="stat-row"><span><strong>${esc(mm.provider)}</strong><div class="muted small">${esc(mm.name)}</div></span><strong>${esc(mm.number)}</strong></div>`).join('')}
            ${payInfo.bank ? `<div class="stat-row"><span><strong>${esc(payInfo.bank.bank_name)}</strong><div class="muted small">${esc(payInfo.bank.account_name)}</div></span><strong>${esc(payInfo.bank.account_number)}</strong></div>` : ''}
            ${payInfo.payment_instructions ? `<div class="muted small mt1">${esc(payInfo.payment_instructions)}</div>` : ''}
          </div>
          <div class="card"><h3>Payment history</h3>
            ${history.data.length ? history.data.slice(0, 6).map((p) => `<div class="stat-row">
              <span>${esc(p.plan?.name || '—')} · ${fmtDate(p.submitted_at)}</span>
              <span>${paymentStatusBadge(p.status)} <strong>${money(p.amount, plansRes.currency)}</strong></span></div>`).join('')
            : '<div class="muted small">No payments submitted yet.</div>'}
          </div>
        </div>
      </div>`;

    $$('[data-plan]', host).forEach((b) => {
      b.onclick = () => {
        const m = openModal(`Submit payment — ${b.dataset.name}`, `
          <form id="sub-pay">
            <div class="alert alert-blue">Pay ${money(b.dataset.price, plansRes.currency)} to one of the MoMo/bank accounts shown, then submit the details here. A platform admin verifies and activates your plan.</div>
            <label>Amount paid (GHS) *</label><input name="amount" type="number" step="0.01" min="0.01" value="${b.dataset.price}" required>
            <label>Method *</label><select name="method"><option value="momo">Mobile Money</option><option value="bank">Bank transfer</option></select>
            <label>Your sender number / account *</label><input name="sender_number" required maxlength="30" placeholder="0240000000">
            <label>Transaction reference *</label><input name="reference" required maxlength="60" placeholder="MoMo TXN ref / bank slip no">
            <label>Proof of payment (optional, image)</label><input name="proof" type="file" accept="image/png,image/jpeg,image/webp">
            <div class="btn-row mt2"><button class="btn btn-primary">Submit for verification</button><button class="btn" data-close type="button">Cancel</button></div>
          </form>`);
        $('#sub-pay', m).onsubmit = async (e) => {
          e.preventDefault();
          const fd = new FormData();
          const v = formValues(e.target);
          fd.append('plan_id', b.dataset.plan);
          ['amount', 'method', 'sender_number', 'reference'].forEach((k) => fd.append(k, v[k]));
          const proof = e.target.proof.files[0];
          if (proof) fd.append('proof', proof);
          try {
            await api.postForm('/billing/payments', fd);
            toast('Payment submitted — pending platform verification.', 'ok');
            closeModal();
          } catch (err) {
            e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`);
          }
        };
      };
    });
  } catch (e) { host.innerHTML = errBlock(e); }
};

/* ================= SETTINGS + ONBOARDING ================= */
Pages['#/settings'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">⚙️ Settings</div></div>
    <div id="settings-body">${loadingBlock(3)}</div>`;
  const host = $('#settings-body', el);

  let d;
  try { d = await api.get('/settings'); } catch (e) { host.innerHTML = errBlock(e); return; }
  const s = d.settings, biz = d.business;
  const canManage = State.can('settings.manage');

  host.innerHTML = `
    ${!d.onboarding_completed ? `<div class="alert alert-amber mb2">Finish setting up your workspace:
      <button class="btn btn-sm btn-primary" id="start-wizard">Run setup wizard →</button></div>` : ''}
    <div class="tabs" id="set-tabs">
      <button data-t="business" class="active">Business profile</button>
      <button data-t="ops">Operations</button>
      <button data-t="branding">Receipt branding</button>
      <button data-t="usage">Plan & usage</button>
    </div>
    <div id="set-panel"></div>`;

  const panel = $('#set-panel', host);
  function show(tab) {
    $$('#set-tabs button', host).forEach((b) => b.classList.toggle('active', b.dataset.t === tab));
    if (tab === 'business') {
      panel.innerHTML = `
        <form id="profile-form" class="card" style="max-width:640px">
          <div class="form-grid">
            <div><label>Business name *</label><input name="name" required maxlength="120" value="${esc(biz.name)}" ${canManage ? '' : 'disabled'}></div>
            <div><label>Business type</label><input name="business_type" maxlength="60" value="${esc(biz.business_type || '')}" ${canManage ? '' : 'disabled'}></div>
          </div>
          <div class="form-grid">
            <div><label>Phone</label><input name="phone" maxlength="30" value="${esc(biz.phone || '')}" ${canManage ? '' : 'disabled'}></div>
            <div><label>Email</label><input name="email" type="email" value="${esc(biz.email || '')}" ${canManage ? '' : 'disabled'}></div>
          </div>
          <label>Address</label><input name="address" maxlength="300" value="${esc(biz.address || '')}" ${canManage ? '' : 'disabled'}>
          <div class="form-grid">
            <div><label>City</label><input name="city" maxlength="80" value="${esc(biz.city || '')}" ${canManage ? '' : 'disabled'}></div>
            <div><label>Region</label><input name="region" maxlength="80" value="${esc(biz.region || '')}" ${canManage ? '' : 'disabled'}></div>
          </div>
          <div class="form-grid">
            <div><label>Country</label><input name="country" maxlength="80" value="${esc(biz.country || 'Ghana')}" ${canManage ? '' : 'disabled'}></div>
            <div><label>Currency (3 letters)</label><input name="currency" maxlength="3" value="${esc(s.currency)}" ${canManage ? '' : 'disabled'}></div>
          </div>
          ${canManage ? '<button class="btn btn-primary mt2">Save profile</button>' : '<div class="muted small mt2">Only the owner/administrator can change settings.</div>'}
        </form>`;
      $('#profile-form', panel).onsubmit = async (e) => {
        e.preventDefault();
        try { await api.put('/settings/profile', formValues(e.target)); toast('Profile saved.', 'ok'); const nt = await api.get('/me'); State.tenant = nt.tenant; }
        catch (err) { e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`); }
      };
    } else if (tab === 'ops') {
      panel.innerHTML = `
        <form id="ops-form" class="card" style="max-width:640px">
          <div class="form-grid">
            <div><label>Default tax rate %</label><input name="tax_rate" type="number" min="0" max="100" step="0.01" value="${s.tax_rate}" ${canManage ? '' : 'disabled'}></div>
            <div><label>Default low-stock threshold</label><input name="low_stock_default" type="number" min="0" value="${s.low_stock_default}" ${canManage ? '' : 'disabled'}></div>
          </div>
          <div class="form-grid">
            <div><label>Expiry warning window (days)</label><input name="expiry_warn_days" type="number" min="0" max="365" value="${s.expiry_warn_days}" ${canManage ? '' : 'disabled'}></div>
            <div><label>Receipt footer message</label><input name="invoice_footer" maxlength="300" value="${esc(s.invoice_footer || '')}" ${canManage ? '' : 'disabled'}></div>
          </div>
          ${canManage ? '<button class="btn btn-primary mt2">Save</button>' : ''}
        </form>`;
      $('#ops-form', panel).onsubmit = async (e) => {
        e.preventDefault();
        try { await api.put('/settings', formValues(e.target)); toast('Saved.', 'ok'); }
        catch (err) { e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`); }
      };
    } else if (tab === 'branding') {
      panel.innerHTML = `
        <div class="card" style="max-width:640px">
          <h3>Business logo</h3>
          ${s.logo_url ? `<img src="${esc(s.logo_url)}" style="max-height:80px" class="mb1">` : '<div class="muted small mb1">No logo uploaded.</div>'}
          ${canManage ? `<input type="file" id="logo-file" accept="image/png,image/jpeg,image/webp" class="mb1">
          <button class="btn btn-primary" id="logo-btn">Upload logo</button>` : ''}
        </div>`;
      $('#logo-btn', panel)?.addEventListener('click', async () => {
        const f = $('#logo-file', panel).files[0];
        if (!f) { toast('Choose a file first.', 'err'); return; }
        const fd = new FormData(); fd.append('logo', f);
        try { await api.postForm('/settings/logo', fd); toast('Logo updated.', 'ok'); Pages['#/settings'](page('Settings')); }
        catch (err) { toast(err.message, 'err'); }
      });
    } else {
      panel.innerHTML = `
        <div class="grid grid-2" style="max-width:800px">
          <div class="card"><h3>Lifecycle</h3>
            <div class="stat-row"><span>Stage</span><strong>${esc(d.lifecycle?.stage || '—')}</strong></div>
            ${d.lifecycle?.days_left !== undefined && d.lifecycle.days_left !== null ? `<div class="stat-row"><span>Days left</span><strong>${d.lifecycle.days_left}</strong></div>` : ''}
            ${d.lifecycle?.detail ? `<div class="muted small mt1">${esc(d.lifecycle.detail)}</div>` : ''}
            <a class="btn btn-sm btn-primary mt1" href="#/billing">Manage billing →</a>
          </div>
          <div class="card"><h3>Usage vs plan limits</h3>
            ${['products', 'users'].map((k) => {
              const u = d.usage[k];
              return `<div class="stat-row"><span style="text-transform:capitalize">${k}</span>
                <strong>${u.used}${u.limit ? ' / ' + u.limit : ' / ∞'}</strong></div>`;
            }).join('')}
          </div>
        </div>`;
    }
  }
  $$('#set-tabs button', host).forEach((b) => { b.onclick = () => show(b.dataset.t); });
  show('business');
  $('#start-wizard', host)?.addEventListener('click', () => Pages['#/onboarding'](page('Welcome')));
};

/* 8-step onboarding wizard for brand-new tenants. */
Pages['#/onboarding'] = async function (el) {
  const steps = ['Welcome', 'Business', 'Currency & tax', 'First category', 'First product', 'First supplier', 'Team (optional)', 'Done'];
  let step = 0;

  function render() {
    el.innerHTML = `<div class="wizard">
      <h2 class="center">🧭 Workspace setup — step ${step + 1} of ${steps.length}</h2>
      <div class="steps">${steps.map((_, i) => `<span class="${i <= step ? 'done' : ''}"></span>`).join('')}</div>
      <div class="card" id="wz-body"></div>
      <div class="btn-row mt1" style="justify-content:space-between">
        <button class="btn" id="wz-back" ${step === 0 ? 'disabled' : ''}>← Back</button>
        <button class="btn btn-ghost" id="wz-skip">Skip for now</button>
      </div></div>`;
    const body = $('#wz-body', el);
    const next = () => { step = Math.min(step + 1, steps.length - 1); render(); };
    $('#wz-back', el).onclick = () => { step = Math.max(0, step - 1); render(); };
    $('#wz-skip', el).onclick = async () => {
      try { await api.post('/settings/onboarding/complete'); } catch (_) {}
      location.hash = '#/dashboard';
    };

    if (step === 0) {
      body.innerHTML = `<h3>Welcome aboard! 🇬🇭</h3>
        <p>This quick wizard gets your shop ready: profile → tax → your first product → first supplier. You can skip any step.</p>
        <button class="btn btn-primary" id="wz-next">Let's go →</button>`;
      $('#wz-next', body).onclick = next;
    } else if (step === 1) {
      body.innerHTML = `<h3>Your business</h3>
        <label>Business type</label><input id="wz-btype" placeholder="e.g. Provision store, Pharmacy, Boutique">
        <label>City</label><input id="wz-city" placeholder="Accra">
        <label>Phone</label><input id="wz-phone" placeholder="0240000000">
        <button class="btn btn-primary mt2" id="wz-next">Save & continue →</button>`;
      $('#wz-next', body).onclick = async () => {
        try {
          await api.put('/settings/profile', {
            business_type: $('#wz-btype', body).value || null,
            city: $('#wz-city', body).value || null,
            phone: $('#wz-phone', body).value || null,
          });
        } catch (_) {}
        next();
      };
    } else if (step === 2) {
      body.innerHTML = `<h3>Currency & tax</h3>
        <label>Currency</label><select id="wz-currency"><option value="GHS">GHS — Ghana Cedi</option><option value="USD">USD</option><option value="NGN">NGN</option><option value="XOF">XOF</option></select>
        <label>Tax rate % (0 = no tax)</label><input id="wz-tax" type="number" min="0" max="100" step="0.01" value="0">
        <button class="btn btn-primary mt2" id="wz-next">Save & continue →</button>`;
      $('#wz-next', body).onclick = async () => {
        try {
          const cur = $('#wz-currency', body).value;
          await api.put('/settings/profile', { currency: cur });
          await api.put('/settings', { tax_rate: $('#wz-tax', body).value || 0, low_stock_default: 10, expiry_warn_days: 14 });
        } catch (_) {}
        next();
      };
    } else if (step === 3) {
      body.innerHTML = `<h3>Your first category</h3><p class="muted small">Categories organise products (e.g. Beverages, Foodstuff, Personal Care).</p>
        <label>Category name</label><input id="wz-cat" placeholder="Beverages"><button class="btn btn-primary mt2" id="wz-next">Create & continue →</button>`;
      $('#wz-next', body).onclick = async () => {
        const name = $('#wz-cat', body).value.trim();
        if (name) { try { await api.post('/categories', { name }); State.__firstCategory = name; } catch (_) {} }
        next();
      };
    } else if (step === 4) {
      body.innerHTML = `<h3>Your first product</h3>
        <label>Product name</label><input id="wz-pname" placeholder="FanYogo Strawberry">
        <div class="form-grid"><div><label>Cost price</label><input id="wz-pcost" type="number" min="0" step="0.01" value="1"></div>
        <div><label>Selling price</label><input id="wz-pprice" type="number" min="0" step="0.01" value="2"></div></div>
        <button class="btn btn-primary mt2" id="wz-next">Create & continue →</button>`;
      $('#wz-next', body).onclick = async () => {
        const name = $('#wz-pname', body).value.trim();
        if (name) {
          try {
            let catId = '';
            const cat = await api.get('/catalog');
            if (cat.categories.length) catId = cat.categories[0].id;
            await api.post('/products', {
              name, sku: 'SKU-' + name.replace(/\s+/g, '').slice(0, 8).toUpperCase(),
              category_id: catId || null,
              cost_price: parseFloat($('#wz-pcost', body).value) || 0,
              selling_price: parseFloat($('#wz-pprice', body).value) || 0,
            });
          } catch (e) { toast(e.message, 'err'); }
        }
        next();
      };
    } else if (step === 5) {
      body.innerHTML = `<h3>Your first supplier</h3>
        <label>Supplier name</label><input id="wz-sname" placeholder="Makola Wholesale">
        <label>Phone</label><input id="wz-sphone" placeholder="0240000000">
        <button class="btn btn-primary mt2" id="wz-next">Save & continue →</button>`;
      $('#wz-next', body).onclick = async () => {
        const name = $('#wz-sname', body).value.trim();
        if (name) { try { await api.post('/suppliers', { name, phone: $('#wz-sphone', body).value || null }); } catch (_) {} }
        next();
      };
    } else if (step === 6) {
      body.innerHTML = `<h3>Invite a team member (optional)</h3>
        <label>Staff email</label><input id="wz-uemail" type="email" placeholder="cashier@yourshop.com">
        <label>Their temp password</label><input id="wz-upass" type="password" minlength="10">
        <label>Role</label><select id="wz-urole"><option value="cashier">Cashier</option><option value="inventory">Inventory Staff</option><option value="manager">Manager</option></select>
        <button class="btn btn-primary mt2" id="wz-next">Invite & continue →</button>`;
      $('#wz-next', body).onclick = async () => {
        const email = $('#wz-uemail', body).value.trim();
        const pass = $('#wz-upass', body).value;
        if (email && pass) {
          try {
            await api.post('/users', { name: email.split('@')[0], email, password: pass, password_confirmation: pass, role: $('#wz-urole', body).value });
          } catch (e) { toast(e.message, 'err'); }
        }
        next();
      };
    } else {
      body.innerHTML = `<h3>You're all set! 🎉</h3>
        <ul><li>Open the <strong>POS</strong> to make your first sale.</li>
        <li>Record a <strong>purchase</strong> to build stock.</li>
        <li>Check the <strong>dashboard</strong> — your numbers become real immediately.</li></ul>
        <button class="btn btn-primary btn-lg" id="wz-finish">Open my dashboard →</button>`;
      $('#wz-finish', body).onclick = async () => {
        try { await api.post('/settings/onboarding/complete'); } catch (_) {}
        location.hash = '#/dashboard';
      };
    }
  }
  render();
};

/* ================= SUPPORT TICKETS ================= */
Pages['#/tickets'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🆘 Support</div>
    <button class="btn btn-primary" id="new-ticket">＋ New ticket</button></div>
    <div id="ticket-list">${loadingBlock(2)}</div>`;

  async function load() {
    const host = $('#ticket-list', el); host.innerHTML = '';
    try {
      await renderList(host, {
        url: '/tickets', emptyIcon: '🆘', emptyText: 'No tickets yet. We reply fast!',
        columns: [
          ['Subject', (t) => `<strong>${esc(t.subject)}</strong>`],
          ['Status', (t) => paymentStatusBadge(t.status)],
          ['Replies', (t) => String(t.replies_count ?? 0)],
          ['Updated', (t) => `<span class="nowrap">${fmtDate(t.updated_at)}</span>`],
        ],
        rowActions: (t) => `<button class="btn btn-sm" data-open="${t.id}">Open</button>`,
      });
      const d = await api.get('/tickets');
      $$('[data-open]', host).forEach((b) => { b.onclick = () => openTicket(d.data.find((t) => t.id == b.dataset.open), load); });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  async function openTicket(t, reload) {
    const d = await api.get('/tickets/' + t.id);
    const m = openModal('🆘 ' + d.data.subject + ' — ' + d.data.status, `
      <div style="max-height:300px;overflow-y:auto" class="mb1">
        ${d.data.replies.map((r) => `<div class="card mb1" style="background:${r.author_type === 'user' ? 'var(--panel)' : 'var(--blue-bg)'}">
          <div class="small muted">${esc(r.author_name)} · ${r.author_type === 'user' ? 'You' : 'Support'} · ${fmtDate(r.created_at)}</div>
          <div>${esc(r.body)}</div></div>`).join('')}
      </div>
      ${d.data.status !== 'closed' ? `<form id="tk-reply"><textarea name="body" rows="3" maxlength="5000" placeholder="Reply…" required></textarea>
        <button class="btn btn-primary mt1">Send reply</button></form>` : ''}
    `, true);
    $('#tk-reply', m)?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await api.post(`/tickets/${t.id}/replies`, formValues(e.target)); closeModal(); reload(); }
      catch (err) { toast(err.message, 'err'); }
    });
  }

  $('#new-ticket', el).onclick = () => {
    const m = openModal('New support ticket', `
      <form id="tk-new"><label>Subject *</label><input name="subject" maxlength="160" required>
      <label>Describe the issue *</label><textarea name="body" rows="5" maxlength="5000" required></textarea>
      <button class="btn btn-primary mt2">Submit ticket</button></form>`);
    $('#tk-new', m).onsubmit = async (e) => {
      e.preventDefault();
      try { await api.post('/tickets', formValues(e.target)); toast('Ticket submitted.', 'ok'); closeModal(); load(); }
      catch (err) { toast(err.message, 'err'); }
    };
  };
  await load();
};

/* ================= LOGIN / AUTH PAGES ================= */
Pages['#/login'] = async function (el) {
  document.title = 'Sign in';
  el.innerHTML = `
  <div class="auth-wrap"><div class="auth-card">
    <div class="auth-logo"><span class="mark">S</span><div><strong>ShopStack</strong></div></div>
    <h1>Sign in to your workspace</h1>
    <p class="auth-sub">Staff sign in here. Platform admins use the console.</p>
    <form id="login-form">
      <div id="login-err"></div>
      <label>Email</label><input name="email" type="email" required autocomplete="username" autofocus>
      <label>Password</label><input name="password" type="password" required autocomplete="current-password">
      <label><input type="checkbox" name="remember" checked> Keep me signed in (30 days)</label>
      <button class="btn btn-primary btn-lg mt1" style="width:100%" type="submit">Sign in</button>
      <div class="btn-row mt1" style="justify-content:space-between">
        <a href="#/forgot">Forgot password?</a>
        <a href="/landing.html">← Back to site</a>
      </div>
    </form>
  </div></div>`;

  $('#login-form', el).onsubmit = async (e) => {
    e.preventDefault();
    const v = formValues(e.target);
    const errHost = $('#login-err', el);
    errHost.innerHTML = '';
    try {
      const r = await api.post('/auth/login', { email: v.email, password: v.password, remember: !!v.remember });
      api.token = r.token;
      location.hash = '#/dashboard';
      location.reload();
    } catch (err) {
      errHost.innerHTML = `<div class="alert alert-red">${esc(err.message)}${err.retryAfter ? ` Try again in ${err.retryAfter}s.` : ''}</div>${fieldErrors(err)}`;
    }
  };
};

Pages['#/forgot'] = async function (el) {
  document.title = 'Reset password';
  el.innerHTML = `<div class="auth-wrap"><div class="auth-card">
    <div class="auth-logo"><span class="mark">S</span><strong>ShopStack</strong></div>
    <h1>Reset your password</h1>
    <form id="forgot-form"><div id="fp-msg"></div>
      <label>Email</label><input name="email" type="email" required>
      <button class="btn btn-primary mt1" style="width:100%">Email reset link</button>
      <div class="center mt1"><a href="#/login">← Back to sign in</a></div></form>
  </div></div>`;
  $('#forgot-form', el).onsubmit = async (e) => {
    e.preventDefault();
    try { await api.post('/auth/forgot-password', { email: e.target.email.value }); } catch (_) {}
    $('#fp-msg', el).innerHTML = `<div class="alert alert-green">If that email exists, a reset link was generated. Check the server log/dev mailbox in development.</div>`;
  };
};
