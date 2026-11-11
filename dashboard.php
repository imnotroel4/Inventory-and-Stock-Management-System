<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];   // everything on this page belongs to the current inventory only
page_start('Dashboard', 'dashboard');

$one = function ($sql, $args = []) use ($pdo, $iid) { $st = $pdo->prepare($sql); $st->execute(array_merge([$iid], $args)); return $st->fetchColumn(); };
$total = (int)$one('SELECT COUNT(*) FROM products WHERE inventory_id=?');
$out = (int)$one('SELECT COUNT(*) FROM products WHERE inventory_id=? AND quantity<=0');
$low = (int)$one('SELECT COUNT(*) FROM products WHERE inventory_id=? AND quantity>0 AND quantity<=min_stock');
$in = $total - $out - $low;
$catCount = (int)$one('SELECT COUNT(*) FROM categories WHERE inventory_id=?');
$supCount = (int)$one('SELECT COUNT(*) FROM suppliers WHERE inventory_id=?');
$inTx = (int)$one('SELECT COUNT(*) FROM stock_in WHERE inventory_id=?');   $inUnits = (int)$one('SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE inventory_id=?');
$outTx = (int)$one('SELECT COUNT(*) FROM stock_out WHERE inventory_id=?'); $outUnits = (int)$one('SELECT COALESCE(SUM(quantity),0) FROM stock_out WHERE inventory_id=?');
$newThisMonth = (int)$one("SELECT COUNT(*) FROM products WHERE inventory_id=? AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')");
$value = (float)$one('SELECT COALESCE(SUM(quantity*cost_price),0) FROM products WHERE inventory_id=?');

// Last 12 months of stock in vs stock out
$months = []; for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime("first day of -$i month"))] = ['in' => 0, 'out' => 0];
$from = array_key_first($months) . '-01';
foreach (['in' => 'stock_in', 'out' => 'stock_out'] as $k => $t) {
    $st = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(quantity) q FROM $t WHERE inventory_id=? AND created_at>=? GROUP BY m");
    $st->execute([$iid, $from]);
    foreach ($st as $r) if (isset($months[$r['m']])) $months[$r['m']][$k] = (int)$r['q'];
}
$products = $pdo->prepare('SELECT p.*, c.name AS cat FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.inventory_id=? ORDER BY p.updated_at DESC, p.id DESC LIMIT 8'); $products->execute([$iid]); $products = $products->fetchAll();
$recent = $pdo->prepare('SELECT m.*,p.name,u.full_name FROM stock_movements m JOIN products p ON p.id=m.product_id LEFT JOIN users u ON u.id=m.user_id WHERE m.inventory_id=? ORDER BY m.id DESC LIMIT 8'); $recent->execute([$iid]); $recent = $recent->fetchAll();
$key = ['success' => 'in', 'warning' => 'low', 'danger' => 'out'];
$ec = expiry_counts($iid); $expRows = expiry_alert_rows($iid, 8);
?>
<div class="row g-3 mb-3">
  <?php foreach ([
      ['Total Products', $total, $newThisMonth ? '+' . $newThisMonth . ' new' : 'No new', $newThisMonth ? 'up' : 'flat', 'products.php'],
      ['Total Categories', $catCount, 'Manage', 'flat', 'categories.php'],
      ['Total Suppliers', $supCount, 'Manage', 'flat', 'suppliers.php'],
      ['Total Stock-In', $inUnits, $inTx . ' deliveries', 'up', 'stock_in.php'],
      ['Total Stock-Out', $outUnits, $outTx . ' releases', 'down', 'stock_out.php'],
      ['Low Stock Products', $low + $out, $out ? $out . ' out' : 'None out', ($low + $out) ? 'warn' : 'up', 'monitoring.php?status=attention'],
      ['Near Expiry', $ec['near'], number_format($ec['near_units']) . ' units', $ec['near'] ? 'warn' : 'up', 'expiry.php?status=near'],
      ['Expired Batches', $ec['expired'], number_format($ec['expired_units']) . ' units', $ec['expired'] ? 'down' : 'up', 'expiry.php?status=expired'],
  ] as [$l, $v, $pill, $c, $href]): ?>
    <div class="col-6 col-md-4 col-xl-3"><a class="text-decoration-none" href="<?= $href ?>"><div class="panel stat"><div class="top"><span><?= $l ?></span></div><div class="v"><?= number_format($v) ?></div><span class="trend <?= $c ?> mt-2 d-inline-block"><?= e($pill) ?></span></div></a></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="panel h-100">
    <div class="panel-head"><h2>Stock Flow</h2>
      <div class="legend"><span><i style="background:#7c6cf0"></i>Stock in</span><span><i style="background:#fb8f86"></i>Stock out</span><span class="d-none d-md-inline">· Value at cost <strong class="text-body"><?= money($value) ?></strong></span></div></div>
    <div class="panel-body"><canvas id="movementChart" height="120"></canvas></div>
  </div></div>
  <div class="col-lg-4"><div class="panel h-100">
    <div class="panel-head"><h2>Inventory Alerts</h2></div>
    <div class="donut"><canvas id="alertChart"></canvas><div class="mid"><b><?= number_format($total) ?></b><span>Products</span></div></div>
    <div class="donut-legend">
      <div><small><i style="background:#6cc68b"></i>In Stock</small><b><?= $in ?></b></div>
      <div><small><i style="background:#fb8f86"></i>Low Stock</small><b><?= $low ?></b></div>
      <div><small><i style="background:#c9cce0"></i>Out of Stock</small><b><?= $out ?></b></div>
    </div>
  </div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h2>Expiry Alerts</h2><a class="btn btn-light btn-pill" href="expiry.php">See All</a></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Medicine</th><th>Batch / lot</th><th>Supplier</th><th>Expiry date</th><th class="text-end">Units left</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($expRows as $x): ?>
      <tr><td class="fw-medium"><?= e($x['pname']) ?></td><td><?= e($x['batch_no']) ?></td><td><?= e($x['sname'] ?? '—') ?></td><td><?= fmt_date($x['expiry_date']) ?></td><td class="text-end"><?= (int)$x['qty_remaining'] ?></td><td><?= expiry_badge($x['expiry_date'], $x['alert_days']) ?></td></tr>
    <?php endforeach; if (!$expRows): ?><tr><td colspan="6" class="empty">No batches are expired or near expiry.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h2>Product Inventory</h2>
    <div class="d-flex gap-2">
      <div class="dropdown" data-status-filter>
        <button class="btn btn-light btn-pill" data-bs-toggle="dropdown"><i class="bi bi-funnel me-1"></i><span id="filterLabel">Filter</span> <i class="bi bi-chevron-down small"></i></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="#" data-status="all">All products</a></li><li><a class="dropdown-item" href="#" data-status="in">In stock</a></li>
          <li><a class="dropdown-item" href="#" data-status="low">Low stock</a></li><li><a class="dropdown-item" href="#" data-status="out">Out of stock</a></li>
        </ul>
      </div>
      <a class="btn btn-light btn-pill" href="products.php">See All</a>
    </div>
  </div>
  <div class="table-responsive"><table class="table table-hover" id="invTable">
    <thead><tr><th>Name</th><th>SKU</th><th>Category</th><th>Units</th><th>Level</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): [, $c] = stock_status($p['quantity'], $p['min_stock']); ?>
      <tr data-status="<?= $key[$c] ?>">
        <td><div class="d-flex align-items-center gap-3"><?php if ($p['image']): ?><img class="thumb" src="uploads/<?= e($p['image']) ?>" alt=""><?php else: ?><div class="thumb"><i class="bi bi-image"></i></div><?php endif; ?><span class="fw-medium"><?= e($p['name']) ?></span></div></td>
        <td><?= e($p['sku']) ?></td><td><?= e($p['cat'] ?? '—') ?></td><td><?= number_format($p['quantity']) ?></td>
        <td style="min-width:120px"><?= level_meter($p['quantity'], $p['min_stock']) ?></td>
        <td><?= status_badge($p['quantity'], $p['min_stock']) ?></td>
        <td class="text-secondary small"><?= date('M d, Y', strtotime($p['updated_at'])) ?></td>
        <td class="text-nowrap">
          <a class="icon-btn" href="products.php?q=<?= urlencode($p['sku']) ?>" title="Open in Products"><i class="bi bi-pencil"></i></a>
          <a class="icon-btn" href="stock_in.php?product=<?= $p['id'] ?>" title="Restock"><i class="bi bi-box-arrow-in-down"></i></a>
        </td></tr>
    <?php endforeach; if (!$products): ?><tr><td colspan="8" class="empty">No products yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Recent Inventory Activities</h2></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="text-end">Quantity</th><th class="text-end">Balance</th><th>Reference</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= date('M d, h:i A', strtotime($r['created_at'])) ?></td><td><?= e($r['name']) ?></td>
        <td><span class="badge badge-<?= $r['type'] === 'out' ? 'danger' : ($r['type'] === 'in' ? 'success' : 'info') ?>"><?= $r['type'] === 'out' ? 'Stock out' : ($r['type'] === 'in' ? 'Stock in' : 'Opening') ?></span></td>
        <td class="text-end"><?= $r['type'] === 'out' ? '−' : '+' ?><?= (int)$r['quantity'] ?></td><td class="text-end"><?= (int)$r['balance_after'] ?></td>
        <td><?= e($r['reference_no']) ?></td><td><?= e($r['full_name'] ?? '—') ?></td></tr>
    <?php endforeach; if (!$recent): ?><tr><td colspan="7" class="empty">No transactions yet. Record a stock in or stock out to see activity here.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
