<?php
require_once __DIR__ . '/includes/layout.php';
require_login();
$forced = !empty($_SESSION['must_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = $pdo->prepare('SELECT password FROM users WHERE id=?'); $st->execute([current_user()['id']]);
    $hash = $st->fetchColumn(); $new = $_POST['new'] ?? '';
    if (!$hash || !password_verify($_POST['current'] ?? '', $hash)) flash('Your current password is incorrect.', 'danger');
    elseif ($problem = password_problem($new)) flash('New password: ' . $problem, 'danger');
    elseif ($new !== ($_POST['confirm'] ?? '')) flash('The new passwords do not match.', 'danger');
    elseif (password_verify($new, $hash)) flash('Choose a password different from the current one.', 'danger');
    else {
        $pdo->prepare('UPDATE users SET password=?, must_change=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), current_user()['id']]);
        unset($_SESSION['must_change']); log_activity('password_change', 'Changed own password');
        flash('Password updated.'); redirect(home_url());
    }
    redirect('change_password.php');
}
page_start('Change password', 'account', 'account');
?>
<div class="panel" style="max-width:480px">
  <div class="panel-head"><h2><?= $forced ? 'Set a new password to continue' : 'Change your password' ?></h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <?php if ($forced): ?><div class="alert alert-warning py-2">An administrator reset your password. Enter the temporary password, then choose your own.</div><?php endif; ?>
    <label class="form-label" for="current"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
    <input class="form-control mb-3" type="password" id="current" name="current" required autocomplete="current-password">
    <label class="form-label" for="new">New password</label>
    <input class="form-control mb-3" type="password" id="new" name="new" minlength="8" required autocomplete="new-password">
    <label class="form-label" for="confirm">Confirm new password</label>
    <input class="form-control mb-4" type="password" id="confirm" name="confirm" minlength="8" required autocomplete="new-password">
    <button class="btn btn-primary">Update password</button>
  </form>
</div>
<?php page_end();
