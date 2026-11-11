<?php
// ONE-TIME protected setup: creates the first administrator.
// It only works (1) from the server computer itself (localhost) and (2) while NO administrator exists.
// After the first admin is created this page refuses to run, so it can never be used to add admins later.
// (Public registration can never create an admin.)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_layout.php';

if (admin_exists()) { flash('Setup is already complete. Please sign in.', 'info'); redirect('login.php'); }
if (!is_local_request()) { http_response_code(403); exit('Setup can only be run from the server computer (http://localhost/...).'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = trim(preg_replace('/\s+/', ' ', $_POST['full_name'] ?? ''));
    $user  = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw    = $_POST['password'] ?? ''; $pw2 = $_POST['confirm'] ?? '';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors['full_name'] = 'Enter a name (2 to 100 characters).';
    if (!valid_username($user)) $errors['username'] = 'Use 3 to 30 letters, numbers, dots, dashes or underscores.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $errors['email'] = 'Enter a valid email address.';
    if ($msg = password_problem($pw)) $errors['password'] = $msg;
    if ($pw !== $pw2) $errors['confirm'] = 'The passwords do not match.';
    if (!$errors) {
        $st = $pdo->prepare('SELECT username,email FROM users WHERE username=? OR email=?'); $st->execute([$user, $email]);
        foreach ($st->fetchAll() as $r) {
            if (strcasecmp($r['username'], $user) === 0) $errors['username'] = 'That username is already taken.';
            if (strcasecmp((string)$r['email'], $email) === 0) $errors['email'] = 'That email is already registered.';
        }
    }
    if (!$errors) {
        $pdo->prepare("INSERT INTO users (full_name,username,email,password,role,status) VALUES (?,?,?,?, 'admin', 'active')")
            ->execute([$name, $user, $email, password_hash($pw, PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO activity_logs (user_id,action,details,ip_address) VALUES (?,?,?,?)')
            ->execute([$pdo->lastInsertId(), 'admin_setup', 'First administrator created: ' . $user, client_ip()]);
        flash('Administrator account created. Sign in below.');
        redirect('login.php');
    }
}
auth_start('Setup', 'Create the administrator', 'One-time setup. This page locks itself once the administrator exists.');
?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="full_name">Full name</label>
        <input class="form-control<?= is_bad($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= old('full_name') ?>" required maxlength="100" autofocus>
        <?= feedback($errors, 'full_name', 'Enter your full name.') ?>
        <label class="form-label mt-3" for="username">Username</label>
        <input class="form-control<?= is_bad($errors, 'username') ?>" id="username" name="username" value="<?= old('username') ?>" required pattern="[A-Za-z0-9_.\-]{3,30}">
        <?= feedback($errors, 'username', 'Use 3 to 30 letters, numbers, dots, dashes or underscores.') ?>
        <label class="form-label mt-3" for="email">Email address</label>
        <input class="form-control<?= is_bad($errors, 'email') ?>" id="email" name="email" type="email" value="<?= old('email') ?>" required maxlength="120">
        <?= feedback($errors, 'email', 'Enter a valid email address.') ?>
        <label class="form-label mt-3" for="password">Password</label>
        <div class="input-group has-validation">
          <input class="form-control<?= is_bad($errors, 'password') ?>" id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
          <button class="btn pw-toggle" type="button" data-toggle-password="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          <?= feedback($errors, 'password', 'Use at least 8 characters with a letter and a number.') ?>
        </div>
        <label class="form-label mt-3" for="confirm">Confirm password</label>
        <div class="input-group has-validation mb-4">
          <input class="form-control<?= is_bad($errors, 'confirm') ?>" id="confirm" name="confirm" type="password" required autocomplete="new-password">
          <button class="btn pw-toggle" type="button" data-toggle-password="#confirm" aria-label="Show password"><i class="bi bi-eye"></i></button>
          <?= feedback($errors, 'confirm', 'The passwords do not match.') ?>
        </div>
        <button class="btn btn-primary w-100 py-2">Create administrator</button>
      </form>
<?php auth_end();
