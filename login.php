<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_layout.php';
if (current_user()) redirect(home_url());

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ident = trim($_POST['identifier'] ?? ''); $pw = $_POST['password'] ?? '';
    if ($ident === '' || $pw === '') {
        $error = 'Enter your username or email and your password.';
    } elseif (too_many_attempts('login_failed')) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $st = $pdo->prepare('SELECT * FROM users WHERE username=? OR email=? ORDER BY (username=?) DESC LIMIT 1');
        $st->execute([$ident, $ident, $ident]);
        $u = $st->fetch();
        if ($u && password_verify($pw, $u['password'])) {
            if ($u['status'] !== 'active') {
                log_activity('login_blocked', $u['username'] . ' (' . $u['status'] . ')');
                $error = account_block_message($u['status']);
            } elseif (maintenance_on() && $u['role'] !== 'admin') {
                $error = setting('maintenance_message');
            } else {
                start_user_session($u);
                $pdo->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$u['id']]);
                if (!empty($_POST['remember'])) remember_issue($u['id']);
                log_activity('login', 'Signed in');
                redirect(!empty($_SESSION['must_change']) ? 'change_password.php' : home_url());   // admin -> admin area, user -> dashboard or Create Inventory
            }
        } else {
            log_activity('login_failed', mb_substr($ident, 0, 100));
            $error = 'Incorrect username/email or password.';
        }
    }
}
auth_start('Sign in', 'Welcome back', 'Sign in to your ' . site_name() . ' account.');
?>
      <?php if (!admin_exists() && is_local_request()): ?><div class="alert alert-info py-2"><i class="bi bi-shield-lock me-1"></i>No administrator exists yet. <a href="setup.php" class="fw-semibold">Run the one-time setup</a>.</div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="identifier">Email or username</label>
        <input class="form-control mb-3" id="identifier" name="identifier" value="<?= old('identifier') ?>" required autofocus autocomplete="username" maxlength="120">
        <label class="form-label" for="password">Password</label>
        <div class="input-group has-validation mb-3">
          <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
          <button class="btn pw-toggle" type="button" data-toggle-password="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          <div class="invalid-feedback">Enter your password.</div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="form-check"><input class="form-check-input" type="checkbox" id="remember" name="remember" value="1"><label class="form-check-label" for="remember">Remember me</label></div>
          <a href="forgot_password.php" class="small">Forgot password?</a>
        </div>
        <button class="btn btn-primary w-100 py-2">Login</button>
      </form>
      <?php if (allow_registration()): ?><p class="text-center text-secondary mt-4 mb-0">Don't have an account yet? <a href="register.php" class="fw-semibold">Create an Account</a></p><?php endif; ?>
<?php auth_end();
