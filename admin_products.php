<?php
// Every product on the platform, with its owner and inventory. Read-only.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
page_start('All products', 'admin_products', 'admin');

$q = trim($_GET['q'] ?? ''); $status = $_GET['status'] ?? ''; $owner = trim($_GET['owner'] ?? ''); $invId = (int)($_GET['inv'] ?? 0);
$where = ['1']; $args = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR c.name LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($owner !== '') { $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR i.name LIKE ?)'; array_push($args, "%$owner%", "%$owner%", "%$owner%"); }
if ($invId) { $where[] = 'p.inventory_id=?'; $args[] = $invId; }
if ($cond = status_condition($status)) $where[] = "($cond)";
$from = 'FROM products p JOIN inventories i ON i.id=p.inventory_id JOIN users u ON u.id=i.user_id LEFT JOIN categories c ON c.id=p.category_id WHERE ' . implode(' AND ', $where);
$c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($args); $total = (int)$c->fetchColumn();
[$page, $pages, $off] = paginate($total, 25);
$st = $pdo->prepare("SELECT p.*, c.name category, i.name inv_name, u.id owner_id, u.full_name, u.username $from ORDER BY p.quantity/NULLIF(p.min_stock,0) ASC, p.name LIMIT 25 OFFSET $off"); $st->execute($args); $rows = $st->fetchAll();
?>
<div class="panel">
  <div class="panel-head"><h2><?= number_format($total) ?> product<?= $total == 1 ? '' : 's' ?></h2>
    <form class="d-flex gap-2 flex-wrap" method="get"><?php if ($invId): ?><input type="hidden" name="inv" value="<?= $invId ?>"><?php endif; ?>
      <input class="form-control form-control-sm" style="width:190px" name="q" value="<?= e($q) ?>" placeholder="Product, SKU, category">
      <input class="form-control form-control-sm" style="width:180px" name="owner" value="<?= e($owner) ?>" placeholder="Owner or inventory">
      <select class="form-select form-select-sm" style="width:160px" name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (['attention' => 'Needs attention', 'low' => 'Low stock', 'out' => 'Out of stock', 'ok' => 'Healthy'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-light">Search</button></form></div>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Product</th><th>Owner</th><th>Inventory</th><th>Category</th><th class="text-end">On hand</th><th class="text-end">Min</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><div class="fw-medium"><?= e($r['name']) ?></div><small class="text-secondary"><?= e($r['sku']) ?></small></td>
      <td><a href="admin_user.php?id=<?= $r['owner_id'] ?>" class="text-decoration-none"><?= e($r['full_name']) ?></a><div class="small text-secondary">@<?= e($r['username']) ?></div></td>
      <td><a href="admin_inventory.php?id=<?= $r['inventory_id'] ?>" class="text-decoration-none"><?= e($r['inv_name']) ?></a></td><td><?= e($r['category'] ?? '—') ?></td>
      <td class="text-end fw-medium"><?= (int)$r['quantity'] ?></td><td class="text-end"><?= (int)$r['min_stock'] ?></td><td><?= status_badge($r['quantity'], $r['min_stock']) ?></td>
      <td class="text-end"><button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#detail" data-p='<?= e(json_encode(['Product' => $r['name'], 'SKU' => $r['sku'], 'Owner' => $r['full_name'] . ' (@' . $r['username'] . ')', 'Inventory' => $r['inv_name'], 'Category' => $r['category'] ?? '—', 'Description' => $r['description'] ?: '—', 'Cost price' => money($r['cost_price']), 'Selling price' => money($r['selling_price']), 'On hand' => $r['quantity'], 'Minimum stock' => $r['min_stock'], 'Stock value (cost)' => money($r['quantity'] * $r['cost_price']), 'Added' => date('M d, Y', strtotime($r['created_at'])), 'Last updated' => date('M d, Y h:i A', strtotime($r['updated_at']))])) ?>'><i class="bi bi-eye"></i></button></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">No products match.</td></tr><?php endif; ?></tbody></table></div>
  <?= pager_html($page, $pages) ?>
</div>
<div class="modal fade" id="detail" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Product details</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><dl class="row mb-0" id="detailBody"></dl></div></div></div></div>
<script>
document.getElementById('detail').addEventListener('show.bs.modal', ev => {
  const d = JSON.parse(ev.relatedTarget.dataset.p), body = document.getElementById('detailBody'); body.innerHTML = '';
  Object.entries(d).forEach(([k, v]) => { const dt = document.createElement('dt'), dd = document.createElement('dd'); dt.className = 'col-5 text-secondary fw-normal'; dd.className = 'col-7'; dt.textContent = k; dd.textContent = v; body.append(dt, dd); });
});
</script>
<?php page_end();
