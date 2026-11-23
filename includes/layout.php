<?php
require_once __DIR__ . '/../config.php';

// $area: 'user'    = pages of a regular user (needs an inventory; admins are sent to the admin area)
//        'admin'   = administrator-only pages
//        'account' = pages both roles use (change password)
function page_start($title, $active, $area = 'user') {
    global $pdo;
    require_login();
    $u = current_user(); $isAdmin = is_admin();
    $inv = null; $alertCount = 0; $alerts = []; $myInvs = [];
    $ec = ['expired' => 0, 'near' => 0]; $expTotal = 0; $expAlerts = [];   // expiry alerts (medicines)
    if ($area === 'admin') require_admin();
    elseif ($area === 'user') $inv = require_inventory();
    elseif (!$isAdmin) $inv = current_inventory();

    if ($inv) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE inventory_id=? AND quantity <= min_stock'); $st->execute([$inv['id']]); $alertCount = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT id,name,quantity,min_stock FROM products WHERE inventory_id=? AND quantity <= min_stock ORDER BY quantity ASC LIMIT 6'); $st->execute([$inv['id']]); $alerts = $st->fetchAll();
        $myInvs = user_inventories($u['id']);
        $ec = expiry_counts($inv['id']); $expTotal = $ec['expired'] + $ec['near'];
        $expAlerts = $expTotal ? expiry_alert_rows($inv['id'], 5) : [];
    }
    $st = $pdo->prepare('SELECT avatar,email FROM users WHERE id=?'); $st->execute([$u['id']]);
    $me = $st->fetch() ?: ['avatar' => null, 'email' => null];
    $first = explode(' ', trim($u['full_name']))[0];

    if ($isAdmin) {
        $nav = [
            'Overview' => [['admin_dashboard.php', 'Dashboard', 'grid-1x2']],
            'Platform' => [['users.php', 'Users', 'people'], ['admin_inventories.php', 'Inventories', 'collection'], ['admin_products.php', 'Products', 'box-seam'], ['admin_transactions.php', 'Transactions', 'arrow-left-right']],
            'Insights' => [['admin_reports.php', 'Reports', 'graph-up'], ['admin_logs.php', 'Activity logs', 'clock-history']],
            'System' => [['admin_settings.php', 'Settings', 'sliders']],
            'Help Center' => [['admin_help_articles.php', 'Help articles', 'journal-text'], ['admin_help_faqs.php', 'FAQs', 'patch-question'], ['admin_help_tutorials.php', 'Tutorials', 'mortarboard'], ['admin_help_categories.php', 'Help categories', 'folder'], ['admin_help_tickets.php', 'Support tickets', 'inbox'], ['help_center.php', 'View Help Center', 'life-preserver']],
        ];
    } elseif ($inv) {
        $nav = [
            'Overview' => [['dashboard.php', 'Dashboard', 'grid-1x2']],
            'Catalog' => [['products.php', 'Products', 'box-seam'], ['categories.php', 'Categories', 'tags'], ['suppliers.php', 'Suppliers', 'truck']],
            'Inventory' => [['purchase_orders.php', 'Purchase orders', 'receipt'], ['stock_in.php', 'Stock in', 'box-arrow-in-down'], ['stock_out.php', 'Stock out', 'box-arrow-up'], ['monitoring.php', 'Stock monitoring', 'activity'], ['expiry.php', 'Expiry tracking', 'calendar-x']],
            'Insights' => [['reports.php', 'Reports', 'graph-up']],
            'Workspace' => [['inventories.php', 'My inventories', 'collection'], ['profile.php', 'Profile & settings', 'person-gear']],
            'Help' => [['help_center.php', 'Help Center', 'life-preserver'], ['help_showcase.php', 'Feature showcase', 'window-stack'], ['help_tutorials.php', 'Tutorials', 'mortarboard'], ['help_demo.php', 'Demo mode', 'play-circle']],
        ];
    } else $nav = [];
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $home = $isAdmin ? ADMIN_HOME : 'dashboard.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(site_name()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<aside class="sidebar" id="sidebar">
  <a class="brand" href="<?= $home ?>"><span class="logo"><i class="bi bi-boxes"></i></span> <?= e(site_name()) ?></a>
  <?php if ($inv): ?>
  <div class="dropdown inv-switch mb-3">
    <button class="inv-btn" data-bs-toggle="dropdown" aria-label="Switch inventory">
      <i class="bi bi-collection"></i><span><small>Inventory</small><strong><?= e($inv['name']) ?></strong></span><i class="bi bi-chevron-expand ms-auto"></i>
    </button>
    <div class="dropdown-menu w-100">
      <?php foreach ($myInvs as $mi): ?>
        <form method="post" action="switch_inventory.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $mi['id'] ?>">
          <button class="dropdown-item d-flex justify-content-between align-items-center"><?= e($mi['name']) ?><?= $mi['id'] == $inv['id'] ? '<i class="bi bi-check2 text-success"></i>' : '' ?></button></form>
      <?php endforeach; ?>
      <div class="dropdown-divider"></div>
      <a class="dropdown-item" href="inventories.php"><i class="bi bi-gear me-2"></i>Manage inventories</a>
      <?php if (count($myInvs) < max_inventories()): ?><a class="dropdown-item" href="create_inventory.php"><i class="bi bi-plus-lg me-2"></i>New inventory</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <nav>
  <?php foreach ($nav as $group => $items): ?>
    <div class="nav-group"><?= e($group) ?></div>
    <?php foreach ($items as [$file, $label, $icon]):
        $on = $active === basename(explode('?', $file)[0], '.php'); ?>
      <a href="<?= $file ?>" class="nav-link <?= $on ? 'active' : '' ?>"><i class="bi bi-<?= $icon ?>"></i><?= e($label) ?>
        <?php if ($file === 'monitoring.php' && $alertCount): ?><span class="count"><?= $alertCount ?></span><?php endif; ?>
        <?php if ($file === 'expiry.php' && $expTotal): ?><span class="count"><?= $expTotal ?></span><?php endif; ?></a>
    <?php endforeach; endforeach; ?>
  </nav>
  <div class="side-user mt-4">
    <?= avatar_html($u['full_name'], $me['avatar']) ?>
    <div><strong><?= e($u['full_name']) ?></strong><small><?= $isAdmin ? 'Administrator' : 'User' ?></small></div>
    <form method="post" action="logout.php" class="m-0"><?= csrf_field() ?><button class="logout-btn" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right"></i></button></form>
  </div>
