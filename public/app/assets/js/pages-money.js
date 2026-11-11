/* Money plane: customers & credit, invoices, expenses, reports & P&L. */
Pages = window.Pages || {};

/* ================= CUSTOMERS ================= */
Pages['#/customers'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🤝 Customers</div>
    <input class="search-input" id="cu-search" placeholder="Search name / phone…">
    <label class="chip" style="border:1px solid var(--line)"><input type="checkbox" id="cu-owing"> Only owing</label>
    <button class="btn btn-primary" id="add-customer" ${State.can('customers.manage') ? '' : 'disabled'}>＋ New customer</button></div>
    <div class="tabs" id="cu-tabs"><button data-t="list" class="active">All customers</button><button data-t="receivables">Receivables (money owed to you)</button></div>
    <div id="cu-body">${loadingBlock(3)}</div>`;

  const host = $('#cu-body', el);
  let search = '';
  async function show(tab) {
    $$('#cu-tabs button', el).forEach((b) => b.classList.toggle('active', b.dataset.t === tab));
    host.innerHTML = loadingBlock(3);
    try {
      if (tab === 'list') {
        host.innerHTML = '';
        await renderList(host, {
          url: '/customers', params: { search, owing: $('#cu-owing', el).checked ? 1 : '' },
          columns: [
            ['Customer', (c) => `<strong>${esc(c.name)}</strong><div class="muted small">${esc(c.phone || '')} ${c.type === 'wholesale' ? badge('Wholesale', 'blue') : ''}</div>`],
            ['Credit limit', (c) => parseFloat(c.credit_limit) > 0 ? money(c.credit_limit, 'GHS') : '—'],
            ['Balance', (c) => parseFloat(c.balance) > 0
              ? `<strong style="color:var(--red)">${money(c.balance, 'GHS')}</strong>` : '<span class="muted">settled</span>'],
            ['Status', (c) => c.status === 'blocked' ? badge('Blocked', 'red') : badge('Active', 'green')],
          ],
          rowActions: (c) => `<a class="btn btn-sm" href="#/customers/${c.id}">Open</a>
            ${State.can('customers.manage') ? `<button class="btn btn-sm" data-edit="${c.id}">Edit</button>` : ''}`,
        });
        const d = await api.get('/customers' + qs({ search, per_page: 200 }));
        $$('[data-edit]', host).forEach((b) => {
          b.onclick = () => Customers.form(d.data.find((c) => c.id == b.dataset.edit), () => show('list'));
        });
      } else {
        const d = await api.get('/customers/receivables');
        host.innerHTML = `<div class="card mb2"><h3>Total outstanding <strong>${money(d.total_outstanding, d.currency)}</strong></h3></div>
          ${d.data.length ? `<div class="panel"><table><thead><tr><th>Sale</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due</th><th>Overdue</th><th></th></tr></thead>
            <tbody>${d.data.map((r) => `<tr>
              <td class="nowrap"><strong>#${r.receipt_no}</strong><div class="muted small">${fmtDate(r.date)}</div></td>
              <td>${esc(r.customer?.name || '—')}</td>
              <td class="nowrap">${money(r.total, d.currency)}</td>
              <td class="nowrap">${money(r.paid, d.currency)}</td>
              <td class="nowrap"><strong>${money(r.balance, d.currency)}</strong></td>
              <td class="nowrap">${r.due_date ? esc(r.due_date) : '—'}</td>
              <td>${r.days_overdue > 0 ? badge(r.days_overdue + 'd late', 'red') : badge('On time', 'green')}</td>
              <td class="nowrap"><a href="#/sales/${r.sale_id}" class="btn btn-sm btn-success">Collect</a></td>
            </tr>`).join('')}</tbody></table></div>`
          : `<div class="panel">${emptyBlock('🎉', 'No unpaid sales — every customer is settled.')}</div>`}`;
      }
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $$('#cu-tabs button', el).forEach((b) => { b.onclick = () => show(b.dataset.t); });
  $('#cu-search', el).oninput = debounce(() => { search = $('#cu-search', el).value.trim(); show('list'); }, 300);
  $('#cu-owing', el).onchange = () => show('list');
  $('#add-customer', el).onclick = () => Customers.form(null, () => show('list'));
  await show('list');
};

const Customers = {
  form(customer, onDone) {
    const c = customer || {};
    const m = openModal(customer ? 'Edit customer' : 'New customer', `
      <form id="cu-form">
        <label>Name *</label><input name="name" maxlength="120" required value="${esc(c.name || '')}">
        <div class="form-grid">
          <div><label>Phone (GH)</label><input name="phone" maxlength="30" placeholder="0240000000" value="${esc(c.phone || '')}"></div>
          <div><label>Email</label><input name="email" type="email" value="${esc(c.email || '')}"></div>
        </div>
        <label>Address</label><input name="address" maxlength="300" value="${esc(c.address || '')}">
        <div class="form-grid">
          <div><label>Type</label><select name="type"><option value="retail" ${c.type === 'retail' ? 'selected' : ''}>Retail</option>
            <option value="wholesale" ${c.type === 'wholesale' ? 'selected' : ''}>Wholesale</option></select></div>
          <div><label>Credit limit (GHS, 0 = no limit)</label><input name="credit_limit" type="number" min="0" step="0.01" value="${c.credit_limit ?? 0}"></div>
        </div>
        <label>Notes</label><textarea name="notes" maxlength="500" rows="2">${esc(c.notes || '')}</textarea>
        <div class="btn-row mt2"><button class="btn btn-primary">${customer ? 'Save' : 'Create'}</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`);
    $('#cu-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        if (customer) await api.put('/customers/' + c.id, formValues(e.target));
        else await api.post('/customers', formValues(e.target));
        toast('Saved.', 'ok'); closeModal(); onDone();
      } catch (err) { e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`); }
    };
  },

  async detail(el, id) {
    el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🤝 Customer</div>
      <a class="btn btn-ghost" href="#/customers">← Back</a></div><div id="cu-detail">${loadingBlock(2)}</div>`;
    const host = $('#cu-detail', el);
    let d;
    try { d = await api.get('/customers/' + id); } catch (e) { host.innerHTML = errBlock(e); return; }
    const c = d.data;

    host.innerHTML = `
      <div class="grid" style="grid-template-columns:1fr 2fr">
        <div>
          <div class="card mb1"><h3>${esc(c.name)}</h3>
            <div class="muted small">${esc(c.phone || '')}<br>${esc(c.email || '')}<br>${esc(c.address || '')}</div>
            ${parseFloat(c.credit_limit) > 0 ? `<div class="stat-row"><span>Credit limit</span><strong>${money(c.credit_limit, 'GHS')}</strong></div>` : ''}
            ${d.stats.available_credit !== null ? `<div class="stat-row"><span>Available credit</span><strong>${money(d.stats.available_credit, 'GHS')}</strong></div>` : ''}
          </div>
          <div class="kpi ${parseFloat(c.balance) > 0 ? 'kpi-red' : ''} mb1"><div class="kpi-label">Current balance</div>
            <div class="kpi-value">${money(c.balance, 'GHS')}</div></div>
          <div class="kpi"><div class="kpi-label">Lifetime spend</div><div class="kpi-value">${money(d.stats.total_spent, 'GHS')}</div>
            <div class="kpi-sub">${d.stats.total_purchases} purchase(s)</div></div>
          <div class="btn-row mt1"><button class="btn btn-sm" id="cu-stmt">📋 Statement</button>
            <button class="btn btn-sm" id="cu-edit" ${State.can('customers.manage') ? '' : 'disabled'}>Edit</button></div>
        </div>
        <div class="card"><h3>Recent purchases</h3>
          ${d.recent_sales.length ? `<table><thead><tr><th>Receipt</th><th>Date</th><th>Total</th><th></th></tr></thead>
            <tbody>${d.recent_sales.map((s) => `<tr><td><a href="#/sales/${s.id}">#${s.receipt_no}</a></td>
              <td class="nowrap">${fmtDate(s.created_at)}</td><td class="nowrap"><strong>${money(s.total, 'GHS')}</strong></td>
              <td>${paymentStatusBadge(s.status)}</td></tr>`).join('')}</tbody></table>`
          : emptyBlock('🧾', 'No purchases yet.')}
          ${(d.recent_payments || []).length ? `<hr><h3>Recent payments</h3>
          ${d.recent_payments.map((p) => `<div class="stat-row"><span>${fmtDate(p.created_at)} · ${esc(p.method)} · ${esc(p.sale ? '#' + p.sale.receipt_no : '')}</span>
            <strong>${money(p.amount, 'GHS')}</strong></div>`).join('')}` : ''}
        </div>
      </div>`;

    $('#cu-edit', host)?.addEventListener('click', () => this.form(c, () => this.detail(el, id)));
    $('#cu-stmt', host)?.addEventListener('click', async () => {
      const s = await api.get(`/customers/${id}/statement`);
      const m = openModal('Account statement — ' + esc(c.name), `
        <div class="mb1 muted small">${s.from} → ${s.to} · Balance carried: <strong>${money(s.balance, s.currency)}</strong></div>
        ${s.lines.length ? `<table><thead><tr><th>Date</th><th>Description</th><th>Debit</th><th>Credit</th><th>Running</th></tr></thead>
          <tbody>${s.lines.map((l) => `<tr><td class="nowrap">${new Date(l.date).toLocaleDateString()}</td>
            <td>${esc(l.description)} ${l.reference ? '<span class="muted small">' + esc(l.reference) + '</span>' : ''}</td>
            <td class="nowrap">${l.debit ? money(l.debit, s.currency) : ''}</td>
            <td class="nowrap">${l.credit ? money(l.credit, s.currency) : ''}</td>
            <td class="nowrap"><strong>${money(l.balance, s.currency)}</strong></td></tr>`).join('')}</tbody></table>`
        : emptyBlock('📋', 'No activity in this period.')}
        <div class="btn-row mt2"><button class="btn" id="stmt-print">🖨 Print</button></div>`, true);
      $('#stmt-print', m).onclick = () => printArea(`<div style="padding:1rem;font-family:inherit">${$('.modal-body', m).innerHTML}</div>`);
    });
  },
};
Pages['#/customers/*'] = (el, id) => Customers.detail(el, id);

/* ================= INVOICES ================= */
Pages['#/invoices'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">📄 Invoices</div>
    <input class="search-input" id="inv-search" placeholder="Search number / customer…"></div>
    <div class="chips mb2" id="inv-chips">
      ${['', 'paid', 'partial', 'unpaid', 'overdue', 'cancelled'].map((s) => `<button class="chip ${s === '' ? 'active' : ''}" data-s="${s}">${s === '' ? 'All' : s[0].toUpperCase() + s.slice(1)}</button>`).join('')}
    </div>
    <div id="inv-list">${loadingBlock(3)}</div>`;

  let status = '', search = '';
  async function load(page = 1) {
    const host = $('#inv-list', el); host.innerHTML = loadingBlock(3);
    try {
      const d = await api.get('/invoices' + qs({ status, search, page }));
      if (!d.data.length) { host.innerHTML = `<div class="panel">${emptyBlock('📄', 'No invoices yet. Enable “issue invoice” when checking out a sale.')}</div>`; return; }
      const filtered = status && status !== 'overdue' && status !== 'cancelled'
        ? d.data.filter((i) => i.status === status) : d.data;
      host.innerHTML = `<div class="panel"><table><thead><tr><th>Invoice #</th><th>Customer</th><th>Issued</th><th>Due</th><th>Total</th><th>Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>${filtered.map((i) => `<tr>
          <td class="nowrap"><strong>${esc(i.invoice_no)}</strong></td>
          <td>${esc(i.customer?.name || 'Walk-in')}</td>
          <td class="nowrap">${esc(i.issue_date)}</td><td class="nowrap">${i.due_date ? esc(i.due_date) : '—'}</td>
          <td class="nowrap">${money(i.total, 'GHS')}</td>
          <td class="nowrap"><strong>${money(i.balance, 'GHS')}</strong></td>
          <td>${paymentStatusBadge(i.status)}</td>
          <td><a class="btn btn-sm" href="#/invoices/${i.id}">Open</a></td></tr>`).join('')}</tbody></table>
        ${pagerHtml(d)}</div>`;
      $$('[data-page]', host).forEach((b) => { b.onclick = () => load(parseInt(b.dataset.page, 10)); });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $$('#inv-chips .chip', el).forEach((c) => { c.onclick = () => { $$('#inv-chips .chip', el).forEach((x) => x.classList.remove('active')); c.classList.add('active'); status = c.dataset.s; load(); }; });
  $('#inv-search', el).oninput = debounce(() => { search = $('#inv-search', el).value.trim(); load(); }, 300);
  await load();
};

Pages['#/invoices/*'] = async function (el, id) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">📄 Invoice</div>
    <a class="btn btn-ghost" href="#/invoices">← Back</a><button class="btn btn-primary" id="inv-print">🖨 Print</button></div>
    <div id="inv-body">${loadingBlock(2)}</div>`;
  const host = $('#inv-body', el);
  let d;
  try { d = await api.get('/invoices/' + id); } catch (e) { host.innerHTML = errBlock(e); return; }
  const inv = d.data, s = inv.sale, b = d.branding;

  host.innerHTML = `
    <div class="card" style="max-width:800px;margin:0 auto" id="inv-doc">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>
          ${b.logo_url ? `<img src="${esc(b.logo_url)}" alt="" style="max-height:54px;margin-bottom:.4rem">` : ''}
          <h2 style="margin:0">${esc(b.business_name)}</h2>
          <div class="muted small">${[b.address, b.city, b.region].filter(Boolean).map(esc).join(', ')}<br>${esc(b.phone || '')} ${esc(b.email || '')}</div>
        </div>
        <div class="right"><h2 style="margin:0">${esc(inv.invoice_no)}</h2>
          <div class="muted small">Issued: ${esc(inv.issue_date)}<br>Due: ${inv.due_date ? esc(inv.due_date) : '—'}</div>
          ${paymentStatusBadge(inv.status)}</div>
      </div>
      <hr>
      <div class="mb1"><strong>Bill to:</strong> ${esc(inv.customer?.name || s.customer_name || 'Walk-in customer')}
        ${s.receipt_no ? `<span class="muted small">(sale receipt #${s.receipt_no})</span>` : ''}</div>
      <table><thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Total</th></tr></thead>
        <tbody>${s.items.map((i) => `<tr><td>${esc(i.product_name)}</td><td class="right">${i.quantity}</td>
          <td class="right">${money(i.unit_price, d.currency)}</td><td class="right"><strong>${money(i.line_total, d.currency)}</strong></td></tr>`).join('')}</tbody></table>
      <hr>
      <div style="max-width:300px;margin-left:auto">
        <div class="cart-line"><span>Subtotal</span><span>${money(s.subtotal, d.currency)}</span></div>
        ${parseFloat(s.discount) > 0 ? `<div class="cart-line"><span>Discount</span><span>− ${money(s.discount, d.currency)}</span></div>` : ''}
        ${parseFloat(s.tax) > 0 ? `<div class="cart-line"><span>Tax</span><span>${money(s.tax, d.currency)}</span></div>` : ''}
        <div class="cart-line total"><span>Total</span><span>${money(s.total, d.currency)}</span></div>
        <div class="cart-line"><span>Paid</span><span>${money(inv.paid, d.currency)}</span></div>
        <div class="cart-line"><span>Balance due</span><strong>${money(inv.balance, d.currency)}</strong></div>
      </div>
      ${b.footer ? `<hr><div class="center muted small">${esc(b.footer)}</div>` : ''}
    </div>`;

  $('#inv-print', el).onclick = () => printArea($('#inv-doc', host).outerHTML);
};

/* ================= EXPENSES ================= */
Pages['#/expenses'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">💸 Expenses</div>
    <input type="date" id="ex-from"> <input type="date" id="ex-to">
    <button class="btn btn-primary" id="add-expense" ${State.can('expenses.manage') ? '' : 'disabled'}>＋ Record expense</button></div>
    <div id="ex-list">${loadingBlock(3)}</div>`;

  let categories = [];
  try { categories = (await api.get('/expense-categories')).data; } catch (_) {}

  async function load(page = 1) {
    const host = $('#ex-list', el); host.innerHTML = '';
    try {
      await renderList(host, {
        url: '/expenses',
        params: { from: $('#ex-from', el).value, to: $('#ex-to', el).value, page },
        emptyIcon: '💸', emptyText: 'No expenses recorded in this period.',
        columns: [
          ['Expense', (x) => `<strong>${esc(x.name)}</strong><div class="muted small">${esc(x.category?.name || 'Uncategorised')}</div>`],
          ['Date', (x) => `<span class="nowrap">${esc(x.expense_date)}</span>`],
          ['Method', (x) => esc(x.payment_method)],
          ['Amount', (x) => `<strong class="nowrap">${money(x.amount, 'GHS')}</strong>`],
          ['Recorded by', (x) => `<span class="muted small">${esc(x.recorded_by?.name || '—')}</span>`],
        ],
        rowActions: (x) => State.can('expenses.manage') ? `<button class="btn btn-sm" data-edit="${x.id}">Edit</button>` : '',
      });
      const pageData = await api.get('/expenses' + qs({ from: $('#ex-from', el).value, to: $('#ex-to', el).value, page, per_page: 100 }));
      $$('[data-edit]', host).forEach((b) => {
        b.onclick = () => Expenses.form(pageData.data.find((x) => x.id == b.dataset.edit), categories, load);
      });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $('#add-expense', el).onclick = () => Expenses.form(null, categories, load);
  $('#ex-from', el).onchange = load; $('#ex-to', el).onchange = load;
  await load();
};

const Expenses = {
  form(expense, categories, onDone) {
    const x = expense || {};
    const m = openModal(expense ? 'Edit expense' : 'Record expense', `
      <form id="ex-form">
        <label>What was paid for? *</label><input name="name" maxlength="160" required value="${esc(x.name || '')}">
        <div class="form-grid">
          <div><label>Amount (GHS) *</label><input name="amount" type="number" min="0.01" step="0.01" required value="${x.amount ?? ''}"></div>
          <div><label>Date *</label><input name="expense_date" type="date" required value="${x.expense_date || todayStr()}"></div>
        </div>
        <div class="form-grid">
          <div><label>Category</label><select name="category_id"><option value="">Uncategorised</option>
            ${categories.map((c) => `<option value="${c.id}" ${x.category_id == c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
          <div><label>Paid via</label><select name="payment_method"><option value="cash">Cash</option><option value="momo">Mobile Money</option>
            <option value="bank">Bank</option><option value="card">Card</option></select></div>
        </div>
        <label>Note</label><textarea name="description" maxlength="500" rows="2">${esc(x.description || '')}</textarea>
        <div class="btn-row mt2"><button class="btn btn-primary">${expense ? 'Save' : 'Record'}</button>
          <button class="btn" id="ex-newcat" type="button">New category…</button>
          <button class="btn" data-close type="button">Cancel</button></div>
      </form>`);
    $('#ex-newcat', m).onclick = () => {
      const cm = openModal('New expense category', `<form id="cat-f"><label>Name</label><input name="name" maxlength="60" required>
        <button class="btn btn-primary mt2">Create</button></form>`);
      $('#cat-f', cm).onsubmit = async (e) => {
        e.preventDefault();
        try {
          const r = await api.post('/expense-categories', formValues(e.target));
          categories.push(r.data);
          closeModal(); toast('Category created.', 'ok');
        } catch (err) { toast(err.message, 'err'); }
      };
    };
    $('#ex-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        if (expense) await api.put('/expenses/' + x.id, formValues(e.target));
        else await api.post('/expenses', formValues(e.target));
        toast('Saved.', 'ok'); closeModal(); onDone();
      } catch (err) { e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`); }
    };
  },
};

/* ================= REPORTS & P&L ================= */
Pages['#/reports'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">📈 Reports & P&L</div></div>
    <div class="toolbar">
      <div class="chips" id="rep-period">
        ${[['day', 'Today'], ['week', 'This week'], ['month', 'This month'], ['year', 'This year'], ['custom', 'Custom']].map(([v, l]) => `<button class="chip ${v === 'month' ? 'active' : ''}" data-p="${v}">${l}</button>`).join('')}
      </div>
      <span id="custom-range" class="hidden"><input type="date" id="rep-from"> <input type="date" id="rep-to">
        <button class="btn btn-sm" id="rep-go">Apply</button></span>
      <button class="btn" id="rep-export">⬇ Export CSV</button>
    </div>
    <div id="rep-body">${loadingBlock(4)}</div>`;

  let period = 'month', lastData = null;
  async function load() {
    const host = $('#rep-body', el); host.innerHTML = loadingBlock(4);
    try {
      const params = period === 'custom' ? { period, from: $('#rep-from', el).value, to: $('#rep-to', el).value } : { period };
      const [pl, sr] = await Promise.all([
        api.get('/reports/profit-loss' + qs(params)),
        api.get('/reports/sales' + qs(params)),
      ]);
      lastData = { pl, sr, params };
      const hidden = !pl.profit_available;

      host.innerHTML = `
        <div class="grid grid-4 mb2">
          <div class="kpi kpi-accent"><div class="kpi-label">Revenue</div><div class="kpi-value">${money(pl.revenue, pl.currency)}</div>
            <div class="kpi-sub">${pl.sales_count} sale(s) · ${esc(pl.range.label)}</div></div>
          <div class="kpi"><div class="kpi-label">Cost of goods sold</div>
            <div class="kpi-value">${hidden ? '<span class="badge badge-gray">N/A</span>' : money(pl.cogs, pl.currency)}</div>
            <div class="kpi-sub">${hidden ? 'some sale lines lack costs' : ''}</div></div>
          <div class="kpi kpi-green"><div class="kpi-label">Gross profit</div>
            <div class="kpi-value">${hidden ? '<span class="badge badge-gray">N/A</span>' : money(pl.gross_profit, pl.currency)}</div>
            <div class="kpi-sub">${hidden ? 'profit hidden — complete cost data first' : (pl.gross_margin !== null ? pl.gross_margin + '% margin' : '')}</div></div>
          <div class="kpi ${pl.net_profit < 0 ? 'kpi-red' : 'kpi-green'}"><div class="kpi-label">Net profit</div>
            <div class="kpi-value">${hidden ? '<span class="badge badge-gray">N/A</span>' : money(pl.net_profit, pl.currency)}</div>
            <div class="kpi-sub">after expenses</div></div>
        </div>
        ${hidden ? `<div class="alert alert-amber mb2">⚠ Profit figures are deliberately hidden because some completed sales have no recorded cost price. ShopStack never shows estimated profit.</div>` : ''}
        <div class="grid grid-3 mb2">
          <div class="card"><h3>Expenses breakdown</h3>
            ${pl.expenses.by_category.length ? pl.expenses.by_category.map((c) =>
              `<div class="stat-row"><span>${esc(c.category)}</span><strong>${money(c.total, pl.currency)}</strong></div>`).join('')
              : '<div class="muted small">No expenses in this period.</div>'}
            <div class="stat-row"><span><strong>Total expenses</strong></span><strong>${money(pl.expenses.total, pl.currency)}</strong></div>
            ${pl.tax_collected > 0 ? `<div class="stat-row"><span>Tax collected</span><strong>${money(pl.tax_collected, pl.currency)}</strong></div>` : ''}
          </div>
          <div class="card"><h3>By payment method</h3>
            ${sr.by_method.map((m) => `<div class="stat-row"><span>${esc(m.method)}</span><span class="nowrap">${m.count} × · <strong>${money(m.total, sr.currency)}</strong></span></div>`).join('') || '<div class="muted small">No sales.</div>'}
            <div class="stat-row"><span>Average basket</span><strong>${money(sr.average_basket, sr.currency)}</strong></div>
          </div>
          <div class="card"><h3>By cashier</h3>
            ${sr.by_cashier.map((m) => `<div class="stat-row"><span>${esc(m.cashier)}</span><span class="nowrap">${m.count} × · <strong>${money(m.total, sr.currency)}</strong></span></div>`).join('') || '<div class="muted small">No sales.</div>'}
          </div>
        </div>
        <div class="card"><h3>Daily revenue</h3>${barsHtml(sr.daily)}
          <h3 class="mt2">Top products</h3>
          ${sr.by_product.length ? `<table><thead><tr><th>Product</th><th class="right">Qty sold</th><th class="right">Revenue</th></tr></thead>
            <tbody>${sr.by_product.map((p) => `<tr><td>${esc(p.name)}</td><td class="right">${p.quantity}</td>
              <td class="right"><strong>${money(p.revenue, sr.currency)}</strong></td></tr>`).join('')}</tbody></table>`
          : '<div class="muted small">No product sales in this period.</div>'}
        </div>`;
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $$('#rep-period .chip', el).forEach((c) => {
    c.onclick = () => {
      $$('#rep-period .chip', el).forEach((x) => x.classList.remove('active'));
      c.classList.add('active'); period = c.dataset.p;
      $('#custom-range', el).classList.toggle('hidden', period !== 'custom');
      if (period !== 'custom') load();
    };
  });
  $('#rep-from', el).value = daysAgo(29); $('#rep-to', el).value = todayStr();
  $('#rep-go', el).onclick = load;
  $('#rep-export', el).onclick = () => {
    if (!lastData) return;
    const { sr, params } = lastData;
    csvExport(`sales-report-${params.period}-${todayStr()}.csv`,
      ['Date', 'Sales count', 'Revenue'],
      sr.daily.map((r) => [r.date, r.count, r.total]));
  };
  await load();
};
