/* Help Center: Demo Mode sandbox + guided tutorial engine.
   SAFETY: this file never calls fetch/XHR and never submits a form to the server. All records live in a JavaScript
   object in the visitor's browser and disappear on reload, so nothing here can touch real inventory or the database. */
(function () {
  'use strict';

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const md = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  const money = n => '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const addDays = n => { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + n); return iso(d); };
  const daysUntil = s => Math.round((new Date(s + 'T12:00:00') - new Date(iso(new Date()) + 'T12:00:00')) / 864e5);
  const fmtDate = s => new Date(s + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const NEAR_DAYS = 90;

  const MODULES = [
    ['dashboard', 'Dashboard', 'grid-1x2'], ['products', 'Products', 'box-seam'], ['inventory', 'Stock in / out', 'arrow-left-right'],
    ['categories', 'Categories', 'tags'], ['suppliers', 'Suppliers', 'truck'], ['orders', 'Purchase orders', 'receipt'],
    ['expiry', 'Expiry tracking', 'calendar-x'], ['reports', 'Reports', 'graph-up'], ['users', 'User management', 'people'],
  ];

  /* ------------------------------------------------------------------ sample data */
  function seed() {
    const moves = []; let id = 1;
    for (let m = 5; m >= 1; m--) {
      const d = new Date(); d.setDate(15); d.setMonth(d.getMonth() - m); const ds = iso(d);
      moves.push({ id: id++, date: ds, type: 'in', prod: 1, qty: 60 + m * 9, ref: 'IN-' + ds.slice(2, 4) + ds.slice(5, 7) + '-A' + m, note: 'Delivery' });
      moves.push({ id: id++, date: ds, type: 'out', prod: 3, qty: 35 + m * 7, ref: 'OUT-' + ds.slice(2, 4) + ds.slice(5, 7) + '-B' + m, note: 'sale' });
    }
    moves.push({ id: id++, date: addDays(-6), type: 'in', prod: 3, qty: 200, ref: 'IN-DEMO-001', note: 'Delivery' });
    moves.push({ id: id++, date: addDays(-2), type: 'out', prod: 2, qty: 25, ref: 'OUT-DEMO-002', note: 'sale' });
    return {
      cats: [{ id: 1, name: 'Pain relievers', desc: 'Analgesics and fever reducers' }, { id: 2, name: 'Vitamins', desc: 'Supplements' }, { id: 3, name: 'First aid', desc: 'Dressings and antiseptics' }, { id: 4, name: 'Antibiotics', desc: 'Prescription only' }],
      sups: [
        { id: 1, code: 'SUP-0001', name: 'MediSource Trading', contact: 'Ana Reyes', phone: '0917 555 0101', email: 'ana@medisource.example', address: 'Makati City', status: 'active' },
        { id: 2, code: 'SUP-0002', name: 'PharmaPlus Wholesale', contact: 'Ben Santos', phone: '0918 555 0142', email: 'ben@pharmaplus.example', address: 'Quezon City', status: 'active' },
        { id: 3, code: 'SUP-0003', name: 'CareLine Supplies', contact: 'Carla Lim', phone: '0920 555 0177', email: 'carla@careline.example', address: 'Cebu City', status: 'inactive' }],
      prods: [
        { id: 1, sku: 'MED-001', name: 'Paracetamol 500mg', cat: 1, cost: 2.5, price: 5, qty: 240, min: 50, track: true },
        { id: 2, sku: 'MED-002', name: 'Ibuprofen 200mg', cat: 1, cost: 1.8, price: 4, qty: 35, min: 60, track: true },
        { id: 3, sku: 'MED-003', name: 'Vitamin C 500mg', cat: 2, cost: 3, price: 6.5, qty: 410, min: 100, track: true },
        { id: 4, sku: 'MED-004', name: 'Amoxicillin 250mg', cat: 4, cost: 5, price: 11, qty: 0, min: 40, track: true },
        { id: 5, sku: 'FA-010', name: 'Adhesive bandages (box)', cat: 3, cost: 0.5, price: 1.5, qty: 520, min: 100, track: false },
        { id: 6, sku: 'FA-011', name: 'Isopropyl alcohol 70% 500ml', cat: 3, cost: 28, price: 45, qty: 18, min: 20, track: false },
        { id: 7, sku: 'MED-005', name: 'Cetirizine 10mg', cat: null, cost: 2.2, price: 5.5, qty: 150, min: 40, track: true }],
      batches: [
        { id: 1, prod: 1, no: 'B2401A', exp: addDays(-20), qty: 15, sup: 1 }, { id: 2, prod: 1, no: 'B2509B', exp: addDays(400), qty: 225, sup: 1 },
        { id: 3, prod: 2, no: 'B2502C', exp: addDays(45), qty: 35, sup: 2 }, { id: 4, prod: 3, no: 'B2507D', exp: addDays(210), qty: 410, sup: 1 },
        { id: 5, prod: 7, no: 'B2508E', exp: addDays(60), qty: 150, sup: 2 }],
      moves, nextIds: { prod: 8, cat: 5, sup: 4, batch: 6, move: id, po: 4 },
      pos: [
        { id: 1, no: 'PO-0001', sup: 2, date: addDays(-5), status: 'ordered', lines: [{ prod: 2, qty: 200, rec: 0, cost: 1.8 }] },
        { id: 2, no: 'PO-0002', sup: 1, date: addDays(-30), status: 'received', lines: [{ prod: 1, qty: 300, rec: 300, cost: 2.5 }] },
        { id: 3, no: 'PO-0003', sup: 1, date: addDays(-1), status: 'draft', lines: [{ prod: 3, qty: 100, rec: 0, cost: 3 }] }],
      users: [
        { id: 1, name: 'Maria Santos', username: 'msantos', role: 'admin', status: 'active' },
        { id: 2, name: 'Jose Rivera', username: 'jrivera', role: 'user', status: 'active' },
        { id: 3, name: 'Liza Tan', username: 'ltan', role: 'user', status: 'suspended' }],
    };
  }

  /* ------------------------------------------------------------------ sandbox */
  function mount(root, opts) {
    opts = opts || {};
    let s = seed();
    const ui = {
      module: opts.module || 'dashboard', tab: 'in', q: '', cat: '', status: '', monitor: 'all', expiry: 'all', supq: '', supf: '',
      rtype: 'stock', rfrom: addDays(-90), rto: addDays(0), report: null, role: 'user', tryMsg: null, poLines: [], pick: '',
    };
    let modal = null, toastTimer = null;
    const P = id => s.prods.find(p => p.id === +id), C = id => s.cats.find(c => c.id === +id), S = id => s.sups.find(x => x.id === +id);
    const statusOf = p => p.qty <= 0 ? ['Out of stock', 'danger'] : p.qty <= p.min ? ['Low stock', 'warning'] : ['In stock', 'success'];
    const badge = (l, c) => `<span class="badge badge-${c}">${esc(l)}</span>`;
    const meter = p => { const [, c] = statusOf(p); const pct = p.min > 0 ? Math.min(100, Math.round(p.qty / (p.min * 2) * 100)) : (p.qty > 0 ? 100 : 0); return `<div class="meter" title="${p.qty} on hand, minimum ${p.min}"><span class="meter-${c}" style="width:${pct}%"></span><i style="left:50%"></i></div>`; };
    const expState = b => { const d = daysUntil(b.exp); return d < 0 ? ['Expired', 'danger', d] : d <= NEAR_DAYS ? ['Near expiry', 'warning', d] : ['Valid', 'success', d]; };
    const expCounts = () => { const r = { expired: 0, near: 0, valid: 0 }; s.batches.filter(b => b.qty > 0).forEach(b => { const st = expState(b)[0]; r[st === 'Expired' ? 'expired' : st === 'Near expiry' ? 'near' : 'valid']++; }); return r; };
    const lowCount = () => s.prods.filter(p => p.qty <= p.min).length;
    const catName = id => (C(id) || {}).name || 'Uncategorized';
    const poTotal = po => po.lines.reduce((t, l) => t + l.qty * l.cost, 0);

    root.innerHTML = `<div class="hd-app${opts.nav === false ? ' hd-nonav' : ''}">
      <aside class="hd-side"></aside><section class="hd-main" data-hd-main></section>
      <div class="hd-modal-layer" hidden data-hd-modal></div><div class="hd-toast" hidden role="status"></div></div>`;
    const app = root.firstElementChild, side = app.querySelector('.hd-side'), main = app.querySelector('[data-hd-main]'),
      layer = app.querySelector('[data-hd-modal]'), toastEl = app.querySelector('.hd-toast');
    const key = k => app.querySelector(`[data-demo="${k}"]`);

    function toast(msg, err) {
      toastEl.textContent = msg; toastEl.className = 'hd-toast' + (err ? ' err' : ''); toastEl.hidden = false;
      clearTimeout(toastTimer); toastTimer = setTimeout(() => toastEl.hidden = true, 3400);
    }
    function nextId(k) { return s.nextIds[k]++; }
    function pushMove(type, prod, qty, ref, note) { s.moves.push({ id: nextId('move'), date: iso(new Date()), type, prod, qty, ref, note }); }
    const ref = p => p + '-' + iso(new Date()).slice(2).replace(/-/g, '') + '-' + Math.random().toString(36).slice(2, 7).toUpperCase();

    /* ---------- chrome ---------- */
    function renderSide() {
      const ec = expCounts();
      side.innerHTML = `<div class="hd-brand"><span class="logo"><i class="bi bi-boxes"></i></span> Demo</div>` + MODULES.map(([k, l, i]) =>
        `<button type="button" data-demo="nav-${k}" data-act="nav" data-v="${k}" class="${ui.module === k ? 'active' : ''}"><i class="bi bi-${i}"></i>${esc(l)}` +
        (k === 'inventory' && lowCount() ? `<span class="count">${lowCount()}</span>` : '') + (k === 'expiry' && ec.expired + ec.near ? `<span class="count">${ec.expired + ec.near}</span>` : '') + `</button>`).join('');
    }
    function keepFocus(fn) {
      const a = document.activeElement, k = a && app.contains(a) ? a.dataset.demo : null, st = a && a.selectionStart;
      fn();
      if (k) { const el = key(k); if (el) { el.focus(); try { if (st != null) el.setSelectionRange(st, st); } catch (e) { } } }
    }
    function render() { renderSide(); main.innerHTML = VIEWS[ui.module](); }
    function go(m) { if (!VIEWS[m]) return; closeModal(); ui.module = m; ui.tryMsg = null; render(); }

    /* ---------- views ---------- */
    const th = (...c) => '<thead><tr>' + c.map(x => `<th>${x}</th>`).join('') + '</tr></thead>';
    const empty = (cols, t) => `<tr><td colspan="${cols}" class="empty">${esc(t)}</td></tr>`;
    const opts2 = (arr, sel, label) => arr.map(x => `<option value="${x.id}"${String(x.id) === String(sel) ? ' selected' : ''}>${esc(label(x))}</option>`).join('');

    const VIEWS = {
      dashboard() {
        const tot = s.prods.length, val = s.prods.reduce((t, p) => t + p.qty * p.cost, 0), ec = expCounts();
        const low = s.prods.filter(p => p.qty > 0 && p.qty <= p.min).length, out = s.prods.filter(p => p.qty <= 0).length, ok = tot - low - out;
        const a1 = ok / (tot || 1) * 360, a2 = a1 + low / (tot || 1) * 360;
        const months = []; for (let i = 5; i >= 0; i--) { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - i); months.push(iso(d).slice(0, 7)); }
        const sum = (t, m) => s.moves.filter(x => x.type === t && x.date.slice(0, 7) === m).reduce((a, x) => a + x.qty, 0);
        const ins = months.map(m => sum('in', m)), outs = months.map(m => sum('out', m)), mx = Math.max(1, ...ins, ...outs);
        const alerts = s.prods.filter(p => p.qty <= p.min).sort((a, b) => a.qty - b.qty).slice(0, 6);
        return `<h2 class="hd-title">Dashboard <small class="text-secondary fw-normal">Sample inventory</small></h2>
        <div class="hd-grid hd-stats" data-demo="dash-stats">
          <div class="hd-stat"><small>Total products</small><b>${tot}</b></div><div class="hd-stat"><small>Stock value</small><b>${money(val)}</b></div>
          <div class="hd-stat"><small>Low / out of stock</small><b>${low + out}</b></div><div class="hd-stat"><small>Expiry alerts</small><b>${ec.expired + ec.near}</b></div></div>
        <div class="hd-grid hd-two">
          <div class="hd-card" data-demo="dash-chart"><h3>Stock movement (units)</h3><div class="hd-pad"><div class="hd-bars">${months.map((m, i) =>
            `<div title="${m}: ${ins[i]} in, ${outs[i]} out"><div style="flex:1;display:flex;align-items:flex-end;gap:2px;width:100%;justify-content:center"><span style="height:${ins[i] / mx * 100}%"></span><span class="o" style="height:${outs[i] / mx * 100}%;background:linear-gradient(180deg,#ffb1aa,#fb8f86)"></span></div>${new Date(m + '-01T12:00').toLocaleString('en-US', { month: 'short' })}</div>`).join('')}</div>
            <div class="hd-legend mt-2"><span><i style="background:#7c6cf0"></i>Stock in</span><span><i style="background:#fb8f86"></i>Stock out</span></div></div></div>
          <div class="hd-card" data-demo="dash-summary"><h3>Inventory summary</h3><div class="hd-pad"><div class="hd-donut" style="background:conic-gradient(#6cc68b 0 ${a1}deg,#f5b94a ${a1}deg ${a2}deg,#fb8f86 ${a2}deg 360deg)"><b>${tot}<small>products</small></b></div>
            <div class="hd-legend"><span><i style="background:#6cc68b"></i>In stock ${ok}</span><span><i style="background:#f5b94a"></i>Low ${low}</span><span><i style="background:#fb8f86"></i>Out ${out}</span></div></div></div></div>
        <div class="hd-card mt-3" data-demo="dash-alerts"><h3>Needs restocking</h3><div class="table-responsive"><table class="table table-hover">${th('Product', 'On hand', 'Minimum', 'Status', '')}<tbody>${alerts.map(p => `<tr><td class="fw-medium">${esc(p.name)}</td><td>${p.qty}</td><td>${p.min}</td><td>${badge(...statusOf(p))}</td><td class="text-end"><button type="button" class="btn btn-sm btn-light" data-act="restock" data-id="${p.id}">Restock</button></td></tr>`).join('') || empty(5, 'All stock levels are healthy.')}</tbody></table></div></div>`;
      },

      products() {
        const q = ui.q.trim().toLowerCase();
        const rows = s.prods.filter(p => (!q || p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q)) && (!ui.cat || String(p.cat || 0) === ui.cat) &&
          (!ui.status || (ui.status === 'in' ? statusOf(p)[1] === 'success' : ui.status === 'low' ? statusOf(p)[1] === 'warning' : statusOf(p)[1] === 'danger')));
        return `<h2 class="hd-title">Products</h2>
        <div class="hd-tools"><input class="form-control form-control-sm" data-demo="products-search" data-bind="q" value="${esc(ui.q)}" placeholder="Search name or SKU…" aria-label="Search products">
          <select class="form-select form-select-sm" data-demo="products-filter" data-bind="cat" aria-label="Category"><option value="">All categories</option><option value="0"${ui.cat === '0' ? ' selected' : ''}>Uncategorized</option>${opts2(s.cats, ui.cat, c => c.name)}</select>
          <select class="form-select form-select-sm" data-bind="status" aria-label="Status"><option value="">Any status</option><option value="in"${ui.status === 'in' ? ' selected' : ''}>In stock</option><option value="low"${ui.status === 'low' ? ' selected' : ''}>Low stock</option><option value="out"${ui.status === 'out' ? ' selected' : ''}>Out of stock</option></select>
          <button type="button" class="btn btn-primary btn-sm ms-auto" data-demo="products-add" data-act="open" data-v="product"><i class="bi bi-plus-lg"></i> Add product</button></div>
        <div class="hd-card" data-demo="products-table"><div class="table-responsive"><table class="table table-hover">${th('Product', 'Category', 'Price', 'On hand', 'Status', '')}<tbody>${rows.map(p => `<tr><td><span class="fw-medium">${esc(p.name)}</span><small class="d-block text-secondary">${esc(p.sku)}${p.track ? ' · batch &amp; expiry tracked' : ''}</small></td><td>${esc(catName(p.cat))}</td><td>${money(p.price)}</td><td>${p.qty}</td><td style="min-width:120px">${badge(...statusOf(p))}${meter(p)}</td>
          <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" aria-label="Edit" data-act="open" data-v="product" data-id="${p.id}"><i class="bi bi-pencil"></i></button> <button type="button" class="btn btn-sm btn-light text-danger" aria-label="Delete" data-act="del-product" data-id="${p.id}"><i class="bi bi-trash"></i></button></td></tr>`).join('') || empty(6, 'No products match your search.')}</tbody></table></div></div>`;
      },

      inventory() {
        const tabs = [['in', 'Stock in'], ['out', 'Stock out'], ['monitor', 'Stock monitoring']];
        let body = '';
        if (ui.tab === 'in') {
          body = `<form class="hd-card" data-form="in" data-demo="in-form" novalidate><h3>Record delivery</h3><div class="hd-pad row g-3">
            <div class="col-md-6"><label class="form-label">Product</label><select class="form-select" name="prod" data-demo="in-product"><option value="">Select a product</option>${opts2(s.prods, ui.pick, p => `${p.name} (${p.qty} on hand)`)}</select></div>
            <div class="col-md-6"><label class="form-label">Supplier</label><select class="form-select" name="sup" data-demo="in-supplier"><option value="">No supplier</option>${opts2(s.sups.filter(x => x.status === 'active'), '', x => x.name)}</select></div>
            <div class="col-12 d-none" data-demo="in-batchbox"><div class="row g-3"><div class="col-md-6"><label class="form-label">Batch / lot no.</label><input class="form-control" name="batch" data-demo="in-batch" maxlength="60"></div><div class="col-md-6"><label class="form-label">Expiry date</label><input type="date" class="form-control" name="exp" data-demo="in-expiry"></div></div></div>
            <div class="col-md-6"><label class="form-label">Quantity received</label><input type="number" min="1" class="form-control" name="qty" data-demo="in-qty"></div>
            <div class="col-md-6"><label class="form-label">Unit cost</label><input type="number" min="0" step="0.01" class="form-control" name="cost" data-demo="in-cost"></div>
            <div class="col-12"><div class="hd-err" data-err hidden></div><button class="btn btn-primary w-100" data-demo="in-submit">Add to inventory</button></div></div></form>`;
        } else if (ui.tab === 'out') {
          body = `<form class="hd-card" data-form="out" data-demo="out-form" novalidate><h3>Record release or sale</h3><div class="hd-pad row g-3">
            <div class="col-md-6"><label class="form-label">Product</label><select class="form-select" name="prod" data-demo="out-product"><option value="">Select a product</option>${opts2(s.prods, ui.pick, p => `${p.name} (${p.qty} on hand)`)}</select></div>
            <div class="col-md-6"><label class="form-label">Batch / lot</label><select class="form-select" name="batch" data-demo="out-batch"><option value="">Earliest expiry first (automatic)</option></select></div>
            <div class="col-md-6"><label class="form-label">Reason</label><select class="form-select" name="reason" data-demo="out-reason"><option value="sale">Sale</option><option value="release">Released for use</option><option value="damaged">Damaged or expired</option><option value="returned">Returned to supplier</option></select></div>
            <div class="col-md-6"><label class="form-label">Quantity</label><input type="number" min="1" class="form-control" name="qty" data-demo="out-qty"></div>
            <div class="col-12"><div class="hd-err" data-err hidden></div><button class="btn btn-primary w-100" data-demo="out-submit">Deduct from inventory</button></div></div></form>`;
        } else {
          const f = ui.monitor, rows = s.prods.filter(p => f === 'all' || (f === 'attention' && p.qty <= p.min) || (f === 'low' && p.qty > 0 && p.qty <= p.min) || (f === 'out' && p.qty <= 0) || (f === 'ok' && p.qty > p.min));
          body = `<div class="hd-tools"><select class="form-select form-select-sm" data-demo="monitor-filter" data-bind="monitor" aria-label="Filter stock status">${[['all', 'All products'], ['attention', 'Needs attention'], ['low', 'Low stock'], ['out', 'Out of stock'], ['ok', 'Healthy']].map(([v, l]) => `<option value="${v}"${f === v ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="hd-card" data-demo="monitor-table"><div class="table-responsive"><table class="table table-hover">${th('Product', 'On hand', 'Level', 'Minimum', '')}<tbody>${rows.map(p => `<tr><td class="fw-medium">${esc(p.name)}</td><td>${p.qty}</td><td style="min-width:130px">${badge(...statusOf(p))}${meter(p)}</td>
            <td><div class="d-flex gap-1"><input type="number" min="0" class="form-control form-control-sm" style="width:80px" value="${p.min}" data-minfor="${p.id}" aria-label="Minimum for ${esc(p.name)}"><button type="button" class="btn btn-sm btn-light" data-act="setmin" data-id="${p.id}">Set</button></div></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-demo="restock-btn" data-act="restock" data-id="${p.id}">Restock</button></td></tr>`).join('') || empty(5, 'Nothing in this view.')}</tbody></table></div></div>`;
        }
        const hist = ui.tab === 'monitor' ? '' : `<div class="hd-card mt-3" data-demo="movements"><h3>Recent movements</h3><div class="table-responsive"><table class="table table-hover">${th('Date', 'Reference', 'Product', 'Type', 'Qty')}<tbody>${s.moves.slice().reverse().slice(0, 7).map(m => `<tr><td>${fmtDate(m.date)}</td><td class="text-nowrap">${esc(m.ref)}</td><td>${esc((P(m.prod) || {}).name || '—')}</td><td>${badge(m.type === 'out' ? 'Out' : m.type === 'opening' ? 'Opening' : 'In', m.type === 'out' ? 'danger' : 'success')}</td><td>${m.type === 'out' ? '−' : '+'}${m.qty}</td></tr>`).join('')}</tbody></table></div></div>`;
        return `<h2 class="hd-title">Stock in / out</h2><div class="hd-tabs">${tabs.map(([k, l]) => `<button type="button" data-demo="tab-${k}" data-act="tab" data-v="${k}" class="${ui.tab === k ? 'active' : ''}">${l}</button>`).join('')}</div>${body}${hist}`;
      },

      categories() {
        return `<h2 class="hd-title">Categories <button type="button" class="btn btn-primary btn-sm ms-auto" data-demo="cat-add" data-act="open" data-v="category"><i class="bi bi-plus-lg"></i> Add category</button></h2>
        <div class="hd-card"><div class="table-responsive"><table class="table table-hover">${th('Name', 'Description', 'Products', '')}<tbody>${s.cats.map(c => `<tr><td class="fw-medium">${esc(c.name)}</td><td class="text-secondary">${esc(c.desc)}</td><td>${s.prods.filter(p => p.cat === c.id).length}</td><td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" aria-label="Edit" data-act="open" data-v="category" data-id="${c.id}"><i class="bi bi-pencil"></i></button> <button type="button" class="btn btn-sm btn-light text-danger" aria-label="Delete" data-act="del-cat" data-id="${c.id}"><i class="bi bi-trash"></i></button></td></tr>`).join('') || empty(4, 'No categories yet.')}</tbody></table></div></div>
        <p class="small text-secondary mt-2 mb-0">Deleting a category never deletes products — they become “Uncategorized”.</p>`;
      },

      suppliers() {
        const q = ui.supq.trim().toLowerCase(), rows = s.sups.filter(x => (!q || (x.name + x.contact + x.code).toLowerCase().includes(q)) && (!ui.supf || x.status === ui.supf));
        return `<h2 class="hd-title">Suppliers</h2><div class="hd-tools"><input class="form-control form-control-sm" data-demo="suppliers-search" data-bind="supq" value="${esc(ui.supq)}" placeholder="Search suppliers…" aria-label="Search suppliers">
          <select class="form-select form-select-sm" data-bind="supf" aria-label="Status"><option value="">All</option><option value="active"${ui.supf === 'active' ? ' selected' : ''}>Active</option><option value="inactive"${ui.supf === 'inactive' ? ' selected' : ''}>Inactive</option></select>
          <button type="button" class="btn btn-primary btn-sm ms-auto" data-demo="suppliers-add" data-act="open" data-v="supplier"><i class="bi bi-plus-lg"></i> Add supplier</button></div>
        <div class="hd-card" data-demo="suppliers-table"><div class="table-responsive"><table class="table table-hover">${th('Code', 'Supplier', 'Contact', 'Status', '')}<tbody>${rows.map(x => `<tr><td class="text-nowrap">${esc(x.code)}</td><td><span class="fw-medium">${esc(x.name)}</span><small class="d-block text-secondary">${esc(x.contact)}</small></td><td><small>${esc(x.phone)}<br>${esc(x.email)}</small></td><td>${badge(x.status === 'active' ? 'Active' : 'Inactive', x.status === 'active' ? 'success' : 'muted')}</td>
          <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" aria-label="Edit" data-act="open" data-v="supplier" data-id="${x.id}"><i class="bi bi-pencil"></i></button> <button type="button" class="btn btn-sm btn-light text-danger" aria-label="Delete" data-act="del-sup" data-id="${x.id}"><i class="bi bi-trash"></i></button></td></tr>`).join('') || empty(5, 'No suppliers match.')}</tbody></table></div></div>
        <p class="small text-secondary mt-2 mb-0">A supplier with purchase orders cannot be deleted — set it to Inactive instead.</p>`;
      },

      orders() {
        const stc = { draft: 'muted', ordered: 'info', partial: 'warning', received: 'success', cancelled: 'danger' };
        return `<h2 class="hd-title">Purchase orders <button type="button" class="btn btn-primary btn-sm ms-auto" data-demo="po-new" data-act="open" data-v="po"><i class="bi bi-plus-lg"></i> New purchase order</button></h2>
        <div class="hd-card" data-demo="po-table"><div class="table-responsive"><table class="table table-hover">${th('PO no.', 'Supplier', 'Date', 'Items', 'Total', 'Status', '')}<tbody>${s.pos.slice().reverse().map(po => {
          const rec = po.lines.some(l => l.rec > 0);
          return `<tr><td class="fw-medium text-nowrap">${esc(po.no)}</td><td>${esc((S(po.sup) || {}).name)}</td><td>${fmtDate(po.date)}</td><td>${po.lines.map(l => `${esc((P(l.prod) || {}).name)} × ${l.qty}`).join('<br>')}</td><td>${money(poTotal(po))}</td><td>${badge(po.status[0].toUpperCase() + po.status.slice(1), stc[po.status])}</td>
          <td class="text-end text-nowrap">${po.status === 'draft' ? `<button type="button" class="btn btn-sm btn-primary" data-act="po-place" data-id="${po.id}">Place</button> ` : ''}${po.status === 'ordered' || po.status === 'partial' ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-demo="po-receive" data-act="open" data-v="receive" data-id="${po.id}">Receive</button> ` : ''}${po.status === 'draft' || (po.status === 'ordered' && !rec) ? `<button type="button" class="btn btn-sm btn-light text-danger" data-act="po-cancel" data-id="${po.id}">${po.status === 'draft' ? 'Delete' : 'Cancel'}</button>` : ''}</td></tr>`;
        }).join('') || empty(7, 'No purchase orders yet.')}</tbody></table></div></div>`;
      },

      expiry() {
        const ec = expCounts(), f = ui.expiry;
        const rows = s.batches.filter(b => b.qty > 0).map(b => ({ b, st: expState(b) })).filter(r => f === 'all' || (f === 'expired' && r.st[0] === 'Expired') || (f === 'near' && r.st[0] === 'Near expiry') || (f === 'valid' && r.st[0] === 'Valid')).sort((a, b) => a.b.exp.localeCompare(b.b.exp));
        return `<h2 class="hd-title">Expiry tracking <small class="text-secondary fw-normal">near-expiry window: ${NEAR_DAYS} days</small></h2>
        <div class="hd-grid hd-stats" data-demo="expiry-summary"><div class="hd-stat"><small>Expired</small><b class="text-danger">${ec.expired}</b></div><div class="hd-stat"><small>Near expiry</small><b style="color:var(--amber)">${ec.near}</b></div><div class="hd-stat"><small>Valid</small><b class="hd-ok">${ec.valid}</b></div></div>
        <div class="hd-tools"><select class="form-select form-select-sm" data-demo="expiry-filter" data-bind="expiry" aria-label="Filter batches">${[['all', 'All batches'], ['expired', 'Expired'], ['near', 'Near expiry'], ['valid', 'Valid']].map(([v, l]) => `<option value="${v}"${f === v ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
        <div class="hd-card" data-demo="expiry-table"><div class="table-responsive"><table class="table table-hover">${th('Medicine', 'Batch / lot', 'Supplier', 'Expiry date', 'Qty left', 'Status', '')}<tbody>${rows.map(({ b, st }) => `<tr><td class="fw-medium">${esc((P(b.prod) || {}).name)}</td><td>${esc(b.no)}</td><td>${esc((S(b.sup) || {}).name || '—')}</td><td>${fmtDate(b.exp)}</td><td>${b.qty}</td><td>${badge(st[0] === 'Expired' ? 'Expired ' + (-st[2]) + ' d ago' : st[0] === 'Near expiry' ? st[2] + ' days left' : 'Valid', st[1])}</td>
          <td class="text-end text-nowrap">${st[0] === 'Expired' ? `<button type="button" class="btn btn-sm btn-outline-danger" data-demo="expiry-writeoff" data-act="writeoff" data-id="${b.id}">Write off</button>` : `<button type="button" class="btn btn-sm btn-outline-secondary" data-act="release" data-id="${b.prod}">Release</button>`}</td></tr>`).join('') || empty(7, 'No batches in this view.')}</tbody></table></div></div>`;
      },

      reports() {
        const R = ui.report, types = [['stock', 'Stock levels'], ['stockin', 'Stock in (deliveries)'], ['stockout', 'Stock out (releases and sales)'], ['expiry', 'Expiry and batches'], ['po', 'Purchase orders'], ['suppliers', 'Suppliers']];
        return `<h2 class="hd-title">Reports</h2><div class="hd-card"><div class="hd-pad"><div class="row g-3 align-items-end">
          <div class="col-md-4"><label class="form-label">Report</label><select class="form-select" data-demo="report-type" data-bind="rtype">${types.map(([v, l]) => `<option value="${v}"${ui.rtype === v ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="col-md-5" data-demo="report-dates"><div class="row g-2"><div class="col-6"><label class="form-label">From</label><input type="date" class="form-control" data-demo="report-from" data-bind="rfrom" value="${ui.rfrom}"></div><div class="col-6"><label class="form-label">To</label><input type="date" class="form-control" data-demo="report-to" data-bind="rto" value="${ui.rto}"></div></div></div>
          <div class="col-md-3 d-flex gap-2"><button type="button" class="btn btn-primary flex-fill" data-demo="report-generate" data-act="report">Generate</button></div></div></div></div>
        <div class="hd-card mt-3" data-demo="report-result"><div class="d-flex align-items-center gap-2 flex-wrap"><h3 class="me-auto">${R ? esc(R.title) : 'Report'}</h3><div class="pe-3 pt-2 d-flex gap-2" data-demo="report-export"><button type="button" class="btn btn-sm btn-light" data-act="csv"><i class="bi bi-download"></i> CSV</button><button type="button" class="btn btn-sm btn-light" data-act="print"><i class="bi bi-printer"></i> Print</button></div></div>
          ${R ? `<div class="table-responsive"><table class="table">${th(...R.cols)}<tbody>${R.rows.map(r => '<tr>' + r.map(c => `<td>${esc(c)}</td>`).join('') + '</tr>').join('') || empty(R.cols.length, 'No data for this period.')}</tbody>${R.foot ? `<tfoot><tr class="fw-semibold">${R.foot.map(c => `<td>${esc(c)}</td>`).join('')}</tr></tfoot>` : ''}</table></div>` : '<div class="empty">Choose a report and click <b>Generate</b>.</div>'}</div>`;
      },

      users() {
        const adm = ui.role === 'admin';
        const menu = adm ? ['Dashboard', 'Users', 'Inventories', 'Products', 'Transactions', 'Reports', 'Activity logs', 'Settings'] : ['Dashboard', 'Products', 'Categories', 'Suppliers', 'Purchase orders', 'Stock in', 'Stock out', 'Stock monitoring', 'Expiry tracking', 'Reports', 'My inventories', 'Profile & settings'];
        const ok = '<i class="bi bi-check-circle-fill hd-ok"></i> ', no = '<i class="bi bi-x-circle-fill hd-no"></i> ', ro = '<i class="bi bi-eye-fill" style="color:var(--amber)"></i> ';
        const perms = [['Own inventories, products, suppliers, orders', ok + 'Full control', ro + 'Read-only inspection'], ['Record stock in / stock out', ok + 'Yes', no + 'No'], ['Reports', ok + 'Own inventory', ok + 'Platform-wide'], ['Add, edit or suspend users', no + 'No', ok + 'Yes'], ['Activity log', no + 'No', ok + 'Yes'], ['System settings', no + 'No', ok + 'Yes']];
        return `<h2 class="hd-title">User management</h2>
        <div class="hd-tools"><span class="small text-secondary">Viewing the system as:</span><div class="hd-role" data-demo="role-toggle" role="group" aria-label="Role"><button type="button" data-act="role" data-v="user" class="${!adm ? 'active' : ''}">Regular user</button><button type="button" data-act="role" data-v="admin" class="${adm ? 'active' : ''}">Administrator</button></div></div>
        <div class="hd-grid hd-two"><div class="hd-card" data-demo="role-menu"><h3>Menu this role sees</h3><div class="hd-pad">${menu.map(m => `<span class="hd-menu-chip">${esc(m)}</span>`).join('')}</div></div>
          <div class="hd-card"><h3>Try opening a page</h3><div class="hd-pad d-grid gap-2"><button type="button" class="btn btn-light btn-sm text-start" data-demo="role-try-admin" data-act="try" data-v="settings"><i class="bi bi-sliders me-1"></i> Open <b>Settings</b> (administrator page)</button><button type="button" class="btn btn-light btn-sm text-start" data-act="try" data-v="stock"><i class="bi bi-box-arrow-in-down me-1"></i> Open <b>Stock in</b> (inventory page)</button>
            ${ui.tryMsg ? `<div class="alert alert-${ui.tryMsg[1]} py-2 mb-0 small">${esc(ui.tryMsg[0])}</div>` : ''}</div></div></div>
        <div class="hd-card mt-3" data-demo="perm-table"><h3>Permissions</h3><div class="table-responsive"><table class="table">${th('Area', 'Regular user', 'Administrator')}<tbody>${perms.map(r => `<tr><td>${esc(r[0])}</td><td style="${!adm ? 'background:var(--accent-soft)' : ''}">${r[1]}</td><td style="${adm ? 'background:var(--accent-soft)' : ''}">${r[2]}</td></tr>`).join('')}</tbody></table></div></div>
        <div class="hd-card mt-3"><h3>Accounts <button type="button" class="btn btn-primary btn-sm float-end" style="margin-top:-.2rem" data-act="adduser"><i class="bi bi-person-plus"></i> Add user</button></h3><div class="table-responsive"><table class="table">${th('Name', 'Role', 'Status', '')}<tbody>${s.users.map(u => `<tr><td class="fw-medium">${esc(u.name)}<small class="d-block text-secondary">@${esc(u.username)}</small></td><td>${badge(u.role === 'admin' ? 'Administrator' : 'User', u.role === 'admin' ? 'info' : 'muted')}</td><td>${badge(u.status[0].toUpperCase() + u.status.slice(1), u.status === 'active' ? 'success' : 'danger')}</td><td class="text-end">${u.role !== 'admin' ? `<button type="button" class="btn btn-sm btn-light" data-act="togglestatus" data-id="${u.id}">${u.status === 'active' ? 'Suspend' : 'Activate'}</button>` : ''}</td></tr>`).join('')}</tbody></table></div></div>`;
      },
    };

    /* ---------- modals ---------- */
    const field = (label, inner, cls = 'col-12') => `<div class="${cls}"><label class="form-label">${label}</label>${inner}</div>`;
    function modalHtml(k, id) {
      const done = (title, body, foot) => `<div class="hd-modal" role="dialog" aria-modal="true" aria-label="${esc(title)}"><header><h3>${esc(title)}</h3><button type="button" class="btn-close" data-act="close" aria-label="Close"></button></header><form data-form="${k}" novalidate><div class="hd-body">${body}<div class="hd-err" data-err hidden></div></div><footer>${foot}</footer></form></div>`;
      if (k === 'product') {
        const p = id ? P(id) : null;
        return done(p ? 'Edit product' : 'Add product', `<input type="hidden" name="id" value="${p ? p.id : ''}"><div class="row g-3">
          ${field('SKU / product code', `<input class="form-control" name="sku" data-demo="pm-sku" maxlength="50" value="${esc(p && p.sku)}">`, 'col-md-5')}${field('Product name', `<input class="form-control" name="name" data-demo="pm-name" maxlength="150" value="${esc(p && p.name)}">`, 'col-md-7')}
          <div class="col-12" data-demo="pm-prices"><div class="row g-3">${field('Category', `<select class="form-select" name="cat" data-demo="pm-category"><option value="">Uncategorized</option>${opts2(s.cats, p && p.cat, c => c.name)}</select>`, 'col-md-4')}${field('Cost price', `<input type="number" min="0" step="0.01" class="form-control" name="cost" data-demo="pm-cost" value="${p ? p.cost : ''}">`, 'col-md-4 col-6')}${field('Selling price', `<input type="number" min="0" step="0.01" class="form-control" name="price" data-demo="pm-price" value="${p ? p.price : ''}">`, 'col-md-4 col-6')}</div></div>
          <div class="col-12" data-demo="pm-stock"><div class="row g-3">${p ? '' : field('Opening quantity', `<input type="number" min="0" class="form-control" name="qty" data-demo="pm-qty" value="0">`, 'col-6')}${field('Minimum stock level', `<input type="number" min="0" class="form-control" name="min" data-demo="pm-min" value="${p ? p.min : 10}">`, p ? 'col-12' : 'col-6')}</div></div>
          <div class="col-12" data-demo="pm-track"><div class="form-check"><input class="form-check-input" type="checkbox" name="track" id="hdTrack" ${p && p.track ? 'checked' : ''}><label class="form-check-label" for="hdTrack">Track batch / lot number and expiry date</label></div>
            ${p ? '' : `<div class="row g-3 mt-0 ${p && p.track ? '' : 'd-none'}" data-trackbox><div class="col-md-6"><label class="form-label">Opening batch / lot no.</label><input class="form-control" name="batch" data-demo="pm-batch" maxlength="60"></div><div class="col-md-6"><label class="form-label">Opening expiry date</label><input type="date" class="form-control" name="exp" data-demo="pm-expiry"></div></div>`}</div></div>
          ${p ? '<p class="small text-secondary mt-3 mb-0">To change the quantity, record a stock in or stock out so the history stays accurate.</p>' : ''}`,
          `<button type="button" class="btn btn-light" data-act="close">Cancel</button><button class="btn btn-primary" data-demo="pm-save">Save product</button>`);
      }
      if (k === 'category') {
        const c = id ? C(id) : null;
        return done(c ? 'Edit category' : 'Add category', `<input type="hidden" name="id" value="${c ? c.id : ''}"><div class="row g-3">${field('Name', `<input class="form-control" name="name" data-demo="cm-name" maxlength="100" value="${esc(c && c.name)}">`)}${field('Description', `<input class="form-control" name="desc" data-demo="cm-desc" maxlength="255" value="${esc(c && c.desc)}">`)}</div>`,
          `<button type="button" class="btn btn-light" data-act="close">Cancel</button><button class="btn btn-primary" data-demo="cm-save">Save category</button>`);
      }
      if (k === 'supplier') {
        const x = id ? S(id) : null;
        return done(x ? 'Edit supplier' : 'Add supplier', `<input type="hidden" name="id" value="${x ? x.id : ''}"><div class="row g-3">
          <div class="col-12" data-demo="sm-basic"><div class="row g-3">${field('Supplier name', `<input class="form-control" name="name" data-demo="sm-name" maxlength="120" value="${esc(x && x.name)}">`, 'col-md-7')}${field('Contact person', `<input class="form-control" name="contact" data-demo="sm-contact" maxlength="100" value="${esc(x && x.contact)}">`, 'col-md-5')}</div></div>
          <div class="col-12" data-demo="sm-contactinfo"><div class="row g-3">${field('Phone', `<input class="form-control" name="phone" data-demo="sm-phone" maxlength="40" value="${esc(x && x.phone)}">`, 'col-md-5')}${field('Email', `<input type="email" class="form-control" name="email" data-demo="sm-email" maxlength="120" value="${esc(x && x.email)}">`, 'col-md-7')}</div></div>
          ${field('Address', `<input class="form-control" name="address" data-demo="sm-address" maxlength="255" value="${esc(x && x.address)}">`, 'col-md-8')}${field('Status', `<select class="form-select" name="status" data-demo="sm-status"><option value="active"${!x || x.status === 'active' ? ' selected' : ''}>Active</option><option value="inactive"${x && x.status === 'inactive' ? ' selected' : ''}>Inactive</option></select>`, 'col-md-4')}</div>`,
          `<button type="button" class="btn btn-light" data-act="close">Cancel</button><button class="btn btn-primary" data-demo="sm-save">Save supplier</button>`);
      }
      if (k === 'po') {
        return done('New purchase order', `<div class="row g-3">${field('Supplier', `<select class="form-select" name="sup" data-demo="po-supplier"><option value="">Select a supplier</option>${opts2(s.sups.filter(x => x.status === 'active'), '', x => x.name)}</select>`, 'col-md-6')}${field('Expected delivery', `<input type="date" class="form-control" name="exp" value="${addDays(7)}">`, 'col-md-6')}
          <div class="col-12" data-demo="po-lines"><label class="form-label">Items</label><div class="row g-2 align-items-end"><div class="col-md-5"><select class="form-select form-select-sm" name="lp" data-demo="po-product"><option value="">Product…</option>${opts2(s.prods, '', p => p.name)}</select></div><div class="col-4 col-md-2"><input type="number" min="1" class="form-control form-control-sm" name="lq" data-demo="po-qty" placeholder="Qty"></div><div class="col-4 col-md-3"><input type="number" min="0" step="0.01" class="form-control form-control-sm" name="lc" data-demo="po-cost" placeholder="Unit cost"></div><div class="col-4 col-md-2"><button type="button" class="btn btn-light btn-sm w-100" data-demo="po-add-line" data-act="po-addline">Add</button></div></div><div class="mt-2" data-lines></div></div></div>`,
          `<button type="button" class="btn btn-light" data-act="close">Cancel</button><button type="button" class="btn btn-light" data-demo="po-save-draft" data-act="po-save" data-v="draft">Save as draft</button><button type="button" class="btn btn-primary" data-demo="po-place" data-act="po-save" data-v="ordered">Place order</button>`);
      }
      if (k === 'receive') {
        const po = P_O(id);
        return done('Receive ' + po.no, `<input type="hidden" name="id" value="${po.id}"><div data-demo="receive-form">${po.lines.map((l, i) => { const pr = P(l.prod), rem = l.qty - l.rec; return `<div class="row g-2 align-items-end mb-2"><div class="col-12 fw-medium">${esc(pr.name)} <small class="text-secondary">(${rem} remaining)</small></div>
          <div class="col-4"><label class="form-label small mb-0">Received now</label><input type="number" min="0" max="${rem}" class="form-control form-control-sm" name="r${i}" data-demo="rc-qty-${i}" value="${rem}"></div>
          ${pr.track ? `<div class="col-4"><label class="form-label small mb-0">Batch / lot no.</label><input class="form-control form-control-sm" name="b${i}" value="B${po.no.slice(-2)}${pr.id}${iso(new Date()).slice(5, 7)}"></div><div class="col-4"><label class="form-label small mb-0">Expiry date</label><input type="date" class="form-control form-control-sm" name="e${i}" value="${addDays(365)}"></div>` : ''}</div>`; }).join('')}</div>`,
          `<button type="button" class="btn btn-light" data-act="close">Cancel</button><button class="btn btn-primary" data-demo="rc-confirm">Receive stock</button>`);
      }
      return '';
    }
    const P_O = id => s.pos.find(p => p.id === +id);

    function openModal(k, id) {
      if (k === 'receive' && !id) { const po = s.pos.slice().reverse().find(p => p.status === 'ordered' || p.status === 'partial'); if (!po) return toast('No order is waiting to be received.', true); id = po.id; }
      modal = { k, id }; ui.poLines = [];
      layer.innerHTML = modalHtml(k, id); layer.hidden = false;
      const first = layer.querySelector('input:not([type=hidden]),select'); if (first && !opts.noAutofocus) first.focus({ preventScroll: true });
    }
    function closeModal() { modal = null; layer.hidden = true; layer.innerHTML = ''; }
    function showErr(form, msg) { const e = form.querySelector('[data-err]'); if (e) { e.textContent = msg; e.hidden = !msg; } if (msg) toast(msg, true); }
    function renderLines() {
      const box = layer.querySelector('[data-lines]'); if (!box) return;
      box.innerHTML = ui.poLines.length ? '<ul class="list-group list-group-flush small">' + ui.poLines.map((l, i) => `<li class="list-group-item d-flex justify-content-between align-items-center px-0">${esc(P(l.prod).name)} × ${l.qty} @ ${money(l.cost)}<button type="button" class="btn btn-sm btn-light text-danger" data-act="po-rmline" data-id="${i}" aria-label="Remove"><i class="bi bi-x-lg"></i></button></li>`).join('') + '</ul>' : '<div class="text-secondary small">No items added yet.</div>';
    }

    /* ---------- form handlers (all local) ---------- */
    const num = v => v === '' || v == null ? NaN : Number(v);
    const SUBMIT = {
      product(f) {
        const g = n => f.elements[n] ? f.elements[n].value.trim() : '', id = +g('id'), sku = g('sku'), name = g('name'), track = f.elements.track.checked;
        const cost = g('cost') === '' ? 0 : num(g('cost')), price = g('price') === '' ? 0 : num(g('price')), min = g('min') === '' ? 0 : num(g('min')), qty = id ? 0 : (g('qty') === '' ? 0 : num(g('qty')));
        if (!sku || !name) return showErr(f, 'SKU and product name are required.');
        if (s.prods.some(p => p.sku.toLowerCase() === sku.toLowerCase() && p.id !== id)) return showErr(f, 'That SKU already exists in this inventory.');
        if ([cost, price].some(n => isNaN(n) || n < 0)) return showErr(f, 'Prices cannot be negative.');
        if ([min, qty].some(n => isNaN(n) || n < 0 || !Number.isInteger(n))) return showErr(f, 'Quantities must be whole numbers, zero or more.');
        if (!id && track && qty > 0 && (!g('batch') || !g('exp'))) return showErr(f, 'Enter the opening batch / lot number and expiry date.');
        const cat = g('cat') ? +g('cat') : null;
        if (id) { Object.assign(P(id), { sku, name, cat, cost, price, min, track }); toast('Product updated.'); }
        else {
          const p = { id: nextId('prod'), sku, name, cat, cost, price, qty, min, track }; s.prods.push(p);
          if (qty > 0) { pushMove('opening', p.id, qty, ref('OPEN'), 'opening'); if (track) s.batches.push({ id: nextId('batch'), prod: p.id, no: g('batch'), exp: g('exp'), qty, sup: null }); }
          toast('Product added.');
        }
        closeModal(); render();
      },
      category(f) {
        const id = +f.elements.id.value, name = f.elements.name.value.trim(), desc = f.elements.desc.value.trim();
        if (!name) return showErr(f, 'Category name is required.');
        if (s.cats.some(c => c.name.toLowerCase() === name.toLowerCase() && c.id !== id)) return showErr(f, 'A category with that name already exists.');
        if (id) Object.assign(C(id), { name, desc }); else s.cats.push({ id: nextId('cat'), name, desc });
        toast(id ? 'Category updated.' : 'Category added.'); closeModal(); render();
      },
      supplier(f) {
        const g = n => f.elements[n].value.trim(), id = +g('id'), name = g('name');
        if (!name) return showErr(f, 'Supplier name is required (up to 120 characters).');
        if (!g('contact')) return showErr(f, 'Contact person is required.');
        if (!/^[0-9+\-()\s]{7,40}$/.test(g('phone'))) return showErr(f, 'Enter a valid phone number (digits, spaces, + - ( ) only; at least 7 characters).');
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(g('email'))) return showErr(f, 'Enter a valid supplier email address.');
        if (s.sups.some(x => x.name.toLowerCase() === name.toLowerCase() && x.id !== id)) return showErr(f, 'A supplier with that name already exists.');
        const d = { name, contact: g('contact'), phone: g('phone'), email: g('email'), address: g('address'), status: g('status') };
        if (id) Object.assign(S(id), d); else { const n = nextId('sup'); s.sups.push(Object.assign({ id: n, code: 'SUP-' + String(n).padStart(4, '0') }, d)); }
        toast(id ? 'Supplier updated.' : 'Supplier registered.'); closeModal(); render();
      },
      in(f) {
        const g = n => f.elements[n].value.trim(), p = P(g('prod')), qty = num(g('qty')), cost = g('cost') === '' ? 0 : num(g('cost'));
        if (!p || isNaN(qty) || qty < 1 || !Number.isInteger(qty)) return showErr(f, 'Choose a product and enter a quantity of at least 1.');
        if (isNaN(cost) || cost < 0) return showErr(f, 'Unit cost cannot be negative.');
        if (p.track) {
          if (!g('sup')) return showErr(f, 'Choose the supplier of this medicine so every batch can be traced back to its source.');
          if (!g('batch') || !g('exp')) return showErr(f, 'Enter the batch / lot number and the expiry date.');
          const ex = s.batches.find(b => b.prod === p.id && b.no === g('batch'));
          if (ex) { ex.qty += qty; ex.exp = g('exp'); } else s.batches.push({ id: nextId('batch'), prod: p.id, no: g('batch'), exp: g('exp'), qty, sup: +g('sup') });
        }
        p.qty += qty; pushMove('in', p.id, qty, ref('IN'), 'Delivery');
        ui.pick = ''; toast(`Added ${qty} × ${p.name}. New balance: ${p.qty}.`); render();
      },
      out(f) {
        const g = n => f.elements[n].value.trim(), p = P(g('prod')), qty = num(g('qty')), reason = g('reason');
        if (!p || isNaN(qty) || qty < 1 || !Number.isInteger(qty)) return showErr(f, 'Choose a product and enter a quantity of at least 1.');
        const writeoff = reason === 'damaged' || reason === 'returned';
        const mine = s.batches.filter(b => b.prod === p.id && b.qty > 0).sort((a, b) => a.exp.localeCompare(b.exp));
        const batched = mine.reduce((t, b) => t + b.qty, 0), loose = Math.max(0, p.qty - batched);
        if (g('batch')) {
          const b = s.batches.find(x => x.id === +g('batch'));
          if (b && expState(b)[0] === 'Expired' && !writeoff) return showErr(f, 'Expired batches cannot be sold or released. Use “Damaged or expired”.');
          if (!b || qty > b.qty) return showErr(f, `Not enough stock in that batch. Available: ${b ? b.qty : 0}`);
          b.qty -= qty;
        } else {
          const usable = mine.filter(b => writeoff || expState(b)[0] !== 'Expired'), avail = usable.reduce((t, b) => t + b.qty, 0) + loose;
          if (qty > avail) return showErr(f, `Not enough stock for ${p.name}. Available: ${avail}`);
          let need = qty; for (const b of usable) { const t = Math.min(need, b.qty); b.qty -= t; need -= t; if (!need) break; }
        }
        p.qty -= qty; pushMove('out', p.id, qty, ref('OUT'), reason);
        ui.pick = ''; toast(`Removed ${qty} × ${p.name}. New balance: ${p.qty}.`); render();
      },
      receive(f) {
        const po = P_O(f.elements.id.value); let any = false;
        for (let i = 0; i < po.lines.length; i++) { const n = num(f.elements['r' + i].value), rem = po.lines[i].qty - po.lines[i].rec; if (isNaN(n) || n < 0 || n > rem || !Number.isInteger(n)) return showErr(f, 'Received quantity cannot be more than what is still on order.'); if (n > 0) any = true; }
        if (!any) return showErr(f, 'Enter a received quantity for at least one item.');
        for (let i = 0; i < po.lines.length; i++) {
          const l = po.lines[i], n = num(f.elements['r' + i].value), p = P(l.prod); if (!n) continue;
          if (p.track) { const no = f.elements['b' + i].value.trim(), ex = f.elements['e' + i].value; if (!no || !ex) return showErr(f, 'Enter batch / lot number and expiry date for tracked items.'); }
        }
        po.lines.forEach((l, i) => {
          const n = num(f.elements['r' + i].value), p = P(l.prod); if (!n) return;
          if (p.track) { const no = f.elements['b' + i].value.trim(), ex = f.elements['e' + i].value, b = s.batches.find(x => x.prod === p.id && x.no === no); if (b) { b.qty += n; b.exp = ex; } else s.batches.push({ id: nextId('batch'), prod: p.id, no, exp: ex, qty: n, sup: po.sup }); }
          p.qty += n; l.rec += n; pushMove('in', p.id, n, po.no, 'Received against ' + po.no);
        });
        po.status = po.lines.every(l => l.rec >= l.qty) ? 'received' : 'partial';
        toast(`${po.no} is now ${po.status}. Stock levels updated.`); closeModal(); render();
      },
    };

    /* ---------- reports ---------- */
    function buildReport() {
      const a = ui.rfrom, b = ui.rto, inR = d => d >= a && d <= b, t = ui.rtype;
      if (a > b) return toast('The “From” date must be before the “To” date.', true);
      if (t === 'stock') return { title: 'Stock levels', cols: ['Product', 'Category', 'On hand', 'Minimum', 'Status', 'Value'], rows: s.prods.map(p => [p.name, catName(p.cat), p.qty, p.min, statusOf(p)[0], money(p.qty * p.cost)]), foot: ['Total', '', s.prods.reduce((x, p) => x + p.qty, 0), '', '', money(s.prods.reduce((x, p) => x + p.qty * p.cost, 0))] };
      if (t === 'stockin' || t === 'stockout') { const m = s.moves.filter(x => (t === 'stockin' ? x.type !== 'out' : x.type === 'out') && inR(x.date)); return { title: (t === 'stockin' ? 'Stock in' : 'Stock out') + ` — ${fmtDate(a)} to ${fmtDate(b)}`, cols: ['Date', 'Reference', 'Product', 'Quantity', 'Note'], rows: m.map(x => [fmtDate(x.date), x.ref, (P(x.prod) || {}).name, x.qty, x.note]), foot: ['Total', '', '', m.reduce((q, x) => q + x.qty, 0), ''] }; }
      if (t === 'expiry') return { title: 'Expiry and batches', cols: ['Medicine', 'Batch', 'Expiry', 'Qty left', 'Status'], rows: s.batches.filter(x => x.qty > 0).map(x => [(P(x.prod) || {}).name, x.no, fmtDate(x.exp), x.qty, expState(x)[0]]) };
      if (t === 'po') { const o = s.pos.filter(x => inR(x.date)); return { title: `Purchase orders — ${fmtDate(a)} to ${fmtDate(b)}`, cols: ['PO no.', 'Supplier', 'Date', 'Status', 'Total'], rows: o.map(x => [x.no, (S(x.sup) || {}).name, fmtDate(x.date), x.status, money(poTotal(x))]), foot: ['Total', '', '', '', money(o.reduce((q, x) => q + poTotal(x), 0))] }; }
      return { title: 'Suppliers', cols: ['Code', 'Supplier', 'Status', 'Orders', 'Ordered value'], rows: s.sups.map(x => { const o = s.pos.filter(p => p.sup === x.id); return [x.code, x.name, x.status, o.length, money(o.reduce((q, p) => q + poTotal(p), 0))]; }) };
    }

    /* ---------- events ---------- */
    function inputEvent(e) {
      const t = e.target, b = t.dataset && t.dataset.bind;
      if (b) { ui[b] = t.value; keepFocus(render); return; }
      if (!t.form) return;
      const f = t.form, k = f.dataset.form;
      if (k === 'product' && t.name === 'track') { const box = f.querySelector('[data-trackbox]'); if (box) box.classList.toggle('d-none', !t.checked); }
      if (k === 'in' && t.name === 'prod') { const p = P(t.value), box = f.querySelector('[data-demo="in-batchbox"]'); ui.pick = t.value; box.classList.toggle('d-none', !(p && p.track)); }
      if (k === 'out' && t.name === 'prod') {
        ui.pick = t.value; const sel = f.elements.batch; sel.innerHTML = '<option value="">Earliest expiry first (automatic)</option>' +
          s.batches.filter(b => b.prod === +t.value && b.qty > 0).sort((a, b) => a.exp.localeCompare(b.exp)).map(b => `<option value="${b.id}">${esc(b.no)} · exp ${fmtDate(b.exp)} · ${b.qty} left${expState(b)[0] === 'Expired' ? ' (expired)' : ''}</option>`).join('');
      }
    }
    app.addEventListener('input', inputEvent);
    app.addEventListener('change', inputEvent);
    app.addEventListener('submit', e => { e.preventDefault(); const f = e.target, h = SUBMIT[f.dataset.form]; if (h) { showErr(f, ''); h(f); } });
    layer.addEventListener('mousedown', e => { if (e.target === layer) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal && !document.querySelector('.hd-coach')) closeModal(); });

    app.addEventListener('click', e => {
      const el = e.target.closest('[data-act]'); if (!el || !app.contains(el)) return;
      const a = el.dataset.act, v = el.dataset.v, id = el.dataset.id;
      switch (a) {
        case 'nav': go(v); break;
        case 'tab': ui.tab = v; ui.pick = ''; render(); break;
        case 'open': openModal(v, id); break;
        case 'close': closeModal(); break;
        case 'restock': ui.module = 'inventory'; ui.tab = 'in'; ui.pick = id; render(); { const sel = key('in-product'); if (sel) { sel.value = id; sel.dispatchEvent(new Event('change', { bubbles: true })); } } break;
        case 'release': ui.module = 'inventory'; ui.tab = 'out'; ui.pick = id; render(); { const sel = key('out-product'); if (sel) { sel.value = id; sel.dispatchEvent(new Event('change', { bubbles: true })); } } break;
        case 'setmin': { const n = num(main.querySelector(`[data-minfor="${id}"]`).value); if (isNaN(n) || n < 0 || !Number.isInteger(n)) return toast('Enter a whole number, zero or more.', true); P(id).min = n; toast('Minimum level updated.'); render(); break; }
        case 'del-product': { const p = P(id); if (s.moves.some(m => m.prod === p.id && m.type !== 'opening') || s.pos.some(o => o.lines.some(l => l.prod === p.id)) || s.batches.some(b => b.prod === p.id && b.qty > 0 && p.qty > 0)) return toast('This product has stock history, so it is kept for your records. (Demo rule.)', true); if (confirm('Delete this product? (Demo data only)')) { s.prods = s.prods.filter(x => x.id !== p.id); s.moves = s.moves.filter(m => m.prod !== p.id); toast('Product deleted.'); render(); } break; }
        case 'del-cat': if (confirm('Delete this category? Its products become uncategorized.')) { s.prods.forEach(p => { if (p.cat === +id) p.cat = null; }); s.cats = s.cats.filter(c => c.id !== +id); toast('Category deleted.'); render(); } break;
        case 'del-sup': if (s.pos.some(o => o.sup === +id)) return toast('This supplier has purchase orders or deliveries on record, so it cannot be deleted. Set it to Inactive instead.', true); if (confirm('Delete this supplier?')) { s.sups = s.sups.filter(x => x.id !== +id); toast('Supplier deleted.'); render(); } break;
        case 'po-addline': {
          const f = el.closest('form'), p = P(f.elements.lp.value), q = num(f.elements.lq.value), c = f.elements.lc.value === '' ? (p ? p.cost : 0) : num(f.elements.lc.value);
          if (!p || isNaN(q) || q < 1 || !Number.isInteger(q)) return showErr(f, 'Every line needs a medicine and a quantity of at least 1.');
          if (isNaN(c) || c < 0) return showErr(f, 'Unit cost must be 0 or more.');
          showErr(f, ''); const ex = ui.poLines.find(l => l.prod === p.id); if (ex) ex.qty += q; else ui.poLines.push({ prod: p.id, qty: q, rec: 0, cost: c });
          f.elements.lq.value = ''; f.elements.lc.value = ''; f.elements.lp.value = ''; renderLines(); break;
        }
        case 'po-rmline': ui.poLines.splice(+id, 1); renderLines(); break;
        case 'po-save': {
          const f = el.closest('form'); if (!f.elements.sup.value) return showErr(f, 'Choose a supplier.'); if (!ui.poLines.length) return showErr(f, 'Add at least one item to the purchase order.');
          const n = nextId('po'); s.pos.push({ id: n, no: 'PO-' + String(n).padStart(4, '0'), sup: +f.elements.sup.value, date: iso(new Date()), status: v, lines: ui.poLines.map(l => ({ ...l })) });
          toast(v === 'ordered' ? 'Purchase order placed.' : 'Draft saved.'); closeModal(); render(); break;
        }
        case 'po-place': { const o = P_O(id); o.status = 'ordered'; toast(o.no + ' placed.'); render(); break; }
        case 'po-cancel': { const o = P_O(id); if (o.status === 'draft') { if (confirm('Delete this draft?')) { s.pos = s.pos.filter(x => x.id !== o.id); toast('Draft deleted.'); render(); } } else { o.status = 'cancelled'; toast(o.no + ' cancelled.'); render(); } break; }
        case 'writeoff': { const b = s.batches.find(x => x.id === +id), p = P(b.prod); p.qty = Math.max(0, p.qty - b.qty); pushMove('out', p.id, b.qty, ref('WO'), 'Expired write-off'); toast(`Batch ${b.no} written off (${b.qty} units).`); b.qty = 0; render(); break; }
        case 'report': { const r = buildReport(); if (r) { ui.report = r; render(); } break; }
        case 'csv': {
          if (!ui.report) return toast('Generate a report first.', true);
          const q = v => '"' + String(v).replace(/"/g, '""') + '"', csv = '﻿' + [ui.report.cols, ...ui.report.rows, ...(ui.report.foot ? [ui.report.foot] : [])].map(r => r.map(q).join(',')).join('\r\n');
          const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = 'demo-report.csv'; document.body.appendChild(a); a.click(); a.remove(); toast('Sample CSV downloaded.'); break;
        }
        case 'print': window.print(); break;
        case 'role': ui.role = v; ui.tryMsg = null; render(); break;
        case 'try': {
          const adm = ui.role === 'admin';
          ui.tryMsg = v === 'settings' ? (adm ? ['Opened. Administrators can change the site name, registration, limits and the near-expiry window here.', 'success'] : ['Access denied. Administrators only.', 'danger'])
            : (adm ? ['Administrators are sent to the admin area: they inspect inventories (read-only) but do not own or edit them.', 'warning'] : ['Opened. Regular users record stock in their own inventory.', 'success']); render(); break;
        }
        case 'adduser': if (ui.role !== 'admin') { ui.tryMsg = ['Access denied. Administrators only.', 'danger']; render(); } else { const n = s.users.length + 1; s.users.push({ id: n, name: 'New Person ' + n, username: 'newuser' + n, role: 'user', status: 'active' }); toast('Sample user added (demo only).'); render(); } break;
        case 'togglestatus': if (ui.role !== 'admin') { ui.tryMsg = ['Access denied. Administrators only.', 'danger']; render(); break; } { const u = s.users.find(x => x.id === +id); u.status = u.status === 'active' ? 'suspended' : 'active'; toast(`${u.name} is now ${u.status}.`); render(); } break;
      }
    });

    /* ---------- scripted actions (used by tutorials) ---------- */
    const fillVal = v => v.replace(/\{\+(\d+)d\}/g, (_, n) => addDays(+n));
    async function runActions(str, fast) {
      const list = String(str || '').split(';').map(x => x.trim()).filter(Boolean);
      for (const item of list) {
        const i = item.indexOf(':'), cmd = i < 0 ? item : item.slice(0, i), arg = i < 0 ? '' : item.slice(i + 1);
        if (cmd === 'open') { const [k, id] = arg.split(':'); openModal(k.trim(), id); }
        else if (cmd === 'close') closeModal();
        else if (cmd === 'tab') { ui.tab = arg.trim(); ui.pick = ''; render(); }
        else if (cmd === 'fill') {
          const j = arg.indexOf('='), k = arg.slice(0, j).trim(), val = fillVal(arg.slice(j + 1)), el = key(k); if (!el) continue;
          if (el.type === 'checkbox') el.checked = val === '1' || val === 'true'; else el.value = val;
          el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
          const again = key(k); if (again && again !== el && el.type !== 'checkbox') again.value = val;
        }
        else if (cmd === 'click') { const el = key(arg.trim()); if (el) el.click(); }
        await sleep(fast ? 0 : 60);
      }
    }
    function reset() { s = seed(); Object.assign(ui, { tab: 'in', q: '', cat: '', status: '', monitor: 'all', expiry: 'all', supq: '', supf: '', rtype: 'stock', rfrom: addDays(-90), rto: addDays(0), report: null, role: 'user', tryMsg: null, poLines: [], pick: '' }); closeModal(); render(); }

    render();
    return { go, reset, render, runActions, key, el: app, toast, get module() { return ui.module; }, open: openModal, close: closeModal };
  }

  /* ------------------------------------------------------------------ guided tutorial */
  function tour(app, steps, o) {
    o = o || {};
    const spot = document.createElement('div'); spot.className = 'hd-spot'; spot.hidden = true; spot.setAttribute('aria-hidden', 'true');
    const coach = document.createElement('div'); coach.className = 'hd-coach'; coach.setAttribute('role', 'dialog'); coach.setAttribute('aria-label', 'Tutorial step'); coach.setAttribute('aria-live', 'polite');
    document.body.append(spot, coach);
    let i = 0, target = null, busy = false, alive = true;

    const resolve = sel => !sel ? null : (/^[\w-]+$/.test(sel) ? app.key(sel) : app.el.querySelector(sel));
    function place() {
      if (!alive) return;
      if (!target || !target.isConnected || !target.offsetParent && getComputedStyle(target).position !== 'fixed') { spot.hidden = true; coach.style.cssText = ''; return; }
      const r = target.getBoundingClientRect(), pad = 6;
      Object.assign(spot.style, { left: r.left - pad + 'px', top: r.top - pad + 'px', width: r.width + pad * 2 + 'px', height: r.height + pad * 2 + 'px' }); spot.hidden = false;
      const cw = Math.min(380, innerWidth - 24), ch = coach.offsetHeight || 260, m = 16;
      if (innerWidth < 576) { const below = r.bottom + ch + m < innerHeight; Object.assign(coach.style, { left: '12px', right: '12px', width: 'auto', top: below ? 'auto' : m + 'px', bottom: below ? m + 'px' : 'auto' }); return; }
      const spots = [{ right: m, bottom: m }, { left: m, bottom: m }, { right: m, top: m }, { left: m, top: m }];
      const hit = c => { const x = c.left != null ? c.left : innerWidth - m - cw, y = c.top != null ? c.top : innerHeight - m - ch; return !(x + cw < r.left - pad || x > r.right + pad || y + ch < r.top - pad || y > r.bottom + pad); };
      const pick = spots.find(c => !hit(c)) || spots[0];
      coach.style.left = pick.left != null ? pick.left + 'px' : 'auto'; coach.style.right = pick.right != null ? pick.right + 'px' : 'auto';
      coach.style.top = pick.top != null ? pick.top + 'px' : 'auto'; coach.style.bottom = pick.bottom != null ? pick.bottom + 'px' : 'auto'; coach.style.width = cw + 'px';
    }
    function paint() {
      const st = steps[i], pct = Math.round((i + 1) / steps.length * 100), last = i === steps.length - 1;
      coach.innerHTML = `<div class="hd-step-no">Step ${i + 1} of ${steps.length}</div><h4>${esc(st.title)}</h4>${st.image ? `<img class="hd-shot" src="${esc(st.image)}" alt="">` : ''}<p>${md(st.body)}</p>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><div class="progress-bar" style="width:${pct}%"></div></div>
        <div class="hd-dots" aria-hidden="true">${steps.map((_, k) => `<i class="${k < i ? 'on' : k === i ? 'cur' : ''}"></i>`).join('')}</div>
        <div class="hd-actions"><button type="button" class="btn btn-light btn-sm" data-t="exit">Exit</button><button type="button" class="btn btn-light btn-sm ms-auto" data-t="prev" ${i === 0 ? 'disabled' : ''}><i class="bi bi-arrow-left"></i> Previous</button>
        <button type="button" class="btn btn-primary btn-sm" data-t="${last ? 'finish' : 'next'}">${last ? 'Finish tutorial <i class="bi bi-check2"></i>' : 'Next <i class="bi bi-arrow-right"></i>'}</button></div>`;
    }
    async function goto(n) {
      if (busy || n < 0 || n >= steps.length) return; busy = true;
      app.reset();                                                      // replay from a clean slate so Previous/Next are always consistent
      for (let k = 0; k <= n; k++) {
        const st = steps[k]; if (st.module && st.module !== app.module) app.go(st.module);
        await app.runActions(st.action, k < n);
        if (k < n) await sleep(0);
      }
      i = n; await sleep(70); paint();
      const st = steps[n]; target = resolve(st.selector);
      if (target) target.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      place(); setTimeout(place, 380); busy = false;
      const nb = coach.querySelector('[data-t=next],[data-t=finish]'); if (nb) nb.focus({ preventScroll: true });
    }
    function end(done) { alive = false; spot.remove(); coach.remove(); removeEventListener('resize', place); removeEventListener('scroll', place, true); removeEventListener('keydown', keys); (done ? o.onFinish : o.onExit) && (done ? o.onFinish : o.onExit)(); }
    const keys = e => { if (e.key === 'ArrowRight') coach.querySelector('[data-t=next]') && goto(i + 1); else if (e.key === 'ArrowLeft') goto(i - 1); else if (e.key === 'Escape') end(false); };
    coach.addEventListener('click', e => { const b = e.target.closest('[data-t]'); if (!b) return; const t = b.dataset.t; if (t === 'next') goto(i + 1); else if (t === 'prev') goto(i - 1); else if (t === 'finish') end(true); else if (t === 'exit') end(false); });
    addEventListener('resize', place); addEventListener('scroll', place, true); addEventListener('keydown', keys);
    goto(0);
    return { stop: () => end(false) };
  }

  window.HelpDemo = { mount, tour };
})();
