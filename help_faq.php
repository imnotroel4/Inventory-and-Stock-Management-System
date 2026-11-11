<?php
// Frequently asked questions: category chips + live filter + accordion.
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$cats = help_categories();
$catId = (int)($_GET['cat'] ?? 0);
$where = ['f.is_published=1']; $args = [];
if ($catId) { $where[] = 'f.category_id=?'; $args[] = $catId; }
if ($q !== '') { $where[] = '(f.question LIKE ? OR f.answer LIKE ?)'; $l = help_like($q); array_push($args, $l, $l); }
$st = $pdo->prepare('SELECT f.*, c.name cat_name FROM help_faqs f LEFT JOIN help_categories c ON c.id=f.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY f.sort_order, f.id');
$st->execute($args); $faqs = $st->fetchAll();

help_start('Frequently asked questions'); help_subnav('faq'); ?>
<div class="panel mb-3"><div class="panel-body pt-3">
  <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
    <div class="hlp-search flex-grow-1" style="max-width:none"><i class="bi bi-search"></i><input type="search" name="q" id="faqFilter" value="<?= e($q) ?>" placeholder="Filter questions…" maxlength="100" aria-label="Filter questions"></div>
    <?php if ($catId): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
    <button class="btn btn-primary btn-pill px-4">Search</button>
  </form>
  <div class="d-flex gap-2 flex-wrap mt-3">
    <a class="btn btn-sm btn-pill <?= !$catId ? 'btn-primary' : 'btn-light' ?>" href="help_faq.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">All</a>
    <?php foreach ($cats as $c): ?><a class="btn btn-sm btn-pill <?= $catId === (int)$c['id'] ? 'btn-primary' : 'btn-light' ?>" href="help_faq.php?cat=<?= (int)$c['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
  </div>
</div></div>

<div class="accordion hlp-faq" id="faqList">
<?php foreach ($faqs as $f): $open = $q !== '' && count($faqs) === 1; ?>
  <div class="accordion-item" id="faq<?= (int)$f['id'] ?>" data-faq>
    <h2 class="accordion-header"><button class="accordion-button <?= $open ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faqa<?= (int)$f['id'] ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>"><?= e($f['question']) ?></button></h2>
    <div id="faqa<?= (int)$f['id'] ?>" class="accordion-collapse collapse <?= $open ? 'show' : '' ?>" data-bs-parent="#faqList"><div class="accordion-body hlp-body"><?= help_render($f['answer']) ?><?php if ($f['cat_name']): ?><span class="hlp-chip mt-2"><i class="bi bi-tag"></i> <?= e($f['cat_name']) ?></span><?php endif; ?></div></div>
  </div>
<?php endforeach; ?>
</div>
<div class="panel <?= $faqs ? 'd-none' : '' ?>" id="faqEmpty"><div class="empty">No questions match. <a href="help_contact.php?subject=<?= urlencode($q) ?>">Ask support</a></div></div>
<p class="text-center text-secondary small mt-4">Still stuck? <a href="help_contact.php">Contact support</a> or try <a href="help_demo.php">Demo mode</a>.</p>
<script>
// open the question named in the address (#faq3), if any
document.addEventListener('DOMContentLoaded', function () {
  var h = location.hash; if (!/^#faq\d+$/.test(h)) return;
  var item = document.querySelector(h); if (!item) return;
  var panel = item.querySelector('.accordion-collapse'); if (panel && window.bootstrap) new bootstrap.Collapse(panel, { toggle: true });
  item.scrollIntoView({ block: 'center' });
});
</script>
<?php help_end(['assets/js/help.js']);
