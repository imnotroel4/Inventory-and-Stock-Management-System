<?php
// One user's profile: account, inventories and activity history. Read-only (account actions live on users.php).
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$id]); $u = $st->fetch();
if (!$u) { http_response_code(404); page_start('Not found', 'users', 'admin'); echo '<div class="alert alert-warning">That user does not exist. <a href="users.php">Back to users</a></div>'; page_end(); exit; }
page_start($u['full_name'], 'users', 'admin');
$invs = user_inventories($id);
$lg = $pdo->prepare('SELECT l.*, i.name inv FROM activity_logs l LEFT JOIN inventories i ON i.id=l.inventory_id WHERE l.user_id=? ORDER BY l.id DESC LIMIT 100'); $lg->execute([$id]); $logs = $lg->fetchAll();
$tx = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stock_in WHERE user_id=?) a, (SELECT COUNT(*) FROM stock_out WHERE user_id=?) b'); $tx->execute([$id, $id]); $tx = $tx->fetch();
$badge = ['active' => 'success', 'inactive' => 'muted', 'suspended' => 'danger'][$u['status']];
?>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="panel h-100"><div class="panel-body">
    <div class="d-flex align-items-center gap-3 mb-3"><?= avatar_html($u['full_name'], $u['avatar'], 'lg') ?><div><h2 class="h5 mb-0"><?= e($u['full_name']) ?></h2><div class="text-secondary">@<?= e($u['username']) ?></div></div></div>
    <?php foreach ([['Email', e($u['email'] ?? '—')], ['Role', $u['role'] === 'admin' ? 'Administrator' : 'User'], ['Status', '<span class="badge badge-' . $badge . '">' . e(ucfirst($u['status'])) . '</span>'], ['Registered', date('M d, Y h:i A', strtotime($u['created_at']))], ['Last sign-in', $u['last_login'] ? date('M d, Y h:i A', strtotime($u['last_login'])) : 'Never'], ['Stock transactions', ($tx['a'] + $tx['b']) . ' (' . $tx['a'] . ' in, ' . $tx['b'] . ' out)']] as [$k, $v]): ?>
      <div class="d-flex justify-content-between py-1 border-bottom small"><span class="text-secondary"><?= $k ?></span><span><?= $v ?></span></div><?php endforeach; ?>
    <a class="btn btn-light mt-3" href="users.php"><i class="bi bi-arrow-left me-1"></i>All users</a>
  </div></div></div>
  <div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><h2>Inventories (<?= count($invs) ?>)</h2></div>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Name</th><th>Type</th><th class="text-end">Products</th><th>Created</th><th></th></tr></thead><tbody>
    <?php foreach ($invs as $i): ?><tr><td class="fw-medium"><?= e($i['name']) ?></td><td class="small"><?= e($i['business_type'] ?? '—') ?></td><td class="text-end"><?= (int)$i['product_count'] ?></td><td class="small"><?= date('M d, Y', strtotime($i['created_at'])) ?></td><td class="text-end"><a class="btn btn-sm btn-light" href="admin_inventory.php?id=<?= $i['id'] ?>"><i class="bi bi-eye"></i> View</a></td></tr>
    <?php endforeach; if (!$invs): ?><tr><td colspan="5" class="empty">This user has not created an inventory yet.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
<div class="panel"><div class="panel-head"><h2>Activity history</h2><span class="text-secondary small">Latest 100</span></div>
  <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Time</th><th>Action</th><th>Details</th><th>Inventory</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($logs as $l): ?><tr><td class="small"><?= date('M d, Y h:i:s A', strtotime($l['created_at'])) ?></td><td><span class="badge badge-muted"><?= e($l['action']) ?></span></td><td><?= e($l['details']) ?></td><td class="small"><?= e($l['inv'] ?? '—') ?></td><td class="small"><?= e($l['ip_address']) ?></td></tr>
  <?php endforeach; if (!$logs): ?><tr><td colspan="5" class="empty">No activity recorded.</td></tr><?php endif; ?></tbody></table></div></div>
<?php page_end();
