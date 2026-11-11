<?php
// Demonstration Mode: a free-play copy of the app with sample data. Runs 100% in the browser — nothing is sent to the server.
require_once __DIR__ . '/includes/help_layout.php';
help_ensure_schema();
$modules = ['dashboard', 'products', 'inventory', 'categories', 'suppliers', 'orders', 'expiry', 'reports', 'users'];
$start = in_array($_GET['module'] ?? '', $modules, true) ? $_GET['module'] : 'dashboard';

help_start('Demo mode', 'help_demo'); help_subnav('demo'); ?>
<div class="panel mb-3"><div class="panel-head"><div><h2>Demo mode</h2><div class="text-secondary small mt-1">Click, type, add and delete — it is all practice data. Reload the page or press Reset to start fresh.</div></div>
  <div class="d-flex gap-2"><button class="btn btn-light" id="demoReset"><i class="bi bi-arrow-counterclockwise"></i> Reset sample data</button><a class="btn btn-primary" href="help_tutorials.php"><i class="bi bi-mortarboard"></i> Guided tutorials</a></div></div></div>
<div class="hd-banner"><i class="bi bi-shield-check"></i><span><b>Safe sandbox.</b> No request is sent to the server while you play here, so your real products, stock and database cannot be changed.</span></div>
<div id="hdRoot"></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var app = HelpDemo.mount(document.getElementById('hdRoot'), { module: <?= json_encode($start) ?>, noAutofocus: true });
  document.getElementById('demoReset').addEventListener('click', function () { app.reset(); app.toast('Sample data restored.'); });
});
</script>
<?php help_end(['assets/js/help-demo.js']);
