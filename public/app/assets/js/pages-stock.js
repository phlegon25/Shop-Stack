/* Catalog: products, inventory ledger, purchases & suppliers. */
Pages = window.Pages || {};

/* ================= PRODUCTS ================= */
Pages['#/products'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">📦 Products</div>
    <input class="search-input" id="prod-search" placeholder="Search name, SKU, barcode…">
    <select id="prod-cat" style="max-width:170px"><option value="">All categories</option></select>
    <button class="btn" id="cat-manage" ${State.can('categories.manage') ? '' : 'style="display:none"'}>Categories</button>
    <button class="btn btn-primary" id="add-product" ${State.can('products.manage') ? '' : 'disabled title="No permission"'}>＋ New product</button></div>
    <div id="prod-list">${loadingBlock(3)}</div>`;

  let catalog = { categories: [], brands: [], units: [] };
  try { catalog = await api.get('/catalog'); } catch (_) {}
  $('#prod-cat', el).innerHTML = '<option value="">All categories</option>' +
    catalog.categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');

  let search = '';
  async function load(page = 1) {
    const host = $('#prod-list', el);
    host.innerHTML = loadingBlock(3);
    try {
      const d = await api.get('/products' + qs({ search, category_id: $('#prod-cat', el).value, page }));
      host.innerHTML = '';
      if (!d.data.length) {
        host.innerHTML = `<div class="panel">${emptyBlock('📦', 'No products yet.',
          State.can('products.manage') ? '<button class="btn btn-primary" id="first-add">Add your first product</button>' : '')}</div>`;
        $('#first-add', host)?.addEventListener('click', () => Products.form(null, () => load()));
        return;
      }
      await renderList(host, {
        url: '/products', params: { search, category_id: $('#prod-cat', el).value, page },
        columns: [
          ['Product', (p) => `<strong>${esc(p.name)}</strong><div class="muted small">${esc(p.sku)} · ${esc(p.category?.name || 'Uncategorised')}${p.brand ? ' · ' + esc(p.brand) : ''}</div>`],
          ['Stock', (p) => {
            const low = p.stock_quantity <= 0 ? badge('OUT', 'red') : (p.stock_quantity <= p.low_stock_threshold ? badge('LOW', 'amber') : '');
            return `<strong>${p.stock_quantity}</strong> ${esc(p.unit || '')} available ${low}<div class="muted small">${p.total_stocked_quantity ?? p.stock_quantity} added</div>`; }],
          ['Cost', (p) => `<span class="nowrap">${money(p.cost_price, 'GHS')}</span>`],
          ['Retail', (p) => `<span class="nowrap"><strong>${money(p.selling_price, 'GHS')}</strong></span>`],
          ['Expiry', (p) => p.expiry_date ? `<span class="nowrap">${esc(p.expiry_date)}</span>` : '—'],
        ],
        rowActions: (p) => State.can('products.manage')
          ? `<button class="btn btn-sm" data-edit="${p.id}">Edit</button>`
          : '',
      });
      $$('[data-edit]', host).forEach((b) => {
        b.onclick = () => Products.form(d.data.find((p) => p.id == b.dataset.edit), () => load());
      });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $('#prod-search', el).oninput = debounce(() => { search = $('#prod-search', el).value.trim(); load(); }, 300);
  $('#prod-cat', el).onchange = () => load();
  $('#add-product', el).onclick = () => Products.form(null, () => load());
  $('#cat-manage', el).onclick = () => Products.catalogModal(catalog, load);
  await load();
};

const Products = {
  async form(product, onDone) {
    const isNew = !product;
    const cat = await api.get('/catalog').catch(() => ({ categories: [], brands: [], units: [] }));
    let suppliers = [];
    try { suppliers = (await api.get('/suppliers?per_page=100')).data; } catch (_) {}

    const p = product || {};
    const m = openModal(isNew ? 'New product' : 'Edit — ' + p.name, `
      <form id="product-form" class="form-grid">
        <div><label>Name *</label><input name="name" required maxlength="160" value="${esc(p.name || '')}"></div>
        <div><label>SKU / product code *</label><input name="sku" required maxlength="60" pattern="[A-Za-z0-9\\-_\\.]+" value="${esc(p.sku || '')}" placeholder="e.g. FANTA-3L"><div class="muted small">A unique short code used to find this product quickly.</div></div>
        <div><label>Barcode</label><input name="barcode" maxlength="80" value="${esc(p.barcode || '')}"></div>
        <div><label>Category</label><select name="category_id"><option value="">—</option>
          ${cat.categories.map((c) => `<option value="${c.id}" ${p.category_id == c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
        <div><label>Subcategory</label><input name="subcategory" maxlength="100" value="${esc(p.subcategory || '')}"></div>
        <div><label>Brand</label><input name="brand" list="brand-list" maxlength="100" value="${esc(p.brand || '')}">
          <datalist id="brand-list">${cat.brands.map((b) => `<option value="${esc(b.name)}">`).join('')}</datalist></div>
        <div><label>Unit</label><input name="unit" list="unit-list" maxlength="30" value="${esc(p.unit || 'Piece')}">
          <datalist id="unit-list">${cat.units.map((u) => `<option value="${esc(u.name)}">`).join('')}</datalist></div>
        <div><label>Preferred supplier</label><select name="supplier_id"><option value="">—</option>
          ${suppliers.map((s) => `<option value="${s.id}" ${p.supplier_id == s.id ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select></div>
        <div><label>Cost price *</label><input name="cost_price" type="number" min="0" step="0.01" required value="${p.cost_price ?? ''}"></div>
        <div><label>Selling price *</label><input name="selling_price" type="number" min="0" step="0.01" required value="${p.selling_price ?? ''}"></div>
        <div><label>Wholesale price</label><input name="wholesale_price" type="number" min="0" step="0.01" value="${p.wholesale_price ?? ''}"></div>
        <div><label>Low stock alert at</label><input name="low_stock_threshold" type="number" min="0" value="${p.low_stock_threshold ?? 10}"></div>
        <div><label>Expiry date</label><input name="expiry_date" type="date" value="${p.expiry_date || ''}"></div>
        <div><label>Shelf / location</label><input name="location" maxlength="120" value="${esc(p.location || '')}"></div>
        <div style="grid-column:1/-1"><label>Description</label><textarea name="description" maxlength="1000" rows="2">${esc(p.description || '')}</textarea></div>
        ${isNew ? `<div><label>Opening quantity (total)</label><input name="opening_quantity" type="number" min="0" max="100000000" step="1" value="0"><div class="muted small">This becomes your available stock and is recorded in the stock ledger.</div></div>` : `<div><label>Available quantity</label><input type="number" value="${p.stock_quantity ?? 0}" disabled><div class="muted small">Use Purchases or Inventory adjustments to change stock.</div></div>`}
        ${!isNew ? `<div style="grid-column:1/-1"><label>Active (selling allowed)</label><input type="checkbox" name="is_active" ${p.is_active ? 'checked' : ''}></div>` : ''}
        <div class="btn-row mt2" style="grid-column:1/-1">
          <button class="btn btn-primary" type="submit">${isNew ? 'Create product' : 'Save changes'}</button>
          ${!isNew ? `<button class="btn" id="img-btn" type="button">Upload image</button>
          <button class="btn btn-danger" id="del-product" type="button">Delete</button>` : ''}
          <button class="btn" data-close type="button">Cancel</button></div>
      </form>`, true);

    if (!isNew) {
      $('#del-product', m).onclick = async () => {
        if (!confirm('Delete this product? Products with sales history are deactivated instead.')) return;
        try { const r = await api.del('/products/' + p.id); toast(r.message, 'ok'); closeModal(); onDone(); }
        catch (e) { toast(e.message, 'err'); }
      };
      $('#img-btn', m).onclick = () => {
        const im = openModal('Upload product image', `
          <input type="file" id="img-file" accept="image/png,image/jpeg,image/webp" class="mb1">
          <button class="btn btn-primary" id="img-up">Upload</button>`);
        $('#img-up', im).onclick = async () => {
          const f = $('#img-file', im).files[0];
          if (!f) { toast('Choose an image first.', 'err'); return; }
          const fd = new FormData(); fd.append('image', f);
          try { await api.postForm('/products/' + p.id + '/image', fd); toast('Image updated.', 'ok'); closeModal(); }
          catch (e) { toast(e.message, 'err'); }
        };
      };
    }

    $('#product-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const data = formValues(e.target);
      ['cost_price', 'selling_price', 'wholesale_price', 'low_stock_threshold'].forEach((k) => { if (data[k] === null) delete data[k]; });
      try {
        if (isNew) await api.post('/products', data); else await api.put('/products/' + p.id, data);
        toast(isNew ? 'Product created.' : 'Product saved.', 'ok');
        closeModal(); onDone();
      } catch (err) {
        m.querySelectorAll('.field-err, .alert-red').forEach((n) => n.remove());
        e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red" style="grid-column:1/-1">${esc(err.message)}</div>${fieldErrors(err)}`);
      }
    };
  },

  catalogModal(catalog, onDone) {
    const m = openModal('Categories, brands & units', `
      <div class="grid grid-3">
        <div class="card"><h3>Categories</h3>${catalog.categories.map((c) => `<div class="list-row">${esc(c.name)}</div>`).join('') || '<div class="muted">None</div>'}
          <form id="qf-cat" class="mt1"><input name="name" placeholder="New category" maxlength="80" required>
          <select name="parent_id" class="mt1"><option value="">Top level</option>${catalog.categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
          <button class="btn btn-sm btn-primary mt1">Add</button></form></div>
        <div class="card"><h3>Brands</h3>${catalog.brands.map((b) => `<div class="list-row">${esc(b.name)}</div>`).join('') || '<div class="muted">None</div>'}
          <form id="qf-brand" class="mt1"><input name="name" placeholder="New brand" maxlength="60" required><button class="btn btn-sm btn-primary mt1">Add</button></form></div>
        <div class="card"><h3>Units</h3>${catalog.units.map((u) => `<div class="list-row">${esc(u.name)}</div>`).join('') || '<div class="muted">None</div>'}
          <form id="qf-unit" class="mt1"><input name="name" placeholder="New unit" maxlength="30" required><button class="btn btn-sm btn-primary mt1">Add</button></form></div>
      </div>`, true);
    $('#qf-cat', m).onsubmit = async (e) => { e.preventDefault();
      try { await api.post('/categories', formValues(e.target)); toast('Category added.', 'ok'); closeModal(); onDone(); }
      catch (err) { toast(err.message, 'err'); } };
    $('#qf-brand', m).onsubmit = async (e) => { e.preventDefault();
      try { await api.post('/catalog/brands', formValues(e.target)); toast('Brand added.', 'ok'); closeModal(); onDone(); }
      catch (err) { toast(err.message, 'err'); } };
    $('#qf-unit', m).onsubmit = async (e) => { e.preventDefault();
      try { await api.post('/catalog/units', formValues(e.target)); toast('Unit added.', 'ok'); closeModal(); onDone(); }
      catch (err) { toast(err.message, 'err'); } };
  },
};

