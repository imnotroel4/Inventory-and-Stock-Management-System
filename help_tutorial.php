<?php
// The guided tutorial player: the Demo mode sandbox + a step-by-step coach that highlights interface elements.
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();

$st = $pdo->prepare('SELECT * FROM help_tutorials WHERE slug=?'); $st->execute([(string)($_GET['slug'] ?? '')]); $t = $st->fetch();
$preview = false;
if ($t && !$t['is_published']) { if (!help_is_guest() && is_admin()) $preview = true; else $t = false; }
if (!$t) {
    http_response_code(404); help_start('Tutorial not found', 'help_tutorials'); help_subnav('tutorials');
    echo '<div class="panel"><div class="empty">That tutorial does not exist. <a href="help_tutorials.php">See all tutorials</a></div></div>'; help_end(); exit;
}
$st = $pdo->prepare('SELECT title,body,target_module module,target_selector selector,demo_action action,image FROM help_tutorial_steps WHERE tutorial_id=? ORDER BY step_no, id');
$st->execute([$t['id']]); $steps = $st->fetchAll();
foreach ($steps as &$s) { $s['image'] = help_image_ok($s['image']) ? 'uploads/' . $s['image'] : null; } unset($s);
$nx = $pdo->prepare('SELECT title,slug FROM help_tutorials WHERE is_published=1 AND (sort_order>? OR (sort_order=? AND id>?)) ORDER BY sort_order,id LIMIT 1');
$nx->execute([$t['sort_order'], $t['sort_order'], $t['id']]); $next = $nx->fetch();
$modules = ['dashboard', 'products', 'inventory', 'categories', 'suppliers', 'orders', 'expiry', 'reports', 'users'];
$firstModule = in_array($steps[0]['module'] ?? '', $modules, true) ? $steps[0]['module'] : 'dashboard';

help_start($t['title'], 'help_tutorials'); help_subnav('tutorials'); ?>
<div class="hlp-crumbs"><a href="help_tutorials.php">Tutorials</a> / <?= e($t['title']) ?></div>
<?php if ($preview): ?><div class="alert alert-warning">Preview: this tutorial is not published yet.</div><?php endif; ?>
<div class="panel mb-3"><div class="panel-head">
  <div><h2><i class="bi bi-<?= e(help_icon($t['icon'], 'mortarboard')) ?> me-1"></i> <?= e($t['title']) ?></h2><div class="text-secondary small mt-1"><?= e($t['description']) ?> · <?= count($steps) ?> steps</div></div>
  <div class="d-flex gap-2"><button class="btn btn-primary" id="tutStart" <?= $steps ? '' : 'disabled' ?>><i class="bi bi-play-fill"></i> <span>Start tutorial</span></button><button class="btn btn-light" id="tutReset"><i class="bi bi-arrow-counterclockwise"></i> Reset sample data</button></div></div></div>
<div class="hd-banner"><i class="bi bi-shield-check"></i><span><b>Demonstration mode.</b> Everything here uses made-up sample data in your browser. Your real inventory and database are never touched.</span></div>
<div id="hdRoot"></div>

<div class="panel mt-3 d-none" id="tutDone"><div class="panel-body pt-4 text-center">
  <div class="fs-1 hd-ok"><i class="bi bi-patch-check-fill"></i></div><h3 class="h5 fw-semibold">Tutorial complete!</h3>
  <p class="text-secondary">You finished “<?= e($t['title']) ?>”. Practise it again, or keep exploring.</p>
  <div class="d-flex gap-2 justify-content-center flex-wrap"><button class="btn btn-light" id="tutAgain"><i class="bi bi-arrow-repeat"></i> Do it again</button><a class="btn btn-light" href="help_demo.php">Free practice</a>
  <?php if ($next): ?><a class="btn btn-primary" href="help_tutorial.php?slug=<?= e($next['slug']) ?>">Next: <?= e($next['title']) ?> <i class="bi bi-arrow-right"></i></a><?php else: ?><a class="btn btn-primary" href="help_tutorials.php">All tutorials</a><?php endif; ?></div>
</div></div>

<script type="application/json" id="hlpTut"><?= json_encode(['slug' => $t['slug'], 'steps' => $steps, 'module' => $firstModule], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var d = JSON.parse(document.getElementById('hlpTut').textContent);
  var app = HelpDemo.mount(document.getElementById('hdRoot'), { module: d.module, noAutofocus: true });
  var startBtn = document.getElementById('tutStart'), doneBox = document.getElementById('tutDone'), running = false;
  function start() {
    if (running || !d.steps.length) return; running = true; doneBox.classList.add('d-none');
    document.getElementById('hdRoot').scrollIntoView({ block: 'start', behavior: 'smooth' });
    HelpDemo.tour(app, d.steps, {
      onFinish: function () {
        running = false; startBtn.querySelector('span').textContent = 'Restart tutorial'; doneBox.classList.remove('d-none');
        try { var a = JSON.parse(localStorage.getItem('hlp_done') || '[]'); if (a.indexOf(d.slug) < 0) a.push(d.slug); localStorage.setItem('hlp_done', JSON.stringify(a)); } catch (e) { }
      },
      onExit: function () { running = false; startBtn.querySelector('span').textContent = 'Restart tutorial'; }
    });
  }
  startBtn.addEventListener('click', start);
  document.getElementById('tutAgain').addEventListener('click', start);
  document.getElementById('tutReset').addEventListener('click', function () { if (!running) { app.reset(); app.go(d.module); } });
});
</script>
<?php help_end(['assets/js/help-demo.js']);
