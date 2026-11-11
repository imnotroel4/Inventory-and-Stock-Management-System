<?php
// There is no email service in a local XAMPP setup, so recovery works through an administrator:
// the request is written to the activity log and an admin issues a temporary password (Users page, key icon).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_layout.php';
if (current_user()) redirect('change_password.php');

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ident = trim($_POST['identifier'] ?? '');
    if ($ident !== '' && !too_many_attempts('password_reset_request', 5, 15)) {
        $st = $pdo->prepare('SELECT id,username FROM users WHERE (username=? OR email=?) AND status=\'active\' LIMIT 1');
        $st->execute([$ident, $ident]);
        if ($u = $st->fetch()) {
            $pdo->prepare('INSERT INTO activity_logs (user_id,action,details,ip_address) VALUES (?,?,?,?)')
                ->execute([$u['id'], 'password_reset_request', 'Requested a password reset: ' . $u['username'], client_ip()]);
        } else {
            $pdo->prepare('INSERT INTO activity_logs (user_id,action,details,ip_address) VALUES (NULL,?,?,?)')
                ->execute(['password_reset_request', 'No match for: ' . mb_substr($ident, 0, 100), client_ip()]);
        }
    }
    $sent = $ident !== ''; // same answer whether or not the account exists
}
auth_start('Forgot password', 'Forgot your password?', 'We cannot email passwords from this system, so an administrator resets it for you.');
?>
      <?php if ($sent): ?>
        <div class="alert alert-success" role="alert"><i class="bi bi-check-circle me-1"></i>If an account matches, an administrator can now see your request. They will give you a temporary password, and you will be asked to choose a new one when you sign in.</div>
        <a class="btn btn-primary w-100" href="login.php">Back to login</a>
      <?php else: ?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="identifier">Email or username</label>
        <input class="form-control mb-4" id="identifier" name="identifier" required autofocus autocomplete="username" maxlength="120">
        <div class="invalid-feedback">Enter your email or username.</div>
        <button class="btn btn-primary w-100 py-2">Request password reset</button>
      </form>
      <p class="text-center mt-4 mb-0"><a href="login.php"><i class="bi bi-arrow-left me-1"></i>Back to login</a></p>
      <?php endif; ?>
<?php auth_end();
