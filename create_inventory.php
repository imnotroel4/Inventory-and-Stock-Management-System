<?php
// First-time experience: a new user with no inventory is sent here after login.
// Also used later to add another inventory (up to the limit set by the administrator).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_layout.php';
require_login();
if (is_admin()) redirect(ADMIN_HOME);
$uid = (int)current_user()['id'];
$existing = user_inventories($uid);
$first = count($existing) === 0;

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    $type = $_POST['business_type'] ?? '';
    $desc = trim($_POST['description'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors['name'] = 'Enter a name (2 to 120 characters).';
    elseif (preg_match('/[<>]/', $name)) $errors['name'] = 'The name contains characters that are not allowed.';
    if (!in_array($type, INVENTORY_TYPES, true)) $errors['business_type'] = 'Choose the type that fits best.';
    if (mb_strlen($desc) > 255) $errors['description'] = 'Use 255 characters or fewer.';
    if (count($existing) >= max_inventories()) $errors['name'] = 'You have reached the limit of ' . max_inventories() . ' inventories.';
    if (!$errors) {
        try {
            // user_id always comes from the session, never from the form, so an inventory can only be created for yourself
            $pdo->prepare('INSERT INTO inventories (user_id,name,business_type,description) VALUES (?,?,?,?)')->execute([$uid, $name, $type, $desc ?: null]);
            $_SESSION['inventory_id'] = (int)$pdo->lastInsertId();
            current_inventory(true);
            log_activity('inventory_create', $name);
            flash($first ? 'Welcome! Your inventory is ready. Start by adding a category and your first products.' : "Inventory “$name” created and selected.");
            redirect('dashboard.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $errors['name'] = 'You already have an inventory with that name.'; else throw $e;
        }
    }
}
auth_start('Create inventory', $first ? 'Create your inventory' : 'Create another inventory',
    $first ? 'Give your workspace a name. Everything you add stays private to your account.' : 'Each inventory has its own products, suppliers and stock records.');
?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="name">Inventory name</label>
        <input class="form-control<?= is_bad($errors, 'name') ?>" id="name" name="name" value="<?= old('name') ?>" required maxlength="120" autofocus placeholder="e.g. Grocery Store Inventory">
        <?= feedback($errors, 'name', 'Enter a name for your inventory.') ?>

        <label class="form-label mt-3" for="business_type">Type of business</label>
        <select class="form-select<?= is_bad($errors, 'business_type') ?>" id="business_type" name="business_type" required>
          <option value="">Choose one…</option>
          <?php foreach (INVENTORY_TYPES as $t): ?><option <?= ($_POST['business_type'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
        <?= feedback($errors, 'business_type', 'Choose the type that fits best.') ?>

        <label class="form-label mt-3" for="description">Description <small class="text-secondary fw-normal">(optional)</small></label>
        <textarea class="form-control<?= is_bad($errors, 'description') ?>" id="description" name="description" rows="2" maxlength="255" placeholder="What is this inventory for?"><?= old('description') ?></textarea>
        <?= feedback($errors, 'description', 'Use 255 characters or fewer.') ?>

        <button class="btn btn-primary w-100 py-2 mt-4">Create inventory</button>
      </form>
      <div class="text-center mt-4">
        <?php if ($first): ?>
          <form method="post" action="logout.php" class="d-inline"><?= csrf_field() ?><button class="btn btn-link p-0">Sign out</button></form>
        <?php else: ?><a href="inventories.php"><i class="bi bi-arrow-left me-1"></i>Back to my inventories</a><?php endif; ?>
      </div>
<?php auth_end();
