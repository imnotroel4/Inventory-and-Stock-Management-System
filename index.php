<?php
require_once __DIR__ . '/config.php';
$in = current_user() !== null;
$dash = $in ? home_url() : 'login.php';   // admins go to the admin area, users to their dashboard (or Create Inventory)
$start = allow_registration() ? 'register.php' : 'login.php';

$features = [
    ['collection', 'Personal Inventory Management', 'Create your own private inventory workspace, or several. Nobody else can see your data.'],
    ['upc-scan', 'Product Management', 'Add products with SKU, photo, cost and selling price, and edit them any time.'],
    ['tags', 'Category Management', 'Group products into categories so large catalogs stay easy to browse and report on.'],
    ['truck', 'Supplier Management', 'Store supplier details and know exactly where each product is purchased from.'],
    ['arrow-left-right', 'Stock-In and Stock-Out Tracking', 'Record every delivery and sale with a reference number. Stock can never go negative.'],
    ['bell', 'Low Stock Notifications', 'Alerts in the top bar tell you which items need restocking before they run out.'],
    ['graph-up-arrow', 'Inventory Reports', 'Review stock movement, inventory value and trends, then export or print them.'],
    ['shield-lock', 'Secure User Accounts', 'Hashed passwords, protected sessions and an administrator who can suspend misuse.'],
];
// Keys used by assets/js/feature-demos.js to open the matching preview (same order as $features)
$featureKeys = ['personal', 'products', 'categories', 'suppliers', 'stock', 'lowstock', 'reports', 'security'];
?>
<!DOCTYPE html>
<html lang="en" style="scroll-padding-top:80px">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= site_name() ?> · Inventory and Stock Management System</title>
<meta name="description" content="Manage your inventory smarter and faster. Track products, stock levels, suppliers and reports in one place.">
<script>document.documentElement.classList.add('js')</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/landing.css" rel="stylesheet">
<link href="assets/css/feature-demos.css" rel="stylesheet">
</head>
<body class="lp" data-bs-spy="scroll" data-bs-target="#lpMenu" data-bs-root-margin="-35% 0px -55% 0px" tabindex="0">

