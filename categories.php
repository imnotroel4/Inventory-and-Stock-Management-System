<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $desc = trim($_POST['description'] ?? '');
        if ($a === 'save') {
            if ($name === '') throw new Exception('Category name is required.');
            if ($id) { require_owned('categories', $id, $iid, 'Category'); $pdo->prepare('UPDATE categories SET name=?,description=? WHERE id=? AND inventory_id=?')->execute([$name, $desc, $id, $iid]); log_activity('category_update', $name); flash('Category updated.'); }
            else { $pdo->prepare('INSERT INTO categories (inventory_id,name,description) VALUES (?,?,?)')->execute([$iid, $name, $desc]); log_activity('category_add', $name); flash('Category added.'); }
        } elseif ($a === 'delete') {
            require_owned('categories', $id, $iid, 'Category');
            $pdo->prepare('DELETE FROM categories WHERE id=? AND inventory_id=?')->execute([$id, $iid]); log_activity('category_delete', "ID $id");
            flash('Category deleted. Its products are now uncategorized.');
        }
    } catch (PDOException $e) { flash($e->getCode() == 23000 ? 'A category with that name already exists.' : 'Database error.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('categories.php');
}
page_start('Categories', 'categories');
$rows = $pdo->prepare('SELECT c.*, COUNT(p.id) products FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.inventory_id=c.inventory_id WHERE c.inventory_id=? GROUP BY c.id ORDER BY c.name'); $rows->execute([$iid]); $rows = $rows->fetchAll();
?>
<div class="panel">
  <div class="panel-head"><h2>All categories</h2>
    <button class="btn btn-primary btn-sm" data-edit-modal="#catModal" data-title-new="Add category" data-title-edit="Edit category"><i class="bi bi-plus-lg"></i> Add category</button></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Name</th><th>Description</th><th class="text-end">Products</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="fw-medium"><?= e($r['name']) ?></td><td class="text-secondary"><?= e($r['description']) ?></td><td class="text-end"><?= $r['products'] ?></td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-light" data-edit-modal="#catModal" data-title-edit="Edit category" data-title-new="Add category" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" data-confirm="Delete this category? Products in it will become uncategorized."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button></form></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="empty">No categories yet. Add one to start organizing products.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="modal fade" id="catModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <label class="form-label">Name</label><input class="form-control mb-3" name="name" required maxlength="100">
    <label class="form-label">Description</label><input class="form-control" name="description" maxlength="255"></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save category</button></div>
</form></div></div>
<?php page_end();
