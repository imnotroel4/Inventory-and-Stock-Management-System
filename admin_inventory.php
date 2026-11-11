<?php
// Inspect ONE inventory. Strictly read-only: there is no form on this page that writes anything,
// so looking inside a user's inventory can never change its contents or its owner.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, u.full_name, u.username, u.email, u.status ustatus FROM inventories i JOIN users u ON u.id=i.user_id WHERE i.id=?'); $st->execute([$id]); $inv = $st->fetch();
if (!$inv) { http_response_code(404); page_start('Not found', 'admin_inventories', 'admin'); echo '<div class="alert alert-warning">That inventory does not exist. <a href="admin_inventories.php">Back to all inventories</a></div>'; page_end(); exit; }
page_start($inv['name'], 'admin_inventories', 'admin');

$one = function ($sql) use ($pdo, $id) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchColumn(); };
$n = ['products' => (int)$one('SELECT COUNT(*) FROM products WHERE inventory_id=?'), 'cats' => (int)$one('SELECT COUNT(*) FROM categories WHERE inventory_id=?'), 'sups' => (int)$one('SELECT COUNT(*) FROM suppliers WHERE inventory_id=?'),
  'low' => (int)$one('SELECT COUNT(*) FROM products WHERE inventory_id=? AND quantity>0 AND quantity<=min_stock'), 'out' => (int)$one('SELECT COUNT(*) FROM products WHERE inventory_id=? AND quantity<=0'),
  'in' => (int)$one('SELECT COUNT(*) FROM stock_in WHERE inventory_id=?'), 'outtx' => (int)$one('SELECT COUNT(*) FROM stock_out WHERE inventory_id=?'), 'value' => (float)$one('SELECT COALESCE(SUM(quantity*cost_price),0) FROM products WHERE inventory_id=?')];
$status = $_GET['status'] ?? '';
$cond = status_condition($status);
$ps = $pdo->prepare('SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.inventory_id=?' . ($cond ? " AND ($cond)" : '') . ' ORDER BY p.name LIMIT 300'); $ps->execute([$id]); $products = $ps->fetchAll();
$mv = $pdo->prepare('SELECT m.*, p.name, u.full_name FROM stock_movements m JOIN products p ON p.id=m.product_id LEFT JOIN users u ON u.id=m.user_id WHERE m.inventory_id=? ORDER BY m.id DESC LIMIT 15'); $mv->execute([$id]); $moves = $mv->fetchAll();
?>
<div class="alert alert-info py-2 no-print"><i class="bi bi-eye me-1"></i> Read-only view. You are inspecting an inventory owned by <strong><?= e($inv['full_name']) ?></strong>. Nothing here can be changed from the admin area.</div>
<div class="panel mb-3"><div class="panel-body d-flex flex-wrap justify-content-between gap-3">
  <div><h2 class="h4 mb-1"><?= e($inv['name']) ?></h2><div class="text-secondary"><?= e($inv['business_type'] ?? '') ?> · created <?= date('M d, Y', strtotime($inv['created_at'])) ?></div><?php if ($inv['description']): ?><div class="small mt-1"><?= e($inv['description']) ?></div><?php endif; ?></div>
  <div class="text-md-end"><div class="text-secondary small">Owner</div><a class="fw-medium text-decoration-none" href="admin_user.php?id=<?= $inv['user_id'] ?>"><?= e($inv['full_name']) ?></a><div class="small text-secondary">@<?= e($inv['username']) ?> · <?= e($inv['email'] ?? '') ?> · <?= e($inv['ustatus']) ?></div></div>
</div></div>
<div class="row g-3 mb-3">
<?php foreach ([['Products', $n['products']], ['Categories', $n['cats']], ['Suppliers', $n['sups']], ['Low stock', $n['low']], ['Out of stock', $n['out']], ['Stock-in tx', $n['in']], ['Stock-out tx', $n['outtx']], ['Value at cost', money($n['value'])]] as [$l, $v]): ?>
  <div class="col-6 col-md-3"><div class="panel stat"><div class="top"><span><?= $l ?></span></div><div class="v" style="font-size:1.6rem"><?= is_int($v) ? number_format($v) : e($v) ?></div></div></div>
<?php endforeach; ?></div>
<div class="panel mb-3">
  <div class="panel-head"><h2>Products and stock levels</h2><div class="btn-group btn-group-sm">
    <?php foreach (['' => 'All', 'attention' => 'Needs attention', 'low' => 'Low', 'out' => 'Out', 'ok' => 'Healthy'] as $k => $l): ?><a class="btn btn-<?= $status === (string)$k ? 'dark' : 'outline-secondary' ?>" href="<?= e(qs(['status' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div></div>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Product</th><th>Category</th><th style="width:150px">Level</th><th class="text-end">On hand</th><th class="text-end">Minimum</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($products as $p): ?><tr><td><div class="fw-medium"><?= e($p['name']) ?></div><small class="text-secondary"><?= e($p['sku']) ?></small></td><td><?= e($p['category'] ?? '—') ?></td><td><?= level_meter($p['quantity'], $p['min_stock']) ?></td><td class="text-end fw-medium"><?= (int)$p['quantity'] ?></td><td class="text-end"><?= (int)$p['min_stock'] ?></td><td><?= status_badge($p['quantity'], $p['min_stock']) ?></td></tr>
  <?php endforeach; if (!$products): ?><tr><td colspan="6" class="empty">No products match.</td></tr><?php endif; ?></tbody></table></div>
</div>
<div class="panel"><div class="panel-head"><h2>Recent stock movements</h2></div>
  <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Reference</th><th>By</th></tr></thead><tbody>
  <?php foreach ($moves as $r): ?><tr><td class="small"><?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></td><td><?= e($r['name']) ?></td><td><span class="badge badge-<?= $r['type'] === 'out' ? 'danger' : ($r['type'] === 'in' ? 'success' : 'info') ?>"><?= e($r['type']) ?></span></td><td class="text-end"><?= (int)$r['quantity'] ?></td><td class="text-end"><?= (int)$r['balance_after'] ?></td><td><?= e($r['reference_no']) ?></td><td><?= e($r['full_name'] ?? '—') ?></td></tr>
  <?php endforeach; if (!$moves): ?><tr><td colspan="7" class="empty">No movements yet.</td></tr><?php endif; ?></tbody></table></div></div>
<a class="btn btn-light mt-3" href="admin_inventories.php"><i class="bi bi-arrow-left me-1"></i>All inventories</a>
<?php page_end();