<nav class="navbar navbar-expand-lg lp-nav fixed-top" id="lpNav">
  <div class="container">
    <a class="navbar-brand lp-brand" href="#home"><span class="logo"><i class="bi bi-boxes"></i></span> <?= site_name() ?></a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#lpMenu" aria-controls="lpMenu" aria-expanded="false" aria-label="Toggle navigation"><i class="bi bi-list fs-2"></i></button>
    <div class="collapse navbar-collapse" id="lpMenu">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
        <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
        <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
        <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
        <li class="nav-item"><a class="nav-link" href="help_center.php">Help Center</a></li>
      </ul>
      <div class="d-flex gap-2 pb-2 pb-lg-0">
        <?php if ($in): ?>
          <a class="btn btn-primary" href="<?= $dash ?>"><i class="bi bi-speedometer2 me-1"></i> Open Dashboard</a>
        <?php else: ?>
          <a class="btn btn-light btn-pill px-4" href="login.php">Login</a>
          <a class="btn btn-primary btn-pill px-4" href="<?= $start ?>">Get Started</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<header class="hero" id="home">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-5">
        <span class="eyebrow rise" style="--d:0"><i class="bi bi-stars"></i> Inventory and Stock Management System</span>
        <h1 class="rise" style="--d:1">Your Inventory. Your Business. <span class="grad">Your Control.</span></h1>
        <p class="lead rise" style="--d:2">Manage your inventory smarter with your own personalized inventory workspace. Track products, monitor stock levels, manage suppliers, and organize your business operations in one platform.</p>
        <div class="d-flex flex-wrap gap-3 rise" style="--d:3">
          <?php if ($in): ?>
            <a class="btn btn-primary btn-lg btn-pill px-4" href="<?= $dash ?>">Open Dashboard <i class="bi bi-arrow-right ms-1"></i></a>
          <?php else: ?>
            <a class="btn btn-primary btn-lg btn-pill px-4" href="<?= $start ?>">Get Started <i class="bi bi-arrow-right ms-1"></i></a>
            <a class="btn btn-light btn-lg btn-pill px-4" href="login.php">Login</a>
          <?php endif; ?>
        </div>
        <ul class="hero-points rise" style="--d:4">
          <li><i class="bi bi-check-circle-fill"></i> Live stock levels</li>
          <li><i class="bi bi-check-circle-fill"></i> Low stock alerts</li>
          <li><i class="bi bi-check-circle-fill"></i> Role-based access</li>
        </ul>
      </div>
      <div class="col-lg-7">
        <div class="preview-wrap rise" style="--d:2" aria-hidden="true">
          <div class="chip chip-a"><i class="bi bi-bell-fill"></i><div><b>Low stock alert</b><small>Wireless Headphones: 8 left</small></div></div>
          <div class="chip chip-b"><i class="bi bi-box-arrow-in-down"></i><div><b>+120 units received</b><small>Stock in · REF-IN-2410</small></div></div>
          <div class="preview">
            <div class="pv-bar"><i></i><i></i><i></i><span>StockRoom · Dashboard</span></div>
            <div class="pv-body">
              <div class="pv-stats">
                <div><small>Total Products</small><b>1,284</b></div><div><small>In Stock</small><b>1,102</b></div>
                <div><small>Low Stock</small><b>170</b></div><div><small>Out of Stock</small><b>12</b></div>
              </div>
              <div class="pv-row">
                <div class="pv-card pv-chart"><small>Stock Flow</small>
                  <div class="bars"><?php foreach ([38, 55, 42, 70, 48, 62, 85, 52, 66, 44, 58, 74] as $i => $h): ?><span style="--h:<?= $h ?>%;--i:<?= $i ?>" class="<?= $i === 6 ? 'hot' : '' ?>"></span><?php endforeach; ?></div></div>
                <div class="pv-card pv-donut-card"><small>Alerts</small><div class="donut-sm"><span>86%</span></div></div>
              </div>
              <div class="pv-card pv-table">
                <div><span class="dot"></span>Wireless Headphones<em class="pill warn">Low Stock</em></div>
                <div><span class="dot"></span>Leather Handbag<em class="pill ok">In Stock</em></div>
                <div><span class="dot"></span>Graphic Sweatshirt<em class="pill bad">Out of Stock</em></div>
              </div>
            </div>
          </div>
          <p class="sample-note">Illustration with sample data</p>
        </div>
      </div>
    </div>
  </div>
</header>

