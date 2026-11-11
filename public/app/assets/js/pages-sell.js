/* POS terminal + Sales history + sale documents (receipt / void / return). */
Pages = window.Pages || {};

/* ================= POS TERMINAL ================= */
Pages['#/pos'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🛒 POS Terminal</div>
      <span class="muted small">Prices & tax are always computed by the server.</span></div>
    <div class="pos-layout">
      <div>
        <div class="toolbar"><input class="search-input" id="pos-search" placeholder="Search name / SKU / barcode…" autofocus>
          <select id="pos-cat" style="max-width:170px"><option value="">All categories</option></select>
          <select id="pos-price" style="max-width:140px"><option value="retail">Retail prices</option><option value="wholesale">Wholesale</option></select>
        </div>
        <div class="pos-grid" id="pos-grid">${loadingBlock(8)}</div>
      </div>
      <div class="cart">
        <div class="cart-head"><strong>Current Sale</strong>
          <button class="btn btn-ghost btn-sm" id="cart-clear">Clear</button></div>
        <div style="padding:.4rem .9rem"><select id="cart-customer"><option value="">Walk-in customer</option></select></div>
        <div class="cart-body" id="cart-body"></div>
        <div class="cart-foot">
          <div class="cart-line"><span>Subtotal</span><span id="ct-sub">—</span></div>
          <div class="cart-line"><span>Discount</span><span><input id="ct-disc" type="number" min="0" step="0.01" value="0" style="width:90px;text-align:right;padding:.2rem .4rem"></span></div>
          <div class="cart-line" id="ct-tax-row" style="display:none"><span>Tax (<span id="ct-tax-rate"></span>%)</span><span id="ct-tax">—</span></div>
          <div class="cart-line total"><span>Total</span><span id="ct-total">—</span></div>
          <label style="margin-top:.4rem">Charge tax on this sale</label>
          <input type="checkbox" id="ct-taxable">
          <div class="mt1">
            <label>Payment method</label>
            <select id="ct-method"><option value="cash">Cash</option><option value="momo">Mobile Money</option>
              <option value="bank">Bank Transfer</option><option value="card">Card</option><option value="credit">Credit (pay later)</option></select>
            <label>Amount received</label>
            <input id="ct-paid" type="number" min="0" step="0.01" placeholder="0.00">
            <label>Due date (credit only)</label>
            <input id="ct-due" type="date">
            <label><input type="checkbox" id="ct-invoice"> Also issue a formal invoice</label>
          </div>
          <div class="btn-row mt1">
            <button class="btn btn-primary btn-lg" id="ct-charge" style="flex:1">Charge <span id="ct-charge-amt"></span></button>
          </div>
        </div>
      </div>
    </div>`;

  const cart = new Map(); // product_id -> {product, qty}
  let products = [], priceType = 'retail', taxRate = 0;

  // Tax rate from business settings (cache if in State).
  try { const s = await api.get('/settings'); taxRate = s.settings?.tax_rate ?? 0; $('#ct-tax-rate', el).textContent = taxRate; } catch (_) {}

  async function loadProducts() {
    const search = $('#pos-search', el).value.trim();
    const cat = $('#pos-cat', el).value;
    try {
      const d = await api.get('/products' + qs({ search, category_id: cat, per_page: 48 }));
      products = d.data;
      $('#pos-grid', el).innerHTML = products.length ? products.map((p) => `
        <button class="pos-tile ${p.stock_quantity <= 0 ? 'out' : ''}" data-id="${p.id}">
          <div class="pt-name">${esc(p.name)}</div>
          <div class="pt-price">${money(priceType === 'wholesale' && p.wholesale_price ? p.wholesale_price : p.selling_price, 'GHS')}</div>
          <div class="pt-stock">${p.stock_quantity <= 0 ? 'OUT OF STOCK' : p.stock_quantity + ' ' + esc(p.unit || 'pcs') + ' left'}</div>
        </button>`).join('')
        : emptyBlock('🛒', 'No products match. Try another search.');
      $$('.pos-tile', el).forEach((t) => { t.onclick = () => addToCart(parseInt(t.dataset.id, 10)); });
    } catch (e) { $('#pos-grid', el).innerHTML = errBlock(e); }
  }

  function addToCart(id) {
    const p = products.find((x) => x.id === id);
    if (!p) return;
    const line = cart.get(id) || { product: p, qty: 0 };
    if (line.qty + 1 > p.stock_quantity) { toast('Not enough stock for ' + p.name, 'err'); return; }
    line.qty += 1;
    cart.set(id, line);
    renderCart();
  }

  function unitPrice(p) {
    return parseFloat(priceType === 'wholesale' && p.wholesale_price ? p.wholesale_price : p.selling_price);
  }

  function totals() {
    const sub = [...cart.values()].reduce((s, l) => s + unitPrice(l.product) * l.qty, 0);
    const disc = Math.min(parseFloat($('#ct-disc', el).value || 0), sub);
    const taxable = $('#ct-taxable', el).checked;
    const tax = taxable ? (sub - disc) * taxRate / 100 : 0;
    return { sub, disc, tax, total: sub - disc + tax };
  }

  function renderCart() {
    const body = $('#cart-body', el);
    if (!cart.size) { body.innerHTML = emptyBlock('🧺', 'Cart is empty — tap a product to add it.'); }
    else {
      body.innerHTML = [...cart.values()].map((l) => `
        <div class="cart-item" data-id="${l.product.id}">
          <div class="ci-name">${esc(l.product.name)}<div class="muted small">${money(unitPrice(l.product), 'GHS')} each · ${l.product.stock_quantity} ${esc(l.product.unit || 'pcs')} in stock</div></div>
          <div class="qty"><button type="button" data-d="-1" aria-label="Decrease quantity">−</button><input type="number" min="1" max="${l.product.stock_quantity}" value="${l.qty}" aria-label="Quantity for ${esc(l.product.name)}"><button type="button" data-d="1" aria-label="Increase quantity">+</button></div>
          <div class="nowrap"><strong>${money(unitPrice(l.product) * l.qty, 'GHS')}</strong></div>
          <button class="btn btn-ghost btn-sm" data-x>✕</button>
        </div>`).join('');
      $$('.cart-item', body).forEach((row) => {
        const id = parseInt(row.dataset.id, 10);
        row.querySelector('[data-x]').onclick = () => { cart.delete(id); renderCart(); };
        row.querySelector('input[type="number"]').onchange = (e) => {
          const l = cart.get(id);
          const requested = parseInt(e.target.value, 10);
          if (!Number.isInteger(requested) || requested < 1) { renderCart(); return; }
          if (requested > l.product.stock_quantity) {
            toast(`Only ${l.product.stock_quantity} ${l.product.unit || 'pcs'} available.`, 'err');
            renderCart();
            return;
          }
          l.qty = requested;
          renderCart();
        };
        row.querySelectorAll('[data-d]').forEach((b) => {
          b.onclick = () => {
            const l = cart.get(id);
            const next = l.qty + parseInt(b.dataset.d, 10);
            if (next <= 0) cart.delete(id);
            else if (next > l.product.stock_quantity) { toast('Not enough stock.', 'err'); return; }
            else l.qty = next;
            renderCart();
          };
        });
      });
    }
    const t = totals();
    $('#ct-sub', el).textContent = money(t.sub, 'GHS');
    $('#ct-tax', el).textContent = money(t.tax, 'GHS');
    $('#ct-tax-row', el).style.display = $('#ct-taxable', el).checked ? 'flex' : 'none';
    $('#ct-total', el).textContent = money(t.total, 'GHS');
    $('#ct-charge-amt', el).textContent = t.total ? '· ' + money(t.total, 'GHS') : '';
    if ($('#ct-paid', el).value === '' || document.activeElement !== $('#ct-paid', el)) {
      $('#ct-paid', el).placeholder = t.total.toFixed(2);
    }
    $('#ct-due', el).closest('div,lable,label').style = '';
    const credit = $('#ct-method', el).value === 'credit';
    $('#ct-paid', el).disabled = false;
    $('#ct-due', el).parentElement.style.display = '';
  }

  // Load customers for the picker
  try {
    const c = await api.get('/customers?per_page=100');
    $('#cart-customer', el).innerHTML = '<option value="">Walk-in customer</option>' +
      c.data.map((cu) => `<option value="${cu.id}">${esc(cu.name)}${parseFloat(cu.balance) > 0 ? ' (owes ' + money(cu.balance, 'GHS') + ')' : ''}</option>`).join('');
  } catch (_) {}
  try {
    const catResponse = await api.get('/catalog');
    $('#pos-cat', el).innerHTML = '<option value="">All categories</option>' +
      catResponse.categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  } catch (_) {}

  $('#pos-search', el).oninput = debounce(loadProducts, 250);
  $('#pos-cat', el).onchange = loadProducts;
  $('#pos-price', el).onchange = () => { priceType = $('#pos-price', el).value; loadProducts(); renderCart(); };
  $('#cart-clear', el).onclick = () => { cart.clear(); renderCart(); };
  ['ct-disc', 'ct-taxable', 'ct-method'].forEach((id) => { $('#' + id, el).onchange = renderCart; $('#' + id, el).oninput = renderCart; });

  $('#ct-charge', el).onclick = async () => {
    if (!cart.size) { toast('Cart is empty.', 'err'); return; }
    const t = totals();
    const method = $('#ct-method', el).value;
    const paid = parseFloat($('#ct-paid', el).value || (method === 'credit' ? 0 : t.total));
    const payload = {
      items: [...cart.values()].map((l) => ({ product_id: l.product.id, quantity: l.qty })),
      payment_method: method,
      price_type: priceType,
      amount_paid: isNaN(paid) ? 0 : paid,
      discount: t.disc,
      taxable: $('#ct-taxable', el).checked,
      issue_invoice: $('#ct-invoice', el).checked,
      customer_id: $('#cart-customer', el).value || null,
      due_date: $('#ct-due', el).value || null,
    };

    const btn = $('#ct-charge', el); btn.disabled = true;
    try {
      const r = await api.post('/sales', payload);
      toast(`Sale completed — receipt #${r.data.receipt_no}`, 'ok');
      const saleId = r.data.id;
      cart.clear();
      await loadProducts();
      renderCart();
      printChoice(saleId);
    } catch (e) {
      openModal('Could not complete sale', `<div class="alert alert-red">${esc(e.message)}</div>${fieldErrors(e)}`);
    } finally { btn.disabled = false; }
  };

  await loadProducts();
  renderCart();
};

