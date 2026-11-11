<?php
// "My inventories": list, switch, edit and delete the inventories you own.
require_once __DIR__ . '/includes/layout.php';
require_login();
if (is_admin()) redirect(ADMIN_HOME);
$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        // Every action first proves the inventory belongs to the signed-in user.
        $st = $pdo->prepare('SELECT * FROM inventories WHERE id=? AND user_id=?'); $st->execute([$id, $uid]);
        $mine = $st->fetch();
        if (!$mine) { throw new Exception('You can only change your own inventories.'); }
        if ($a === 'save') {
            $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? '')); $type = $_POST['business_type'] ?? ''; $desc = trim($_POST['description'] ?? '');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[<>]/', $name)) throw new Exception('Enter a valid name (2 to 120 characters).');
            if (!in_array($type, INVENTORY_TYPES, true)) throw new Exception('Choose the type of business.');
            if (mb_strlen($desc) > 255) throw new Exception('The description is too long (255 characters maximum).');
            $pdo->prepare('UPDATE inventories SET name=?,business_type=?,description=? WHERE id=? AND user_id=?')->execute([$name, $type, $desc ?: null, $id, $uid]);
            $_SESSION['inventory_id'] = $id; current_inventory(true);
            log_activity('inventory_update', $name); flash('Inventory updated.');
        } elseif ($a === 'delete') {
            delete_inventory_cascade($id);
            if ((int)($_SESSION['inventory_id'] ?? 0) === $id) unset($_SESSION['inventory_id']);
            log_activity('inventory_delete', $mine['name']);
            flash('Inventory “' . $mine['name'] . '” and all its records were deleted.');
        }
    } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'You already have an inventory with that name.' : 'Database error.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('inventories.php');
}
page_start('My inventories', 'inventories');
$rows = user_inventories($uid);
$cur = current_inventory()['id'] ?? 0;
?>
<div class="panel">
  <div class="panel-head"><h2>My inventories <small class="text-secondary fw-normal"><?= count($rows) ?> of <?= max_inventories() ?></small></h2>
    <?php if (count($rows) < max_inventories()): ?><a class="btn btn-primary btn-sm" href="create_inventory.php"><i class="bi bi-plus-lg"></i> New inventory</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Name</th><th>Type</th><th class="text-end">Products</th><th>Created</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><div class="fw-medium"><?= e($r['name']) ?> <?= $r['id'] == $cur ? '<span class="badge badge-success ms-1">Current</span>' : '' ?></div><small class="text-secondary"><?= e($r['description']) ?></small></td>
        <td><?= e($r['business_type'] ?? '—') ?></td><td class="text-end"><?= (int)$r['product_count'] ?></td><td class="small"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
        <td class="text-end text-nowrap">
          <?php if ($r['id'] != $cur): ?><form method="post" action="switch_inventory.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light">Open</button></form><?php endif; ?>
          <button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#invModal" data-title-edit="Edit inventory" data-title-new="Edit inventory" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" data-confirm="Delete “<?= e($r['name']) ?>”? ALL of its <?= (int)$r['product_count'] ?> products, suppliers, categories and stock records will be permanently deleted. This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<div class="modal fade" id="invModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <label class="form-label">Inventory name</label><input class="form-control mb-3" name="name" required maxlength="120">
    <label class="form-label">Type of business</label><select class="form-select mb-3" name="business_type" required><?php foreach (INVENTORY_TYPES as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select>
    <label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" maxlength="255"></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save changes</button></div>
</form></div></div>
<?php page_end();
