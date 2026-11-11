/* Dashboard — every KPI is computed server-side; this page only renders. */
Pages = window.Pages || {};

Pages['#/dashboard'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">Dashboard</div>
    <a href="#/pos" class="btn btn-primary">🛒 New Sale</a>
    <a href="#/products" class="btn">＋ Product</a></div>
    <div id="dash-body">${loadingBlock(6)}</div>`;

  const host = $('#dash-body', el);
  let d;
  try { d = await api.get('/reports/dashboard'); }
  catch (e) { host.innerHTML = errBlock(e); return; }

  const profitCell = (period) => period.profit_available
    ? money(period.profit, d.currency)
    : '<span class="badge badge-gray" title="Profit is hidden until every sale line has a recorded cost.">N/A — costs incomplete</span>';

  host.innerHTML = `
    <div class="grid grid-4 mb2">
      <div class="kpi kpi-accent"><div class="kpi-label">Today's Sales</div>
        <div class="kpi-value">${money(d.today.sales, d.currency)}</div>
        <div class="kpi-sub">This month: ${money(d.month.sales, d.currency)}</div></div>
      <div class="kpi kpi-green"><div class="kpi-label">Today's Profit</div>
        <div class="kpi-value">${profitCell(d.today)}</div>
        <div class="kpi-sub">This month: ${d.month.profit_available ? money(d.month.profit, d.currency) : 'N/A'}</div></div>
      <div class="kpi"><div class="kpi-label">Inventory Value (cost)</div>
        <div class="kpi-value">${money(d.inventory.cost_value, d.currency)}</div>
        <div class="kpi-sub">${d.inventory.units} units · retail ${money(d.inventory.retail_value, d.currency)}</div></div>
      <div class="kpi ${d.stock.low || d.stock.out ? 'kpi-amber' : ''}"><div class="kpi-label">Stock Alerts</div>
        <div class="kpi-value">${d.stock.low + d.stock.out}</div>
        <div class="kpi-sub">${d.stock.low} low · ${d.stock.out} out of stock</div></div>
    </div>

    <div class="grid grid-4 mb2">
      <div class="kpi ${d.money.receivables > 0 ? 'kpi-red' : ''}"><div class="kpi-label">Customers Owe You</div>
        <div class="kpi-value">${money(d.money.receivables, d.currency)}</div>
        <div class="kpi-sub">${money(d.money.receivables_overdue, d.currency)} overdue · <a href="#/customers">Collect →</a></div></div>
      <div class="kpi ${d.money.payables > 0 ? 'kpi-amber' : ''}"><div class="kpi-label">You Owe Suppliers</div>
        <div class="kpi-value">${money(d.money.payables, d.currency)}</div>
        <div class="kpi-sub"><a href="#/suppliers">Payables →</a></div></div>
      <div class="kpi"><div class="kpi-label">Expenses Today</div>
        <div class="kpi-value">${money(d.expenses.today, d.currency)}</div>
        <div class="kpi-sub">This month: ${money(d.expenses.month, d.currency)}</div></div>
      <div class="kpi"><div class="kpi-label">Unpaid Sales</div>
        <div class="kpi-value">${d.money.outstanding_sales}</div>
        <div class="kpi-sub">sale(s) awaiting payment</div></div>
    </div>

    <div class="grid grid-3 mb2">
      <div class="card" style="grid-column: span 2">
        <h3>Revenue — last 14 days <a class="card-link" href="#/reports">Full reports →</a></h3>
        ${barsHtml(d.series)}
      </div>
      <div class="card">
        <h3>Top products (30 days)</h3>
        ${d.top_products.length
          ? d.top_products.map((p, i) => `<div class="stat-row">
              <span><strong>#${i + 1}</strong> ${esc(p.name)}</span>
              <span class="nowrap"><strong>${p.quantity}</strong> × · ${money(p.revenue, d.currency)}</span></div>`).join('')
          : emptyBlock('🛍️', 'No sales in the last 30 days.')}
      </div>
    </div>

    <div class="card">
      <h3>Recent sales <a class="card-link" href="#/sales">View all →</a></h3>
      ${d.recent_sales.length ? `<table><thead><tr><th>Receipt</th><th>Customer</th><th>Total</th><th>Method</th><th>Status</th><th>When</th></tr></thead>
        <tbody>${d.recent_sales.map((s) => `<tr data-id="${s.id}" class="row-link">
          <td class="nowrap">#${s.receipt_no}</td>
          <td>${esc(s.customer?.name || s.customer_name || 'Walk-in')}</td>
          <td class="nowrap">${money(s.total, d.currency)}</td>
          <td>${esc(s.payment_method)}</td><td>${paymentStatusBadge(s.status)}</td>
          <td class="nowrap">${fmtDate(s.created_at)}</td></tr>`).join('')}</tbody></table>`
      : emptyBlock('🧾', 'No sales yet — open the POS to make your first sale.', '<a class="btn btn-primary" href="#/pos">Open POS</a>')}
    </div>`;

  $$('.row-link', host).forEach((tr) => {
    tr.style.cursor = 'pointer';
    tr.onclick = () => { location.hash = '#/sales/' + tr.dataset.id; };
  });
};
