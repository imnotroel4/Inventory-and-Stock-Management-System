<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_layout.php';
if (current_user()) redirect(home_url());
if (!allow_registration()) { flash('Self-registration is turned off. Ask an administrator to create your account.', 'warning'); redirect('login.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['website'])) redirect('login.php'); // honeypot field: real people never fill it in

    $name  = trim(preg_replace('/\s+/', ' ', $_POST['full_name'] ?? ''));
    $user  = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw    = $_POST['password'] ?? '';
    $pw2   = $_POST['confirm'] ?? '';

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors['full_name'] = 'Enter your full name (2 to 100 characters).';
    elseif (preg_match('/[<>]/', $name)) $errors['full_name'] = 'The name contains characters that are not allowed.';
    if (!valid_username($user)) $errors['username'] = 'Use 3 to 30 letters, numbers, dots, dashes or underscores.';
    if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($msg = password_problem($pw)) $errors['password'] = $msg;
    if ($pw2 === '' || $pw !== $pw2) $errors['confirm'] = 'The passwords do not match.';
    if (too_many_attempts('register', 5, 60)) $errors['form'] = 'Too many sign-ups from this device. Please try again later.';

    if (!$errors) {
        $st = $pdo->prepare('SELECT username, email FROM users WHERE username=? OR email=?');
        $st->execute([$user, $email]);
        foreach ($st->fetchAll() as $row) {
            if (strcasecmp($row['username'], $user) === 0) $errors['username'] = 'That username is already taken.';
            if ($row['email'] !== null && strcasecmp($row['email'], $email) === 0) $errors['email'] = 'That email address is already registered.';
        }
    }
    if (!$errors) {
        try {
            // Public registration can ONLY ever create a regular user. The role is fixed here, never read from the form.
            $pdo->prepare("INSERT INTO users (full_name,username,email,password,role,status) VALUES (?,?,?,?, 'user', 'active')")
                ->execute([$name, $user, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO activity_logs (user_id,action,details,ip_address) VALUES (?,?,?,?)')
                ->execute([$pdo->lastInsertId(), 'register', 'New account: ' . $user, client_ip()]);
            flash('Account created. Please sign in with your new credentials.');
            redirect('login.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $errors['username'] = 'That username or email is already registered.';
            else throw $e;
        }
    }
}
auth_start('Create account', 'Create your account', 'It takes less than a minute.');
?>
      <?php if (!empty($errors['form'])): ?><div class="alert alert-danger py-2" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <div class="d-none" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

        <label class="form-label" for="full_name">Full name</label>
        <input class="form-control mb-3<?= is_bad($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= old('full_name') ?>" required maxlength="100" autocomplete="name" autofocus>
        <?= feedback($errors, 'full_name', 'Enter your full name.') ?>

        <label class="form-label mt-3" for="username">Username</label>
        <input class="form-control<?= is_bad($errors, 'username') ?>" id="username" name="username" value="<?= old('username') ?>" required pattern="[A-Za-z0-9_.\-]{3,30}" autocomplete="username">
        <?= feedback($errors, 'username', 'Use 3 to 30 letters, numbers, dots, dashes or underscores.') ?>

        <label class="form-label mt-3" for="email">Email address</label>
        <input class="form-control<?= is_bad($errors, 'email') ?>" id="email" name="email" type="email" value="<?= old('email') ?>" required maxlength="120" autocomplete="email">
        <?= feedback($errors, 'email', 'Enter a valid email address.') ?>

        <label class="form-label mt-3" for="password">Password</label>
        <div class="input-group has-validation">
          <input class="form-control<?= is_bad($errors, 'password') ?>" id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password">
          <button class="btn pw-toggle" type="button" data-toggle-password="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          <?= feedback($errors, 'password', 'Use at least 8 characters with a letter and a number.') ?>
        </div>
        <div class="strength mt-2" aria-hidden="true"><span id="strengthBar"></span></div>
        <small class="text-secondary" id="strengthText">At least 8 characters, with a letter and a number.</small>

        <label class="form-label mt-3" for="confirm">Confirm password</label>
        <div class="input-group has-validation mb-4">
          <input class="form-control<?= is_bad($errors, 'confirm') ?>" id="confirm" name="confirm" type="password" required autocomplete="new-password">
          <button class="btn pw-toggle" type="button" data-toggle-password="#confirm" aria-label="Show password"><i class="bi bi-eye"></i></button>
          <?= feedback($errors, 'confirm', 'The passwords do not match.') ?>
        </div>
        <button class="btn btn-primary w-100 py-2">Create Account</button>
      </form>
      <p class="text-center text-secondary mt-4 mb-0">Already have an account? <a href="login.php" class="fw-semibold">Login</a></p>
<?php auth_end();