/* ================= INVENTORY ================= */
Pages['#/inventory'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🗄️ Inventory</div>
    <button class="btn btn-primary" id="adj-btn" ${State.can('inventory.adjust') ? '' : 'disabled title="No permission"'}>Stock adjustment</button></div>
    <div class="tabs" id="inv-tabs">
      <button data-t="movements" class="active">Stock movements</button>
      <button data-t="low">Low / out of stock</button>
      <button data-t="expiring">Expiring soon</button>
    </div>
    <div id="inv-body">${loadingBlock(3)}</div>`;

  const host = $('#inv-body', el);
  async function show(tab, page = 1) {
    $$('#inv-tabs button', el).forEach((b) => b.classList.toggle('active', b.dataset.t === tab));
    host.innerHTML = loadingBlock(3);
    try {
      if (tab === 'movements') {
        const d = await api.get('/stock/movements' + qs({ page }));
        host.innerHTML = `<div class="panel"><table><thead><tr><th>When</th><th>Product</th><th>Type</th><th>Qty</th><th>Balance</th><th>Note</th></tr></thead>
          <tbody>${d.data.map((m) => `<tr><td class="nowrap">${fmtDate(m.created_at)}</td>
            <td>${esc(m.product?.name || '—')}</td><td>${badge(m.type.replaceAll('_', ' '), m.quantity >= 0 ? 'green' : 'red')}</td>
            <td class="nowrap"><strong>${m.quantity > 0 ? '+' : ''}${m.quantity}</strong></td>
            <td>${m.balance_after ?? '—'}</td><td class="muted small">${esc(m.note || '')}</td></tr>`).join('')}</tbody></table>
          ${pagerHtml(d)}</div>`;
        host.querySelectorAll('[data-page]').forEach((b) => { b.onclick = () => show('movements', parseInt(b.dataset.page, 10)); });
        if (!d.data.length) host.innerHTML = `<div class="panel">${emptyBlock('🗄️', 'No stock movements yet.')}</div>`;
      } else if (tab === 'low') {
        const d = await api.get('/products/low-stock');
        const rows = d.data || [];
        host.innerHTML = rows.length ? `<div class="panel"><table><thead><tr><th>Product</th><th>Stock</th><th>Threshold</th><th>Supplier</th></tr></thead>
          <tbody>${rows.map((p) => `<tr><td><strong>${esc(p.name)}</strong><div class="muted small">${esc(p.sku)}</div></td>
            <td>${p.stock_quantity <= 0 ? badge('OUT', 'red') : badge(String(p.stock_quantity), 'amber')}</td>
            <td>${p.low_stock_threshold}</td><td>${esc(p.supplier?.name || '—')}</td></tr>`).join('')}</tbody></table></div>`
          : `<div class="panel">${emptyBlock('✅', 'Stock levels are healthy.')}</div>`;
      } else {
        const d = await api.get('/products/expiring');
        host.innerHTML = d.data.length ? `<div class="panel"><table><thead><tr><th>Product</th><th>Expiry</th><th>Days left</th><th>Stock</th></tr></thead>
          <tbody>${d.data.map((p) => `<tr><td><strong>${esc(p.name)}</strong></td>
            <td class="nowrap">${esc(p.expiry_date)}</td>
            <td>${p.days_to_expiry <= 7 ? badge(p.days_to_expiry + ' days', 'red') : badge(p.days_to_expiry + ' days', 'amber')}</td>
            <td>${p.stock_quantity}</td></tr>`).join('')}</tbody></table></div>`
          : `<div class="panel">${emptyBlock('🗓️', `Nothing expiring within ${d.window_days || 14} days.`)}</div>`;
      }
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $$('#inv-tabs button', el).forEach((b) => { b.onclick = () => show(b.dataset.t); });

  $('#adj-btn', el).onclick = async () => {
    const prods = (await api.get('/products?per_page=200')).data;
    const m = openModal('Manual stock adjustment', `
      <form id="adj-form">
        <label>Product</label><select name="product_id">${prods.map((p) => `<option value="${p.id}">${esc(p.name)} (${p.stock_quantity})</option>`).join('')}</select>
        <label>Change (+ restock / − write-off)</label><input name="quantity" type="number" required placeholder="e.g. 12 or -3">
        <label>Reason *</label><input name="reason" maxlength="300" required placeholder="e.g. Damaged goods written off">
        <div class="btn-row mt2"><button class="btn btn-primary">Apply</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`);
    $('#adj-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const data = formValues(e.target); data.quantity = parseInt(data.quantity, 10);
      try { await api.post('/stock/adjustments', data); toast('Adjustment applied.', 'ok'); closeModal(); show('movements'); }
      catch (err) { toast(err.message, 'err'); }
    };
  };

  await show('movements');
};

/* ================= PURCHASES ================= */
Pages['#/purchases'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">📥 Purchases</div>
    <button class="btn btn-primary" id="new-po" ${State.can('purchases.manage') ? '' : 'disabled'}>＋ New purchase</button></div>
    <div class="tabs" id="po-tabs"><button data-t="list" class="active">All purchases</button><button data-t="payables">Payables (what you owe)</button></div>
    <div id="po-body">${loadingBlock(3)}</div>`;

  const host = $('#po-body', el);
  async function show(tab) {
    $$('#po-tabs button', el).forEach((b) => b.classList.toggle('active', b.dataset.t === tab));
    host.innerHTML = loadingBlock(3);
    try {
      if (tab === 'list') {
        host.innerHTML = '';
        await renderList(host, {
          url: '/purchases',
          columns: [
            ['Reference', (p) => `<strong>${esc(p.reference)}</strong>`],
            ['Supplier', (p) => esc(p.supplier?.name || '—')],
            ['Total', (p) => `<span class="nowrap">${money(p.total, 'GHS')}</span>`],
            ['Paid', (p) => `<span class="nowrap">${money(p.amount_paid, 'GHS')}</span>`],
            ['Status', (p) => paymentStatusBadge(p.status)],
            ['Restocked', (p) => `<span class="nowrap">${fmtDate(p.received_at || p.created_at)}</span>`],
          ],
          rowActions: (p) => `<a class="btn btn-sm" href="#/purchases/${p.id}">Open</a>`,
        });
      } else {
        const d = await api.get('/purchases/payables');
        host.innerHTML = `<div class="card mb2"><h3>Total owed to suppliers <strong>${money(d.total_outstanding, d.currency)}</strong></h3></div>
          ${d.data.length ? `<div class="panel"><table><thead><tr><th>PO</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due date</th><th></th></tr></thead>
            <tbody>${d.data.map((p) => `<tr><td><strong>${esc(p.reference)}</strong></td><td>${esc(p.supplier?.name || '—')}</td>
              <td class="nowrap">${money(p.total, d.currency)}</td><td class="nowrap">${money(p.paid, d.currency)}</td>
              <td class="nowrap"><strong>${money(p.balance, d.currency)}</strong></td>
              <td class="nowrap">${p.due_date ? esc(p.due_date) : '—'}</td>
              <td><a class="btn btn-sm" href="#/purchases/${p.purchase_id}">Pay</a></td></tr>`).join('')}</tbody></table></div>`
          : `<div class="panel">${emptyBlock('🎉', 'No outstanding supplier payments.')}</div>`}`;
      }
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $$('#po-tabs button', el).forEach((b) => { b.onclick = () => show(b.dataset.t); });
  $('#new-po', el).onclick = () => Purchases.form(() => show('list'));
  await show('list');
};

const Purchases = {
  async form(onDone) {
    const [prods, suppliers] = await Promise.all([
      api.get('/products?per_page=200').then((d) => d.data).catch(() => []),
      api.get('/suppliers?per_page=100').then((d) => d.data).catch(() => []),
    ]);
    if (!prods.length) { toast('Add products before recording purchases.', 'err'); return; }

    const m = openModal('Record purchase (stock comes in)', `
      <form id="po-form">
        <div class="form-grid">
          <div><label>Supplier</label><select name="supplier_id"><option value="">—</option>
            ${suppliers.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select></div>
          <div><label>Reference *</label><input name="reference" required maxlength="60" pattern="[A-Za-z0-9\\-_\\.]+" placeholder="PO-001"></div>
          <div><label>Restock date *</label><input name="received_at" type="date" max="${todayStr()}" value="${todayStr()}" required><div class="muted small">When these goods arrived and were added to stock.</div></div>
        </div>
        <div id="po-items"></div>
        <button class="btn btn-sm mt1" type="button" id="po-add-line">＋ Add line</button>
        <div class="form-grid mt1">
          <div><label>Paid now</label><input name="amount_paid" type="number" min="0" step="0.01" value="0"></div>
          <div><label>Due date</label><input name="due_date" type="date"></div>
        </div>
        <div class="cart-line total mt1"><span>Purchase total</span><span id="po-total">GHS 0.00</span></div>
        <div class="btn-row mt2"><button class="btn btn-primary">Record & restock</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`, true);

    const itemsHost = $('#po-items', m);
    function addLine() {
      const row = document.createElement('div');
      row.className = 'list-row';
      row.innerHTML = `<select data-p style="flex:2">${prods.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>
        <input data-q type="number" min="1" value="5" style="width:80px" title="Qty">
        <input data-c type="number" min="0" step="0.01" value="0" style="width:100px" title="Unit cost">
        <button class="btn btn-ghost btn-sm" type="button">✕</button>`;
      row.querySelector('button').onclick = () => { row.remove(); recalc(); };
      row.querySelectorAll('input,select').forEach((i) => { i.oninput = recalc; });
      itemsHost.appendChild(row); recalc();
    }
    function recalc() {
      let t = 0;
      $$('.list-row', itemsHost).forEach((r) => { t += (parseFloat(r.querySelector('[data-q]').value) || 0) * (parseFloat(r.querySelector('[data-c]').value) || 0); });
      $('#po-total', m).textContent = money(t, 'GHS');
    }
    $('#po-add-line', m).onclick = addLine; addLine();

    $('#po-form', m).onsubmit = async (e) => {
      e.preventDefault();
      const data = formValues(e.target);
      data.items = $$('.list-row', itemsHost).map((r) => ({
        product_id: parseInt(r.querySelector('[data-p]').value, 10),
        quantity: parseInt(r.querySelector('[data-q]').value, 10),
        unit_cost: parseFloat(r.querySelector('[data-c]').value) || 0,
      }));
      if (!data.items.length) { toast('Add at least one line.', 'err'); return; }
      delete data['data-q'];
      try {
        await api.post('/purchases', data);
        toast('Purchase recorded — stock updated.', 'ok'); closeModal(); onDone();
      } catch (err) {
        e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>${fieldErrors(err)}`);
      }
    };
  },

  async detail(el, id) {
    el.innerHTML = `<div class="toolbar"><div class="toolbar-title">📥 Purchase</div><a class="btn btn-ghost" href="#/purchases">← Back</a></div>
      <div id="po-detail">${loadingBlock(2)}</div>`;
    const host = $('#po-detail', el);
    let d;
    try { d = (await api.get('/purchases/' + id)).data; } catch (e) { host.innerHTML = errBlock(e); return; }

    host.innerHTML = `
      <div class="grid" style="grid-template-columns:2fr 1fr">
        <div class="card"><h3>${esc(d.reference)} ${paymentStatusBadge(d.status)}</h3>
          <div class="muted small mb1">Restocked ${fmtDate(d.received_at || d.created_at)} · Supplier: ${esc(d.supplier?.name || '—')} · Recorded by ${esc(d.recorded_by?.name || '—')}</div>
          <table><thead><tr><th>Product</th><th>Qty</th><th>Unit cost</th><th>Line total</th></tr></thead>
            <tbody>${d.items.map((i) => `<tr><td>${esc(i.product?.name || '—')}</td><td>${i.quantity}</td>
              <td class="nowrap">${money(i.unit_cost, 'GHS')}</td><td class="nowrap">${money(i.line_total, 'GHS')}</td></tr>`).join('')}</tbody></table>
          <hr><div class="cart-line total"><span>Total</span><span>${money(d.total, 'GHS')}</span></div>
          <div class="cart-line"><span>Paid</span><span>${money(d.amount_paid, 'GHS')}</span></div>
          <div class="cart-line"><span>Balance</span><strong>${money(d.balance_due, 'GHS')}</strong> ${paymentStatusBadge(d.payment_status)}</div>
        </div>
        <div>
        <div class="card"><h3>Actions</h3><div class="btn-row" style="flex-direction:column;align-items:stretch">
          ${d.status === 'received' && d.balance_due > 0 && State.can('payments.manage') ? `<button class="btn btn-success" id="po-pay">💵 Record supplier payment</button>` : ''}
          ${d.status === 'received' && d.payment_status === 'unpaid' && State.can('purchases.manage') ? `<button class="btn btn-danger" id="po-cancel">Cancel purchase</button>` : ''}
        </div></div>
        ${(d.payments || []).length ? `<div class="card mt1"><h3>Payments</h3>${d.payments.map((p) => `<div class="stat-row"><span>${fmtDate(p.created_at)} · ${esc(p.method)}</span><strong>${money(p.amount, 'GHS')}</strong></div>`).join('')}</div>` : ''}
        </div></div>`;

    $('#po-pay', host)?.addEventListener('click', () => {
      const m = openModal(`Pay supplier — owes ${money(d.balance_due, 'GHS')}`, `
        <form id="po-pay-form">
          <label>Amount</label><input name="amount" type="number" min="0.01" max="${d.balance_due}" step="0.01" value="${d.balance_due}" required>
          <label>Method</label><select name="method"><option value="cash">Cash</option><option value="momo">Mobile Money</option><option value="bank">Bank</option></select>
          <label>Note</label><input name="note" maxlength="300">
          <div class="btn-row mt2"><button class="btn btn-primary">Record</button><button class="btn" data-close type="button">Cancel</button></div>
        </form>`);
      $('#po-pay-form', m).onsubmit = async (e) => {
        e.preventDefault();
        try { await api.post(`/purchases/${d.id}/payments`, formValues(e.target)); toast('Payment recorded.', 'ok'); closeModal(); Purchases.detail(el, d.id); }
        catch (err) { toast(err.message, 'err'); }
      };
    });

    $('#po-cancel', host)?.addEventListener('click', async () => {
      if (!confirm('Cancel this purchase? Stock is reversed. Only possible when nothing was paid yet.')) return;
      try { await api.post(`/purchases/${d.id}/cancel`); toast('Purchase cancelled.', 'ok'); Purchases.detail(el, d.id); }
      catch (e) { toast(e.message, 'err'); }
    });
  },
};
Pages['#/purchases/*'] = (el, id) => Purchases.detail(el, id);

/* ================= SUPPLIERS ================= */
Pages['#/suppliers'] = async function (el) {
  el.innerHTML = `<div class="toolbar"><div class="toolbar-title">🚚 Suppliers</div>
    <input class="search-input" id="sup-search" placeholder="Search…">
    <button class="btn btn-primary" id="add-sup" ${State.can('suppliers.manage') ? '' : 'disabled'}>＋ New supplier</button></div>
    <div id="sup-list">${loadingBlock(3)}</div>`;

  let search = '';
  async function load(page = 1) {
    const host = $('#sup-list', el);
    host.innerHTML = '';
    try {
      host.innerHTML = loadingBlock(3);
      const data = await api.get('/suppliers' + qs({ search, page }));
      host.innerHTML = '';
      if (!data.data.length) {
        host.innerHTML = `<div class="panel">${emptyBlock('🚚', 'No suppliers yet.', State.can('suppliers.manage') ? '<button class="btn btn-primary" id="sup-first">Add supplier</button>' : '')}</div>`;
        $('#sup-first', host)?.addEventListener('click', () => Suppliers.form(null, load));
        return;
      }
      const d = data;
      await renderList(host, {
        url: '/suppliers', params: { search, page },
        columns: [
          ['Name', (s) => `<strong>${esc(s.name)}</strong>`],
          ['Phone', (s) => esc(s.phone || '—')],
          ['Email', (s) => esc(s.email || '—')],
          ['Address', (s) => `<span class="muted small">${esc(s.address || '—')}</span>`],
        ],
        rowActions: (s) => `<button class="btn btn-sm" data-open="${s.id}">Open</button>
          ${State.can('suppliers.manage') ? `<button class="btn btn-sm" data-edit="${s.id}">Edit</button>` : ''}`,
      });
      $$('[data-open]', host).forEach((b) => { b.onclick = () => Suppliers.detail(b.dataset.open); });
      $$('[data-edit]', host).forEach((b) => { b.onclick = () => Suppliers.form(d.data.find((s) => s.id == b.dataset.edit), load); });
    } catch (e) { host.innerHTML = errBlock(e); }
  }

  $('#sup-search', el).oninput = debounce(() => { search = $('#sup-search', el).value.trim(); load(); }, 300);
  $('#add-sup', el).onclick = () => Suppliers.form(null, load);
  await load();
};

const Suppliers = {
  form(supplier, onDone) {
    const s = supplier || {};
    const m = openModal(supplier ? 'Edit supplier' : 'New supplier', `
      <form id="sup-form">
        <label>Name *</label><input name="name" required maxlength="120" value="${esc(s.name || '')}">
        <label>Phone</label><input name="phone" maxlength="30" value="${esc(s.phone || '')}">
        <label>Email</label><input name="email" type="email" value="${esc(s.email || '')}">
        <label>Address</label><textarea name="address" maxlength="300" rows="2">${esc(s.address || '')}</textarea>
        <div class="btn-row mt2"><button class="btn btn-primary">${supplier ? 'Save' : 'Create'}</button><button class="btn" data-close type="button">Cancel</button></div>
      </form>`);
    $('#sup-form', m).onsubmit = async (e) => {
      e.preventDefault();
      try {
        if (supplier) await api.put('/suppliers/' + s.id, formValues(e.target));
        else await api.post('/suppliers', formValues(e.target));
        toast('Saved.', 'ok'); closeModal(); onDone();
      } catch (err) { e.target.insertAdjacentHTML('afterbegin', `<div class="alert alert-red">${esc(err.message)}</div>`); }
    };
  },

  async detail(id) {
    let d;
    try { d = await api.get('/suppliers/' + id); } catch (e) { toast(e.message, 'err'); return; }
    openModal('🚚 ' + d.data.name, `
      <div class="grid grid-2 mb1">
        <div class="kpi"><div class="kpi-label">Total purchases</div><div class="kpi-value">${d.stats.total_purchases}</div></div>
        <div class="kpi kpi-amber"><div class="kpi-label">You owe them</div><div class="kpi-value">${money(d.stats.outstanding, 'GHS')}</div></div>
      </div>
      <h4>Contact</h4><p class="muted small">${esc(d.data.phone || '')} ${esc(d.data.email || '')} ${esc(d.data.address || '')}</p>
      <h4>Open purchases</h4>
      ${d.open_purchases.length ? d.open_purchases.map((p) => `<div class="list-row"><span><strong>${esc(p.reference)}</strong> due ${esc(p.due_date || '—')}</span>
        <span><a href="#/purchases/${p.id}" class="btn btn-sm">Pay ${money(p.balance, 'GHS')}</a></span></div>`).join('')
        : '<div class="muted small">Nothing outstanding.</div>'}
      <h4 class="mt1">Recent purchases</h4>
      ${(d.recent_purchases || []).map((p) => `<div class="list-row"><span>#${esc(p.reference)}</span><strong>${money(p.total, 'GHS')}</strong></div>`).join('') || '<div class="muted small">None yet.</div>'}`,
      true);
  },
};
