<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $min = max(0, (int)($_POST['min_stock'] ?? 0));
    $st = $pdo->prepare('UPDATE products SET min_stock=? WHERE id=? AND inventory_id=?'); $st->execute([$min, $id, $iid]);
    log_activity('min_stock_update', "Product ID $id set to $min"); flash('Minimum stock level updated.');
    redirect('monitoring.php' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}
page_start('Stock monitoring', 'monitoring');
$status = $_GET['status'] ?? 'all';
$where = ['attention' => 'p.quantity<=p.min_stock', 'low' => 'p.quantity>0 AND p.quantity<=p.min_stock', 'out' => 'p.quantity=0', 'ok' => 'p.quantity>p.min_stock'][$status] ?? '1';
$rows = $pdo->prepare("SELECT p.*,c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.inventory_id=? AND ($where) ORDER BY p.quantity/NULLIF(p.min_stock,0) ASC, p.name"); $rows->execute([$iid]); $rows = $rows->fetchAll();
$n = $pdo->prepare('SELECT COALESCE(SUM(quantity=0),0) o, COALESCE(SUM(quantity>0 AND quantity<=min_stock),0) l FROM products WHERE inventory_id=?'); $n->execute([$iid]); $n = $n->fetch();
$canEdit = true;
?>
<?php if ($n['o']): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i> <strong><?= $n['o'] ?> product<?= $n['o'] > 1 ? 's are' : ' is' ?> out of stock.</strong> Customers can't be served until they are restocked.</div><?php endif; ?>
<?php if ($n['l']): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong><?= $n['l'] ?> product<?= $n['l'] > 1 ? 's are' : ' is' ?> at or below the minimum level.</strong></div><?php endif; ?>
<div class="panel">
  <div class="panel-head">
    <div class="btn-group btn-group-sm">
      <?php foreach (['all' => 'All', 'attention' => 'Needs attention', 'low' => 'Low stock', 'out' => 'Out of stock', 'ok' => 'Healthy'] as $k => $l): ?>
        <a class="btn btn-<?= $status === $k ? 'dark' : 'outline-secondary' ?>" href="?status=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
    </div>
    <input class="form-control form-control-sm" style="width:220px" placeholder="Filter products" data-filter="#monTable">
  </div>
  <div class="table-responsive"><table class="table table-hover" id="monTable">
    <thead><tr><th>Product</th><th>Category</th><th style="width:170px">Stock level</th><th class="text-end">On hand</th><th class="text-end" style="width:170px">Minimum</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><div class="fw-medium"><?= e($r['name']) ?></div><small class="text-secondary"><?= e($r['sku']) ?></small></td><td><?= e($r['category'] ?? '—') ?></td>
        <td><?= level_meter($r['quantity'], $r['min_stock']) ?></td><td class="text-end fw-medium"><?= (int)$r['quantity'] ?></td>
        <td class="text-end"><?php if ($canEdit): ?><form method="post" class="d-flex gap-1 justify-content-end"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <input class="form-control form-control-sm text-end" style="width:80px" type="number" min="0" name="min_stock" value="<?= (int)$r['min_stock'] ?>" aria-label="Minimum stock for <?= e($r['name']) ?>"><button class="btn btn-sm btn-light">Set</button></form><?php else: echo (int)$r['min_stock']; endif; ?></td>
        <td><?= status_badge($r['quantity'], $r['min_stock']) ?></td>
        <td class="text-end"><?php if ($r['quantity'] <= $r['min_stock']): ?><a class="btn btn-sm btn-outline-secondary" href="stock_in.php?product=<?= $r['id'] ?>">Restock</a><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">No products match this filter.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<p class="small text-secondary mt-3">The bar fills to full at twice the minimum level. The dark tick marks the minimum, so a bar that stops short of the tick needs restocking.</p>
<?php page_end();
