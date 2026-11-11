<?php
// Profile & settings for a regular user: name, email, photo. The role is never read from this form.
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory();
$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $name = trim(preg_replace('/\s+/', ' ', $_POST['full_name'] ?? '')); $email = strtolower(trim($_POST['email'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[<>]/', $name)) throw new Exception('Enter your full name (2 to 100 characters).');
        if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address.');
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email=? AND id<>?'); $st->execute([$email, $uid]);
        if ($st->fetchColumn()) throw new Exception('That email address is already registered to another account.');
        $old = $pdo->prepare('SELECT avatar FROM users WHERE id=?'); $old->execute([$uid]);
        $avatar = upload_image($_FILES['avatar'] ?? [], $old->fetchColumn() ?: null);
        $pdo->prepare('UPDATE users SET full_name=?, email=?, avatar=? WHERE id=?')->execute([$name, $email, $avatar, $uid]);   // note: role and status are not touched
        $_SESSION['user']['full_name'] = $name;
        log_activity('profile_update', 'Updated own profile'); flash('Profile updated.');
    } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'That email address is already registered.' : 'Database error.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('profile.php');
}
page_start('Profile & settings', 'profile');
$st = $pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]); $me = $st->fetch();
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="panel"><div class="panel-head"><h2>Your profile</h2></div>
    <form method="post" enctype="multipart/form-data" class="panel-body"><?= csrf_field() ?>
      <div class="d-flex align-items-center gap-3 mb-3"><?= avatar_html($me['full_name'], $me['avatar'], 'lg') ?>
        <div class="flex-grow-1"><label class="form-label">Profile photo <small class="text-secondary fw-normal">(optional, max 2 MB)</small></label><input class="form-control" type="file" name="avatar" accept="image/*"></div></div>
      <label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" value="<?= e($me['full_name']) ?>" required maxlength="100">
      <label class="form-label">Username</label><input class="form-control mb-3" value="<?= e($me['username']) ?>" disabled>
      <label class="form-label">Email address</label><input class="form-control mb-4" type="email" name="email" value="<?= e($me['email']) ?>" required maxlength="120">
      <button class="btn btn-primary">Save profile</button>
    </form></div></div>
  <div class="col-lg-5">
    <div class="panel mb-3"><div class="panel-head"><h2>Account</h2></div><div class="panel-body small">
      <div class="d-flex justify-content-between py-1"><span class="text-secondary">Member since</span><span><?= date('M d, Y', strtotime($me['created_at'])) ?></span></div>
      <div class="d-flex justify-content-between py-1"><span class="text-secondary">Last sign-in</span><span><?= $me['last_login'] ? date('M d, Y h:i A', strtotime($me['last_login'])) : '—' ?></span></div>
      <a class="btn btn-light mt-3" href="change_password.php"><i class="bi bi-key me-1"></i>Change password</a></div></div>
    <div class="panel"><div class="panel-head"><h2>Inventory settings</h2></div><div class="panel-body small">
      <p class="mb-2">Current inventory: <strong><?= e($inv['name']) ?></strong></p>
      <a class="btn btn-light" href="inventories.php"><i class="bi bi-collection me-1"></i>Manage my inventories</a></div></div>
  </div>
</div>
<?php page_end();
