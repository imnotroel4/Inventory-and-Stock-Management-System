<?php
// Admin: manage Frequently Asked Questions.
require_once __DIR__ . '/includes/help_layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
help_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $q = trim(preg_replace('/\s+/', ' ', $_POST['question'] ?? '')); $ans = str_replace("\r\n", "\n", trim($_POST['answer'] ?? '')); $cat = (int)($_POST['category_id'] ?? 0) ?: null;
            $ord = max(0, min(9999, (int)($_POST['sort_order'] ?? 0))); $pub = isset($_POST['is_published']) ? 1 : 0;
            if (!help_len_ok($q, 5, 255)) throw new Exception('The question must be 5 to 255 characters.');
            if (!help_len_ok($ans, 2, 8000)) throw new Exception('The answer must be 2 to 8,000 characters.');
            if ($cat) { $c = $pdo->prepare('SELECT 1 FROM help_categories WHERE id=?'); $c->execute([$cat]); if (!$c->fetchColumn()) throw new Exception('Choose a valid category.'); }
            if ($id) { $pdo->prepare('UPDATE help_faqs SET category_id=?,question=?,answer=?,is_published=?,sort_order=? WHERE id=?')->execute([$cat, $q, $ans, $pub, $ord, $id]); log_activity('help_faq_update', $q); flash('FAQ updated.'); }
            else { $pdo->prepare('INSERT INTO help_faqs (category_id,question,answer,is_published,sort_order) VALUES (?,?,?,?,?)')->execute([$cat, $q, $ans, $pub, $ord]); log_activity('help_faq_add', $q); flash('FAQ added.'); }
        } elseif ($a === 'toggle') {
            $pdo->prepare('UPDATE help_faqs SET is_published = 1 - is_published WHERE id=?')->execute([$id]); flash('FAQ visibility changed.');
        } elseif ($a === 'delete') {
            $pdo->prepare('DELETE FROM help_faqs WHERE id=?')->execute([$id]); log_activity('help_faq_delete', "ID $id"); flash('FAQ deleted.');
        }
    } catch (PDOException $e) { flash('Database error. Please try again.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('admin_help_faqs.php');
}

page_start('FAQs', 'admin_help_faqs', 'admin');
$cats = help_categories(false); $q = trim($_GET['q'] ?? '');
$where = ['1']; $args = []; if ($q !== '') { $where[] = '(f.question LIKE ? OR f.answer LIKE ?)'; $l = help_like($q); array_push($args, $l, $l); }
$st = $pdo->prepare('SELECT f.*, c.name cat_name FROM help_faqs f LEFT JOIN help_categories c ON c.id=f.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY f.sort_order, f.id'); $st->execute($args); $rows = $st->fetchAll(); ?>
<div class="panel"><div class="panel-head"><h2><?= count($rows) ?> FAQ<?= count($rows) == 1 ? '' : 's' ?></h2><button class="btn btn-primary btn-sm" data-edit-modal="#faqModal" data-title-new="Add FAQ" data-title-edit="Edit FAQ"><i class="bi bi-plus-lg"></i> Add FAQ</button></div>
  <form class="panel-body border-bottom d-flex gap-2" method="get"><input class="form-control form-control-sm" style="max-width:320px" name="q" value="<?= e($q) ?>" placeholder="Search FAQs"><button class="btn btn-sm btn-primary">Search</button><a class="btn btn-sm btn-light" href="admin_help_faqs.php">Reset</a></form>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Question</th><th>Category</th><th class="text-end">Order</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><span class="fw-medium"><?= e($r['question']) ?></span><small class="d-block text-secondary"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $r['answer']), 0, 110, '…')) ?></small></td><td><?= e($r['cat_name'] ?? '—') ?></td><td class="text-end"><?= (int)$r['sort_order'] ?></td>
    <td><?= $r['is_published'] ? '<span class="badge badge-success">Published</span>' : '<span class="badge badge-muted">Unpublished</span>' ?></td>
    <td class="text-end text-nowrap"><button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#faqModal" data-title-edit="Edit FAQ" data-title-new="Add FAQ" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $r['is_published'] ? 'Unpublish' : 'Publish' ?>" aria-label="<?= $r['is_published'] ? 'Unpublish' : 'Publish' ?>"><i class="bi bi-<?= $r['is_published'] ? 'toggle-on text-success' : 'toggle-off' ?>"></i></button></form>
      <form method="post" class="d-inline" data-confirm="Delete this FAQ?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="empty">No FAQs yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="modal fade" id="faqModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="col-12"><label class="form-label">Question</label><input class="form-control" name="question" required maxlength="255"></div>
    <div class="col-12"><label class="form-label">Answer <span class="text-secondary fw-normal small">(supports **bold**, - bullets, [links](help_center.php))</span></label><textarea class="form-control" name="answer" rows="6" required maxlength="8000"></textarea></div>
    <div class="col-md-6"><label class="form-label">Category</label><select class="form-select" name="category_id"><option value="">No category</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" min="0" max="9999" value="0"></div>
    <div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_published" id="faqPub" value="1" checked><label class="form-check-label" for="faqPub">Published</label></div></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save FAQ</button></div>
</form></div></div>
<script>
document.querySelectorAll('[data-edit-modal="#faqModal"]').forEach(function (b) { b.addEventListener('click', function () {
  var row = b.dataset.row ? JSON.parse(b.dataset.row) : null, f = document.querySelector('#faqModal form');
  f.elements.is_published.checked = row ? row.is_published == 1 : true; f.elements.category_id.value = row && row.category_id ? row.category_id : ''; if (!row) f.elements.sort_order.value = 0;
}); });
</script>
<?php page_end();
