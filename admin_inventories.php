<?php
// All inventories on the platform with their owners. Read-only.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
page_start('All inventories', 'admin_inventories', 'admin');

$q = trim($_GET['q'] ?? ''); $type = $_GET['type'] ?? ''; $flag = $_GET['flag'] ?? '';
$base = "SELECT i.id,i.name,i.business_type,i.created_at,u.id owner_id,u.full_name,u.username,u.status ustatus,
  (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id) products,
  (SELECT COALESCE(SUM(p.quantity),0) FROM products p WHERE p.inventory_id=i.id) units,
  (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id AND p.quantity>0 AND p.quantity<=p.min_stock) low,
  (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id AND p.quantity<=0) outs
  FROM inventories i JOIN users u ON u.id=i.user_id";
$where = []; $args = [];
if ($q !== '') { $where[] = '(t.name LIKE ? OR t.full_name LIKE ? OR t.username LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if (in_array($type, INVENTORY_TYPES, true)) { $where[] = 't.business_type=?'; $args[] = $type; }
if ($flag === 'low') $where[] = 't.low>0'; elseif ($flag === 'out') $where[] = 't.outs>0'; elseif ($flag === 'empty') $where[] = 't.products=0';
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$c = $pdo->prepare("SELECT COUNT(*) FROM ($base) t$w"); $c->execute($args); $total = (int)$c->fetchColumn();
[$page, $pages, $off] = paginate($total, 25);
$st = $pdo->prepare("SELECT * FROM ($base) t$w ORDER BY t.id DESC LIMIT 25 OFFSET $off"); $st->execute($args); $rows = $st->fetchAll();
?>
<div class="panel">
  <div class="panel-head"><h2><?= number_format($total) ?> inventor<?= $total == 1 ? 'y' : 'ies' ?></h2>
    <form class="d-flex gap-2 flex-wrap" method="get">
      <input class="form-control form-control-sm" style="width:220px" name="q" value="<?= e($q) ?>" placeholder="Inventory or owner">
      <select class="form-select form-select-sm" style="width:190px" name="type" onchange="this.form.submit()"><option value="">All business types</option><?php foreach (INVENTORY_TYPES as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm" style="width:170px" name="flag" onchange="this.form.submit()"><option value="">Any stock level</option><option value="low" <?= $flag === 'low' ? 'selected' : '' ?>>Has low stock</option><option value="out" <?= $flag === 'out' ? 'selected' : '' ?>>Has out of stock</option><option value="empty" <?= $flag === 'empty' ? 'selected' : '' ?>>No products</option></select>
      <button class="btn btn-sm btn-light">Search</button></form></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Inventory</th><th>Owner</th><th>Type</th><th class="text-end">Products</th><th class="text-end">Units</th><th class="text-end">Low</th><th class="text-end">Out</th><th>Created</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="fw-medium"><?= e($r['name']) ?></td>
        <td><a href="admin_user.php?id=<?= $r['owner_id'] ?>" class="text-decoration-none"><?= e($r['full_name']) ?></a><div class="small text-secondary">@<?= e($r['username']) ?><?= $r['ustatus'] !== 'active' ? ' · ' . e($r['ustatus']) : '' ?></div></td>
        <td class="small"><?= e($r['business_type'] ?? '—') ?></td><td class="text-end"><?= (int)$r['products'] ?></td><td class="text-end"><?= number_format($r['units']) ?></td>
        <td class="text-end"><?= $r['low'] ? '<span class="badge badge-warning">' . (int)$r['low'] . '</span>' : '0' ?></td><td class="text-end"><?= $r['outs'] ? '<span class="badge badge-danger">' . (int)$r['outs'] . '</span>' : '0' ?></td>
        <td class="small"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light" href="admin_inventory.php?id=<?= $r['id'] ?>"><i class="bi bi-eye"></i> View</a></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">No inventories match.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?= pager_html($page, $pages) ?>
</div>
<?php page_end();
