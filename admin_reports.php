<?php
// Platform-wide reports with date filters, CSV export (opens in Excel) and print / Save as PDF.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();

$titles = ['users' => 'User registration report', 'inventories' => 'Inventory report', 'products' => 'Product report', 'stock_in' => 'Stock-in report',
           'stock_out' => 'Stock-out report', 'low_stock' => 'Low stock report', 'user_activity' => 'User activity report'];
$dated = ['users', 'inventories', 'stock_in', 'stock_out', 'user_activity'];   // the others are "as of now" snapshots
$type = isset($titles[$_GET['type'] ?? '']) ? $_GET['type'] : 'users';
[$from, $to] = date_range(date('Y-m-01'), date('Y-m-d'));
$owner = trim($_GET['owner'] ?? '');
$cols = []; $rows = []; $foot = []; $args = [];
$ownerLike = fn($fields) => $owner === '' ? '' : ' AND (' . implode(' OR ', array_map(fn($f) => "$f LIKE ?", $fields)) . ')';
$ownerArgs = fn($n) => $owner === '' ? [] : array_fill(0, $n, "%$owner%");
$period = in_array($type, $dated, true) ? date('M d, Y', strtotime($from)) . ' to ' . date('M d, Y', strtotime($to)) : 'As of ' . date('F d, Y');
$run = function ($sql, $args) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(); };
$dt = fn($v) => $v ? date('Y-m-d H:i', strtotime($v)) : '—';

