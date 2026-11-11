<?php
// A visitor's or user's own support requests.
//   ?id=N   → signed-in owner only.   ?t=TOKEN → anyone holding the private link (visitors).   no params → list (signed in).
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

$me = current_user();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0); $tok = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$ticket = null;
if ($tok !== '' && preg_match('/^[a-f0-9]{32}$/', $tok)) {
    $st = $pdo->prepare('SELECT * FROM help_tickets WHERE public_token=?'); $st->execute([$tok]); $ticket = $st->fetch() ?: null;
} elseif ($id && $me) {
    $st = $pdo->prepare('SELECT * FROM help_tickets WHERE id=? AND user_id=?'); $st->execute([$id, $me['id']]); $ticket = $st->fetch() ?: null;   // owner check in SQL
}
$link = fn() => $tok !== '' ? 'help_tickets.php?t=' . $tok : 'help_tickets.php?id=' . (int)$ticket['id'];

if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'reply') {
            $msg = trim($_POST['message'] ?? '');
            if ($ticket['status'] === 'closed') throw new Exception('This request is closed. Please send a new request.');
            if (!help_len_ok($msg, 2, 5000)) throw new Exception('Write your reply (2 to 5,000 characters).');
            $r = $pdo->prepare('SELECT COUNT(*) FROM help_ticket_replies WHERE ticket_id=? AND is_staff=0 AND created_at > (NOW() - INTERVAL 1 MINUTE)'); $r->execute([$ticket['id']]);
            if ((int)$r->fetchColumn() >= 5) throw new Exception('Please slow down and try again in a minute.');
            $pdo->prepare('INSERT INTO help_ticket_replies (ticket_id,user_id,is_staff,author_name,message) VALUES (?,?,0,?,?)')->execute([$ticket['id'], $me['id'] ?? null, $ticket['name'], $msg]);
            $pdo->prepare("UPDATE help_tickets SET status='open' WHERE id=?")->execute([$ticket['id']]);
            flash('Your reply was sent.');
        } elseif ($a === 'close') {
            $pdo->prepare("UPDATE help_tickets SET status='closed' WHERE id=?")->execute([$ticket['id']]); flash('Request closed. Thank you!');
        }
    } catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect($link());
}

help_start($ticket ? $ticket['ticket_no'] : 'My requests', 'help_center'); help_subnav($me ? 'tickets' : 'contact');

if ($ticket) {
    $rp = $pdo->prepare('SELECT * FROM help_ticket_replies WHERE ticket_id=? ORDER BY id'); $rp->execute([$ticket['id']]); $replies = $rp->fetchAll(); ?>
<div class="hlp-crumbs"><?php if ($me): ?><a href="help_tickets.php">My requests</a> / <?php endif; ?><?= e($ticket['ticket_no']) ?></div>
<?php if ($tok !== '' && !$me): ?><div class="alert alert-info"><i class="bi bi-link-45deg me-1"></i> <strong>Keep this page’s address.</strong> It is the private link to your request — anyone who has it can read the conversation.</div><?php endif; ?>
<div class="panel mb-3"><div class="panel-head"><div><h2><?= e($ticket['subject']) ?></h2>
  <div class="small text-secondary mt-1"><?= e($ticket['ticket_no']) ?> · <?= e(HELP_TICKET_TYPES[$ticket['type']]) ?> · opened <?= date('M d, Y h:i A', strtotime($ticket['created_at'])) ?></div></div><?= help_ticket_badge($ticket['status']) ?></div>
  <div class="panel-body"><div class="hlp-thread">
    <div class="hlp-msg"><div class="meta"><strong><?= e($ticket['name']) ?></strong><span><?= help_ago($ticket['created_at']) ?></span></div><div class="txt"><?= e($ticket['message']) ?></div></div>
    <?php foreach ($replies as $r): ?><div class="hlp-msg <?= $r['is_staff'] ? 'staff' : '' ?>"><div class="meta"><strong><?= e($r['author_name']) ?></strong><?= $r['is_staff'] ? '<span class="badge badge-info">Support</span>' : '' ?><span><?= help_ago($r['created_at']) ?></span></div><div class="txt"><?= e($r['message']) ?></div></div><?php endforeach; ?>
  </div></div></div>
<?php if ($ticket['status'] !== 'closed'): ?>
<div class="panel"><form method="post" class="panel-body pt-3"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>"><?php if ($tok !== ''): ?><input type="hidden" name="t" value="<?= e($tok) ?>"><?php endif; ?>
  <label class="form-label" for="rMsg">Add a reply</label><textarea class="form-control mb-3" id="rMsg" name="message" rows="4" maxlength="5000" required></textarea>
  <div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary"><i class="bi bi-send me-1"></i> Send reply</button>
    <button class="btn btn-light" name="action" value="close" formnovalidate onclick="return confirm('Close this request? You can still send a new one later.')">Mark as resolved</button></div></form></div>
<?php else: ?><div class="alert alert-secondary">This request is closed. <a href="help_contact.php">Send a new request</a> if you still need help.</div><?php endif;

} elseif ($me) {
    $st = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM help_ticket_replies r WHERE r.ticket_id=t.id AND r.is_staff=1) staff_replies FROM help_tickets t WHERE t.user_id=? ORDER BY t.id DESC LIMIT 100'); $st->execute([$me['id']]); $rows = $st->fetchAll(); ?>
<div class="panel"><div class="panel-head"><h2>My requests</h2><a class="btn btn-primary btn-sm" href="help_contact.php"><i class="bi bi-plus-lg"></i> New request</a></div>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Ticket</th><th>Subject</th><th>Type</th><th>Status</th><th>Replies</th><th>Opened</th></tr></thead><tbody>
  <?php foreach ($rows as $t): ?><tr><td class="text-nowrap"><a class="text-decoration-none fw-medium" href="help_tickets.php?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_no']) ?></a></td><td><?= e($t['subject']) ?></td><td><?= e(HELP_TICKET_TYPES[$t['type']]) ?></td><td><?= help_ticket_badge($t['status']) ?></td><td><?= (int)$t['staff_replies'] ?></td><td class="small text-nowrap"><?= help_ago($t['created_at']) ?></td></tr><?php endforeach;
  if (!$rows): ?><tr><td colspan="6" class="empty">You have not sent any requests yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php } else { ?>
<div class="panel"><div class="empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i><?= $tok !== '' ? 'That link is not valid.' : 'Open the private link you received after sending your request, or <a href="login.php">sign in</a> to see all your requests.' ?><div class="mt-3"><a class="btn btn-primary btn-sm" href="help_contact.php">Send a new request</a></div></div></div>
<?php }
help_end();