Chart.defaults.color = '#7a82a0';
const labels = <?= json_encode(array_map(fn($m) => date('M', strtotime($m . '-01')), array_keys($months))) ?>;
const cur = labels.length - 1;
const tint = (strong, soft) => labels.map((_, i) => i === cur ? strong : soft);
new Chart(document.getElementById('movementChart'), {
  type: 'bar',
  data: { labels, datasets: [
    { label: 'Stock in', data: <?= json_encode(array_column($months, 'in')) ?>, backgroundColor: tint('#7c6cf0', '#e4e0fc'), borderRadius: 6, borderSkipped: false },
    { label: 'Stock out', data: <?= json_encode(array_column($months, 'out')) ?>, backgroundColor: tint('#fb8f86', '#fde3e0'), borderRadius: 6, borderSkipped: false } ] },
  options: { animation: calm ? false : { duration: 900, easing: 'easeOutQuart', delay: c => c.type === 'data' ? c.dataIndex * 60 + 250 : 0 },
    plugins: { legend: { display: false } },
    scales: { x: { stacked: true, grid: { display: false }, border: { display: false } }, y: { stacked: true, beginAtZero: true, border: { display: false }, grid: { color: '#eef0f7', borderDash: [4, 4] } } } }
});
new Chart(document.getElementById('alertChart'), {
  type: 'doughnut',
  data: { labels: ['In stock', 'Low stock', 'Out of stock'], datasets: [{ data: [<?= $in ?>, <?= $low ?>, <?= $out ?>], backgroundColor: ['#6cc68b', '#fb8f86', '#c9cce0'], borderWidth: 0, spacing: 3, borderRadius: 8 }] },
  options: { cutout: '72%', animation: calm ? false : { duration: 1000, easing: 'easeOutQuart' }, plugins: { legend: { display: false } } }
});
</script>
<?php page_end();
