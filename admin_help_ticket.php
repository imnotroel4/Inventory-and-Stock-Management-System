<?php
// Admin: read one support request, reply, change status/priority, delete.
require_once __DIR__ . '/includes/help_layout.php';
require_admin();
help_ensure_schema();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, u.username FROM help_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=?'); $st->execute([$id]); $t = $st->fetch();
if (!$t) { flash('Ticket not found.', 'danger'); redirect('admin_help_tickets.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = $_POST['action'] ?? ''; $me = current_user();
    try {
        if ($a === 'reply') {
            $msg = trim($_POST['message'] ?? ''); $new = $_POST['status'] ?? 'answered';
            if (!help_len_ok($msg, 2, 5000)) throw new Exception('Write your reply (2 to 5,000 characters).');
            if (!isset(HELP_TICKET_STATUSES[$new])) $new = 'answered';
            $pdo->prepare('INSERT INTO help_ticket_replies (ticket_id,user_id,is_staff,author_name,message) VALUES (?,?,1,?,?)')->execute([$id, $me['id'], $me['full_name'], $msg]);
            $pdo->prepare('UPDATE help_tickets SET status=? WHERE id=?')->execute([$new, $id]); log_activity('help_ticket_reply', $t['ticket_no']); flash('Reply sent.');
        } elseif ($a === 'update') {
            $s = $_POST['status'] ?? ''; $p = $_POST['priority'] ?? '';
            if (!isset(HELP_TICKET_STATUSES[$s]) || !isset(HELP_TICKET_PRIORITIES[$p])) throw new Exception('Invalid status or priority.');
            $pdo->prepare('UPDATE help_tickets SET status=?, priority=? WHERE id=?')->execute([$s, $p, $id]); log_activity('help_ticket_update', $t['ticket_no'] . ' → ' . $s); flash('Ticket updated.');
        } elseif ($a === 'delete') {
            $pdo->prepare('DELETE FROM help_tickets WHERE id=?')->execute([$id]); log_activity('help_ticket_delete', $t['ticket_no']); flash('Ticket deleted.'); redirect('admin_help_tickets.php');
        }
    } catch (PDOException $e) { flash('Database error. Please try again.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('admin_help_ticket.php?id=' . $id);
}

page_start($t['ticket_no'], 'admin_help_tickets', 'admin'); echo '<link href="assets/css/help.css" rel="stylesheet">';
$rp = $pdo->prepare('SELECT * FROM help_ticket_replies WHERE ticket_id=? ORDER BY id'); $rp->execute([$id]); $replies = $rp->fetchAll(); ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel mb-3"><div class="panel-head"><div><h2><?= e($t['subject']) ?></h2><div class="small text-secondary mt-1"><?= e($t['ticket_no']) ?> · <?= e(HELP_TICKET_TYPES[$t['type']]) ?> · <?= date('M d, Y h:i A', strtotime($t['created_at'])) ?></div></div><?= help_ticket_badge($t['status']) ?></div>
      <div class="panel-body"><div class="hlp-thread">
        <div class="hlp-msg"><div class="meta"><strong><?= e($t['name']) ?></strong><span><?= e($t['email']) ?></span><span><?= help_ago($t['created_at']) ?></span></div><div class="txt"><?= e($t['message']) ?></div></div>
        <?php foreach ($replies as $r): ?><div class="hlp-msg <?= $r['is_staff'] ? 'staff' : '' ?>"><div class="meta"><strong><?= e($r['author_name']) ?></strong><?= $r['is_staff'] ? '<span class="badge badge-info">Support</span>' : '' ?><span><?= help_ago($r['created_at']) ?></span></div><div class="txt"><?= e($r['message']) ?></div></div><?php endforeach; ?></div></div></div>
    <div class="panel"><form method="post" class="panel-body pt-3"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= $id ?>">
      <label class="form-label" for="aRep">Reply to <?= e($t['name']) ?></label><textarea class="form-control mb-3" id="aRep" name="message" rows="5" maxlength="5000" required></textarea>
      <div class="d-flex gap-2 align-items-center flex-wrap"><button class="btn btn-primary"><i class="bi bi-send me-1"></i> Send reply</button><label class="small text-secondary ms-2" for="aRepSt">then mark as</label>
        <select class="form-select form-select-sm w-auto" id="aRepSt" name="status"><?php foreach (HELP_TICKET_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'answered' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="form-text mt-2">The person sees your reply on their ticket page<?= $t['user_id'] ? ' (My requests)' : ' (their private link)' ?>. No email is sent automatically.</div></form></div>
  </div>
  <div class="col-lg-4">
    <div class="panel mb-3"><div class="panel-head"><h2>Details</h2></div><div class="panel-body">
      <dl class="small mb-3"><dt class="text-secondary fw-normal">From</dt><dd><?= e($t['name']) ?> — <a href="mailto:<?= e($t['email']) ?>"><?= e($t['email']) ?></a></dd>
        <dt class="text-secondary fw-normal">Account</dt><dd><?= $t['user_id'] ? '<a href="admin_user.php?id=' . (int)$t['user_id'] . '">@' . e($t['username']) . '</a>' : 'Visitor (not signed in)' ?></dd>
        <dt class="text-secondary fw-normal">IP address</dt><dd class="mb-0"><?= e($t['ip_address']) ?></dd></dl>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
        <label class="form-label">Status</label><select class="form-select mb-2" name="status"><?php foreach (HELP_TICKET_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <label class="form-label">Priority</label><select class="form-select mb-3" name="priority"><?php foreach (HELP_TICKET_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $t['priority'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-light w-100">Update</button></form></div></div>
    <a class="btn btn-light w-100 mb-2" href="admin_help_tickets.php"><i class="bi bi-arrow-left"></i> All tickets</a>
    <form method="post" data-confirm="Delete this ticket and its replies permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-light text-danger w-100"><i class="bi bi-trash"></i> Delete ticket</button></form>
  </div>
</div>
<?php page_end();
