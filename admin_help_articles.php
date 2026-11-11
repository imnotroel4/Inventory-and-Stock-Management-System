<?php
// Admin: create, edit, delete, publish and unpublish help articles (with image uploads).
require_once __DIR__ . '/includes/help_layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();
help_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $title = trim(preg_replace('/\s+/', ' ', $_POST['title'] ?? '')); $summary = trim($_POST['summary'] ?? ''); $body = str_replace("\r\n", "\n", trim($_POST['body'] ?? ''));
            $cat = (int)($_POST['category_id'] ?? 0) ?: null; $pub = isset($_POST['is_published']) ? 1 : 0; $feat = isset($_POST['is_featured']) ? 1 : 0; $ord = max(0, min(9999, (int)($_POST['sort_order'] ?? 0)));
            $slugIn = trim($_POST['slug'] ?? '');
            if (!help_len_ok($title, 3, 160)) throw new Exception('Title must be 3 to 160 characters.');
            if (mb_strlen($summary) > 300) throw new Exception('The summary can be up to 300 characters.');
            if (!help_len_ok($body, 10, 60000)) throw new Exception('The article body must be 10 to 60,000 characters.');
            if ($cat) { $c = $pdo->prepare('SELECT 1 FROM help_categories WHERE id=?'); $c->execute([$cat]); if (!$c->fetchColumn()) throw new Exception('Choose a valid category.'); }
            if ($id) { $c = $pdo->prepare('SELECT 1 FROM help_articles WHERE id=?'); $c->execute([$id]); if (!$c->fetchColumn()) throw new Exception('Article not found.'); }
            $slug = help_unique_slug('help_articles', $slugIn !== '' ? $slugIn : $title, $id);
            if ($id) {
                $pdo->prepare('UPDATE help_articles SET category_id=?,title=?,slug=?,summary=?,body=?,is_published=?,is_featured=?,sort_order=? WHERE id=?')->execute([$cat, $title, $slug, $summary, $body, $pub, $feat, $ord, $id]);
                log_activity('help_article_update', $title); flash('Article saved.');
            } else {
                $pdo->prepare('INSERT INTO help_articles (category_id,title,slug,summary,body,is_published,is_featured,sort_order,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$cat, $title, $slug, $summary, $body, $pub, $feat, $ord, current_user()['id']]);
                $id = (int)$pdo->lastInsertId(); log_activity('help_article_add', $title); flash('Article created.');
            }
            redirect('admin_help_articles.php?edit=' . $id);
        } elseif ($a === 'toggle') {
            $pdo->prepare('UPDATE help_articles SET is_published = 1 - is_published WHERE id=?')->execute([$id]);
            $s = $pdo->prepare('SELECT is_published,title FROM help_articles WHERE id=?'); $s->execute([$id]); $r = $s->fetch();
            if ($r) { log_activity('help_article_publish', ($r['is_published'] ? 'Published: ' : 'Unpublished: ') . $r['title']); flash($r['is_published'] ? 'Article published.' : 'Article unpublished (hidden from visitors).'); }
        } elseif ($a === 'delete') {
            $s = $pdo->prepare('SELECT title FROM help_articles WHERE id=?'); $s->execute([$id]); $t = $s->fetchColumn();
            $pdo->prepare('DELETE FROM help_articles WHERE id=?')->execute([$id]); if ($t) log_activity('help_article_delete', $t); flash('Article deleted.');
        }
    } catch (PDOException $e) { flash('Database error. Please try again.', 'danger'); redirect('admin_help_articles.php' . ($id ? '?edit=' . $id : '?new=1')); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); if (($_POST['action'] ?? '') === 'save') $_SESSION['help_form'] = $_POST; redirect('admin_help_articles.php' . (($_POST['action'] ?? '') === 'save' ? ($id ? '?edit=' . $id : '?new=1') : '')); }
    redirect('admin_help_articles.php');
}

$cats = help_categories(false);
$editing = isset($_GET['new']) || isset($_GET['edit']);
page_start($editing ? 'Help article' : 'Help articles', 'admin_help_articles', 'admin');
echo '<link href="assets/css/help.css" rel="stylesheet">';

