<?php
// One help article. Unpublished articles are visible only to administrators (as a preview).
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

$slug = (string)($_GET['slug'] ?? '');
$st = $pdo->prepare('SELECT a.*, c.name cat_name, c.slug cat_slug, c.is_active cat_active FROM help_articles a LEFT JOIN help_categories c ON c.id=a.category_id WHERE a.slug=?');
$st->execute([$slug]); $a = $st->fetch();
$preview = false;
if ($a && (!$a['is_published'] || ($a['category_id'] && !$a['cat_active']))) {
    if (!help_is_guest() && is_admin()) $preview = true; else $a = false;
}
if (!$a) {
    http_response_code(404);
    help_start('Article not found'); help_subnav('center');
    echo '<div class="panel"><div class="empty"><i class="bi bi-file-earmark-x fs-2 d-block mb-2"></i>That article does not exist or is not published. <a href="help_center.php">Back to the Help Center</a></div></div>';
    help_end(); exit;
}
if (!$preview) {   // count a view once per visitor session
    $_SESSION['help_seen'] = $_SESSION['help_seen'] ?? [];
    if (!in_array((int)$a['id'], $_SESSION['help_seen'], true)) { $_SESSION['help_seen'][] = (int)$a['id']; $pdo->prepare('UPDATE help_articles SET views=views+1 WHERE id=?')->execute([$a['id']]); }
}
$rel = $pdo->prepare('SELECT title,slug FROM help_articles WHERE is_published=1 AND category_id<=>? AND id<>? ORDER BY sort_order, title LIMIT 6');
$rel->execute([$a['category_id'], $a['id']]); $rel = $rel->fetchAll();

help_start($a['title']); help_subnav('center'); ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="hlp-crumbs"><a href="help_center.php">Help Center</a><?php if ($a['cat_name']): ?> / <a href="help_center.php?cat=<?= e($a['cat_slug']) ?>"><?= e($a['cat_name']) ?></a><?php endif; ?></div>
    <?php if ($preview): ?><div class="alert alert-warning"><i class="bi bi-eye me-1"></i> <strong>Preview.</strong> This article is not published, so only administrators can see it. <a href="admin_help_articles.php?edit=<?= (int)$a['id'] ?>">Edit</a></div><?php endif; ?>
    <article class="panel hlp-article">
      <div class="panel-head"><div><h2 style="font-size:1.5rem"><?= e($a['title']) ?></h2><?php if ($a['summary']): ?><div class="text-secondary mt-1"><?= e($a['summary']) ?></div><?php endif; ?></div></div>
      <div class="hlp-body hlp-body-main"><?= help_render($a['body']) ?></div>
      <div class="px-4 pb-4 text-secondary small">Last updated <?= date('M d, Y', strtotime($a['updated_at'])) ?></div>
    </article>
  </div>
  <div class="col-lg-4">
    <?php if ($rel): ?><div class="panel hlp-list mb-3"><div class="panel-head"><h2>Related articles</h2></div>
      <?php foreach ($rel as $r): ?><a class="hlp-row" href="help_article.php?slug=<?= e($r['slug']) ?>"><i class="bi bi-file-earmark-text ico"></i><span><strong><?= e($r['title']) ?></strong></span></a><?php endforeach; ?></div><?php endif; ?>
    <div class="panel"><div class="panel-body pt-3"><h3 class="h6 fw-semibold">Learn by doing</h3><p class="small text-secondary">Practice safely with sample data.</p>
      <div class="d-grid gap-2"><a class="btn btn-primary btn-sm" href="help_demo.php"><i class="bi bi-play-circle me-1"></i> Open Demo mode</a><a class="btn btn-light btn-sm" href="help_tutorials.php"><i class="bi bi-mortarboard me-1"></i> Guided tutorials</a><a class="btn btn-light btn-sm" href="help_contact.php?subject=<?= urlencode('Question about: ' . $a['title']) ?>"><i class="bi bi-chat-dots me-1"></i> Still need help?</a></div></div></div>
  </div>
</div>
<?php help_end();
