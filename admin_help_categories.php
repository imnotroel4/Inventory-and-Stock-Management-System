<?php
// Admin: organize Help Center content into categories.
require_once __DIR__ . '/includes/help_layout.php';
require_admin();
help_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? '')); $desc = trim($_POST['description'] ?? ''); $icon = trim($_POST['icon'] ?? '');
            $ord = max(0, min(9999, (int)($_POST['sort_order'] ?? 0))); $act = isset($_POST['is_active']) ? 1 : 0;
            if (!help_len_ok($name, 2, 100)) throw new Exception('Category name must be 2 to 100 characters.');
            if (mb_strlen($desc) > 255) throw new Exception('The description can be up to 255 characters.');
            if ($icon === '') $icon = 'journal-text'; if (!preg_match('/^[a-z0-9-]{2,40}$/', $icon)) throw new Exception('Icon: use a Bootstrap Icons name such as journal-text or rocket-takeoff.');
            $dup = $pdo->prepare('SELECT 1 FROM help_categories WHERE name=? AND id<>?'); $dup->execute([$name, $id]);
            if ($dup->fetchColumn()) throw new Exception('A category with that name already exists.');
            if ($id) { $pdo->prepare('UPDATE help_categories SET name=?,icon=?,description=?,sort_order=?,is_active=? WHERE id=?')->execute([$name, $icon, $desc, $ord, $act, $id]); log_activity('help_category_update', $name); flash('Category updated.'); }
            else { $pdo->prepare('INSERT INTO help_categories (name,slug,icon,description,sort_order,is_active) VALUES (?,?,?,?,?,?)')->execute([$name, help_unique_slug('help_categories', $name), $icon, $desc, $ord, $act]); log_activity('help_category_add', $name); flash('Category added.'); }
        } elseif ($a === 'delete') {
            $pdo->prepare('DELETE FROM help_categories WHERE id=?')->execute([$id]); log_activity('help_category_delete', "ID $id");
            flash('Category deleted. Its articles and FAQs are kept and now have no category.');
        }
    } catch (PDOException $e) { flash('Database error. Please try again.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('admin_help_categories.php');
}

page_start('Help categories', 'admin_help_categories', 'admin');
$rows = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM help_articles a WHERE a.category_id=c.id) arts, (SELECT COUNT(*) FROM help_faqs f WHERE f.category_id=c.id) faqs FROM help_categories c ORDER BY c.sort_order, c.name')->fetchAll(); ?>
<div class="panel"><div class="panel-head"><h2>Help categories</h2><button class="btn btn-primary btn-sm" data-edit-modal="#hcModal" data-title-new="Add category" data-title-edit="Edit category"><i class="bi bi-plus-lg"></i> Add category</button></div>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Category</th><th>Address</th><th class="text-end">Articles</th><th class="text-end">FAQs</th><th class="text-end">Order</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><i class="bi bi-<?= e(help_icon($r['icon'])) ?> me-2" style="color:var(--accent)"></i><span class="fw-medium"><?= e($r['name']) ?></span><small class="d-block text-secondary"><?= e($r['description']) ?></small></td><td class="small text-secondary"><?= e($r['slug']) ?></td><td class="text-end"><?= (int)$r['arts'] ?></td><td class="text-end"><?= (int)$r['faqs'] ?></td><td class="text-end"><?= (int)$r['sort_order'] ?></td>
    <td><?= $r['is_active'] ? '<span class="badge badge-success">Visible</span>' : '<span class="badge badge-muted">Hidden</span>' ?></td>
    <td class="text-end text-nowrap"><button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#hcModal" data-title-edit="Edit category" data-title-new="Add category" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline" data-confirm="Delete this category? Its articles and FAQs are kept but will have no category."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">No categories yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="modal fade" id="hcModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="col-12"><label class="form-label">Name</label><input class="form-control" name="name" required maxlength="100"></div>
    <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255"></div>
    <div class="col-md-6"><label class="form-label">Icon <a class="small" href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">(names)</a></label><input class="form-control" name="icon" maxlength="40" placeholder="journal-text" pattern="[a-z0-9\-]*"></div>
    <div class="col-md-6"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" min="0" max="9999" value="0"></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="hcAct" value="1" checked><label class="form-check-label" for="hcAct">Visible in the Help Center</label></div></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save category</button></div>
</form></div></div>
<script>
// the shared modal filler (app.js) does not handle checkboxes except a few known names, so set ours here
document.querySelectorAll('[data-edit-modal="#hcModal"]').forEach(function (b) { b.addEventListener('click', function () {
  var row = b.dataset.row ? JSON.parse(b.dataset.row) : null, f = document.querySelector('#hcModal form');
  f.elements.is_active.checked = row ? row.is_active == 1 : true; if (!row) f.elements.sort_order.value = 0;
}); });
</script>
<?php page_end();
