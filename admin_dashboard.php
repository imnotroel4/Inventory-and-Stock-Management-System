<?php
// Platform overview for administrators. Every figure comes straight from the database.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
page_start('Admin dashboard', 'admin_dashboard', 'admin');

$one = fn($sql) => (int)$pdo->query($sql)->fetchColumn();
$users = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(status='active'),0) active, COALESCE(SUM(status='suspended'),0) suspended, COALESCE(SUM(status='inactive'),0) inactive FROM users WHERE role='user'")->fetch();
$admins = $one("SELECT COUNT(*) FROM users WHERE role='admin'");
$invCount = $one('SELECT COUNT(*) FROM inventories');
$prodCount = $one('SELECT COUNT(*) FROM products');
$catCount = $one('SELECT COUNT(*) FROM categories');
$supCount = $one('SELECT COUNT(*) FROM suppliers');
$inTx = $one('SELECT COUNT(*) FROM stock_in');   $outTx = $one('SELECT COUNT(*) FROM stock_out');
$low = $one('SELECT COUNT(*) FROM products WHERE quantity>0 AND quantity<=min_stock'); $out = $one('SELECT COUNT(*) FROM products WHERE quantity<=0');

// Chart data
$reg = month_counts($pdo, "SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) v FROM users WHERE role='user' AND created_at >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH,'%Y-%m-01') GROUP BY m");
$invNew = month_counts($pdo, "SELECT DATE_FORMAT(created_at,'%Y-%m') m, COUNT(*) v FROM inventories WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH,'%Y-%m-01') GROUP BY m");
$run = $one("SELECT COUNT(*) FROM inventories WHERE created_at < DATE_FORMAT(NOW() - INTERVAL 11 MONTH,'%Y-%m-01')"); $invTotal = [];
foreach ($invNew as $k => $v) { $run += $v; $invTotal[$k] = $run; }
$flowIn = month_counts($pdo, "SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(quantity) v FROM stock_in WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH,'%Y-%m-01') GROUP BY m");
$flowOut = month_counts($pdo, "SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(quantity) v FROM stock_out WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH,'%Y-%m-01') GROUP BY m");
$dist = $pdo->query('SELECT i.name, COUNT(p.id) n FROM inventories i JOIN products p ON p.inventory_id=i.id GROUP BY i.id ORDER BY n DESC LIMIT 6')->fetchAll();
$distOther = $prodCount - array_sum(array_column($dist, 'n'));
$active = $pdo->query("SELECT u.full_name, COUNT(*) n FROM activity_logs l JOIN users u ON u.id=l.user_id WHERE u.role='user' AND l.created_at >= NOW() - INTERVAL 30 DAY AND l.action NOT IN ('login','logout','login_failed','login_blocked','register') GROUP BY u.id ORDER BY n DESC LIMIT 8")->fetchAll();
$recent = $pdo->query('SELECT l.*, u.full_name, i.name inv FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN inventories i ON i.id=l.inventory_id ORDER BY l.id DESC LIMIT 10')->fetchAll();
$cards = [
    ['Registered Users', $users['total'], $admins . ' admin' . ($admins == 1 ? '' : 's'), 'flat', 'people', 'users.php'],
    ['Active Users', $users['active'], $users['inactive'] ? $users['inactive'] . ' inactive' : 'All good', 'up', 'person-check', 'users.php'],
    ['Suspended Users', $users['suspended'], $users['suspended'] ? 'Review' : 'None', $users['suspended'] ? 'down' : 'flat', 'person-slash', 'users.php'],
    ['Total Inventories', $invCount, 'View all', 'flat', 'collection', 'admin_inventories.php'],
    ['Total Products', $prodCount, $low + $out . ' need attention', ($low + $out) ? 'warn' : 'up', 'box-seam', 'admin_products.php'],
    ['Total Categories', $catCount, '', 'flat', 'tags', 'admin_inventories.php'],
    ['Total Suppliers', $supCount, '', 'flat', 'truck', 'admin_inventories.php'],
    ['Stock-In Transactions', $inTx, 'View', 'up', 'box-arrow-in-down', 'admin_transactions.php?type=in'],
    ['Stock-Out Transactions', $outTx, 'View', 'down', 'box-arrow-up', 'admin_transactions.php?type=out'],
];
?>
<div class="row g-3 mb-3">
  <?php foreach ($cards as [$l, $v, $pill, $c, $ic, $href]): ?>
    <div class="col-6 col-md-4 col-xl"><a class="text-decoration-none" href="<?= $href ?>"><div class="panel stat"><div class="top"><span><?= $l ?></span><i class="bi bi-<?= $ic ?> text-secondary"></i></div><div class="v"><?= number_format($v) ?></div><?php if ($pill !== ''): ?><span class="trend <?= $c ?> mt-2 d-inline-block"><?= e($pill) ?></span><?php endif; ?></div></a></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><h2>User registrations</h2><span class="text-secondary small">Last 12 months</span></div><div class="panel-body"><canvas id="chReg" height="150"></canvas></div></div></div>
  <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><h2>Inventory growth</h2><span class="text-secondary small">New per month and running total</span></div><div class="panel-body"><canvas id="chInv" height="150"></canvas></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="panel h-100"><div class="panel-head"><h2>Product distribution</h2></div><div class="panel-body"><?php if ($prodCount): ?><canvas id="chDist" height="220"></canvas><?php else: ?><div class="empty">No products yet.</div><?php endif; ?></div></div></div>
  <div class="col-lg-8"><div class="panel h-100"><div class="panel-head"><h2>Stock-in and stock-out activity</h2><span class="text-secondary small">Units per month</span></div><div class="panel-body"><canvas id="chFlow" height="120"></canvas></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><h2>Most active users</h2><span class="text-secondary small">Last 30 days</span></div><div class="panel-body"><?php if ($active): ?><canvas id="chActive" height="200"></canvas><?php else: ?><div class="empty">No user activity in the last 30 days.</div><?php endif; ?></div></div></div>
  <div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><h2>Latest activity</h2><a class="btn btn-light btn-pill btn-sm" href="admin_logs.php">View all</a></div>
    <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Inventory</th></tr></thead><tbody>
    <?php foreach ($recent as $l): ?><tr><td class="small"><?= date('M d, h:i A', strtotime($l['created_at'])) ?></td><td><?= e($l['full_name'] ?? 'Guest / deleted') ?></td><td><span class="badge badge-muted"><?= e($l['action']) ?></span></td><td class="small"><?= e($l['inv'] ?? '—') ?></td></tr>
    <?php endforeach; if (!$recent): ?><tr><td colspan="4" class="empty">No activity yet.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const calm = matchMedia('(prefers-reduced-motion: reduce)').matches, anim = calm ? false : { duration: 800, easing: 'easeOutQuart' };
Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif"; Chart.defaults.color = '#7a82a0';
const months = <?= json_encode(month_labels($reg)) ?>;
const scales = { x: { grid: { display: false }, border: { display: false } }, y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef0f7', borderDash: [4, 4] }, ticks: { precision: 0 } } };
new Chart(chReg, { type: 'bar', data: { labels: months, datasets: [{ label: 'New users', data: <?= json_encode(array_values($reg)) ?>, backgroundColor: '#7c6cf0', borderRadius: 6, borderSkipped: false }] }, options: { animation: anim, plugins: { legend: { display: false } }, scales } });
new Chart(chInv, { data: { labels: months, datasets: [
  { type: 'bar', label: 'New inventories', data: <?= json_encode(array_values($invNew)) ?>, backgroundColor: '#e4e0fc', borderRadius: 6, borderSkipped: false },
  { type: 'line', label: 'Total inventories', data: <?= json_encode(array_values($invTotal)) ?>, borderColor: '#6657d8', backgroundColor: '#6657d8', tension: .3, pointRadius: 3 } ] }, options: { animation: anim, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } }, scales } });
