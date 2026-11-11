<?php
// Admin: all support requests, with filters and counts.
require_once __DIR__ . '/includes/help_layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
help_ensure_schema();
page_start('Support tickets', 'admin_help_tickets', 'admin');

$counts = ['all' => 0] + array_fill_keys(array_keys(HELP_TICKET_STATUSES), 0);
foreach ($pdo->query('SELECT status, COUNT(*) c FROM help_tickets GROUP BY status') as $r) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }
$stt = $_GET['status'] ?? ''; $type = $_GET['type'] ?? ''; $q = trim($_GET['q'] ?? '');
$where = ['1']; $args = [];
if (isset(HELP_TICKET_STATUSES[$stt])) { $where[] = 't.status=?'; $args[] = $stt; }
if (isset(HELP_TICKET_TYPES[$type])) { $where[] = 't.type=?'; $args[] = $type; }
if ($q !== '') { $where[] = '(t.ticket_no LIKE ? OR t.subject LIKE ? OR t.name LIKE ? OR t.email LIKE ? OR t.message LIKE ?)'; $l = help_like($q); array_push($args, $l, $l, $l, $l, $l); }
$c = $pdo->prepare('SELECT COUNT(*) FROM help_tickets t WHERE ' . implode(' AND ', $where)); $c->execute($args); $total = (int)$c->fetchColumn();
[$page, $pages, $off] = paginate($total, 25);
$st = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM help_ticket_replies r WHERE r.ticket_id=t.id) replies FROM help_tickets t WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(t.status,'open','in_progress','answered','closed'), FIELD(t.priority,'high','normal','low'), t.id DESC LIMIT 25 OFFSET $off");
$st->execute($args); $rows = $st->fetchAll(); ?>
<div class="d-flex gap-2 flex-wrap mb-3">
  <a class="btn btn-sm btn-pill <?= $stt === '' ? 'btn-primary' : 'btn-light' ?>" href="admin_help_tickets.php">All <span class="opacity-75">(<?= $counts['all'] ?>)</span></a>
  <?php foreach (HELP_TICKET_STATUSES as $k => $l): ?><a class="btn btn-sm btn-pill <?= $stt === $k ? 'btn-primary' : 'btn-light' ?>" href="admin_help_tickets.php?status=<?= $k ?>"><?= e($l) ?> <span class="opacity-75">(<?= $counts[$k] ?>)</span></a><?php endforeach; ?></div>
<div class="panel"><div class="panel-head"><h2><?= number_format($total) ?> request<?= $total == 1 ? '' : 's' ?></h2></div>
  <form class="panel-body border-bottom row g-2 align-items-end mx-0" method="get"><?php if ($stt): ?><input type="hidden" name="status" value="<?= e($stt) ?>"><?php endif; ?>
    <div class="col-md-5"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Ticket no., subject, name, email or text"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Type</label><select class="form-select form-select-sm" name="type"><option value="">All</option><?php foreach (HELP_TICKET_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto d-flex gap-2"><button class="btn btn-sm btn-primary">Filter</button><a class="btn btn-sm btn-light" href="admin_help_tickets.php">Reset</a></div></form>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Ticket</th><th>Subject</th><th>From</th><th>Type</th><th>Priority</th><th>Status</th><th class="text-end">Replies</th><th>Opened</th></tr></thead><tbody>
  <?php foreach ($rows as $t): ?><tr><td class="text-nowrap"><a class="fw-medium text-decoration-none" href="admin_help_ticket.php?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_no']) ?></a></td><td><?= e($t['subject']) ?></td>
    <td class="small"><?= e($t['name']) ?><small class="d-block text-secondary"><?= e($t['email']) ?><?= $t['user_id'] ? '' : ' · visitor' ?></small></td><td><?= e(HELP_TICKET_TYPES[$t['type']]) ?></td>
    <td><?= $t['priority'] === 'high' ? '<span class="badge badge-danger">High</span>' : ($t['priority'] === 'low' ? '<span class="badge badge-muted">Low</span>' : 'Normal') ?></td><td><?= help_ticket_badge($t['status']) ?></td><td class="text-end"><?= (int)$t['replies'] ?></td><td class="small text-nowrap"><?= help_ago($t['created_at']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">No support requests.</td></tr><?php endif; ?></tbody></table></div>
  <?= pager_html($page, $pages) ?></div>
<?php page_end();