if ($editing) {
    $art = ['id' => 0, 'title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'category_id' => '', 'is_published' => 0, 'is_featured' => 0, 'sort_order' => 0];
    if (!isset($_GET['new'])) { $s = $pdo->prepare('SELECT * FROM help_articles WHERE id=?'); $s->execute([(int)$_GET['edit']]); $art = $s->fetch() ?: null; if (!$art) { flash('Article not found.', 'danger'); redirect('admin_help_articles.php'); } }
    if (!empty($_SESSION['help_form'])) { $art = array_merge($art, array_intersect_key($_SESSION['help_form'], $art), ['is_published' => isset($_SESSION['help_form']['is_published']) ? 1 : 0, 'is_featured' => isset($_SESSION['help_form']['is_featured']) ? 1 : 0]); unset($_SESSION['help_form']); } ?>
<form method="post" class="row g-3" id="artForm">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$art['id'] ?>">
  <div class="col-lg-8"><div class="panel"><div class="panel-head"><h2><?= $art['id'] ? 'Edit article' : 'New article' ?></h2>
      <div class="d-flex gap-2"><?php if ($art['id']): ?><a class="btn btn-light btn-sm" href="help_article.php?slug=<?= e($art['slug']) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a><?php endif; ?><a class="btn btn-light btn-sm" href="admin_help_articles.php">Back to list</a></div></div>
    <div class="panel-body row g-3">
      <div class="col-12"><label class="form-label" for="aTitle">Title</label><input class="form-control" id="aTitle" name="title" maxlength="160" required value="<?= e($art['title']) ?>"></div>
      <div class="col-12"><label class="form-label" for="aSum">Short summary <span class="text-secondary fw-normal">(shown in lists and search)</span></label><input class="form-control" id="aSum" name="summary" maxlength="300" value="<?= e($art['summary']) ?>"></div>
      <div class="col-12"><label class="form-label" for="aBody">Article text</label>
        <div class="hlp-editor-bar">
          <?php foreach ([['## ', 'Heading', 'type-h2'], ['**|**', 'Bold', 'type-bold'], ['- ', 'Bullet', 'list-ul'], ['1. ', 'Numbered step', 'list-ol'], ['> ', 'Tip / note', 'lightbulb'], ['`|`', 'Code', 'code']] as [$ins, $tt, $ic]): ?>
            <button type="button" class="btn btn-light btn-sm" data-ins="<?= e($ins) ?>" title="<?= e($tt) ?>" aria-label="<?= e($tt) ?>"><i class="bi bi-<?= $ic ?>"></i></button><?php endforeach; ?>
          <label class="btn btn-light btn-sm mb-0" title="Upload an instructional image or screenshot"><i class="bi bi-image"></i> Upload image<input type="file" id="imgUp" accept="image/jpeg,image/png,image/webp,image/gif" hidden></label>
          <span class="small text-secondary align-self-center" id="upStatus"></span></div>
        <textarea class="form-control hlp-md" id="aBody" name="body" rows="18" required><?= e($art['body']) ?></textarea>
        <div class="form-text">Formatting: <code>## Heading</code> · <code>- bullet</code> · <code>1. step</code> · <code>&gt; tip</code> · <code>**bold**</code> · <code>[text](help_faq.php)</code> · uploaded images are inserted as <code>![caption](help/…)</code>. Anything else is shown as plain text (HTML is never executed).</div></div>
    </div></div></div>
  <div class="col-lg-4"><div class="panel"><div class="panel-head"><h2>Publishing</h2></div><div class="panel-body">
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="aPub" name="is_published" <?= $art['is_published'] ? 'checked' : '' ?>><label class="form-check-label" for="aPub">Published (visible to everyone)</label></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="aFeat" name="is_featured" <?= $art['is_featured'] ? 'checked' : '' ?>><label class="form-check-label" for="aFeat">Featured on the Help Center home</label></div>
      <label class="form-label" for="aCat">Category</label><select class="form-select mb-3" id="aCat" name="category_id"><option value="">No category</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$art['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['is_active'] ? '' : ' (hidden)' ?></option><?php endforeach; ?></select>
      <label class="form-label" for="aSlug">Address (slug)</label><input class="form-control mb-1" id="aSlug" name="slug" maxlength="100" pattern="[a-z0-9\-]*" value="<?= e($art['slug']) ?>" placeholder="auto from title"><div class="form-text mb-3">Leave empty to generate from the title.</div>
      <label class="form-label" for="aOrd">Sort order</label><input type="number" class="form-control mb-3" id="aOrd" name="sort_order" min="0" max="9999" value="<?= (int)$art['sort_order'] ?>">
      <button class="btn btn-primary w-100"><i class="bi bi-check2"></i> Save article</button></div></div></div>
</form>
<script>
(function () {
  var ta = document.getElementById('aBody'), csrf = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('[data-ins]').forEach(function (b) { b.addEventListener('click', function () {
    var ins = b.dataset.ins, s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e), txt;
    if (ins.indexOf('|') > -1) { var p = ins.split('|'); txt = p[0] + (sel || 'text') + p[1]; } else { txt = (s && ta.value[s - 1] !== '\n' ? '\n' : '') + ins + sel; }
    ta.setRangeText(txt, s, e, 'end'); ta.focus();
  }); });
  document.getElementById('imgUp').addEventListener('change', function () {
    var f = this.files[0], st = document.getElementById('upStatus'); if (!f) return;
    var alt = prompt('Caption for this image (optional):', '') || '';
    var fd = new FormData(); fd.append('image', f); fd.append('alt', alt); fd.append('csrf', csrf); st.textContent = 'Uploading…';
    fetch('admin_help_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { st.textContent = d.error; return; }
      var s = ta.selectionStart, ins = (s && ta.value[s - 1] !== '\n' ? '\n\n' : '') + d.markdown + '\n\n'; ta.setRangeText(ins, s, ta.selectionEnd, 'end'); st.textContent = 'Image inserted.';
    }).catch(function () { st.textContent = 'Upload failed.'; });
    this.value = '';
  });
})();
</script>
<?php } else {
    $q = trim($_GET['q'] ?? ''); $cat = (int)($_GET['cat'] ?? 0); $stt = $_GET['status'] ?? '';
    $where = ['1']; $args = [];
    if ($q !== '') { $where[] = '(a.title LIKE ? OR a.summary LIKE ? OR a.body LIKE ?)'; $l = help_like($q); array_push($args, $l, $l, $l); }
    if ($cat) { $where[] = 'a.category_id=?'; $args[] = $cat; }
    if ($stt === 'published') $where[] = 'a.is_published=1'; elseif ($stt === 'draft') $where[] = 'a.is_published=0';
    $c = $pdo->prepare('SELECT COUNT(*) FROM help_articles a WHERE ' . implode(' AND ', $where)); $c->execute($args); $total = (int)$c->fetchColumn();
    [$page, $pages, $off] = paginate($total, 25);
    $st = $pdo->prepare('SELECT a.*, c.name cat_name FROM help_articles a LEFT JOIN help_categories c ON c.id=a.category_id WHERE ' . implode(' AND ', $where) . " ORDER BY a.updated_at DESC, a.id DESC LIMIT 25 OFFSET $off"); $st->execute($args); $rows = $st->fetchAll(); ?>
<div class="panel"><div class="panel-head"><h2><?= number_format($total) ?> article<?= $total == 1 ? '' : 's' ?></h2><a class="btn btn-primary btn-sm" href="admin_help_articles.php?new=1"><i class="bi bi-plus-lg"></i> New article</a></div>
  <form class="panel-body border-bottom row g-2 align-items-end mx-0" method="get">
    <div class="col-md-4"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Title, summary or text"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Category</label><select class="form-select form-select-sm" name="cat"><option value="">All</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-1">Status</label><select class="form-select form-select-sm" name="status"><option value="">All</option><option value="published" <?= $stt === 'published' ? 'selected' : '' ?>>Published</option><option value="draft" <?= $stt === 'draft' ? 'selected' : '' ?>>Unpublished</option></select></div>
    <div class="col-auto d-flex gap-2"><button class="btn btn-sm btn-primary">Filter</button><a class="btn btn-sm btn-light" href="admin_help_articles.php">Reset</a></div></form>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Title</th><th>Category</th><th>Status</th><th class="text-end">Views</th><th>Updated</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><a class="fw-medium text-decoration-none" href="admin_help_articles.php?edit=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a><?= $r['is_featured'] ? ' <i class="bi bi-star-fill text-warning" title="Featured"></i>' : '' ?><small class="d-block text-secondary"><?= e($r['summary']) ?></small></td>
    <td><?= e($r['cat_name'] ?? '—') ?></td><td><?= $r['is_published'] ? '<span class="badge badge-success">Published</span>' : '<span class="badge badge-muted">Unpublished</span>' ?></td><td class="text-end"><?= number_format($r['views']) ?></td><td class="small text-nowrap"><?= help_ago($r['updated_at']) ?></td>
    <td class="text-end text-nowrap">
      <a class="btn btn-sm btn-light" href="help_article.php?slug=<?= e($r['slug']) ?>" target="_blank" rel="noopener" aria-label="Preview"><i class="bi bi-eye"></i></a>
      <a class="btn btn-sm btn-light" href="admin_help_articles.php?edit=<?= (int)$r['id'] ?>" aria-label="Edit"><i class="bi bi-pencil"></i></a>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" title="<?= $r['is_published'] ? 'Unpublish' : 'Publish' ?>" aria-label="<?= $r['is_published'] ? 'Unpublish' : 'Publish' ?>"><i class="bi bi-<?= $r['is_published'] ? 'toggle-on text-success' : 'toggle-off' ?>"></i></button></form>
      <form method="post" class="d-inline" data-confirm="Delete this article permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No articles match.</td></tr><?php endif; ?></tbody></table></div>
  <?= pager_html($page, $pages) ?></div>
<?php }
page_end();
