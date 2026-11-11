<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];

$type = $_GET['type'] ?? 'inventory';
$from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? date('Y-m-d'); $year = (int)($_GET['year'] ?? date('Y'));
$titles = ['inventory' => 'Inventory report', 'stock_in' => 'Stock-in report', 'stock_out' => 'Stock-out report', 'monthly' => 'Monthly transaction report', 'expiry' => 'Expiry & batch report', 'purchase_orders' => 'Purchase order report', 'suppliers' => 'Supplier report'];
if (!isset($titles[$type])) $type = 'inventory';
$cols = []; $rows = []; $foot = []; $period = 'As of ' . date('F d, Y');

if ($type === 'inventory') {
    $cols = ['SKU', 'Product', 'Category', 'On hand', 'Minimum', 'Status', 'Cost', 'Stock value'];
    $tv = 0; $tq = 0;
    $pq = $pdo->prepare('SELECT p.*,c.name cat FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.inventory_id=? ORDER BY p.name'); $pq->execute([$iid]);
    foreach ($pq as $p) {
        $v = $p['quantity'] * $p['cost_price']; $tv += $v; $tq += $p['quantity'];
        $rows[] = [$p['sku'], $p['name'], $p['cat'] ?? 'Uncategorized', $p['quantity'], $p['min_stock'], stock_status($p['quantity'], $p['min_stock'])[0], money($p['cost_price']), money($v)];
    }
    $foot = ['Total', '', '', $tq, '', '', '', money($tv)];
} elseif ($type === 'expiry') {
    $cols = ['SKU', 'Medicine', 'Batch / lot', 'Supplier', 'Received', 'Expiry date', 'Days left', 'Status', 'Qty remaining', 'Stock value'];
    $tq = 0; $tv = 0;
    $bq = $pdo->prepare('SELECT b.*, p.name, p.sku, p.expiry_alert_days, s.name sname FROM product_batches b JOIN products p ON p.id=b.product_id LEFT JOIN suppliers s ON s.id=b.supplier_id WHERE b.inventory_id=? AND b.qty_remaining>0 ORDER BY b.expiry_date, p.name'); $bq->execute([$iid]);
    foreach ($bq as $b) {
        [$s, $d] = expiry_state($b['expiry_date'], $b['expiry_alert_days']); $v = $b['qty_remaining'] * $b['unit_cost']; $tq += $b['qty_remaining']; $tv += $v;
        $rows[] = [$b['sku'], $b['name'], $b['batch_no'], $b['sname'] ?? '—', date('Y-m-d', strtotime($b['received_at'])), $b['expiry_date'], $d, ['expired' => 'Expired', 'near' => 'Near expiry', 'ok' => 'Valid'][$s], $b['qty_remaining'], money($v)];
    }
    $foot = ['Total', '', '', '', '', '', '', '', $tq, money($tv)];
} elseif ($type === 'purchase_orders') {
    $period = date('M d, Y', strtotime($from)) . ' to ' . date('M d, Y', strtotime($to));
    $cols = ['PO no.', 'Order date', 'Supplier ID', 'Supplier', 'Status', 'Units ordered', 'Units received', 'Total value'];
    $tq = 0; $tr = 0; $tv = 0;
    $pq = $pdo->prepare('SELECT po.*, s.name sname, s.supplier_code, (SELECT COALESCE(SUM(quantity),0) FROM purchase_order_items WHERE po_id=po.id) qo, (SELECT COALESCE(SUM(qty_received),0) FROM purchase_order_items WHERE po_id=po.id) qr, (SELECT COALESCE(SUM(quantity*unit_cost),0) FROM purchase_order_items WHERE po_id=po.id) tv FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.inventory_id=? AND po.order_date BETWEEN ? AND ? ORDER BY po.order_date DESC, po.id DESC');
    $pq->execute([$iid, $from, $to]);
    foreach ($pq as $r) { $tq += $r['qo']; $tr += $r['qr']; $tv += $r['tv']; $rows[] = [$r['po_number'], $r['order_date'], $r['supplier_code'], $r['sname'], PO_STATUS_LABEL[$r['status']], $r['qo'], $r['qr'], money($r['tv'])]; }
    $foot = ['Total', '', '', '', '', $tq, $tr, money($tv)];
} elseif ($type === 'suppliers') {
    $cols = ['Supplier ID', 'Supplier', 'Contact person', 'Phone', 'Email', 'Address', 'Status', 'Registered', 'Purchase orders', 'Units delivered'];
    $tn = 0; $tu = 0;
    $sq = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id=s.id) pos, (SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE supplier_id=s.id) units FROM suppliers s WHERE s.inventory_id=? ORDER BY s.supplier_code'); $sq->execute([$iid]);
    foreach ($sq as $r) { $tn += $r['pos']; $tu += $r['units']; $rows[] = [$r['supplier_code'], $r['name'], $r['contact_person'], $r['phone'], $r['email'], $r['address'], ucfirst($r['status']), $r['registered_at'] ?: date('Y-m-d', strtotime($r['created_at'])), $r['pos'], $r['units']]; }
    $foot = ['Total', '', '', '', '', '', '', '', $tn, $tu];
} elseif ($type === 'stock_in' || $type === 'stock_out') {
    $period = date('M d, Y', strtotime($from)) . ' to ' . date('M d, Y', strtotime($to));
    $st = $pdo->prepare("SELECT t.*,p.name,p.sku,u.full_name" . ($type === 'stock_in' ? ',s.name sname,po.po_number' : '') . " FROM $type t JOIN products p ON p.id=t.product_id LEFT JOIN users u ON u.id=t.user_id " . ($type === 'stock_in' ? 'LEFT JOIN suppliers s ON s.id=t.supplier_id LEFT JOIN purchase_orders po ON po.id=t.po_id ' : '') . 'WHERE t.inventory_id=? AND DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC');
    $st->execute([$iid, $from, $to]); $tq = 0; $tv = 0;
    if ($type === 'stock_in') {
        $cols = ['Date', 'Reference', 'PO no.', 'Product', 'Supplier', 'Batch / lot', 'Expiry date', 'Quantity', 'Unit cost', 'Total cost', 'Recorded by'];
        foreach ($st as $r) { $tq += $r['quantity']; $tv += $r['quantity'] * $r['unit_cost']; $rows[] = [date('Y-m-d H:i', strtotime($r['created_at'])), $r['reference_no'], $r['po_number'] ?? '—', $r['name'], $r['sname'] ?? '—', $r['batch_no'] ?? '—', $r['expiry_date'] ?? '—', $r['quantity'], money($r['unit_cost']), money($r['quantity'] * $r['unit_cost']), $r['full_name'] ?? '—']; }
        $foot = ['Total', '', '', '', '', '', '', $tq, '', money($tv), ''];
    } else {
        $cols = ['Date', 'Reference', 'Product', 'Reason', 'Quantity', 'Unit price', 'Sales amount', 'Recorded by'];
        foreach ($st as $r) { $amt = $r['reason'] === 'sale' ? $r['quantity'] * $r['unit_price'] : 0; $tq += $r['quantity']; $tv += $amt; $rows[] = [date('Y-m-d H:i', strtotime($r['created_at'])), $r['reference_no'], $r['name'], ucfirst($r['reason']), $r['quantity'], $r['reason'] === 'sale' ? money($r['unit_price']) : '—', $r['reason'] === 'sale' ? money($amt) : '—', $r['full_name'] ?? '—']; }
        $foot = ['Total', '', '', '', $tq, '', money($tv), ''];
    }
} else {
    $period = "Year $year";
    $cols = ['Month', 'Units in', 'Cost of stock in', 'Units out', 'Units sold', 'Sales revenue', 'Transactions'];
    $m = []; for ($i = 1; $i <= 12; $i++) $m[$i] = array_fill(0, 6, 0);
    $q = $pdo->prepare('SELECT MONTH(created_at) m, SUM(quantity) q, SUM(quantity*unit_cost) c, COUNT(*) n FROM stock_in WHERE inventory_id=? AND YEAR(created_at)=? GROUP BY m'); $q->execute([$iid, $year]);
    foreach ($q as $r) { $m[$r['m']][0] = $r['q']; $m[$r['m']][1] = $r['c']; $m[$r['m']][5] += $r['n']; }
    $q = $pdo->prepare("SELECT MONTH(created_at) m, SUM(quantity) q, SUM(IF(reason='sale',quantity,0)) sq, SUM(IF(reason='sale',quantity*unit_price,0)) rev, COUNT(*) n FROM stock_out WHERE inventory_id=? AND YEAR(created_at)=? GROUP BY m"); $q->execute([$iid, $year]);
    foreach ($q as $r) { $m[$r['m']][2] = $r['q']; $m[$r['m']][3] = $r['sq']; $m[$r['m']][4] = $r['rev']; $m[$r['m']][5] += $r['n']; }
    $t = array_fill(0, 6, 0);
    foreach ($m as $i => $v) { foreach ($v as $k => $x) $t[$k] += $x; $rows[] = [date('F', mktime(0, 0, 0, $i, 1)), (int)$v[0], money($v[1]), (int)$v[2], (int)$v[3], money($v[4]), (int)$v[5]]; }
    $foot = ['Total', (int)$t[0], money($t[1]), (int)$t[2], (int)$t[3], money($t[4]), (int)$t[5]];
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $type . '_' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $cols);
    $safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v) ? "'" . $v : $v;   // blocks spreadsheet formula injection
    foreach ($rows as $r) fputcsv($o, array_map($safe, $r)); fputcsv($o, $foot); fclose($o);
    log_activity('report_export', $titles[$type]); exit;
}
page_start('Reports', 'reports');
?>
<form class="panel panel-body mb-3 no-print row g-3 align-items-end mx-0" method="get">
  <div class="col-md-3"><label class="form-label">Report</label><select class="form-select" name="type" onchange="this.form.submit()"><?php foreach ($titles as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <?php if (in_array($type, ['stock_in', 'stock_out', 'purchase_orders'], true)): ?>
    <div class="col-md-2"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <?php elseif ($type === 'monthly'): ?>
    <div class="col-md-2"><label class="form-label">Year</label><input class="form-control" type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></div>
  <?php endif; ?>
  <div class="col-auto d-flex gap-2"><button class="btn btn-primary">Generate</button>
    <a class="btn btn-light" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
</form>
<div class="panel">
  <div class="panel-body border-bottom"><div class="print-title"><strong>StockRoom</strong> · <?= e($inv['name']) ?></div>
    <h2 class="h5 mb-1"><?= $titles[$type] ?></h2><div class="text-secondary"><?= e($period) ?> · Generated <?= date('M d, Y h:i A') ?> by <?= e(current_user()['full_name']) ?></div></div>
  <div class="table-responsive"><table class="table table-sm table-hover">
    <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($cols) ?>" class="empty">No records for this period.</td></tr><?php endif; ?>
    </tbody><?php if ($rows): ?><tfoot><tr class="fw-semibold"><?php foreach ($foot as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?></table></div>
</div>
<?php page_end();
