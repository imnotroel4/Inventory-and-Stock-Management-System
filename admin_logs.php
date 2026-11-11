<?php
// Platform-wide activity log with filters.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
page_start('Activity logs', 'admin_logs', 'admin');

$q = trim($_GET['q'] ?? ''); $action = trim($_GET['action'] ?? ''); $uid = (int)($_GET['user'] ?? 0);
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : ''; $to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$where = ['1']; $args = [];
if ($q !== '') { $where[] = '(l.details LIKE ? OR l.ip_address LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($action !== '') { $where[] = 'l.action=?'; $args[] = $action; }
if ($uid) { $where[] = 'l.user_id=?'; $args[] = $uid; }
if ($from) { $where[] = 'DATE(l.created_at)>=?'; $args[] = $from; }
if ($to) { $where[] = 'DATE(l.created_at)<=?'; $args[] = $to; }
$from_sql = 'FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN inventories i ON i.id=l.inventory_id WHERE ' . implode(' AND ', $where);
$c = $pdo->prepare("SELECT COUNT(*) $from_sql"); $c->execute($args); $total = (int)$c->fetchColumn();
[$page, $pages, $off] = paginate($total, 50);
$st = $pdo->prepare("SELECT l.*, u.full_name, u.role, i.name inv $from_sql ORDER BY l.id DESC LIMIT 50 OFFSET $off"); $st->execute($args); $rows = $st->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$people = $pdo->query('SELECT id, full_name, username FROM users ORDER BY full_name')->fetchAll();
?>
<div class="panel">
  <div class="panel-head"><h2><?= number_format($total) ?> log entr<?= $total == 1 ? 'y' : 'ies' ?></h2></div>
  <form class="panel-body border-bottom row g-2 align-items-end mx-0" method="get">
    <div class="col-md-2"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Details, IP, name"></div>
    <div class="col-md-2"><label class="form-label small mb-1">User</label><select class="form-select form-select-sm" name="user"><option value="">Everyone</option><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>" <?= $uid === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['full_name']) ?> (@<?= e($p['username']) ?>)</option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-1">Action</label><select class="form-select form-select-sm" name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-1">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="col-auto d-flex gap-2"><button class="btn btn-sm btn-primary">Filter</button><a class="btn btn-sm btn-light" href="admin_logs.php">Reset</a></div></form>
  <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>Inventory</th><th>IP address</th></tr></thead><tbody>
  <?php foreach ($rows as $l): ?><tr><td class="small text-nowrap"><?= date('M d, Y h:i:s A', strtotime($l['created_at'])) ?></td>
    <td><?= $l['user_id'] ? '<a class="text-decoration-none" href="admin_user.php?id=' . (int)$l['user_id'] . '">' . e($l['full_name']) . '</a>' . ($l['role'] === 'admin' ? ' <span class="badge badge-info">admin</span>' : '') : '<span class="text-secondary">Guest / deleted user</span>' ?></td>
    <td><span class="badge badge-muted"><?= e($l['action']) ?></span></td><td><?= e($l['details']) ?></td><td class="small"><?= e($l['inv'] ?? '—') ?></td><td class="small"><?= e($l['ip_address']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No log entries match.</td></tr><?php endif; ?></tbody></table></div>
  <?= pager_html($page, $pages) ?>
</div>
<?php page_end();