async function printChoice(saleId) {
  const d = await api.get('/sales/' + saleId);
  Sales.renderReceipt(d.data, State.settings);
}

/* ================= SALES HISTORY ================= */
Pages['#/sales'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🧾 Sales History</div>
    <a href="#/pos" class="btn btn-primary">New sale</a></div>
    <div class="toolbar">
      <div class="chips" id="status-chips">
        <button class="chip active" data-s="">All</button>
        <button class="chip" data-s="completed">Completed</button>
        <button class="chip" data-s="voided">Voided</button>
      </div>
      <input type="date" id="s-from" title="From"> <input type="date" id="s-to" title="To">
    </div>
    <div id="sales-list">${loadingBlock(3)}</div>`;

  const state = { status: '', from: null, to: null };
  async function load(page = 1) {
    const host = $('#sales-list', el);
    host.innerHTML = loadingBlock(3);
    try {
      const d = await api.get('/sales' + qs({ ...state, page }));
      host.innerHTML = '';
      if (!d.data.length) { host.innerHTML = `<div class="panel">${emptyBlock('🧾', 'No sales match your filters.', '<a href="#/pos" class="btn btn-primary">Open POS</a>')}</div>`; return; }
      await renderList(host, {
        url: '/sales', params: { ...state, page },
        columns: [
          ['Receipt #', (s) => `<strong>#${s.receipt_no}</strong>`],
            ['Date', (s) => `<span class="nowrap">${fmtDate(s.created_at)}</span>`],
          ['Customer', (s) => esc(s.customer?.name || s.customer_name || 'Walk-in')],
          ['Items', (s) => s.items?.length ?? '—'],
          ['Total', (s) => `<strong class="nowrap">${money(s.total, 'GHS')}</strong>`],
          ['Status', (s) => paymentStatusBadge(s.status)],
        ],
        rowActions: (s) => `<a class="btn btn-sm" href="#/sales/${s.id}">Open</a>`,
      });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $$('#status-chips .chip', el).forEach((c) => {
    c.onclick = () => { $$('#status-chips .chip', el).forEach((x) => x.classList.remove('active')); c.classList.add('active'); state.status = c.dataset.s; load(); };
  });
  $('#s-from', el).onchange = (e) => { state.from = e.target.value; load(); };
  $('#s-to', el).onchange = (e) => { state.to = e.target.value; load(); };
  await load();
};