</aside>
<main class="content">
  <header class="topbar no-print">
    <button class="btn btn-light d-lg-none" id="menuBtn" aria-label="Open menu"><i class="bi bi-list"></i></button>
    <h1><?= ($active === 'dashboard' || $active === 'admin_dashboard') ? 'Hey ' . e($first) . ' <span class="wave">👋</span>, welcome back!' : e($title) ?></h1>
    <div class="ms-auto d-flex align-items-center gap-2">
      <?php if ($inv): ?>
      <form class="search-box d-none d-md-flex" action="products.php" method="get" role="search">
        <i class="bi bi-search"></i><input name="q" placeholder="Search Products / SKU..." aria-label="Search products or SKU">
      </form>
      <?php endif; ?>
      <?php if ($isAdmin): ?><a class="btn btn-primary d-none d-sm-inline-flex align-items-center gap-2" href="users.php?new=1"><i class="bi bi-person-plus"></i> Add user</a><?php endif; ?>
      <?php if ($inv): ?>
      <div class="dropdown">
        <button class="round-btn" data-bs-toggle="dropdown" aria-label="Stock alerts">
          <i class="bi bi-bell"></i><?php if ($alertCount + $expTotal): ?><span class="bell-count"><?= $alertCount + $expTotal ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end alert-menu p-0">
          <div class="p-3 border-bottom fw-semibold"><?= ($alertCount + $expTotal) ? 'Needs your attention' : 'All stock levels are healthy' ?></div>
          <?php if ($alertCount): ?><div class="px-3 pt-2 pb-1 small text-secondary"><?= $alertCount ?> product<?= $alertCount > 1 ? 's need' : ' needs' ?> restocking</div><?php endif; ?>
          <?php foreach ($alerts as $a): ?>
            <a class="dropdown-item d-flex justify-content-between py-2" href="stock_in.php?product=<?= $a['id'] ?>">
              <span><?= e($a['name']) ?></span><?= status_badge($a['quantity'], $a['min_stock']) ?></a>
          <?php endforeach; ?>
          <?php if ($alertCount): ?><a class="dropdown-item text-center py-2 border-top" href="monitoring.php?status=attention">View all</a><?php endif; ?>
          <?php if ($expTotal): ?>
            <div class="px-3 pt-2 pb-1 small text-secondary border-top"><?= (int)$ec['expired'] ?> expired · <?= (int)$ec['near'] ?> near expiry</div>
            <?php foreach ($expAlerts as $x): ?>
              <a class="dropdown-item d-flex justify-content-between align-items-center gap-2 py-2" href="expiry.php"><span><?= e($x['pname']) ?><small class="d-block text-secondary">Batch <?= e($x['batch_no']) ?></small></span><?= expiry_badge($x['expiry_date'], $x['alert_days']) ?></a>
            <?php endforeach; ?>
            <a class="dropdown-item text-center py-2 border-top" href="expiry.php">View all</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="dropdown">
        <button class="avatar-btn" data-bs-toggle="dropdown" aria-label="Account menu"><?= avatar_html($u['full_name'], $me['avatar']) ?></button>
        <div class="dropdown-menu dropdown-menu-end" style="min-width:230px">
          <div class="px-3 py-2"><div class="fw-semibold"><?= e($u['full_name']) ?></div><small class="text-secondary"><?= e($me['email'] ?: '@' . $u['username']) ?></small></div>
          <?php if ($isAdmin): ?><div class="dropdown-divider"></div><a class="dropdown-item" href="users.php?new=1"><i class="bi bi-person-plus me-2"></i>Add user</a><a class="dropdown-item" href="users.php"><i class="bi bi-people me-2"></i>Manage users</a>
          <?php else: ?><div class="dropdown-divider"></div><a class="dropdown-item" href="profile.php"><i class="bi bi-person-gear me-2"></i>Profile &amp; settings</a><a class="dropdown-item" href="inventories.php"><i class="bi bi-collection me-2"></i>My inventories</a><?php endif; ?>
          <div class="dropdown-divider"></div><a class="dropdown-item" href="change_password.php"><i class="bi bi-key me-2"></i>Change password</a>
          <form method="post" action="logout.php"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form>
        </div>
      </div>
    </div>
  </header>
  <div class="page">
  <?php if ($isAdmin && maintenance_on()): ?><div class="alert alert-warning no-print"><i class="bi bi-cone-striped me-1"></i> <strong>Maintenance mode is ON.</strong> Regular users cannot sign in. <a href="admin_settings.php">Change</a></div><?php endif; ?>
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[1]) ?> alert-dismissible fade show no-print"><?= e($flash[0]) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php }

function page_end() { ?>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php }
