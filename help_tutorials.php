<?php
// List of guided tutorials (content comes from the help_tutorials table, managed by administrators).
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

$rows = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM help_tutorial_steps s WHERE s.tutorial_id=t.id) steps FROM help_tutorials t WHERE t.is_published=1 ORDER BY t.sort_order, t.title')->fetchAll();

help_start('Tutorials', 'help_tutorials'); help_subnav('tutorials'); ?>
<div class="panel mb-3"><div class="panel-head"><div><h2>Guided tutorials</h2><div class="text-secondary small mt-1">Each tutorial highlights the buttons and fields you need and explains every step. They run in Demo mode, so you cannot change real records.</div></div>
  <a class="btn btn-light btn-sm" href="help_demo.php"><i class="bi bi-play-circle me-1"></i> Free practice</a></div></div>
<div class="row g-3">
<?php foreach ($rows as $t): ?>
  <div class="col-md-6 col-xl-4"><div class="panel hlp-tut p-4" data-tut="<?= e($t['slug']) ?>">
    <span class="ico"><i class="bi bi-<?= e(help_icon($t['icon'], 'mortarboard')) ?>"></i></span>
    <h3 class="h6 fw-semibold mb-0 mt-1"><?= e($t['title']) ?> <span class="badge badge-success" data-donebadge hidden>Done</span></h3>
    <p class="text-secondary small mb-1 flex-grow-1"><?= e($t['description']) ?></p>
    <div class="small text-secondary"><i class="bi bi-list-ol"></i> <?= (int)$t['steps'] ?> steps · <i class="bi bi-clock"></i> ~<?= (int)$t['est_minutes'] ?> min</div>
    <a class="btn btn-primary btn-sm mt-2" href="help_tutorial.php?slug=<?= e($t['slug']) ?>"><i class="bi bi-play-fill"></i> Start tutorial</a>
  </div></div>
<?php endforeach; if (!$rows): ?><div class="col-12"><div class="panel"><div class="empty">No tutorials are published yet.</div></div></div><?php endif; ?>
</div>
<?php help_end(['assets/js/help.js']);