<section class="section" id="features">
  <div class="container">
    <div class="section-head reveal"><span class="kicker">Features</span><h2>Everything you need to run your stock room</h2><p>Simple tools that cover the full inventory cycle, from adding a product to reporting on it.</p></div>
    <div class="row g-4">
      <?php foreach ($features as $i => [$icon, $title, $text]): ?>
        <div class="col-md-6 col-lg-4 reveal" style="--d:<?= $i % 3 ?>">
          <article class="feature" data-feature="<?= $featureKeys[$i] ?>" role="button" tabindex="0" aria-haspopup="dialog" aria-label="<?= e($title) ?>: open interactive preview"><div class="f-icon"><i class="bi bi-<?= $icon ?>"></i></div><h3><?= e($title) ?></h3><p><?= e($text) ?></p><span class="f-more">Explore feature <i class="bi bi-arrow-right"></i></span></article>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section alt" id="about">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6 reveal">
        <span class="kicker">About</span>
        <h2>Built to keep businesses organized</h2>
        <p class="text-secondary">The Inventory and Stock Management System replaces scattered notebooks and spreadsheets with a single, reliable place for your stock. It shows how much of each product you have, warns you when levels get low, and records every movement so you always know what changed and who changed it. Every account has its own private workspace, and a platform administrator keeps the whole system healthy.</p>
        <ul class="about-list">
          <li><i class="bi bi-diagram-3"></i><div><b>Organize products</b><span>Categories, suppliers, SKUs and photos keep the catalog tidy.</span></div></li>
          <li><i class="bi bi-speedometer2"></i><div><b>Monitor stock levels</b><span>Minimum levels and alerts highlight what needs attention first.</span></div></li>
          <li><i class="bi bi-lightning-charge"></i><div><b>Manage inventory efficiently</b><span>Fast stock-in and stock-out entries with a full activity history.</span></div></li>
        </ul>
      </div>
      <div class="col-lg-6 reveal" style="--d:1">
        <div class="steps">
          <div class="step"><span>1</span><div><b>Add your products</b><small>Enter items, categories and suppliers once.</small></div></div>
          <div class="step"><span>2</span><div><b>Record stock movement</b><small>Log deliveries and sales as they happen.</small></div></div>
          <div class="step"><span>3</span><div><b>Act on alerts and reports</b><small>Restock in time and review trends.</small></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section pb-0">
  <div class="container">
    <div class="cta reveal">
      <h2>Ready to take control of your inventory?</h2>
      <p>Create your account and start tracking your stock in minutes.</p>
      <div class="d-flex flex-wrap justify-content-center gap-3">
        <?php if ($in): ?>
          <a class="btn btn-light btn-lg btn-pill px-4" href="<?= $dash ?>">Open Dashboard</a>
        <?php else: ?>
          <a class="btn btn-light btn-lg btn-pill px-4" href="<?= $start ?>"><?= allow_registration() ? 'Create Your Account' : 'Get Started' ?></a>
          <a class="btn btn-outline-light btn-lg btn-pill px-4" href="login.php">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<footer class="lp-footer" id="contact">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-5">
        <a class="lp-brand text-white" href="#home"><span class="logo"><i class="bi bi-boxes"></i></span> <?= site_name() ?></a>
        <p class="mt-3 mb-0">A multi-user Inventory and Stock Management System: every account gets its own private inventory workspace.</p>
      </div>
      <div class="col-6 col-lg-3">
        <h4>Navigation</h4>
        <ul><li><a href="#home">Home</a></li><li><a href="#features">Features</a></li><li><a href="#about">About</a></li><li><a href="login.php">Login</a></li>
        <?php if (allow_registration()): ?><li><a href="register.php">Create an Account</a></li><?php endif; ?></ul>
      </div>
      <div class="col-6 col-lg-4">
        <h4>Contact</h4>
        <ul class="contact">
          <li><i class="bi bi-envelope"></i> stockroom@admin.com</li>
          <li><i class="bi bi-telephone"></i> +63 945 856 4795</li>
          <li><i class="bi bi-geo-alt"></i> Butuan City, Agusan Del Norte, Philippines</li>
        </ul>
      </div>
    </div>
    <div class="copy">&copy; <?= date('Y') ?> <?= site_name() ?>. All rights reserved.</div>
  </div>
</footer>

<!-- Interactive feature preview (sample data only, never touches the database) -->
<div class="modal fade fd-modal" id="featureModal" tabindex="-1" aria-labelledby="fdTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <div class="fd-icon"><i class="bi"></i></div>
        <div><h2 class="modal-title" id="fdTitle"></h2><p class="fd-sub">Interactive preview</p></div>
        <span class="fd-badge"><i class="bi bi-shield-check"></i> Sample data · nothing is saved</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close preview"></button>
      </div>
      <div class="modal-body">
        <p class="fd-lead"></p>
        <ul class="fd-points"></ul>
        <div class="fd-stage"></div>
      </div>
      <div class="modal-footer">
        <div class="fd-nav">
          <button type="button" class="btn btn-light btn-sm fd-prev" aria-label="Previous feature"><i class="bi bi-chevron-left"></i></button>
          <span class="fd-count-label"></span>
          <button type="button" class="btn btn-light btn-sm fd-next" aria-label="Next feature"><i class="bi bi-chevron-right"></i></button>
        </div>
        <a class="btn btn-primary btn-pill px-4 fd-cta" href="<?= e($start) ?>">Get Started</a>
      </div>
    </div>
  </div>
</div>

<script>
window.LP_FEATURES = <?= json_encode([
    'loggedIn'    => $in,
    'isAdmin'     => $in && is_admin(),
    'home'        => $dash,
    'start'       => $start,
    'canRegister' => allow_registration(),
    'currency'    => CURRENCY,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/landing.js"></script>
<script src="assets/js/feature-demos.js"></script>
</body>
</html>
