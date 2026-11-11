<?php
// Help Center home: search, getting started, feature docs, categories, featured articles.
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

function help_hl($text, $q) {   // escape first, then wrap matches in <mark> (safe against injection)
    $t = e($text);
    if ($q === '') return $t;
    return preg_replace('/(' . preg_quote(e($q), '/') . ')/iu', '<mark>$1</mark>', $t) ?? $t;
}
function help_excerpt($body, $q, $len = 160) {
    $plain = trim(preg_replace('/\s+/', ' ', preg_replace('/[#>*`\[\]!()-]+/', ' ', strip_tags((string)$body))));
    $pos = $q !== '' ? mb_stripos($plain, $q) : false;
    $start = $pos !== false ? max(0, $pos - 50) : 0;
    return ($start ? '…' : '') . mb_substr($plain, $start, $len) . (mb_strlen($plain) > $start + $len ? '…' : '');
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$catSlug = (string)($_GET['cat'] ?? '');
$cats = help_categories();

help_start('Help Center');
help_subnav('center');

/* ---------------------------------------------------------------- search results */
if ($q !== '') {
    $r = help_search($q); $n = count($r['articles']) + count($r['faqs']) + count($r['tutorials']); ?>
<div class="panel mb-3"><div class="panel-body pt-3"><?= help_search_box($q, true) ?></div></div>
<h2 class="hlp-section-title mt-0"><?= $n ?> result<?= $n === 1 ? '' : 's' ?> for “<?= e($q) ?>”</h2>
<?php if (!$n): ?>
  <div class="panel"><div class="empty"><i class="bi bi-search fs-2 d-block mb-2"></i>Nothing matched. Try fewer or different words, browse the categories below, or <a href="help_contact.php">ask our support team</a>.</div></div>
  <div class="hlp-tiles mt-3"><?php foreach ($cats as $c): ?><a class="hlp-tile" href="help_center.php?cat=<?= e($c['slug']) ?>"><span class="ico"><i class="bi bi-<?= e(help_icon($c['icon'])) ?>"></i></span><strong><?= e($c['name']) ?></strong><span><?= e($c['description']) ?></span></a><?php endforeach; ?></div>
<?php endif;
    if ($r['articles']): ?>
  <div class="panel hlp-list mb-3"><div class="panel-head"><h2><i class="bi bi-journal-text me-1"></i> Articles</h2></div>
    <?php foreach ($r['articles'] as $a): ?><a class="hlp-row" href="help_article.php?slug=<?= e($a['slug']) ?>"><i class="bi bi-file-earmark-text ico"></i><span><strong><?= help_hl($a['title'], $q) ?></strong><small><?= help_hl($a['summary'] ?: '', $q) ?><?= $a['cat'] ? ' · ' . e($a['cat']) : '' ?></small></span></a><?php endforeach; ?></div>
<?php endif;
    if ($r['tutorials']): ?>
  <div class="panel hlp-list mb-3"><div class="panel-head"><h2><i class="bi bi-mortarboard me-1"></i> Tutorials</h2></div>
    <?php foreach ($r['tutorials'] as $t): ?><a class="hlp-row" href="help_tutorial.php?slug=<?= e($t['slug']) ?>"><i class="bi bi-play-circle ico"></i><span><strong><?= help_hl($t['title'], $q) ?></strong><small><?= help_hl($t['description'] ?: '', $q) ?></small></span></a><?php endforeach; ?></div>
<?php endif;
    if ($r['faqs']): ?>
  <div class="panel hlp-list mb-3"><div class="panel-head"><h2><i class="bi bi-patch-question me-1"></i> Frequently asked questions</h2></div>
    <?php foreach ($r['faqs'] as $f): ?><a class="hlp-row" href="help_faq.php?q=<?= urlencode($q) ?>#faq<?= (int)$f['id'] ?>"><i class="bi bi-question-circle ico"></i><span><strong><?= help_hl($f['question'], $q) ?></strong><small><?= help_hl(help_excerpt($f['answer'], $q), $q) ?></small></span></a><?php endforeach; ?></div>
<?php endif; ?>
<div class="text-center text-secondary small mt-3">Did not find it? <a href="help_contact.php?subject=<?= urlencode($q) ?>">Contact support</a></div>
<?php
/* ---------------------------------------------------------------- one category */
} elseif ($catSlug !== '') {
    $st = $pdo->prepare('SELECT * FROM help_categories WHERE slug=? AND is_active=1'); $st->execute([$catSlug]); $cat = $st->fetch();
    if (!$cat) { http_response_code(404); echo '<div class="panel"><div class="empty">That category does not exist. <a href="help_center.php">Back to the Help Center</a></div></div>'; help_end(['assets/js/help.js']); exit; }
    $st = $pdo->prepare('SELECT id,title,slug,summary FROM help_articles WHERE category_id=? AND is_published=1 ORDER BY sort_order, title'); $st->execute([$cat['id']]); $arts = $st->fetchAll();
    $st = $pdo->prepare('SELECT id,question FROM help_faqs WHERE category_id=? AND is_published=1 ORDER BY sort_order'); $st->execute([$cat['id']]); $faqs = $st->fetchAll(); ?>
<div class="hlp-crumbs"><a href="help_center.php">Help Center</a> / <?= e($cat['name']) ?></div>
<div class="panel hlp-list mb-3"><div class="panel-head"><h2><i class="bi bi-<?= e(help_icon($cat['icon'])) ?> me-1"></i> <?= e($cat['name']) ?></h2><span class="text-secondary small"><?= e($cat['description']) ?></span></div>
  <?php foreach ($arts as $a): ?><a class="hlp-row" href="help_article.php?slug=<?= e($a['slug']) ?>"><i class="bi bi-file-earmark-text ico"></i><span><strong><?= e($a['title']) ?></strong><small><?= e($a['summary']) ?></small></span></a><?php endforeach;
  if (!$arts): ?><div class="empty">No articles in this category yet.</div><?php endif; ?></div>
<?php if ($faqs): ?><div class="panel hlp-list"><div class="panel-head"><h2>Related questions</h2></div>
  <?php foreach ($faqs as $f): ?><a class="hlp-row" href="help_faq.php#faq<?= (int)$f['id'] ?>"><i class="bi bi-question-circle ico"></i><span><strong><?= e($f['question']) ?></strong></span></a><?php endforeach; ?></div><?php endif;
/* ---------------------------------------------------------------- home */
} else {
    $feat = $pdo->query('SELECT a.title,a.slug,a.summary FROM help_articles a LEFT JOIN help_categories c ON c.id=a.category_id WHERE a.is_published=1 AND a.is_featured=1 AND (c.id IS NULL OR c.is_active=1) ORDER BY a.sort_order, a.title LIMIT 6')->fetchAll();
    $pop = $pdo->query('SELECT a.title,a.slug FROM help_articles a LEFT JOIN help_categories c ON c.id=a.category_id WHERE a.is_published=1 AND a.views>0 AND (c.id IS NULL OR c.is_active=1) ORDER BY a.views DESC LIMIT 5')->fetchAll(); ?>
<section class="hlp-hero">
  <h2>How can we help you?</h2>
  <p>Search articles, FAQs and tutorials — or explore every feature safely in Demo mode.</p>
  <?= help_search_box('', true) ?>
  <div class="mt-3 small" style="opacity:.9">Popular: <?php foreach (['expiry', 'purchase order', 'low stock', 'supplier', 'report'] as $w): ?><a class="text-white me-2" href="help_center.php?q=<?= urlencode($w) ?>"><?= e($w) ?></a><?php endforeach; ?></div>
</section>

<div class="hlp-tiles">
  <?php foreach ($cats as $c): ?>
    <a class="hlp-tile" href="help_center.php?cat=<?= e($c['slug']) ?>"><span class="ico"><i class="bi bi-<?= e(help_icon($c['icon'])) ?>"></i></span><strong><?= e($c['name']) ?></strong><span><?= e($c['description']) ?></span></a>
  <?php endforeach; ?>
  <a class="hlp-tile" href="help_tutorials.php"><span class="ico"><i class="bi bi-mortarboard"></i></span><strong>Step-by-step tutorials</strong><span>Guided walkthroughs with highlighted buttons.</span></a>
  <a class="hlp-tile" href="help_showcase.php"><span class="ico"><i class="bi bi-window-stack"></i></span><strong>Interactive feature showcase</strong><span>Try every module with sample data.</span></a>
  <a class="hlp-tile" href="help_faq.php"><span class="ico"><i class="bi bi-patch-question"></i></span><strong>FAQ</strong><span>Quick answers to common questions.</span></a>
  <a class="hlp-tile" href="help_contact.php"><span class="ico"><i class="bi bi-chat-dots"></i></span><strong>Contact support</strong><span>Send a question, concern or technical issue.</span></a>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-7"><h2 class="hlp-section-title">Start here</h2>
    <div class="panel hlp-list"><?php foreach ($feat as $a): ?><a class="hlp-row" href="help_article.php?slug=<?= e($a['slug']) ?>"><i class="bi bi-stars ico"></i><span><strong><?= e($a['title']) ?></strong><small><?= e($a['summary']) ?></small></span></a><?php endforeach;
      if (!$feat): ?><div class="empty">No featured articles yet.</div><?php endif; ?></div></div>
  <div class="col-lg-5"><h2 class="hlp-section-title">Most read</h2>
    <div class="panel hlp-list"><?php foreach ($pop as $a): ?><a class="hlp-row" href="help_article.php?slug=<?= e($a['slug']) ?>"><i class="bi bi-graph-up-arrow ico"></i><span><strong><?= e($a['title']) ?></strong></span></a><?php endforeach;
      if (!$pop): ?><div class="empty">Popular articles will appear here.</div><?php endif; ?></div></div>
</div>
<?php }
help_end(['assets/js/help.js']);