new Chart(chFlow, { type: 'bar', data: { labels: months, datasets: [
  { label: 'Stock in', data: <?= json_encode(array_values($flowIn)) ?>, backgroundColor: '#7c6cf0', borderRadius: 6, borderSkipped: false },
  { label: 'Stock out', data: <?= json_encode(array_values($flowOut)) ?>, backgroundColor: '#fb8f86', borderRadius: 6, borderSkipped: false } ] }, options: { animation: anim, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } }, scales } });
<?php if ($prodCount): $dl = array_column($dist, 'name'); $dv = array_map('intval', array_column($dist, 'n')); if ($distOther > 0) { $dl[] = 'Others'; $dv[] = $distOther; } ?>
new Chart(chDist, { type: 'doughnut', data: { labels: <?= json_encode($dl) ?>, datasets: [{ data: <?= json_encode($dv) ?>, backgroundColor: ['#7c6cf0', '#fb8f86', '#6cc68b', '#f2c14e', '#5aa9e6', '#b3a8fb', '#c9cce0'], borderWidth: 0, spacing: 2 }] }, options: { cutout: '62%', animation: anim, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } } } });
<?php endif; if ($active): ?>
new Chart(chActive, { type: 'bar', data: { labels: <?= json_encode(array_column($active, 'full_name')) ?>, datasets: [{ label: 'Actions', data: <?= json_encode(array_map('intval', array_column($active, 'n'))) ?>, backgroundColor: '#7c6cf0', borderRadius: 6 }] }, options: { indexAxis: 'y', animation: anim, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef0f7' } }, y: { grid: { display: false } } } } });
<?php endif; ?>
</script>
<?php page_end();
