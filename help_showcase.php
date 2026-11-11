<?php
// Interactive Feature Showcase: pick a module, read what it does, and try a live demo of it.
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

// Static descriptions of each module (the live demo is help-demo.js; long-form docs are the editable Help articles).
$mods = [
    'dashboard' => ['Dashboard', 'grid-1x2', 'Your daily overview: inventory statistics, charts, low-stock alerts and a summary of stock health.',
        ['Statistic cards for products, stock value, low stock and expiry alerts', 'Stock-movement chart and a donut summary (in stock / low / out)', 'Low-stock alert list with one-click Restock', 'Bell icon shows alerts from every page'],
        ['Click “Restock” on an alert to jump to Stock in', 'Add or remove stock in another module, then come back — the numbers update'], 'Everyone', 'monitoring-low-stock', 'feature-dashboard'],
    'products' => ['Product management', 'box-seam', 'Add, edit, search, filter and manage everything you stock.',
        ['SKU, category, cost and selling price, minimum level, image', 'Search by name or SKU; filter by category or stock status', 'In stock / Low stock / Out of stock badges with level meters', 'Optional batch and expiry tracking per product'],
        ['Press “Add product” and save with an empty name to see validation', 'Search “vit”, then filter by “Low stock”', 'Edit a product with the pencil icon'], 'Regular users', 'adding-a-new-product', 'feature-products'],
    'inventory' => ['Inventory management', 'arrow-left-right', 'Stock in, stock out and stock monitoring — every change is recorded in the movement history.',
        ['Stock in: record deliveries with supplier, batch number and expiry', 'Stock out: sale, release, damaged/expired or returned — oldest-expiry batch first', 'Adjustments are recorded as stock-out with a reason, so history explains every change', 'Stock monitoring: level meters, filters and one-click Restock'],
        ['Receive 50 Ibuprofen from MediSource (batch + expiry required)', 'Try to release more than you have to see the error', 'Open “Stock monitoring” and set a new minimum level'], 'Regular users', 'recording-stock-in-out', 'feature-inventory'],
    'categories' => ['Category management', 'tags', 'Create, edit and organize product categories.',
        ['Unique names per inventory', 'Product count for every category', 'Deleting a category keeps its products (they become Uncategorized)'],
        ['Add “Eye care”, then assign it to a product', 'Try adding a duplicate name'], 'Regular users', null, 'feature-categories'],
    'suppliers' => ['Supplier management', 'truck', 'Register suppliers and keep their contact information.',
        ['Automatic codes like SUP-0001', 'Contact person, phone, email, address, Active/Inactive', 'Suppliers with purchase orders are protected from deletion'],
        ['Register a supplier with a bad email to see validation', 'Try deleting “PharmaPlus Wholesale”'], 'Regular users', 'registering-a-supplier', 'feature-suppliers'],
    'orders' => ['Purchase order management', 'receipt', 'Create purchase orders, choose suppliers and receive products into stock.',
        ['Draft → Ordered → Partial → Received life cycle', 'Add several items with quantity and unit cost', 'Receiving updates stock, batches and the order status'],
        ['Create an order, place it, then press Receive', 'Receive only part of an order to see “Partial”'], 'Regular users', 'creating-purchase-orders', 'feature-purchase-orders'],
    'expiry' => ['Expiration and batch / lot tracking', 'calendar-x', 'Monitor expiry dates and batch numbers, with near-expiry alerts.',
        ['Every delivery is a batch with its own number and expiry date', 'Expired / near-expiry (90-day window) / valid', 'Write off expired stock; oldest batch is sold first (FEFO)'],
        ['Filter to “Near expiry”', 'Write off the expired Paracetamol batch and watch stock drop'], 'Regular users', 'tracking-expiration-dates', 'feature-expiry'],
    'reports' => ['Reports and analytics', 'graph-up', 'Generate, filter, review and export inventory reports.',
        ['Stock levels, deliveries, releases, expiry, purchase orders, suppliers', 'Date-range filters and totals', 'Export to CSV for Excel, or print'],
        ['Generate “Stock levels”, then download the sample CSV', 'Switch to “Stock out” and change the dates'], 'Regular users (own inventory) · Administrators (platform-wide)', 'generating-reports', 'feature-reports'],
    'users' => ['User management', 'people', 'Administrator and regular-user roles, permissions and access restrictions.',
        ['Two roles: Administrator and Regular user', 'Administrators manage users, settings and read all inventories (read-only)', 'Pages are protected on the server, not just hidden in the menu'],
        ['Switch role, then press “Open Settings”', 'As administrator, suspend a sample user'], 'Administrators manage · everyone is affected', null, 'feature-users'],
];
$start = array_key_exists($_GET['module'] ?? '', $mods) ? $_GET['module'] : 'dashboard';

