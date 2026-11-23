/* Landing page: interactive feature previews.
   Everything here runs in the visitor's browser with made-up sample data.
   No fetch/XHR, no forms posted, no database access: nothing can touch real records. */
(function () {
  'use strict';

  var cfg = window.LP_FEATURES || {};
  var CUR = cfg.currency || '₱';

  /* ---------- helpers ---------- */
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) {
    return CUR + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function today() { return new Date().toISOString().slice(0, 10); }
  function pad(n, w) { n = String(n); while (n.length < w) n = '0' + n; return n; }
  function status(q, min) {
    if (q <= 0) return '<em class="pill bad">Out of Stock</em>';
    if (q <= min) return '<em class="pill warn">Low Stock</em>';
    return '<em class="pill ok">In Stock</em>';
  }
  function badge(text) { return '<span class="fd-tag">' + esc(text) + '</span>'; }
  function note(el, msg, kind) {
    el.className = 'fd-msg ' + (kind || 'ok');
    el.innerHTML = '<i class="bi bi-' + (kind === 'bad' ? 'exclamation-circle' : kind === 'warn' ? 'info-circle' : 'check-circle') + '"></i> ' + esc(msg);
    el.hidden = false;
  }
  function download(name, text) {
    var blob = new Blob(['\uFEFF' + text], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }
  function on(root, evt, sel, fn) {
    root.addEventListener(evt, function (e) {
      var t = e.target.closest(sel);
      if (t && root.contains(t)) fn(e, t);
    });
  }

  /* ---------- sample data (fresh copy every time a preview opens) ---------- */
  var CAT_ICON = { Electronics: 'headphones', Apparel: 'handbag', 'Home & Kitchen': 'cup-hot', Accessories: 'watch' };
  function seed() {
    return {
      cats: ['Electronics', 'Apparel', 'Home & Kitchen', 'Accessories'],
      sups: [
        { id: 1, name: 'TechSource Trading', person: 'Ana Reyes', phone: '+63 917 555 0142', email: 'sales@techsource.example', addr: '12 Rizal St, Butuan City', active: true },
        { id: 2, name: 'Urban Threads Co.', person: 'Marco Santos', phone: '+63 918 555 0177', email: 'orders@urbanthreads.example', addr: '88 J.C. Aquino Ave, Butuan City', active: true },
        { id: 3, name: 'HomeWorks Supply', person: 'Liza Cruz', phone: '+63 919 555 0108', email: 'hello@homeworks.example', addr: '5 Montilla Blvd, Butuan City', active: true }
      ],
      prods: [
        { id: 1, name: 'Wireless Headphones', sku: 'WH-1001', cat: 'Electronics', sup: 1, cost: 1250, price: 1890, qty: 8, min: 15 },
        { id: 2, name: 'Phone Charger 20W', sku: 'EL-2040', cat: 'Electronics', sup: 1, cost: 180, price: 349, qty: 64, min: 20 },
        { id: 3, name: 'Leather Handbag', sku: 'AP-3002', cat: 'Apparel', sup: 2, cost: 980, price: 1650, qty: 22, min: 10 },
        { id: 4, name: 'Graphic Sweatshirt', sku: 'AP-3015', cat: 'Apparel', sup: 2, cost: 320, price: 590, qty: 0, min: 12 },
        { id: 5, name: 'Ceramic Mug Set', sku: 'HK-5001', cat: 'Home & Kitchen', sup: 3, cost: 210, price: 399, qty: 40, min: 15 },
        { id: 6, name: 'Stainless Bottle', sku: 'HK-5012', cat: 'Home & Kitchen', sup: 3, cost: 150, price: 320, qty: 12, min: 12 },
        { id: 7, name: 'Analog Wristwatch', sku: 'AC-7001', cat: 'Accessories', sup: 2, cost: 700, price: 1290, qty: 17, min: 8 }
      ],
      tx: [
        { type: 'IN', pid: 2, qty: 40, date: '2026-11-02', ref: 'REF-IN-2401' },
        { type: 'OUT', pid: 3, qty: 3, date: '2026-11-04', ref: 'REF-OUT-3310' },
        { type: 'IN', pid: 5, qty: 25, date: '2026-11-06', ref: 'REF-IN-2402' }
      ]
    };
  }
  function pById(d, id) { return d.prods.filter(function (p) { return p.id === id; })[0]; }
  function sById(d, id) { return d.sups.filter(function (s) { return s.id === id; })[0]; }
  function thumb(cat) {
    return '<span class="fd-thumb"><i class="bi bi-' + (CAT_ICON[cat] || 'box-seam') + '"></i></span>';
  }
  function catOptions(d, sel) {
    return d.cats.map(function (c) {
      return '<option' + (c === sel ? ' selected' : '') + '>' + esc(c) + '</option>';
    }).join('');
  }

  /* ======================================================================
     1. Personal Inventory Management
     ====================================================================== */
  function demoPersonal(root) {
    var users = {
      Maria: [
        { name: 'Main Store', icon: 'shop', items: [['Wireless Headphones', 8, 1250], ['Leather Handbag', 22, 980], ['Ceramic Mug Set', 40, 210]] },
        { name: 'Online Shop', icon: 'globe2', items: [['Phone Charger 20W', 64, 180], ['Analog Wristwatch', 17, 700]] }
      ],
      Juan: [
        { name: 'Hardware Corner', icon: 'tools', items: [['Cement 40kg', 120, 245], ['Steel Nails 1kg', 85, 70], ['Paint 4L White', 18, 520]] }
      ]
    };
    var LIMIT = 3, user = 'Maria', ws = 0, added = 0;
    root.innerHTML =
      '<div class="fd-toolbar"><div class="fd-seg" role="group" aria-label="Sample account">' +
      '<span class="fd-seg-label">Signed in as</span>' +
      '<button type="button" data-user="Maria" class="on">Maria</button><button type="button" data-user="Juan">Juan</button></div>' +
      '<span class="fd-hint" id="pUserHint"></span></div>' +
      '<div class="fd-grid-2">' +
      '<div><h4 class="fd-h">Workspaces</h4><div id="pTabs" class="fd-ws"></div>' +
      '<form id="pNew" class="fd-inline mt-2" autocomplete="off"><input class="form-control form-control-sm" id="pName" maxlength="40" placeholder="New workspace name" aria-label="New workspace name">' +
      '<button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Create</button></form>' +
      '<p class="fd-msg ok" id="pMsg" hidden></p></div>' +
      '<div><div id="pPanel"></div></div></div>' +
      '<div class="fd-lock"><i class="bi bi-lock-fill"></i><div><b>Private by design.</b> Every record belongs to one workspace and every workspace to one account. ' +
      'Switch to <b>Juan</b> above: none of Maria\'s products appear, and the reverse is also true.</div></div>';

    var msg = root.querySelector('#pMsg');
    function draw() {
      var list = users[user];
      if (ws >= list.length) ws = 0;
      root.querySelector('#pUserHint').textContent = list.length + ' workspace' + (list.length > 1 ? 's' : '') + ' · only ' + user + ' can see ' + (list.length > 1 ? 'them' : 'it');
      root.querySelector('#pTabs').innerHTML = list.map(function (w, i) {
        return '<button type="button" data-ws="' + i + '" class="fd-ws-item' + (i === ws ? ' on' : '') + '"><i class="bi bi-' + w.icon + '"></i><span>' + esc(w.name) +
          '<small>' + w.items.length + ' products</small></span></button>';
      }).join('');
      var w = list[ws], units = 0, val = 0;
      w.items.forEach(function (x) { units += x[1]; val += x[1] * x[2]; });
      root.querySelector('#pPanel').innerHTML =
        '<h4 class="fd-h">' + esc(w.name) + ' <small class="text-secondary fw-normal">· ' + esc(user) + '\'s workspace</small></h4>' +
        '<div class="fd-stats"><div><small>Products</small><b>' + w.items.length + '</b></div><div><small>Units on hand</small><b>' + units + '</b></div><div><small>Inventory value</small><b>' + money(val) + '</b></div></div>' +
        '<div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Product</th><th class="text-end">Qty</th></tr></thead><tbody>' +
        w.items.map(function (x) { return '<tr><td>' + esc(x[0]) + '</td><td class="text-end">' + x[1] + '</td></tr>'; }).join('') + '</tbody></table></div>';
    }
    on(root, 'click', '[data-user]', function (e, b) {
      user = b.dataset.user; ws = 0; msg.hidden = true;
      root.querySelectorAll('[data-user]').forEach(function (x) { x.classList.toggle('on', x === b); });
      draw();
    });
    on(root, 'click', '[data-ws]', function (e, b) { ws = +b.dataset.ws; draw(); });
    root.querySelector('#pNew').addEventListener('submit', function (e) {
      e.preventDefault();
      var input = root.querySelector('#pName'), name = input.value.trim();
      if (!name) return note(msg, 'Give the workspace a name first.', 'bad');
      if (users[user].length >= LIMIT) return note(msg, 'Limit reached (' + LIMIT + ' in this demo). The administrator sets how many workspaces each account may have.', 'warn');
      users[user].push({ name: name, icon: 'collection', items: [] });
      ws = users[user].length - 1; input.value = ''; added++;
      note(msg, '"' + name + '" created. It starts empty and is visible only to you.');
      draw();
    });
    draw();
  }

  /* ======================================================================
     2. Product Management
     ====================================================================== */
  function demoProducts(root) {
    var d = seed(), editing = null, nextId = 100, imgUrl = null;
    root.innerHTML =
      '<div class="fd-grid-prod"><div>' +
      '<div class="fd-toolbar"><h4 class="fd-h m-0">Products <small class="text-secondary fw-normal" id="prCount"></small></h4>' +
      '<input class="form-control form-control-sm fd-search" id="prSearch" placeholder="Search name or SKU" aria-label="Search products"></div>' +
      '<div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Product</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">Qty</th><th></th></tr></thead><tbody id="prRows"></tbody></table></div>' +
      '<div id="prView" class="fd-detail" hidden></div></div>' +
      '<form id="prForm" class="fd-card" autocomplete="off" novalidate>' +
      '<h4 class="fd-h" id="prFormTitle">Add a product</h4>' +
      '<label class="fd-l">Product name<input class="form-control form-control-sm" name="name" maxlength="60" placeholder="e.g. Bluetooth Speaker"></label>' +
      '<div class="fd-row"><label class="fd-l">SKU<input class="form-control form-control-sm" name="sku" maxlength="20" placeholder="BS-4001"></label>' +
      '<label class="fd-l">Category<select class="form-select form-select-sm" name="cat">' + catOptions(d) + '</select></label></div>' +
      '<div class="fd-row"><label class="fd-l">Cost price<input class="form-control form-control-sm" name="cost" type="number" min="0" step="0.01" placeholder="0.00"></label>' +
      '<label class="fd-l">Selling price<input class="form-control form-control-sm" name="price" type="number" min="0" step="0.01" placeholder="0.00"></label></div>' +
      '<div class="fd-row"><label class="fd-l">Quantity<input class="form-control form-control-sm" name="qty" type="number" min="0" step="1" value="0"></label>' +
      '<label class="fd-l">Min. stock<input class="form-control form-control-sm" name="min" type="number" min="0" step="1" value="10"></label></div>' +
      '<label class="fd-l">Product image<input class="form-control form-control-sm" name="img" type="file" accept="image/*"></label>' +
      '<div class="fd-img-prev" id="prImg" hidden></div>' +
      '<div class="fd-margin" id="prMargin">Profit margin: <b>–</b></div>' +
      '<div class="d-flex gap-2"><button class="btn btn-sm btn-primary flex-grow-1" type="submit" id="prSave"><i class="bi bi-plus-lg"></i> Add product</button>' +
      '<button class="btn btn-sm btn-light" type="button" id="prCancel" hidden>Cancel</button></div>' +
      '<p class="fd-msg ok" id="prMsg" hidden></p><p class="fd-fine">The photo stays in your browser and is never uploaded.</p></form></div>';

    var f = root.querySelector('#prForm'), msg = root.querySelector('#prMsg');
    function rows() {
      var q = root.querySelector('#prSearch').value.trim().toLowerCase();
      var list = d.prods.filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) > -1 || p.sku.toLowerCase().indexOf(q) > -1; });
      root.querySelector('#prCount').textContent = '(' + d.prods.length + ')';
      root.querySelector('#prRows').innerHTML = list.map(function (p) {
        return '<tr' + (editing === p.id ? ' class="sel"' : '') + '><td><div class="fd-prod">' + (p.img ? '<img class="fd-thumb" src="' + p.img + '" alt="">' : thumb(p.cat)) +
          '<div><b>' + esc(p.name) + '</b><small>' + esc(p.sku) + '</small></div></div></td><td>' + esc(p.cat) + '</td>' +
          '<td class="text-end">' + money(p.cost) + '</td><td class="text-end">' + money(p.price) + '</td>' +
          '<td class="text-end">' + p.qty + '<div>' + status(p.qty, p.min) + '</div></td>' +
          '<td class="text-end text-nowrap"><button type="button" class="fd-ic" data-view="' + p.id + '" aria-label="View ' + esc(p.name) + '"><i class="bi bi-eye"></i></button>' +
          '<button type="button" class="fd-ic" data-edit="' + p.id + '" aria-label="Edit ' + esc(p.name) + '"><i class="bi bi-pencil"></i></button>' +
          '<button type="button" class="fd-ic text-danger" data-del="' + p.id + '" aria-label="Delete ' + esc(p.name) + '"><i class="bi bi-trash"></i></button></td></tr>';
      }).join('') || '<tr><td colspan="6" class="text-center text-secondary py-4">No products match.</td></tr>';
    }
    function margin() {
      var c = parseFloat(f.elements.cost.value), p = parseFloat(f.elements.price.value), el = root.querySelector('#prMargin');
      if (!(p > 0) || isNaN(c)) { el.innerHTML = 'Profit margin: <b>–</b>'; return; }
      var m = ((p - c) / p) * 100;
      el.innerHTML = 'Profit per unit ' + money(p - c) + ' · margin <b class="' + (m < 0 ? 'text-danger' : 'text-success') + '">' + m.toFixed(1) + '%</b>';
    }
    function reset() {
      editing = null; imgUrl = null; f.reset(); f.elements.min.value = 10; f.elements.qty.value = 0;
      root.querySelector('#prFormTitle').textContent = 'Add a product';
      root.querySelector('#prSave').innerHTML = '<i class="bi bi-plus-lg"></i> Add product';
      root.querySelector('#prCancel').hidden = true; root.querySelector('#prImg').hidden = true; margin(); rows();
    }
    f.addEventListener('input', margin);
    f.elements.img.addEventListener('change', function () {
      var file = f.elements.img.files && f.elements.img.files[0], box = root.querySelector('#prImg');
      if (!file || !/^image\//.test(file.type)) { imgUrl = null; box.hidden = true; return; }
      imgUrl = URL.createObjectURL(file);
      box.innerHTML = '<img src="' + imgUrl + '" alt="Selected product photo preview">'; box.hidden = false;
    });
    root.querySelector('#prSearch').addEventListener('input', rows);
    root.querySelector('#prCancel').addEventListener('click', function () { msg.hidden = true; reset(); });
    on(root, 'click', '[data-view]', function (e, b) {
      var p = pById(d, +b.dataset.view), s = sById(d, p.sup), v = root.querySelector('#prView');
      v.innerHTML = '<div class="fd-detail-head">' + (p.img ? '<img class="fd-thumb lg" src="' + p.img + '" alt="">' : thumb(p.cat).replace('fd-thumb', 'fd-thumb lg')) +
        '<div><b>' + esc(p.name) + '</b><small>SKU ' + esc(p.sku) + ' · ' + esc(p.cat) + '</small></div><button type="button" class="btn-close ms-auto" data-closeview aria-label="Close details"></button></div>' +
        '<dl class="fd-dl"><div><dt>Cost</dt><dd>' + money(p.cost) + '</dd></div><div><dt>Selling price</dt><dd>' + money(p.price) + '</dd></div>' +
        '<div><dt>On hand</dt><dd>' + p.qty + ' (min ' + p.min + ')</dd></div><div><dt>Stock value</dt><dd>' + money(p.qty * p.cost) + '</dd></div>' +
        '<div><dt>Supplier</dt><dd>' + (s ? esc(s.name) : '–') + '</dd></div><div><dt>Status</dt><dd>' + status(p.qty, p.min) + '</dd></div></dl>';
      v.hidden = false;
    });
    on(root, 'click', '[data-closeview]', function () { root.querySelector('#prView').hidden = true; });
    on(root, 'click', '[data-edit]', function (e, b) {
      var p = pById(d, +b.dataset.edit); editing = p.id;
      f.elements.name.value = p.name; f.elements.sku.value = p.sku; f.elements.cat.value = p.cat; f.elements.cost.value = p.cost; f.elements.price.value = p.price; f.elements.qty.value = p.qty; f.elements.min.value = p.min;
      root.querySelector('#prFormTitle').textContent = 'Edit product';
      root.querySelector('#prSave').innerHTML = '<i class="bi bi-check-lg"></i> Save changes';
      root.querySelector('#prCancel').hidden = false; msg.hidden = true; margin(); rows(); f.elements.name.focus();
    });
    on(root, 'click', '[data-del]', function (e, b) {
      var id = +b.dataset.del;
      d.prods = d.prods.filter(function (p) { return p.id !== id; });
      if (editing === id) reset();
      root.querySelector('#prView').hidden = true; rows(); note(msg, 'Product removed from the sample list.', 'warn');
    });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = f.elements.name.value.trim(), sku = f.elements.sku.value.trim().toUpperCase(), cost = parseFloat(f.elements.cost.value), price = parseFloat(f.elements.price.value);
      var qty = parseInt(f.elements.qty.value, 10), min = parseInt(f.elements.min.value, 10);
      if (!name) return note(msg, 'Product name is required.', 'bad');
      if (!sku) return note(msg, 'SKU is required.', 'bad');
      if (d.prods.some(function (p) { return p.sku === sku && p.id !== editing; })) return note(msg, 'That SKU already exists. SKUs must be unique.', 'bad');
      if (isNaN(cost) || cost < 0 || isNaN(price) || price < 0) return note(msg, 'Enter a cost and selling price of 0 or more.', 'bad');
      if (isNaN(qty) || qty < 0 || isNaN(min) || min < 0) return note(msg, 'Quantity and minimum stock must be whole numbers, 0 or more.', 'bad');
      var data = { name: name, sku: sku, cat: f.elements.cat.value, cost: cost, price: price, qty: qty, min: min };
      if (editing) {
        var p = pById(d, editing); Object.keys(data).forEach(function (k) { p[k] = data[k]; }); if (imgUrl) p.img = imgUrl;
        note(msg, '"' + name + '" updated.'); reset();
      } else {
        data.id = nextId++; data.sup = 1; if (imgUrl) data.img = imgUrl; d.prods.unshift(data);
        reset(); note(msg, '"' + name + '" added to the sample list. In a real account it would be saved to your inventory.');
      }
    });
    margin(); rows();
  }

  /* ======================================================================
     3. Category Management
     ====================================================================== */
  function demoCategories(root) {
    var d = seed(), filter = null, renaming = null;
    root.innerHTML =
      '<div class="fd-grid-2"><div><h4 class="fd-h">Categories</h4><div id="cList" class="fd-list"></div>' +
      '<form id="cForm" class="fd-inline mt-2" autocomplete="off"><input class="form-control form-control-sm" id="cName" maxlength="40" placeholder="New category name" aria-label="New category name">' +
      '<button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button></form>' +
      '<p class="fd-msg ok" id="cMsg" hidden></p></div>' +
      '<div><h4 class="fd-h" id="cTitle">Products</h4><p class="fd-fine mb-2">Pick a category on the left to filter. Use the dropdown to move a product and watch the counts change.</p>' +
      '<div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Product</th><th>Category</th></tr></thead><tbody id="cRows"></tbody></table></div></div></div>';
    var msg = root.querySelector('#cMsg');
    function count(c) { return d.prods.filter(function (p) { return p.cat === c; }).length; }
    function unc() { return d.prods.filter(function (p) { return !p.cat; }).length; }
    function draw() {
      root.querySelector('#cList').innerHTML =
        '<div class="fd-list-item' + (filter === null ? ' on' : '') + '"><button type="button" class="fd-list-main" data-cat=""><i class="bi bi-grid"></i><span>All products</span></button><span class="fd-count">' + d.prods.length + '</span></div>' +
        d.cats.map(function (c, i) {
          var main = renaming === i
            ? '<form class="fd-inline flex-grow-1" data-save-rename="' + i + '"><input class="form-control form-control-sm" value="' + esc(c) + '" maxlength="40" aria-label="Rename category" data-rename-input><button class="btn btn-sm btn-primary" type="submit">Save</button></form>'
            : '<button type="button" class="fd-list-main" data-cat="' + esc(c) + '"><i class="bi bi-' + (CAT_ICON[c] || 'tag') + '"></i><span>' + esc(c) + '</span></button>';
          return '<div class="fd-list-item' + (filter === c ? ' on' : '') + '">' + main + '<span class="fd-count">' + count(c) + '</span>' +
            (renaming === i ? '' : '<button type="button" class="fd-ic" data-rename="' + i + '" aria-label="Rename ' + esc(c) + '"><i class="bi bi-pencil"></i></button><button type="button" class="fd-ic text-danger" data-delcat="' + i + '" aria-label="Delete ' + esc(c) + '"><i class="bi bi-trash"></i></button>') + '</div>';
        }).join('') +
        (unc() ? '<div class="fd-list-item' + (filter === '' ? ' on' : '') + '"><button type="button" class="fd-list-main" data-cat="__none"><i class="bi bi-question-circle"></i><span>Uncategorized</span></button><span class="fd-count">' + unc() + '</span></div>' : '');
      var list = d.prods.filter(function (p) { return filter === null || (filter === '' ? !p.cat : p.cat === filter); });
      root.querySelector('#cTitle').textContent = filter === null ? 'All products' : (filter === '' ? 'Uncategorized' : filter);
      root.querySelector('#cRows').innerHTML = list.map(function (p) {
        return '<tr><td><div class="fd-prod">' + thumb(p.cat) + '<div><b>' + esc(p.name) + '</b><small>' + esc(p.sku) + '</small></div></div></td><td>' +
          '<select class="form-select form-select-sm" data-move="' + p.id + '" aria-label="Category for ' + esc(p.name) + '"><option value=""' + (!p.cat ? ' selected' : '') + '>Uncategorized</option>' + catOptions(d, p.cat) + '</select></td></tr>';
      }).join('') || '<tr><td colspan="2" class="text-center text-secondary py-4">No products in this category yet.</td></tr>';
    }
    on(root, 'click', '[data-cat]', function (e, b) {
      var v = b.dataset.cat; filter = v === '' ? null : (v === '__none' ? '' : v); renaming = null; draw();
    });
    on(root, 'click', '[data-rename]', function (e, b) { renaming = +b.dataset.rename; draw(); var i = root.querySelector('[data-rename-input]'); if (i) { i.focus(); i.select(); } });
    on(root, 'submit', '[data-save-rename]', function (e, form) {
      e.preventDefault();
      var i = +form.dataset.saveRename, old = d.cats[i], nu = form.querySelector('input').value.trim();
      if (!nu) return note(msg, 'A category needs a name.', 'bad');
      if (d.cats.some(function (c, k) { return k !== i && c.toLowerCase() === nu.toLowerCase(); })) return note(msg, 'That category already exists.', 'bad');
      d.prods.forEach(function (p) { if (p.cat === old) p.cat = nu; });
      if (filter === old) filter = nu;
      d.cats[i] = nu; renaming = null; note(msg, 'Renamed to "' + nu + '". Its products moved with it.'); draw();
    });
    on(root, 'click', '[data-delcat]', function (e, b) {
      var i = +b.dataset.delcat, c = d.cats[i], n = count(c);
      d.prods.forEach(function (p) { if (p.cat === c) p.cat = ''; });
      d.cats.splice(i, 1); if (filter === c) filter = null; renaming = null;
      note(msg, 'Category deleted.' + (n ? ' Its ' + n + ' product' + (n > 1 ? 's are' : ' is') + ' now uncategorized.' : ''), 'warn'); draw();
    });
    on(root, 'change', '[data-move]', function (e, s) { pById(d, +s.dataset.move).cat = s.value; draw(); });
    root.querySelector('#cForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var i = root.querySelector('#cName'), v = i.value.trim();
      if (!v) return note(msg, 'Type a category name first.', 'bad');
      if (d.cats.some(function (c) { return c.toLowerCase() === v.toLowerCase(); })) return note(msg, 'That category already exists.', 'bad');
      d.cats.push(v); i.value = ''; note(msg, '"' + v + '" added. Move products into it from the list on the right.'); draw();
    });
    draw();
  }

  /* ======================================================================
     4. Supplier Management
     ====================================================================== */
  function demoSuppliers(root) {
    var d = seed(), sel = 1, nextId = 50;
    var pos = { 1: [['PO-0007', '2026-10-28', 'Received', 40000], ['PO-0009', '2026-11-05', 'Ordered', 15000]], 2: [['PO-0008', '2026-11-01', 'Received', 22400]], 3: [['PO-0006', '2026-10-20', 'Received', 9800]] };
    root.innerHTML =
      '<div class="fd-grid-prod"><div><h4 class="fd-h">Suppliers</h4><div id="sList" class="fd-list"></div><div id="sDetail" class="fd-detail mt-3"></div></div>' +
      '<form id="sForm" class="fd-card" autocomplete="off" novalidate><h4 class="fd-h">Add a supplier</h4>' +
      '<label class="fd-l">Supplier name<input class="form-control form-control-sm" name="name" maxlength="60" placeholder="Company name"></label>' +
      '<label class="fd-l">Contact person<input class="form-control form-control-sm" name="person" maxlength="60"></label>' +
      '<div class="fd-row"><label class="fd-l">Phone<input class="form-control form-control-sm" name="phone" maxlength="30"></label>' +
      '<label class="fd-l">Email<input class="form-control form-control-sm" name="email" type="email" maxlength="80"></label></div>' +
      '<label class="fd-l">Business address<input class="form-control form-control-sm" name="addr" maxlength="100"></label>' +
      '<button class="btn btn-sm btn-primary w-100" type="submit"><i class="bi bi-plus-lg"></i> Add supplier</button>' +
      '<p class="fd-msg ok" id="sMsg" hidden></p></form></div>';
    var f = root.querySelector('#sForm'), msg = root.querySelector('#sMsg');
    function draw() {
      root.querySelector('#sList').innerHTML = d.sups.map(function (s) {
        return '<div class="fd-list-item' + (s.id === sel ? ' on' : '') + '"><button type="button" class="fd-list-main" data-sup="' + s.id + '"><i class="bi bi-truck"></i><span>' + esc(s.name) +
          '<small>' + esc(s.person || 'No contact') + '</small></span></button>' + (s.active ? '' : '<em class="pill bad">Inactive</em>') + '</div>';
      }).join('');
      var s = sById(d, sel), box = root.querySelector('#sDetail');
      if (!s) { box.innerHTML = '<p class="text-secondary m-0">Select a supplier to see its details.</p>'; return; }
      var items = d.prods.filter(function (p) { return p.sup === s.id; }), po = pos[s.id] || [];
      box.innerHTML =
        '<div class="fd-detail-head"><div><b>' + esc(s.name) + '</b><small>' + (s.active ? 'Active supplier' : 'Inactive supplier') + '</small></div>' +
        '<button type="button" class="btn btn-sm btn-light ms-auto" data-toggle-sup="' + s.id + '">' + (s.active ? 'Set inactive' : 'Set active') + '</button></div>' +
        '<dl class="fd-dl"><div><dt>Contact person</dt><dd>' + esc(s.person || '–') + '</dd></div><div><dt>Phone</dt><dd>' + esc(s.phone || '–') + '</dd></div>' +
        '<div><dt>Email</dt><dd>' + esc(s.email || '–') + '</dd></div><div><dt>Address</dt><dd>' + esc(s.addr || '–') + '</dd></div></dl>' +
        '<h5 class="fd-h2">Products bought from this supplier</h5>' +
        (items.length ? '<ul class="fd-chips">' + items.map(function (p) { return '<li>' + thumb(p.cat) + esc(p.name) + '</li>'; }).join('') + '</ul>' : '<p class="fd-fine">No products linked yet.</p>') +
        '<h5 class="fd-h2">Purchase orders</h5>' +
        (po.length ? '<table class="fd-table"><tbody>' + po.map(function (r) {
          return '<tr><td>' + r[0] + '</td><td>' + r[1] + '</td><td>' + (r[2] === 'Received' ? '<em class="pill ok">Received</em>' : '<em class="pill warn">Ordered</em>') + '</td><td class="text-end">' + money(r[3]) + '</td></tr>';
        }).join('') + '</tbody></table>' : '<p class="fd-fine">No purchase orders yet.</p>');
    }
    on(root, 'click', '[data-sup]', function (e, b) { sel = +b.dataset.sup; draw(); });
    on(root, 'click', '[data-toggle-sup]', function (e, b) { var s = sById(d, +b.dataset.toggleSup); s.active = !s.active; draw(); });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = f.elements.name.value.trim();
      if (!name) return note(msg, 'Supplier name is required.', 'bad');
      if (f.elements.email.value && !f.elements.email.checkValidity()) return note(msg, 'Enter a valid email address or leave it blank.', 'bad');
      var s = { id: nextId++, name: name, person: f.elements.person.value.trim(), phone: f.elements.phone.value.trim(), email: f.elements.email.value.trim(), addr: f.elements.addr.value.trim(), active: true };
      d.sups.push(s); sel = s.id; f.reset(); note(msg, '"' + name + '" added. You can now link products and purchase orders to it.'); draw();
    });
    draw();
  }

  /* ======================================================================
     5. Stock-In / Stock-Out
     ====================================================================== */
  function demoStock(root) {
    var d = seed(), type = 'IN', seqIn = 2403, seqOut = 3311;
    root.innerHTML =
      '<div class="fd-grid-prod"><div><h4 class="fd-h">Current stock</h4><div id="kCards" class="fd-stock"></div>' +
      '<h4 class="fd-h mt-3">Transactions</h4><div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Type</th><th>Product</th><th class="text-end">Qty</th><th>Date</th><th>Reference</th></tr></thead><tbody id="kRows"></tbody></table></div></div>' +
      '<form id="kForm" class="fd-card" autocomplete="off" novalidate><h4 class="fd-h">Record a movement</h4>' +
      '<div class="fd-seg w-100 mb-2" role="group" aria-label="Movement type"><button type="button" class="on" data-type="IN"><i class="bi bi-box-arrow-in-down"></i> Stock In</button><button type="button" data-type="OUT"><i class="bi bi-box-arrow-up"></i> Stock Out</button></div>' +
      '<label class="fd-l">Product<select class="form-select form-select-sm" name="pid">' + d.prods.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('') + '</select></label>' +
      '<div class="fd-row"><label class="fd-l">Quantity<input class="form-control form-control-sm" name="qty" type="number" min="1" step="1" value="5"></label>' +
      '<label class="fd-l">Date<input class="form-control form-control-sm" name="date" type="date" value="' + today() + '"></label></div>' +
      '<label class="fd-l">Reference no.<input class="form-control form-control-sm" name="ref" maxlength="30"></label>' +
      '<div class="fd-preview" id="kPrev"></div>' +
      '<button class="btn btn-sm btn-primary w-100" type="submit" id="kBtn">Record stock in</button>' +
      '<p class="fd-msg ok" id="kMsg" hidden></p></form></div>';
    var f = root.querySelector('#kForm'), msg = root.querySelector('#kMsg');
    function autoRef() { f.elements.ref.value = type === 'IN' ? 'REF-IN-' + seqIn : 'REF-OUT-' + seqOut; }
    function preview() {
      var p = pById(d, +f.elements.pid.value), q = parseInt(f.elements.qty.value, 10) || 0, after = p.qty + (type === 'IN' ? q : -q);
      root.querySelector('#kPrev').innerHTML = 'Stock after this entry: <b>' + p.qty + '</b> <i class="bi bi-arrow-right"></i> <b class="' + (after < 0 ? 'text-danger' : 'text-success') + '">' + after + '</b>' +
        (after < 0 ? '<div class="text-danger small">Not enough stock. This would go negative.</div>' : '');
    }
    function draw() {
      root.querySelector('#kCards').innerHTML = d.prods.map(function (p) {
        return '<div class="fd-stock-item" data-flash="' + p.id + '"><span>' + esc(p.name) + '</span><b>' + p.qty + '</b>' + status(p.qty, p.min) + '</div>';
      }).join('');
      root.querySelector('#kRows').innerHTML = d.tx.slice(0, 8).map(function (t) {
        return '<tr><td>' + (t.type === 'IN' ? '<em class="pill ok">IN</em>' : '<em class="pill bad">OUT</em>') + '</td><td>' + esc(pById(d, t.pid).name) +
          '</td><td class="text-end">' + (t.type === 'IN' ? '+' : '−') + t.qty + '</td><td>' + t.date + '</td><td>' + esc(t.ref) + '</td></tr>';
      }).join('');
      preview();
    }
    on(root, 'click', '[data-type]', function (e, b) {
      type = b.dataset.type; msg.hidden = true;
      root.querySelectorAll('[data-type]').forEach(function (x) { x.classList.toggle('on', x === b); });
      root.querySelector('#kBtn').textContent = type === 'IN' ? 'Record stock in' : 'Record stock out';
      autoRef(); preview();
    });
    f.addEventListener('input', preview);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var p = pById(d, +f.elements.pid.value), q = parseInt(f.elements.qty.value, 10), ref = f.elements.ref.value.trim();
      if (!(q >= 1)) return note(msg, 'Quantity must be a whole number of 1 or more.', 'bad');
      if (!f.elements.date.value) return note(msg, 'Choose a date.', 'bad');
      if (!ref) return note(msg, 'A reference number is required.', 'bad');
      if (type === 'OUT' && q > p.qty) return note(msg, 'Only ' + p.qty + ' of "' + p.name + '" in stock. Stock can never go negative, so this was blocked.', 'bad');
      p.qty += type === 'IN' ? q : -q;
      d.tx.unshift({ type: type, pid: p.id, qty: q, date: f.elements.date.value, ref: ref });
      if (type === 'IN') seqIn++; else seqOut++;
      autoRef(); draw();
      note(msg, (type === 'IN' ? 'Received ' : 'Sold ') + q + ' × ' + p.name + '. Quantity is now ' + p.qty + '.');
      var c = root.querySelector('[data-flash="' + p.id + '"]'); if (c) { c.classList.add('flash'); }
    });
    f.elements.pid.addEventListener('change', preview);
    autoRef(); draw();
  }

  /* ======================================================================
     6. Low Stock Notifications
     ====================================================================== */
  function demoLowStock(root) {
    var d = seed();
    root.innerHTML =
      '<div class="fd-toolbar"><p class="fd-hint m-0">A product is flagged when its quantity is at or below its minimum level. Try selling a few units or restocking.</p>' +
      '<div class="fd-bell-wrap"><button type="button" class="fd-bell" id="lBell" aria-expanded="false" aria-label="Stock alerts"><i class="bi bi-bell"></i><span id="lCount"></span></button>' +
      '<div class="fd-bell-menu" id="lMenu" hidden></div></div></div>' +
      '<div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Product</th><th class="text-end">On hand</th><th class="text-end">Minimum</th><th>Level</th><th>Status</th><th></th></tr></thead><tbody id="lRows"></tbody></table></div>' +
      '<p class="fd-msg ok" id="lMsg" hidden></p>';
    var msg = root.querySelector('#lMsg'), open = false;
    function alerts() { return d.prods.filter(function (p) { return p.qty <= p.min; }).sort(function (a, b) { return a.qty - b.qty; }); }
    function draw() {
      var al = alerts(), c = root.querySelector('#lCount');
      c.textContent = al.length || ''; c.hidden = !al.length;
      root.querySelector('#lMenu').innerHTML = '<div class="fd-bell-head">' + (al.length ? 'Needs your attention' : 'All stock levels are healthy') + '</div>' +
        al.map(function (p) {
          return '<div class="fd-bell-row"><i class="bi bi-' + (p.qty === 0 ? 'x-circle text-danger' : 'exclamation-triangle text-warning') + '"></i><div><b>' + esc(p.name) + '</b><small>' + (p.qty === 0 ? 'Out of stock' : p.qty + ' left · minimum ' + p.min) + '</small></div></div>';
        }).join('');
      root.querySelector('#lRows').innerHTML = d.prods.map(function (p) {
        var pct = Math.min(100, Math.round(p.qty / (p.min * 2) * 100)), cls = p.qty <= 0 ? 'bad' : p.qty <= p.min ? 'warn' : 'ok';
        return '<tr><td><div class="fd-prod">' + thumb(p.cat) + '<div><b>' + esc(p.name) + '</b><small>' + esc(p.sku) + '</small></div></div></td><td class="text-end">' + p.qty + '</td><td class="text-end">' + p.min +
          '</td><td style="min-width:90px"><div class="fd-meter ' + cls + '"><span style="width:' + pct + '%"></span></div></td><td>' + status(p.qty, p.min) + '</td>' +
          '<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-light" data-sell="' + p.id + '"' + (p.qty ? '' : ' disabled') + '>Sell 5</button> ' +
          '<button type="button" class="btn btn-sm ' + (p.qty <= p.min ? 'btn-primary' : 'btn-light') + '" data-restock="' + p.id + '">Restock</button></td></tr>';
      }).join('');
    }
    on(root, 'click', '[data-sell]', function (e, b) {
      var p = pById(d, +b.dataset.sell), n = Math.min(5, p.qty), was = p.qty > p.min; p.qty -= n; draw();
      if (was && p.qty <= p.min) note(msg, '"' + p.name + '" just dropped to ' + p.qty + ' (minimum ' + p.min + '). A new alert appeared in the bell.', 'warn'); else msg.hidden = true;
    });
    on(root, 'click', '[data-restock]', function (e, b) {
      var p = pById(d, +b.dataset.restock); p.qty += p.min * 2; draw();
      note(msg, '"' + p.name + '" restocked to ' + p.qty + '. The alert cleared automatically.');
    });
    root.querySelector('#lBell').addEventListener('click', function () {
      open = !open; root.querySelector('#lMenu').hidden = !open; this.setAttribute('aria-expanded', open);
    });
    draw();
  }

  /* ======================================================================
     7. Inventory Reports
     ====================================================================== */
  function demoReports(root) {
    var d = seed(), tab = 'move';
    var months = ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'], inn = [120, 90, 140, 110, 160, 95], out = [100, 105, 120, 98, 130, 118];
    root.innerHTML =
      '<div class="fd-toolbar"><div class="fd-seg" role="tablist" aria-label="Report type">' +
      '<button type="button" class="on" data-tab="move">Stock movement</button><button type="button" data-tab="value">Inventory valuation</button></div>' +
      '<div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-light" id="rCsv"><i class="bi bi-filetype-csv"></i> Export CSV</button>' +
      '<button type="button" class="btn btn-sm btn-light" id="rPrint"><i class="bi bi-printer"></i> Print</button></div></div>' +
      '<div id="rBody" class="fd-print-area"></div>';
    function move() {
      var max = Math.max.apply(null, inn.concat(out)), ti = inn.reduce(function (a, b) { return a + b; }), to = out.reduce(function (a, b) { return a + b; });
      return '<div class="fd-stats"><div><small>Units received (6 mo)</small><b>' + ti + '</b></div><div><small>Units sold (6 mo)</small><b>' + to + '</b></div><div><small>Net change</small><b>' + (ti - to >= 0 ? '+' : '') + (ti - to) + '</b></div></div>' +
        '<div class="fd-chart" role="img" aria-label="Bar chart of units received and sold per month">' + months.map(function (m, i) {
          return '<div class="fd-col"><div class="fd-bars"><span class="in" style="height:' + inn[i] / max * 100 + '%" title="In ' + inn[i] + '"></span><span class="out" style="height:' + out[i] / max * 100 + '%" title="Out ' + out[i] + '"></span></div><small>' + m + '</small></div>';
        }).join('') + '</div><div class="fd-legend"><span><i class="in"></i> Stock in</span><span><i class="out"></i> Stock out</span></div>' +
        '<div class="fd-table-wrap mt-2"><table class="fd-table"><thead><tr><th>Month</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Net</th></tr></thead><tbody>' +
        months.map(function (m, i) { return '<tr><td>' + m + ' 2026</td><td class="text-end">' + inn[i] + '</td><td class="text-end">' + out[i] + '</td><td class="text-end">' + (inn[i] - out[i]) + '</td></tr>'; }).join('') + '</tbody></table></div>';
    }
    function value() {
      var cost = 0, retail = 0, byCat = {};
      d.prods.forEach(function (p) { cost += p.qty * p.cost; retail += p.qty * p.price; byCat[p.cat] = (byCat[p.cat] || 0) + p.qty * p.cost; });
      var colors = ['#7c6cf0', '#fb8f86', '#6cc68b', '#f5b94a'], acc = 0, stops = [], keys = Object.keys(byCat);
      keys.forEach(function (k, i) { var s = acc / cost * 100; acc += byCat[k]; stops.push(colors[i % 4] + ' ' + s + '% ' + acc / cost * 100 + '%'); });
      return '<div class="fd-stats"><div><small>Inventory value (cost)</small><b>' + money(cost) + '</b></div><div><small>Potential sales value</small><b>' + money(retail) + '</b></div><div><small>Potential profit</small><b>' + money(retail - cost) + '</b></div></div>' +
        '<div class="fd-grid-2 align-items-center"><div class="fd-donut-wrap"><div class="fd-donut" style="background:conic-gradient(' + stops.join(',') + ')" role="img" aria-label="Inventory value by category"></div>' +
        '<ul class="fd-legend-col">' + keys.map(function (k, i) { return '<li><i style="background:' + colors[i % 4] + '"></i>' + esc(k) + '<b>' + Math.round(byCat[k] / cost * 100) + '%</b></li>'; }).join('') + '</ul></div>' +
        '<div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr></thead><tbody>' +
        d.prods.map(function (p) { return '<tr><td>' + esc(p.name) + '</td><td class="text-end">' + p.qty + '</td><td class="text-end">' + money(p.qty * p.cost) + '</td></tr>'; }).join('') +
        '<tr class="fd-total"><td>Total</td><td></td><td class="text-end">' + money(cost) + '</td></tr></tbody></table></div></div>';
    }
    function draw() { root.querySelector('#rBody').innerHTML = '<div class="fd-print-title"><b>StockRoom</b> · Sample report · ' + (tab === 'move' ? 'Stock movement' : 'Inventory valuation') + ' (sample data)</div>' + (tab === 'move' ? move() : value()); }
    on(root, 'click', '[data-tab]', function (e, b) {
      tab = b.dataset.tab; root.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); }); draw();
    });
    root.querySelector('#rCsv').addEventListener('click', function () {
      var rows = tab === 'move'
        ? [['Month', 'Stock in', 'Stock out', 'Net']].concat(months.map(function (m, i) { return [m + ' 2026', inn[i], out[i], inn[i] - out[i]]; }))
        : [['SKU', 'Product', 'Category', 'Qty', 'Unit cost', 'Stock value']].concat(d.prods.map(function (p) { return [p.sku, p.name, p.cat, p.qty, p.cost, p.qty * p.cost]; }));
      download('sample_' + (tab === 'move' ? 'stock_movement' : 'inventory_valuation') + '.csv',
        rows.map(function (r) { return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n'));
    });
    root.querySelector('#rPrint').addEventListener('click', function () {
      document.body.classList.add('fd-printing');
      var done = function () { document.body.classList.remove('fd-printing'); window.removeEventListener('afterprint', done); };
      window.addEventListener('afterprint', done); window.print();
    });
    draw();
  }

  /* ======================================================================
     8. Secure User Accounts
     ====================================================================== */
  function demoSecurity(root) {
    var users = [
      { name: 'Maria Lopez', email: 'maria@example.com', inv: 2, status: 'active' },
      { name: 'Juan Dela Cruz', email: 'juan@example.com', inv: 1, status: 'active' },
      { name: 'Sample Spammer', email: 'spam@example.com', inv: 0, status: 'active' }
    ];
    var step = 'reg';
    root.innerHTML =
      '<div class="fd-seg mb-3" role="tablist" aria-label="Security topics"><button type="button" class="on" data-step="reg">1 · Register</button><button type="button" data-step="login">2 · Sign in</button><button type="button" data-step="admin">3 · Administrator</button></div>' +
      '<div id="secBody"></div>';
    function body() {
      if (step === 'reg') return '<div class="fd-grid-2"><form id="secReg" class="fd-card" autocomplete="off" novalidate><h4 class="fd-h">Create an account</h4>' +
        '<p class="fd-fine">Type any made-up password. It never leaves your browser.</p>' +
        '<label class="fd-l">Password<input class="form-control form-control-sm" name="pw" type="password" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters"></label>' +
        '<div class="fd-meter" id="secMeter"><span style="width:0"></span></div><small id="secMeterTxt" class="fd-fine">Use at least 8 characters.</small>' +
        '<div class="fd-hash mt-3"><small>What the database stores instead of your password</small><code id="secHash">–</code></div>' +
        '<p class="fd-fine mt-2">Illustration only: real hashes are made on the server with PHP\'s <code>password_hash()</code> and cannot be turned back into the password.</p></form>' +
        '<ul class="fd-checks"><li><i class="bi bi-check-circle-fill"></i> Passwords are stored as one-way hashes, never as plain text.</li><li><i class="bi bi-check-circle-fill"></i> 8 to 72 character passwords are enforced.</li>' +
        '<li><i class="bi bi-check-circle-fill"></i> Public sign-up can only create normal user accounts, never administrators.</li><li><i class="bi bi-check-circle-fill"></i> Forms carry a security token that blocks forged requests.</li><li><i class="bi bi-check-circle-fill"></i> Too many sign-up attempts from one device are temporarily blocked.</li></ul></div>';
      if (step === 'login') return '<div class="fd-flow">' +
        [['bi-person-lock', 'You sign in', 'Your password is checked against the stored hash.'], ['bi-shield-check', 'A fresh session starts', 'The session ID is renewed at login so old ones cannot be reused.'],
        ['bi-database-lock', 'Your workspace opens', 'Every query is limited to inventories you own.'], ['bi-door-open', 'You sign out', 'The session ends and the next visitor sees nothing.']].map(function (s, i) {
          return '<div class="fd-flow-step"><span>' + (i + 1) + '</span><i class="bi ' + s[0] + '"></i><b>' + s[1] + '</b><small>' + s[2] + '</small></div>';
        }).join('') + '</div><ul class="fd-checks mt-3"><li><i class="bi bi-check-circle-fill"></i> Repeated failed logins are rate-limited.</li><li><i class="bi bi-check-circle-fill"></i> Account status is re-checked on every request, so a suspended user is signed out at once.</li>' +
        '<li><i class="bi bi-check-circle-fill"></i> Administrator-only pages are protected on the server, not just hidden from menus.</li></ul>';
      return '<p class="fd-hint">This is what an administrator sees. Try suspending an account.</p><div class="fd-table-wrap"><table class="fd-table"><thead><tr><th>User</th><th class="text-end">Inventories</th><th>Status</th><th></th></tr></thead><tbody>' +
        users.map(function (u, i) {
          return '<tr><td><b>' + esc(u.name) + '</b><div class="fd-fine">' + esc(u.email) + '</div></td><td class="text-end">' + u.inv + '</td><td>' +
            (u.status === 'active' ? '<em class="pill ok">Active</em>' : '<em class="pill bad">Suspended</em>') + '</td><td class="text-end">' +
            '<button type="button" class="btn btn-sm ' + (u.status === 'active' ? 'btn-light text-danger' : 'btn-primary') + '" data-susp="' + i + '">' + (u.status === 'active' ? 'Suspend' : 'Activate') + '</button></td></tr>';
        }).join('') + '</tbody></table></div><p class="fd-msg warn" id="secMsg" hidden></p>' +
        '<ul class="fd-checks mt-3"><li><i class="bi bi-check-circle-fill"></i> Administrators can search users, suspend or activate accounts, and reset passwords.</li><li><i class="bi bi-check-circle-fill"></i> Platform-wide monitoring pages are read-only, so administrators cannot edit your stock records.</li><li><i class="bi bi-check-circle-fill"></i> An activity log records important actions.</li></ul>';
    }
    function fakeHash(pw) {
      if (!pw) return '–';
      var a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789./', h = 5381, i, out = '$2y$10$';
      for (i = 0; i < pw.length; i++) h = ((h << 5) + h + pw.charCodeAt(i)) >>> 0;
      for (i = 0; i < 53; i++) { h = (Math.imul(h, 1664525) + 1013904223) >>> 0; out += a[h >>> 26]; }
      return out;
    }
    function strength(pw) {
      var s = 0; if (pw.length >= 8) s++; if (pw.length >= 12) s++; if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++; if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) s++;
      return pw.length < 8 ? 0 : Math.max(1, s);
    }
    function draw() {
      var box = root.querySelector('#secBody'); box.innerHTML = body();
      var f = box.querySelector('#secReg');
      if (f) f.addEventListener('input', function () {
        var pw = f.elements.pw.value, s = strength(pw), m = box.querySelector('#secMeter');
        m.className = 'fd-meter ' + (s <= 1 ? 'bad' : s <= 2 ? 'warn' : 'ok'); m.firstChild.style.width = (pw ? Math.max(s, 1) * 25 : 0) + '%';
        box.querySelector('#secMeterTxt').textContent = !pw ? 'Use at least 8 characters.' : pw.length < 8 ? 'Too short. Use at least 8 characters.' : ['', 'Weak', 'Fair', 'Good', 'Strong'][s] + ' password';
        box.querySelector('#secHash').textContent = fakeHash(pw);
      });
    }
    on(root, 'click', '[data-step]', function (e, b) {
      step = b.dataset.step; root.querySelectorAll('[data-step]').forEach(function (x) { x.classList.toggle('on', x === b); }); draw();
    });
    on(root, 'click', '[data-susp]', function (e, b) {
      var u = users[+b.dataset.susp]; u.status = u.status === 'active' ? 'suspended' : 'active'; draw();
      var m = root.querySelector('#secMsg'); note(m, u.status === 'suspended' ? u.name + ' was suspended and would be signed out immediately.' : u.name + ' is active again.', u.status === 'suspended' ? 'warn' : 'ok');
    });
    draw();
  }

  /* ---------- feature registry ---------- */
  var F = [
    { key: 'personal', icon: 'collection', title: 'Personal Inventory Management', demo: demoPersonal, page: 'inventories.php', pageLabel: 'Open my inventories',
      lead: 'Each account gets its own private inventory workspace, and can run several, for example a main store and an online shop. Nothing is shared between accounts.',
      points: ['Create and name your own workspaces', 'Switch between them in one click', 'Your data is invisible to other users'] },
    { key: 'products', icon: 'upc-scan', title: 'Product Management', demo: demoProducts, page: 'products.php', pageLabel: 'Open my products',
      lead: 'Keep a clean catalog: name, SKU, category, cost and selling price, quantity and a photo. Try adding, viewing, editing and deleting products below.',
      points: ['SKUs must be unique', 'Profit margin is shown as you type', 'Edit any product at any time'] },
    { key: 'categories', icon: 'tags', title: 'Category Management', demo: demoCategories, page: 'categories.php', pageLabel: 'Open my categories',
      lead: 'Group products into categories so large catalogs stay easy to browse and report on. Create, rename and delete categories, and move products between them.',
      points: ['Renaming a category updates all its products', 'Deleting a category keeps its products as uncategorized', 'Counts update instantly'] },
    { key: 'suppliers', icon: 'truck', title: 'Supplier Management', demo: demoSuppliers, page: 'suppliers.php', pageLabel: 'Open my suppliers',
      lead: 'Store each supplier\'s contact details and know exactly where every product is purchased from, together with its purchase orders.',
      points: ['Contact person, phone, email and address', 'Link suppliers to products and purchase orders', 'Mark suppliers inactive instead of losing history'] },
    { key: 'stock', icon: 'arrow-left-right', title: 'Stock-In and Stock-Out Tracking', demo: demoStock, page: 'stock_in.php', pageLabel: 'Open stock in',
      lead: 'Record every delivery and sale with a reference number. Quantities update automatically, and stock can never go negative.',
      points: ['Stock in adds, stock out subtracts', 'Every movement keeps a reference and date', 'Selling more than you have is blocked'] },
    { key: 'lowstock', icon: 'bell', title: 'Low Stock Notifications', demo: demoLowStock, page: 'monitoring.php', pageLabel: 'Open stock monitoring',
      lead: 'Alerts in the top bar tell you which items need restocking before they run out. A product is flagged when it reaches its minimum level.',
      points: ['Set a minimum level per product', 'The bell shows the most urgent items first', 'Alerts clear once you restock'] },
    { key: 'reports', icon: 'graph-up-arrow', title: 'Inventory Reports', demo: demoReports, page: 'reports.php', pageLabel: 'Open my reports',
      lead: 'Review stock movement, inventory value and trends, then export to CSV or print. These reports use sample figures.',
      points: ['Stock movement summaries', 'Inventory valuation by product and category', 'Export CSV and print'] },
    { key: 'security', icon: 'shield-lock', title: 'Secure User Accounts', demo: demoSecurity, page: 'profile.php', pageLabel: 'Open my profile',
      lead: 'Hashed passwords, protected sessions and an administrator who can suspend misuse. Walk through how registration, sign-in and administration work.',
      points: ['Passwords are hashed, never stored as text', 'Each account\'s data is separated', 'Administrators can suspend accounts'] }
  ];

  /* ---------- modal wiring ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('featureModal');
    if (!el || !window.bootstrap) return;
    var modal = bootstrap.Modal.getOrCreateInstance(el), cur = 0, lastCard = null;
    var q = function (s) { return el.querySelector(s); };

    // A brand-new stage element per preview, so listeners from a previous preview can never fire again
    function freshStage() {
      var old = q('.fd-stage'), nu = document.createElement('div');
      nu.className = 'fd-stage'; old.parentNode.replaceChild(nu, old); return nu;
    }
    function show(i) {
      cur = (i + F.length) % F.length;
      var f = F[cur], stage = freshStage();
      q('#fdTitle').textContent = f.title;
      q('.fd-icon i').className = 'bi bi-' + f.icon;
      q('.fd-lead').textContent = f.lead;
      q('.fd-points').innerHTML = f.points.map(function (p) { return '<li><i class="bi bi-check2-circle"></i>' + esc(p) + '</li>'; }).join('');
      q('.fd-count-label').textContent = (cur + 1) + ' of ' + F.length;
      stage.innerHTML = ''; stage.scrollTop = 0; q('.modal-body').scrollTop = 0;
      try { f.demo(stage); } catch (err) { stage.innerHTML = '<p class="text-secondary">This preview could not load.</p>'; if (window.console) console.error(err); }
      // Call to action: real page for signed-in users, sign-up for visitors
      var cta = q('.fd-cta');
      if (cfg.loggedIn && !cfg.isAdmin) { cta.href = f.page; cta.innerHTML = '<i class="bi bi-box-arrow-up-right me-1"></i> ' + esc(f.pageLabel); }
      else if (cfg.loggedIn) { cta.href = cfg.home; cta.innerHTML = 'Open Dashboard <i class="bi bi-arrow-right ms-1"></i>'; }
      else { cta.href = cfg.start; cta.innerHTML = (cfg.canRegister ? 'Create your free account' : 'Sign in to get started') + ' <i class="bi bi-arrow-right ms-1"></i>'; }
    }

    document.querySelectorAll('[data-feature]').forEach(function (card) {
      function open() {
        var idx = F.map(function (x) { return x.key; }).indexOf(card.dataset.feature);
        if (idx < 0) return;
        lastCard = card; show(idx); modal.show();
      }
      card.addEventListener('click', open);
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
    q('.fd-prev').addEventListener('click', function () { show(cur - 1); });
    q('.fd-next').addEventListener('click', function () { show(cur + 1); });
    el.addEventListener('hidden.bs.modal', function () {
      freshStage(); document.body.classList.remove('fd-printing');
      if (lastCard) lastCard.focus({ preventScroll: true });
    });
  });
})();
