<?php
// Contact Support: anyone can submit a question, concern or technical issue. Signed-in users get their name/email pre-filled
// and can follow replies under "My requests"; visitors receive a private link to their ticket.
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

$me = current_user(); $errors = [];
$prefill = ['name' => '', 'email' => ''];
if ($me) { $st = $pdo->prepare('SELECT full_name,email FROM users WHERE id=?'); $st->execute([$me['id']]); $u = $st->fetch() ?: []; $prefill = ['name' => $u['full_name'] ?? '', 'email' => $u['email'] ?? '']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? '')); $email = strtolower(trim($_POST['email'] ?? ''));
    $type = $_POST['type'] ?? ''; $prio = $_POST['priority'] ?? 'normal'; $subject = trim(preg_replace('/\s+/', ' ', $_POST['subject'] ?? '')); $msg = trim($_POST['message'] ?? '');
    if (!empty($_POST['website'])) { flash('Thank you. Your message was received.'); redirect('help_center.php'); }   // honeypot: bots fill hidden fields
    if (!help_len_ok($name, 2, 100) || preg_match('/[<>]/', $name)) $errors['name'] = 'Enter your name (2 to 100 characters).';
    if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!isset(HELP_TICKET_TYPES[$type])) $errors['type'] = 'Choose what your request is about.';
    if (!isset(HELP_TICKET_PRIORITIES[$prio])) $prio = 'normal';
    if (!help_len_ok($subject, 5, 160)) $errors['subject'] = 'Give your request a short subject (5 to 160 characters).';
    if (!help_len_ok($msg, 10, 5000)) $errors['message'] = 'Describe your request in 10 to 5,000 characters.';
    if (!$errors) {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM help_tickets WHERE ip_address=? AND created_at > (NOW() - INTERVAL 1 HOUR)'); $cnt->execute([client_ip()]);
        if ((int)$cnt->fetchColumn() >= 5) $errors['form'] = 'Too many requests from your connection. Please wait a while and try again.';
    }
    if (!$errors) {
        try {
            $no = help_ticket_no(); $tok = bin2hex(random_bytes(16));
            $pdo->prepare('INSERT INTO help_tickets (ticket_no,public_token,user_id,name,email,type,priority,subject,message,ip_address) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$no, $tok, $me['id'] ?? null, $name, $email, $type, $prio, $subject, $msg, client_ip()]);
            $id = (int)$pdo->lastInsertId();
            if ($me) log_activity('help_ticket', $no . ': ' . $subject);
            flash("Thank you. Your request $no was sent. We will reply here.");
            redirect($me ? 'help_tickets.php?id=' . $id : 'help_tickets.php?t=' . $tok);
        } catch (PDOException $e) { $errors['form'] = 'Could not save your request right now. Please try again.'; }
    }
}
$v = fn($k, $d = '') => e($_POST[$k] ?? ($_GET[$k] ?? $d));

help_start('Contact support', 'help_center'); help_subnav('contact'); ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel"><div class="panel-head"><div><h2>Contact support</h2><div class="text-secondary small mt-1">Ask a question, share a concern or report a technical issue. Check the <a href="help_faq.php">FAQ</a> first — your answer may be there.</div></div></div>
      <form method="post" class="panel-body row g-3" novalidate>
        <?= csrf_field() ?>
        <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if (isset($errors['form'])): ?><div class="col-12"><div class="alert alert-danger mb-0"><?= e($errors['form']) ?></div></div><?php endif; ?>
        <div class="col-md-6"><label class="form-label" for="cName">Your name</label><input class="form-control<?= is_bad($errors, 'name') ?>" id="cName" name="name" maxlength="100" required value="<?= $v('name', $prefill['name']) ?>"><?= feedback($errors, 'name', 'Enter your name.') ?></div>
        <div class="col-md-6"><label class="form-label" for="cEmail">Email address</label><input type="email" class="form-control<?= is_bad($errors, 'email') ?>" id="cEmail" name="email" maxlength="120" required value="<?= $v('email', $prefill['email']) ?>"><?= feedback($errors, 'email', 'Enter a valid email.') ?></div>
        <div class="col-md-6"><label class="form-label" for="cType">This is a…</label><select class="form-select<?= is_bad($errors, 'type') ?>" id="cType" name="type" required><option value="">Choose…</option><?php foreach (HELP_TICKET_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= feedback($errors, 'type', 'Choose one.') ?></div>
        <div class="col-md-6"><label class="form-label" for="cPrio">Priority</label><select class="form-select" id="cPrio" name="priority"><?php foreach (HELP_TICKET_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="cSub">Subject</label><input class="form-control<?= is_bad($errors, 'subject') ?>" id="cSub" name="subject" maxlength="160" required value="<?= $v('subject') ?>"><?= feedback($errors, 'subject', 'Enter a subject.') ?></div>
        <div class="col-12"><label class="form-label" for="cMsg">Message</label><textarea class="form-control<?= is_bad($errors, 'message') ?>" id="cMsg" name="message" rows="7" maxlength="5000" required placeholder="What were you trying to do? What happened? Include any error message you saw."><?= e($_POST['message'] ?? '') ?></textarea><?= feedback($errors, 'message', 'Describe your request.') ?></div>
        <div class="col-12"><button class="btn btn-primary px-4"><i class="bi bi-send me-1"></i> Send request</button></div>
      </form></div>
  </div>
  <div class="col-lg-4">
    <div class="panel mb-3"><div class="panel-body pt-3"><h3 class="h6 fw-semibold">Before you write</h3>
      <ul class="small text-secondary ps-3 mb-0"><li><a href="help_center.php">Search the Help Center</a></li><li><a href="help_article.php?slug=troubleshooting-errors">Common error messages</a></li><li><a href="help_demo.php">Practise in Demo mode</a></li></ul></div></div>
    <div class="panel"><div class="panel-body pt-3"><h3 class="h6 fw-semibold">What happens next?</h3><p class="small text-secondary mb-0"><?= $me ? 'You can follow the conversation any time under <a href="help_tickets.php">My requests</a>.' : 'After sending you get a private link to your request. Keep it to read our reply. <a href="login.php">Sign in</a> to see all your requests in one place.' ?></p></div></div>
  </div>
</div>
<?php help_end();