help_start('Feature showcase', 'help_showcase'); help_subnav('showcase'); ?>
<div class="panel mb-3"><div class="panel-head"><div><h2>Interactive feature showcase</h2><div class="text-secondary small mt-1">Choose a module to see what it does — then try it live below with sample data.</div></div>
  <button class="btn btn-light btn-sm" id="scReset"><i class="bi bi-arrow-counterclockwise"></i> Reset sample data</button></div></div>

<div class="hlp-modules" role="tablist" aria-label="Modules">
<?php foreach ($mods as $k => $m): ?><button type="button" role="tab" data-mod="<?= e($k) ?>" class="<?= $k === $start ? 'active' : '' ?>" aria-selected="<?= $k === $start ? 'true' : 'false' ?>"><i class="bi bi-<?= e($m[1]) ?>"></i><?= e($m[0]) ?></button><?php endforeach; ?>
</div>

<?php foreach ($mods as $k => $m): ?>
<div class="panel hlp-feature-info mb-3" data-info="<?= e($k) ?>" <?= $k === $start ? '' : 'hidden' ?>><div class="panel-body pt-4"><div class="row g-4">
  <div class="col-lg-6"><h3><i class="bi bi-<?= e($m[1]) ?> me-2" style="color:var(--accent)"></i><?= e($m[0]) ?></h3><p class="text-secondary"><?= e($m[2]) ?></p>
    <span class="hlp-chip"><i class="bi bi-person"></i> <?= e($m[5]) ?></span>
    <h4 class="h6 fw-semibold mt-3">What it does</h4><ul><?php foreach ($m[3] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></div>
  <div class="col-lg-6"><h4 class="h6 fw-semibold"><i class="bi bi-hand-index-thumb me-1"></i> Try this in the demo below</h4><ul><?php foreach ($m[4] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
    <div class="d-flex gap-2 flex-wrap mt-3"><?php if ($m[6]): ?><a class="btn btn-primary btn-sm" href="help_tutorial.php?slug=<?= e($m[6]) ?>"><i class="bi bi-mortarboard me-1"></i> Guided tutorial</a><?php endif; ?>
      <a class="btn btn-light btn-sm" href="help_article.php?slug=<?= e($m[7]) ?>"><i class="bi bi-journal-text me-1"></i> Read the guide</a></div></div>
</div></div></div>
<?php endforeach; ?>

<div class="hd-banner"><i class="bi bi-shield-check"></i><span><b>Demonstration mode.</b> Sample data in your browser only — real records are never changed.</span></div>
<div id="hdRoot"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var mods = <?= json_encode(array_keys($mods)) ?>, cur = <?= json_encode($start) ?>;
  var app = HelpDemo.mount(document.getElementById('hdRoot'), { module: cur, nav: false, noAutofocus: true });
  function show(k) {
    if (mods.indexOf(k) < 0) return; cur = k; app.go(k);
    document.querySelectorAll('[data-mod]').forEach(function (b) { var on = b.dataset.mod === k; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
    document.querySelectorAll('[data-info]').forEach(function (p) { p.hidden = p.dataset.info !== k; });
    try { history.replaceState(null, '', '#' + k); } catch (e) { }
  }
  document.querySelectorAll('[data-mod]').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.mod); }); });
  document.getElementById('scReset').addEventListener('click', function () { app.reset(); app.go(cur); app.toast('Sample data restored.'); });
  var h = location.hash.slice(1); if (h && h !== cur) show(h);
});
</script>
<?php help_end(['assets/js/help-demo.js']);