if ($type === 'users') {
    $cols = ['Name', 'Username', 'Email', 'Role', 'Status', 'Registered', 'Last sign-in', 'Inventories'];
    foreach ($run('SELECT u.*, (SELECT COUNT(*) FROM inventories i WHERE i.user_id=u.id) n FROM users u WHERE DATE(u.created_at) BETWEEN ? AND ?' . $ownerLike(['u.full_name', 'u.username', 'u.email']) . ' ORDER BY u.created_at DESC LIMIT 5000', array_merge([$from, $to], $ownerArgs(3))) as $r)
        $rows[] = [$r['full_name'], $r['username'], $r['email'] ?? '—', ucfirst($r['role']), ucfirst($r['status']), $dt($r['created_at']), $dt($r['last_login']), (int)$r['n']];
    $foot = ['Total: ' . count($rows) . ' accounts', '', '', '', '', '', '', array_sum(array_column($rows, 7))];
} elseif ($type === 'inventories') {
    $cols = ['Inventory', 'Owner', 'Type', 'Created', 'Products', 'Units on hand', 'Low stock', 'Out of stock', 'Value at cost'];
    foreach ($run("SELECT i.name, i.business_type, i.created_at, u.full_name,
        (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id) n, (SELECT COALESCE(SUM(p.quantity),0) FROM products p WHERE p.inventory_id=i.id) units,
        (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id AND p.quantity>0 AND p.quantity<=p.min_stock) low, (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id AND p.quantity<=0) outs,
        (SELECT COALESCE(SUM(p.quantity*p.cost_price),0) FROM products p WHERE p.inventory_id=i.id) val
        FROM inventories i JOIN users u ON u.id=i.user_id WHERE DATE(i.created_at) BETWEEN ? AND ?" . $ownerLike(['i.name', 'u.full_name', 'u.username']) . ' ORDER BY i.created_at DESC LIMIT 5000', array_merge([$from, $to], $ownerArgs(3))) as $r)
        $rows[] = [$r['name'], $r['full_name'], $r['business_type'] ?? '—', $dt($r['created_at']), (int)$r['n'], (int)$r['units'], (int)$r['low'], (int)$r['outs'], money($r['val'])];
    $foot = ['Total: ' . count($rows), '', '', '', array_sum(array_column($rows, 4)), array_sum(array_column($rows, 5)), array_sum(array_column($rows, 6)), array_sum(array_column($rows, 7)), ''];
} elseif ($type === 'products' || $type === 'low_stock') {
    $cols = ['SKU', 'Product', 'Inventory', 'Owner', 'Category', 'On hand', 'Minimum', 'Status', 'Cost', 'Stock value'];
    $cond = $type === 'low_stock' ? ' AND p.quantity<=p.min_stock' : '';
    $tv = 0; $tq = 0;
    foreach ($run('SELECT p.*, c.name cat, i.name inv, u.full_name FROM products p JOIN inventories i ON i.id=p.inventory_id JOIN users u ON u.id=i.user_id LEFT JOIN categories c ON c.id=p.category_id WHERE 1' . $cond . $ownerLike(['u.full_name', 'u.username', 'i.name', 'p.name', 'p.sku']) . ' ORDER BY ' . ($type === 'low_stock' ? 'p.quantity/NULLIF(p.min_stock,0) ASC,' : '') . ' u.full_name, i.name, p.name LIMIT 5000', $ownerArgs(5)) as $p) {
        $v = $p['quantity'] * $p['cost_price']; $tv += $v; $tq += $p['quantity'];
        $rows[] = [$p['sku'], $p['name'], $p['inv'], $p['full_name'], $p['cat'] ?? 'Uncategorized', (int)$p['quantity'], (int)$p['min_stock'], stock_status($p['quantity'], $p['min_stock'])[0], money($p['cost_price']), money($v)];
    }
    $foot = ['Total: ' . count($rows), '', '', '', '', $tq, '', '', '', money($tv)];
} elseif ($type === 'stock_in' || $type === 'stock_out') {
    $in = $type === 'stock_in'; $T = $in ? 'stock_in' : 'stock_out';
    $cols = $in ? ['Date', 'Reference', 'Product', 'Inventory', 'Owner', 'Supplier', 'Quantity', 'Unit cost', 'Total cost', 'Recorded by']
                : ['Date', 'Reference', 'Product', 'Inventory', 'Owner', 'Reason', 'Quantity', 'Unit price', 'Sales amount', 'Recorded by'];
    $tq = 0; $tv = 0;
    foreach ($run("SELECT t.*, p.name pname, i.name inv, o.full_name owner, u.full_name by_name" . ($in ? ', s.name sname' : '') . " FROM $T t JOIN products p ON p.id=t.product_id JOIN inventories i ON i.id=t.inventory_id JOIN users o ON o.id=i.user_id LEFT JOIN users u ON u.id=t.user_id" . ($in ? ' LEFT JOIN suppliers s ON s.id=t.supplier_id' : '') . ' WHERE DATE(t.created_at) BETWEEN ? AND ?' . $ownerLike(['o.full_name', 'o.username', 'i.name', 'p.name']) . ' ORDER BY t.created_at DESC LIMIT 5000', array_merge([$from, $to], $ownerArgs(4))) as $r) {
        if ($in) { $amt = $r['quantity'] * $r['unit_cost']; $rows[] = [$dt($r['created_at']), $r['reference_no'], $r['pname'], $r['inv'], $r['owner'], $r['sname'] ?? '—', (int)$r['quantity'], money($r['unit_cost']), money($amt), $r['by_name'] ?? '—']; }
        else { $amt = $r['reason'] === 'sale' ? $r['quantity'] * $r['unit_price'] : 0; $rows[] = [$dt($r['created_at']), $r['reference_no'], $r['pname'], $r['inv'], $r['owner'], ucfirst($r['reason']), (int)$r['quantity'], $r['reason'] === 'sale' ? money($r['unit_price']) : '—', $r['reason'] === 'sale' ? money($amt) : '—', $r['by_name'] ?? '—']; }
        $tq += $r['quantity']; $tv += $amt;
    }
    $foot = ['Total: ' . count($rows), '', '', '', '', '', $tq, '', money($tv), ''];
} else { // user_activity
    $cols = ['User', 'Username', 'Status', 'Actions in period', 'Stock-in', 'Stock-out', 'Products added', 'Logins', 'Last activity'];
    foreach ($run("SELECT u.full_name, u.username, u.status, COUNT(l.id) n,
        COALESCE(SUM(l.action='stock_in'),0) si, COALESCE(SUM(l.action='stock_out'),0) so, COALESCE(SUM(l.action='product_add'),0) pa, COALESCE(SUM(l.action='login'),0) lg, MAX(l.created_at) last
        FROM users u JOIN activity_logs l ON l.user_id=u.id AND DATE(l.created_at) BETWEEN ? AND ? WHERE 1" . $ownerLike(['u.full_name', 'u.username']) . ' GROUP BY u.id ORDER BY n DESC LIMIT 5000', array_merge([$from, $to], $ownerArgs(2))) as $r)
        $rows[] = [$r['full_name'], $r['username'], ucfirst($r['status']), (int)$r['n'], (int)$r['si'], (int)$r['so'], (int)$r['pa'], (int)$r['lg'], $dt($r['last'])];
    $foot = ['Total: ' . count($rows) . ' users', '', '', array_sum(array_column($rows, 3)), array_sum(array_column($rows, 4)), array_sum(array_column($rows, 5)), array_sum(array_column($rows, 6)), array_sum(array_column($rows, 7)), ''];
}

if (($_GET['export'] ?? '') === 'csv') {
    log_activity('report_export', $titles[$type] . ' (' . count($rows) . ' rows)');
    csv_download('platform_' . $type . '_' . date('Ymd') . '.csv', $cols, $rows, $foot);
}
page_start('Reports', 'admin_reports', 'admin');
$shown = array_slice($rows, 0, 500);
?>
<form class="panel panel-body mb-3 no-print row g-3 align-items-end mx-0" method="get">
  <div class="col-md-3"><label class="form-label">Report</label><select class="form-select" name="type" onchange="this.form.submit()"><?php foreach ($titles as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <?php if (in_array($type, $dated, true)): ?>
    <div class="col-md-2"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <?php endif; ?>
  <div class="col-md-2"><label class="form-label">Owner / search</label><input class="form-control" name="owner" value="<?= e($owner) ?>" placeholder="Optional"></div>
  <div class="col-auto d-flex gap-2"><button class="btn btn-primary">Generate</button>
    <a class="btn btn-light" href="<?= e(qs(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> Export CSV / Excel</a>
    <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Print / PDF</button></div>
</form>
<div class="panel">
  <div class="panel-body border-bottom"><div class="print-title"><strong><?= e(site_name()) ?></strong> · Platform report</div>
    <h2 class="h5 mb-1"><?= e($titles[$type]) ?></h2><div class="text-secondary"><?= e($period) ?><?= $owner !== '' ? ' · filter: ' . e($owner) : '' ?> · Generated <?= date('M d, Y h:i A') ?> by <?= e(current_user()['full_name']) ?></div></div>
  <div class="table-responsive"><table class="table table-sm table-hover">
    <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($shown as $r): ?><tr><?php foreach ($r as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($cols) ?>" class="empty">No records for this selection.</td></tr><?php endif; ?>
    </tbody><?php if ($rows): ?><tfoot><tr class="fw-semibold"><?php foreach ($foot as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?></table></div>
  <?php if (count($rows) > 500): ?><div class="p-3 small text-secondary no-print">Showing the first 500 of <?= number_format(count($rows)) ?> rows. Totals include all rows. Export CSV for the full list.</div><?php endif; ?>
</div>
<?php page_end();
