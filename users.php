<?php
require_once __DIR__ . '/includes/layout.php';
require_admin();   // server-side lock: a normal user who types this address gets "Access denied"
const USER_STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'];

// True if some OTHER active administrator exists (so the last admin can never be locked out or deleted)
function other_active_admin($exceptId) {
    global $pdo;
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' AND id<>?"); $st->execute([$exceptId]);
    return (int)$st->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $id = (int)($_POST['id'] ?? 0); $me = (int)current_user()['id'];
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'save') {
            $name = trim(preg_replace('/\s+/', ' ', $_POST['full_name'] ?? '')); $user = trim($_POST['username'] ?? '');
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
            $status = array_key_exists($_POST['status'] ?? '', USER_STATUSES) ? $_POST['status'] : 'active';
            $pw = $_POST['password'] ?? ''; $email = strtolower(trim($_POST['email'] ?? '')); $mc = isset($_POST['must_change']) ? 1 : 0;
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/[<>]/', $name)) throw new Exception('Enter a valid full name (2 to 100 characters).');
            if (!valid_username($user)) throw new Exception('Username: use 3 to 30 letters, numbers, dots, dashes or underscores.');
            if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address.');
            if (!$id || $pw !== '') { if ($p = password_problem($pw)) throw new Exception('Password: ' . $p); }
            $dup = $pdo->prepare('SELECT username,email FROM users WHERE (username=? OR email=?) AND id<>?'); $dup->execute([$user, $email, $id]);
            foreach ($dup->fetchAll() as $r) {
                if (strcasecmp($r['username'], $user) === 0) throw new Exception('That username is already taken.');
                throw new Exception('That email address is already registered.');
            }
            $old = null; $prev = null;
            if ($id) { $q = $pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $prev = $q->fetch(); if (!$prev) throw new Exception('User not found.'); $old = $prev['avatar']; }
            if ($id === $me && ($status !== 'active' || $role !== 'admin')) throw new Exception('You cannot disable or demote your own account.');
            if ($prev && $prev['role'] === 'admin' && ($role !== 'admin' || $status !== 'active') && !other_active_admin($id)) throw new Exception('This is the last active administrator and cannot be demoted or disabled.');
            $avatar = upload_image($_FILES['avatar'] ?? [], $old);
            if ($id) {
                $pdo->prepare('UPDATE users SET full_name=?,username=?,email=?,avatar=?,role=?,status=?,must_change=? WHERE id=?')->execute([$name, $user, $email, $avatar, $role, $status, $mc, $id]);
                if ($id === $me) $_SESSION['user']['full_name'] = $name;
                if ($pw !== '') $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                if ($prev['status'] !== $status) log_activity('user_status', "$user: {$prev['status']} -> $status");
                if ($prev['role'] !== $role) log_activity('user_role', "$user: {$prev['role']} -> $role");
                log_activity('user_update', $user); flash('User updated.');
            } else {
                $pdo->prepare('INSERT INTO users (full_name,username,email,avatar,password,role,status,must_change) VALUES (?,?,?,?,?,?,?,?)')->execute([$name, $user, $email, $avatar, password_hash($pw, PASSWORD_DEFAULT), $role, $status, $mc]);
                log_activity('user_add', "$user ($role)"); flash('User created.');
            }
        } elseif ($a === 'set_status') {   // quick Activate / Suspend buttons
            $status = array_key_exists($_POST['status'] ?? '', USER_STATUSES) ? $_POST['status'] : null;
            $q = $pdo->prepare('SELECT username,role,status FROM users WHERE id=?'); $q->execute([$id]); $t = $q->fetch();
            if (!$t || !$status) throw new Exception('User not found.');
            if ($id === $me) throw new Exception('You cannot change the status of your own account.');
            if ($t['role'] === 'admin' && $status !== 'active' && !other_active_admin($id)) throw new Exception('This is the last active administrator.');
            $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status, $id]);
            if ($status !== 'active') $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$id]);
            log_activity('user_status', "{$t['username']}: {$t['status']} -> $status"); flash("{$t['username']} is now " . strtolower(USER_STATUSES[$status]) . '.');
        } elseif ($a === 'reset') {
            if ($id === $me) throw new Exception('Use Change password in the account menu for your own account.');
            $q = $pdo->prepare('SELECT username FROM users WHERE id=?'); $q->execute([$id]); $target = $q->fetchColumn();
            if (!$target) throw new Exception('User not found.');
            $tmp = substr(strtr(base64_encode(random_bytes(9)), '+/=', 'xyz'), 0, 10) . '7a';
            $pdo->prepare('UPDATE users SET password=?, must_change=1 WHERE id=?')->execute([password_hash($tmp, PASSWORD_DEFAULT), $id]);
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id=?')->execute([$id]);
            log_activity('password_reset', $target);
            flash("Temporary password for $target: $tmp. Share it privately. It is shown only once and must be changed at the next sign-in.", 'warning');
        } elseif ($a === 'delete') {
            if ($id === $me) throw new Exception('You cannot delete your own account.');
            $q = $pdo->prepare('SELECT username,role FROM users WHERE id=?'); $q->execute([$id]); $t = $q->fetch();
            if (!$t) throw new Exception('User not found.');
            if ($t['role'] === 'admin' && !other_active_admin($id)) throw new Exception('This is the last active administrator and cannot be deleted.');
            $n = $pdo->prepare('SELECT COUNT(*) FROM inventories WHERE user_id=?'); $n->execute([$id]); $n = (int)$n->fetchColumn();
            delete_user_cascade($id);
            log_activity('user_delete', $t['username'] . " (and $n inventories)"); flash('User deleted together with ' . $n . ' inventor' . ($n === 1 ? 'y' : 'ies') . '.');
        }
    } catch (PDOException $e) {
        if (!empty($avatar) && $avatar !== ($old ?? null)) @unlink(UPLOAD_DIR . $avatar); flash($e->getCode() == 23000 ? 'That username or email is already taken.' : 'Database error.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('users.php');
}
if (($_GET['tab'] ?? '') === 'logs') redirect('admin_logs.php');
$tab = 'users';
page_start($tab === 'logs' ? 'Activity logs' : 'Users', 'users', 'admin');
if ($tab === 'users'):
    $rows = $pdo->query('SELECT u.*, (SELECT COUNT(*) FROM inventories i WHERE i.user_id=u.id) inv_count FROM users u ORDER BY u.role, u.full_name')->fetchAll();
?>
<div class="panel">
  <div class="panel-head"><h2>Accounts</h2><div class="d-flex gap-2"><input class="form-control form-control-sm" style="width:220px" placeholder="Search name, username, email" data-filter="#userTable"><button class="btn btn-primary btn-sm" data-edit-modal="#userModal" data-title-new="Add user" data-title-edit="Edit user"><i class="bi bi-plus-lg"></i> Add user</button></div></div>
  <div class="table-responsive"><table class="table table-hover" id="userTable"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th class="text-end">Inventories</th><th>Status</th><th>Registered</th><th>Last sign-in</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $r['password'] = ''; $self = $r['id'] == current_user()['id']; ?>
    <tr><td><div class="d-flex align-items-center gap-3"><?= avatar_html($r['full_name'], $r['avatar'], 'sm') ?><div><a class="fw-medium text-decoration-none" href="admin_user.php?id=<?= $r['id'] ?>"><?= e($r['full_name']) ?></a><br><small class="text-secondary"><?= e($r['email'] ?: 'No email') ?></small></div></div></td><td><?= e($r['username']) ?></td>
      <td><span class="badge badge-<?= $r['role'] === 'admin' ? 'info' : 'muted' ?>"><?= $r['role'] === 'admin' ? 'Admin' : 'User' ?></span></td>
      <td class="text-end"><?= (int)$r['inv_count'] ?></td>
      <td><span class="badge badge-<?= ['active' => 'success', 'inactive' => 'muted', 'suspended' => 'danger'][$r['status']] ?>"><?= e(USER_STATUSES[$r['status']]) ?></span></td>
      <td class="small"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
      <td class="small"><?= $r['last_login'] ? date('M d, Y h:i A', strtotime($r['last_login'])) : 'Never' ?></td>
      <td class="text-end text-nowrap"><button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#userModal" data-title-edit="Edit user" data-title-new="Add user" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
        <?php if (!$self): ?>
        <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'active' ? 'Suspend ' . e($r['username']) . '? They will be signed out and cannot log in until reactivated.' : 'Activate ' . e($r['username']) . '?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="status" value="<?= $r['status'] === 'active' ? 'suspended' : 'active' ?>">
          <button class="btn btn-sm btn-light <?= $r['status'] === 'active' ? 'text-warning' : 'text-success' ?>" title="<?= $r['status'] === 'active' ? 'Suspend' : 'Activate' ?>"><i class="bi bi-<?= $r['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i></button></form>
        <form method="post" class="d-inline" data-confirm="Reset the password for <?= e($r['username']) ?>? A new temporary password will be shown once."><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light" title="Reset password"><i class="bi bi-key"></i></button></form>
        <form method="post" class="d-inline" data-confirm="PERMANENTLY delete <?= e($r['username']) ?> and ALL <?= (int)$r['inv_count'] ?> of their inventories, products and stock records? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light text-danger" title="Delete user"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="d-flex align-items-center gap-3 mb-3"><img id="avatarPrev" data-img class="avatar lg d-none" alt=""><div class="flex-grow-1"><label class="form-label">Profile photo <small class="text-secondary fw-normal">(optional, max 2 MB)</small></label><input class="form-control" type="file" name="avatar" accept="image/*" data-preview="#avatarPrev"></div></div>
    <label class="form-label">Full name</label><input class="form-control mb-3" name="full_name" required maxlength="100">
    <label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required maxlength="120" autocomplete="off">
    <div class="row g-3 mb-3"><div class="col-4"><label class="form-label">Username</label><input class="form-control" name="username" required autocomplete="off"></div>
      <div class="col-4"><label class="form-label">Role</label><select class="form-select" name="role"><option value="user">User</option><option value="admin">Admin</option></select></div>
      <div class="col-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (USER_STATUSES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div></div>
    <label class="form-label">Password <small class="text-secondary fw-normal">(leave blank to keep the current one)</small></label><input class="form-control mb-3" type="password" name="password" autocomplete="new-password">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="must_change" id="must_change" value="1"><label class="form-check-label" for="must_change">Require a new password at next sign-in</label></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save user</button></div>
</form></div></div>
<?php if (isset($_GET['new'])): ?><script>window.addEventListener('load',()=>document.querySelector('[data-edit-modal="#userModal"]').click())</script><?php endif; ?>
<?php else:
    $logs = $pdo->query('SELECT l.*,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 300')->fetchAll(); ?>
<div class="panel"><div class="panel-head"><h2>Latest 300 activities</h2><input class="form-control form-control-sm" style="width:220px" placeholder="Filter logs" data-filter="#logTable"></div>
  <div class="table-responsive"><table class="table table-sm table-hover" id="logTable"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead><tbody>
  <?php foreach ($logs as $l): ?><tr><td><?= date('M d, Y h:i:s A', strtotime($l['created_at'])) ?></td><td><?= e($l['full_name'] ?? 'Deleted user') ?></td><td><span class="badge badge-muted"><?= e($l['action']) ?></span></td><td><?= e($l['details']) ?></td><td><?= e($l['ip_address']) ?></td></tr>
  <?php endforeach; if (!$logs): ?><tr><td colspan="5" class="empty">No activity recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; page_end();