/* ================= SALE DETAIL (receipt / void / return / payments) ================= */
const Sales = {
  async detail(el, id) {
    el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🧾 Sale</div>
      <a href="#/sales" class="btn btn-ghost">← Back</a></div><div id="sale-body">${loadingBlock(2)}</div>`;
    const host = $('#sale-body', el);
    let d;
    try { d = (await api.get('/sales/' + id)).data; } catch (e) { host.innerHTML = errBlock(e); return; }

    const rows = d.items.map((i) => `<tr>
      <td>${esc(i.product_name)}<div class="muted small">${esc(i.product?.sku || '')}</div></td>
      <td class="nowrap">${i.quantity} × ${money(i.unit_price, 'GHS')}</td>
      <td class="nowrap"><strong>${money(i.line_total, 'GHS')}</strong></td></tr>`).join('');

    const payments = (d.payments || []).map((p) => `<div class="stat-row"><span>+ ${fmtDate(p.created_at)} · ${esc(p.method)}</span><strong>${money(p.amount, 'GHS')}</strong></div>`).join('');

    host.innerHTML = `
      <div class="grid" style="grid-template-columns: 2fr 1fr">
        <div class="card"><h3>Receipt #${d.receipt_no} ${paymentStatusBadge(d.status)}</h3>
          <div class="muted small mb1">${fmtDate(d.created_at)} · Cashier: ${esc(d.cashier?.name || '—')}
            · Customer: ${esc(d.customer?.name || d.customer_name || 'Walk-in')}</div>
          <table><thead><tr><th>Item</th><th>Qty × Price</th><th>Total</th></tr></thead><tbody>${rows}</tbody></table>
          <hr>
          <div class="cart-line"><span>Subtotal</span><span>${money(d.subtotal, 'GHS')}</span></div>
          <div class="cart-line"><span>Discount</span><span>− ${money(d.discount, 'GHS')}</span></div>
          ${parseFloat(d.tax) > 0 ? `<div class="cart-line"><span>Tax</span><span>${money(d.tax, 'GHS')}</span></div>` : ''}
          <div class="cart-line total"><span>Total</span><span>${money(d.total, 'GHS')}</span></div>
          <div class="cart-line"><span>Paid initially (${esc(d.payment_method)})</span><span>${money(d.amount_paid, 'GHS')}</span></div>
          ${parseFloat(d.change_given) > 0 ? `<div class="cart-line"><span>Change given</span><span>${money(d.change_given, 'GHS')}</span></div>` : ''}
          <div class="cart-line"><span>Balance due</span><strong class="${d.balance_due > 0 ? '' : 'muted'}">${money(d.balance_due, 'GHS')}</strong> ${paymentStatusBadge(d.payment_status)}</div>
          ${d.due_date ? `<div class="cart-line"><span>Due date</span><span>${esc(d.due_date)}</span></div>` : ''}
        </div>
        <div>
          <div class="card mb1"><h3>Actions</h3>
            <div class="btn-row" style="flex-direction:column;align-items:stretch">
              <button class="btn" id="act-print">🖨 Print receipt</button>
              ${d.invoice ? `<a class="btn" href="#/invoices/${d.invoice.id}">📄 Open invoice ${esc(d.invoice.invoice_no)}</a>` : ''}
              ${d.status === 'completed' && d.balance_due > 0 && State.can('payments.manage') ? `<button class="btn btn-success" id="act-pay">💵 Record payment</button>` : ''}
              ${d.status === 'completed' && State.can('sales.return') ? `<button class="btn" id="act-return">↩ Process return</button>` : ''}
              ${d.status === 'completed' && State.can('sales.void') ? `<button class="btn btn-danger" id="act-void">🛑 Void sale</button>` : ''}
            </div>
          </div>
          ${payments ? `<div class="card"><h3>Payments received</h3>${payments}</div>` : ''}
          ${(d.returns || []).length ? `<div class="card mt1"><h3>Returns</h3>
            ${d.returns.map((r) => `<div class="stat-row"><span>${fmtDate(r.created_at)} · ${esc(r.reason)}</span><strong>− ${money(r.total, 'GHS')}</strong></div>`).join('')}</div>` : ''}
        </div>
      </div>`;

    $('#act-print', host).onclick = () => this.renderReceipt(d);
    $('#act-pay', host)?.addEventListener('click', () => this.paymentModal(d, () => this.detail(el, id)));
    $('#act-void', host)?.addEventListener('click', () => this.voidModal(d, () => this.detail(el, id)));
    $('#act-return', host)?.addEventListener('click', () => this.returnModal(d, () => this.detail(el, id)));
  },

  renderReceipt(s, settings) {
    const biz = {
      name: State.tenant?.name || 'My Shop',
      phone: State.tenant?.phone, address: State.tenant?.address, footer: settings?.invoice_footer,
    };
    const rows = s.items.map((i) => `<tr><td>${esc(i.product_name)}</td><td style="text-align:right">${i.quantity}×${Number(i.unit_price).toFixed(2)}</td><td style="text-align:right">${Number(i.line_total).toFixed(2)}</td></tr>`).join('');
    const html = `
      <div class="receipt">
        <h2>${esc(biz.name)}</h2>
        <div class="r-sub">${esc(biz.address || '')} ${esc(biz.phone || '')}</div>
        <hr style="border-top:1px dashed #000">
        <div>Receipt #${s.receipt_no} · ${new Date(s.created_at).toLocaleString()}</div>
        <div>Customer: ${esc(s.customer?.name || s.customer_name || 'Walk-in')}</div>
        <table style="width:100%"><thead><tr><th>Item</th><th style="text-align:right">Qty×Price</th><th style="text-align:right">Total</th></tr></thead>
          <tbody>${rows}</tbody></table>
        <table style="width:100%"><tbody>
          <tr><td class="r-total">TOTAL</td><td class="r-total" style="text-align:right">GHS ${Number(s.total).toFixed(2)}</td></tr>
          <tr><td>Paid (${esc(s.payment_method)})</td><td style="text-align:right">${Number(s.amount_paid).toFixed(2)}</td></tr>
          ${Number(s.change_given) > 0 ? `<tr><td>Change</td><td style="text-align:right">${Number(s.change_given).toFixed(2)}</td></tr>` : ''}
          ${s.balance_due > 0 ? `<tr><td>BALANCE DUE</td><td style="text-align:right">${Number(s.balance_due).toFixed(2)}</td></tr>` : ''}
        </tbody></table>
        <div class="r-foot">${esc(biz.footer || 'Thank you!')}<br>Powered by ShopStack</div>
      </div>`;
    printArea(html);
  },

  paymentModal(sale, onDone) {
    const m = openModal(`Record payment — owes ${money(sale.balance_due, 'GHS')}`, `
      <form id="pay-form">
        <label>Amount</label><input name="amount" type="number" step="0.01" min="0.01" max="${sale.balance_due}" value="${sale.balance_due}" required>
        <label>Method</label><select name="method"><option value="cash">Cash</option><option value="momo">Mobile Money</option><option value="bank">Bank</option><option value="card">Card</option></select>
        <label>Note (optional)</label><input name="note" maxlength="300">
        <div class="btn-row mt2"><button class="btn btn-primary" type="submit">Record payment</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`);
    $('#pay-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api.post(`/sales/${sale.id}/payments`, formValues(e.target));
        toast('Payment recorded.', 'ok'); closeModal(); onDone();
      } catch (err) { openModal('Payment failed', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`); }
    };
  },

  voidModal(sale, onDone) {
    const m = openModal('Void this sale?', `
      <div class="alert alert-red">Voiding is a permanent, audited reversal — stock is restored and any customer debt from this sale is released.</div>
      <form id="void-form"><label>Reason (required)</label><input name="reason" maxlength="300" required>
      <div class="btn-row mt2"><button class="btn btn-danger" type="submit">Void sale</button><button class="btn" data-close type="button">Cancel</button></div></form>`);
    $('#void-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api.post(`/sales/${sale.id}/void`, formValues(e.target));
        toast('Sale voided.', 'ok'); closeModal(); onDone();
      } catch (err) { openModal('Cannot void', `<div class="alert alert-red">${esc(err.message)}</div>`); }
    };
  },

  returnModal(sale, onDone) {
    const m = openModal('Process a return', `
      <form id="ret-form">
        ${sale.items.map((i) => `
          <div class="list-row"><span>${esc(i.product_name)} <span class="muted">(${i.quantity} sold)</span></span>
          <input type="number" name="qty_${i.id}" min="0" max="${i.quantity}" value="0" style="width:80px"></div>`).join('')}
        <label>Reason (required)</label><input name="reason" maxlength="300" required>
        <div class="btn-row mt2"><button class="btn btn-primary" type="submit">Process return</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`, true);
    $('#ret-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const items = sale.items.map((i) => ({
        sale_item_id: i.id,
        quantity: parseInt(e.target['qty_' + i.id].value || 0, 10),
      })).filter((x) => x.quantity > 0);
      if (!items.length) { toast('Select at least one item to return.', 'err'); return; }
      try {
        await api.post(`/sales/${sale.id}/returns`, { items, reason: e.target.reason.value });
        toast('Return processed.', 'ok'); closeModal(); onDone();
      } catch (err) { openModal('Return failed', `<div class="alert alert-red">${esc(err.message)}</div>`); }
    };
  },
};
Pages['#/sales/*'] = (el, id) => Sales.detail(el, id);
