<?php
// Admin: manage guided tutorials (the list). Steps are edited on admin_help_steps.php.
require_once __DIR__ . '/includes/help_layout.php';
require_admin();
help_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $title = trim(preg_replace('/\s+/', ' ', $_POST['title'] ?? '')); $desc = trim($_POST['description'] ?? ''); $icon = trim($_POST['icon'] ?? '') ?: 'mortarboard';
            $min = max(1, min(120, (int)($_POST['est_minutes'] ?? 3))); $ord = max(0, min(9999, (int)($_POST['sort_order'] ?? 0))); $pub = isset($_POST['is_published']) ? 1 : 0;
            if (!help_len_ok($title, 3, 160)) throw new Exception('Title must be 3 to 160 characters.');
            if (mb_strlen($desc) > 300) throw new Exception('The description can be up to 300 characters.');
            if (!preg_match('/^[a-z0-9-]{2,40}$/', $icon)) throw new Exception('Icon: use a Bootstrap Icons name such as mortarboard.');
            if ($pub && $id) { $n = $pdo->prepare('SELECT COUNT(*) FROM help_tutorial_steps WHERE tutorial_id=?'); $n->execute([$id]); if (!(int)$n->fetchColumn()) throw new Exception('Add at least one step before publishing this tutorial.'); }
            if ($id) { $pdo->prepare('UPDATE help_tutorials SET title=?,description=?,icon=?,est_minutes=?,sort_order=?,is_published=? WHERE id=?')->execute([$title, $desc, $icon, $min, $ord, $pub, $id]); log_activity('help_tutorial_update', $title); flash('Tutorial updated.'); }
            else {
                $pdo->prepare('INSERT INTO help_tutorials (title,slug,description,icon,est_minutes,sort_order,is_published) VALUES (?,?,?,?,?,?,0)')->execute([$title, help_unique_slug('help_tutorials', $title), $desc, $icon, $min, $ord]);
                log_activity('help_tutorial_add', $title); flash('Tutorial created. Add its steps, then publish it.'); redirect('admin_help_steps.php?id=' . (int)$pdo->lastInsertId());
            }
        } elseif ($a === 'toggle') {
            $n = $pdo->prepare('SELECT COUNT(*) FROM help_tutorial_steps WHERE tutorial_id=?'); $n->execute([$id]);
            $cur = $pdo->prepare('SELECT is_published FROM help_tutorials WHERE id=?'); $cur->execute([$id]); $was = (int)$cur->fetchColumn();
            if (!$was && !(int)$n->fetchColumn()) throw new Exception('Add at least one step before publishing this tutorial.');
            $pdo->prepare('UPDATE help_tutorials SET is_published = 1 - is_published WHERE id=?')->execute([$id]); flash($was ? 'Tutorial unpublished.' : 'Tutorial published.');
        } elseif ($a === 'delete') {
            $im = $pdo->prepare('SELECT image FROM help_tutorial_steps WHERE tutorial_id=? AND image IS NOT NULL'); $im->execute([$id]); $imgs = $im->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('DELETE FROM help_tutorials WHERE id=?')->execute([$id]); foreach ($imgs as $f) help_delete_image($f);
            log_activity('help_tutorial_delete', "ID $id"); flash('Tutorial deleted.');
        }
    } catch (PDOException $e) { flash('Database error. Please try again.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('admin_help_tutorials.php');
}

page_start('Tutorials', 'admin_help_tutorials', 'admin');
$rows = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM help_tutorial_steps s WHERE s.tutorial_id=t.id) steps FROM help_tutorials t ORDER BY t.sort_order, t.title')->fetchAll(); ?>
<div class="panel"><div class="panel-head"><h2>Guided tutorials</h2><button class="btn btn-primary btn-sm" data-edit-modal="#tutModal" data-title-new="New tutorial" data-title-edit="Edit tutorial"><i class="bi bi-plus-lg"></i> New tutorial</button></div>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Tutorial</th><th class="text-end">Steps</th><th class="text-end">Minutes</th><th class="text-end">Order</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><i class="bi bi-<?= e(help_icon($r['icon'], 'mortarboard')) ?> me-2" style="color:var(--accent)"></i><a class="fw-medium text-decoration-none" href="admin_help_steps.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a><small class="d-block text-secondary"><?= e($r['description']) ?></small></td><td class="text-end"><?= (int)$r['steps'] ?></td><td class="text-end"><?= (int)$r['est_minutes'] ?></td><td class="text-end"><?= (int)$r['sort_order'] ?></td>
    <td><?= $r['is_published'] ? '<span class="badge badge-success">Published</span>' : '<span class="badge badge-muted">Unpublished</span>' ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="admin_help_steps.php?id=<?= (int)$r['id'] ?>" title="Edit steps"><i class="bi bi-list-ol"></i> Steps</a>
      <a class="btn btn-sm btn-light" href="help_tutorial.php?slug=<?= e($r['slug']) ?>" target="_blank" rel="noopener" aria-label="Try it"><i class="bi bi-play"></i></a>
      <button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#tutModal" data-title-edit="Edit tutorial" data-title-new="New tutorial" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $r['is_published'] ? 'Unpublish' : 'Publish' ?>" aria-label="<?= $r['is_published'] ? 'Unpublish' : 'Publish' ?>"><i class="bi bi-<?= $r['is_published'] ? 'toggle-on text-success' : 'toggle-off' ?>"></i></button></form>
      <form method="post" class="d-inline" data-confirm="Delete this tutorial and all its steps?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No tutorials yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="modal fade" id="tutModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="col-12"><label class="form-label">Title</label><input class="form-control" name="title" required maxlength="160"></div>
    <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="300"></div>
    <div class="col-md-4"><label class="form-label">Icon</label><input class="form-control" name="icon" maxlength="40" placeholder="mortarboard" pattern="[a-z0-9\-]*"></div>
    <div class="col-md-4"><label class="form-label">Minutes</label><input type="number" class="form-control" name="est_minutes" min="1" max="120" value="3"></div>
    <div class="col-md-4"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" min="0" max="9999" value="0"></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_published" id="tutPub" value="1"><label class="form-check-label" for="tutPub">Published</label></div></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save tutorial</button></div>
</form></div></div>
<script>
document.querySelectorAll('[data-edit-modal="#tutModal"]').forEach(function (b) { b.addEventListener('click', function () {
  var row = b.dataset.row ? JSON.parse(b.dataset.row) : null, f = document.querySelector('#tutModal form');
  f.elements.is_published.checked = !!row && row.is_published == 1; if (!row) { f.elements.est_minutes.value = 3; f.elements.sort_order.value = 0; }
}); });
</script>
<?php page_end();
