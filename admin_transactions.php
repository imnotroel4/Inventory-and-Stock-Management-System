<?php
// All stock-in / stock-out transactions on the platform. Read-only.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
page_start('Transactions', 'admin_transactions', 'admin');

$type = ($_GET['type'] ?? 'in') === 'out' ? 'out' : 'in'; $T = $type === 'out' ? 'stock_out' : 'stock_in';   // whitelisted table name
[$from, $to] = date_range(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
$q = trim($_GET['q'] ?? ''); $owner = trim($_GET['owner'] ?? ''); $reason = $_GET['reason'] ?? '';
$where = ['DATE(t.created_at) BETWEEN ? AND ?']; $args = [$from, $to];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR t.reference_no LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($owner !== '') { $where[] = '(o.full_name LIKE ? OR o.username LIKE ? OR i.name LIKE ?)'; array_push($args, "%$owner%", "%$owner%", "%$owner%"); }
if ($type === 'out' && in_array($reason, ['sale', 'release', 'damaged', 'returned'], true)) { $where[] = 't.reason=?'; $args[] = $reason; }
$from_sql = "FROM $T t JOIN products p ON p.id=t.product_id JOIN inventories i ON i.id=t.inventory_id JOIN users o ON o.id=i.user_id LEFT JOIN users u ON u.id=t.user_id" . ($type === 'in' ? ' LEFT JOIN suppliers s ON s.id=t.supplier_id' : '') . ' WHERE ' . implode(' AND ', $where);
$agg = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(t.quantity),0) q $from_sql"); $agg->execute($args); $a = $agg->fetch(); $total = (int)$a['n'];
[$page, $pages, $off] = paginate($total, 25);
$st = $pdo->prepare("SELECT t.*, p.name pname, p.sku, i.id inv_id, i.name inv_name, o.id owner_id, o.full_name owner_name, u.full_name by_name" . ($type === 'in' ? ', s.name sname' : '') . " $from_sql ORDER BY t.created_at DESC, t.id DESC LIMIT 25 OFFSET $off"); $st->execute($args); $rows = $st->fetchAll();
$tab = fn($t, $l) => '<a class="btn btn-' . ($type === $t ? 'dark' : 'outline-secondary') . '" href="' . e(qs(['type' => $t, 'page' => '', 'reason' => ''])) . '">' . $l . '</a>';
?>
<div class="panel">
  <div class="panel-head"><div class="btn-group btn-group-sm"><?= $tab('in', 'Stock in') ?><?= $tab('out', 'Stock out') ?></div>
    <div class="small text-secondary"><?= number_format($total) ?> transactions · <?= number_format($a['q']) ?> units · <?= date('M d, Y', strtotime($from)) ?> to <?= date('M d, Y', strtotime($to)) ?></div></div>
  <form class="panel-body border-bottom row g-2 align-items-end mx-0" method="get"><input type="hidden" name="type" value="<?= $type ?>">
    <div class="col-md-2"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-1">Product / reference</label><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-1">Owner / inventory</label><input class="form-control form-control-sm" name="owner" value="<?= e($owner) ?>"></div>
    <?php if ($type === 'out'): ?><div class="col-md-2"><label class="form-label small mb-1">Reason</label><select class="form-select form-select-sm" name="reason"><option value="">Any</option><?php foreach (['sale', 'release', 'damaged', 'returned'] as $r): ?><option value="<?= $r ?>" <?= $reason === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="col-auto d-flex gap-2"><button class="btn btn-sm btn-primary">Apply</button><a class="btn btn-sm btn-light" href="<?= e(qs(['from' => '2000-01-01', 'to' => date('Y-m-d'), 'page' => ''])) ?>">All time</a></div></form>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Date</th><th>Reference</th><th>Product</th><th>Inventory</th><th>Owner</th><th class="text-end">Qty</th><th><?= $type === 'in' ? 'Supplier' : 'Reason' ?></th><th>Recorded by</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="small"><?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></td><td><?= e($r['reference_no']) ?></td><td><div class="fw-medium"><?= e($r['pname']) ?></div><small class="text-secondary"><?= e($r['sku']) ?></small></td>
      <td><a class="text-decoration-none" href="admin_inventory.php?id=<?= $r['inv_id'] ?>"><?= e($r['inv_name']) ?></a></td><td><a class="text-decoration-none" href="admin_user.php?id=<?= $r['owner_id'] ?>"><?= e($r['owner_name']) ?></a></td>
      <td class="text-end fw-medium"><?= $type === 'in' ? '+' : '−' ?><?= (int)$r['quantity'] ?></td>
      <td><?= $type === 'in' ? e($r['sname'] ?? '—') . ($r['batch_no'] ? '<div class="small text-secondary">Batch ' . e($r['batch_no']) . ' · exp ' . e($r['expiry_date']) . '</div>' : '') : '<span class="badge badge-' . ['sale' => 'success', 'release' => 'info', 'damaged' => 'danger', 'returned' => 'muted'][$r['reason']] . '">' . e(ucfirst($r['reason'])) . '</span>' ?></td><td><?= e($r['by_name'] ?? '—') ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">No transactions in this period.</td></tr><?php endif; ?></tbody></table></div>
  <?= pager_html($page, $pages) ?>
</div>
<?php page_end();
