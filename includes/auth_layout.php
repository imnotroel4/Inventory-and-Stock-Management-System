<?php
// Shared two-column layout for the Login, Create account and Forgot password pages.
function auth_start($title, $heading, $sub) {
    // Static sample items only. This page is public, so it must never read real products from the database.
    $demo = [['name' => 'Mineral Water 500ml', 'quantity' => 120, 'min_stock' => 40], ['name' => 'Orange Juice 1L', 'quantity' => 18, 'min_stock' => 15], ['name' => 'Sardines 155g', 'quantity' => 4, 'min_stock' => 30]];
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= site_name() ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<link href="assets/css/auth.css" rel="stylesheet"></head>
<body><div class="login-wrap">
  <section class="login-art">
    <a class="brand p-0" href="index.php"><span class="logo"><i class="bi bi-boxes"></i></span> <?= site_name() ?></a>
    <div>
      <h2>Know what is on the shelf before it runs out.</h2>
      <?php if ($demo): ?><div class="shelf-demo mt-4">
        <?php foreach ($demo as $d): ?><div><span><?= e($d['name']) ?></span><span><?= (int)$d['quantity'] ?> left</span></div><?= level_meter($d['quantity'], $d['min_stock']) ?><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
    <a class="back-link" href="index.php"><i class="bi bi-arrow-left me-1"></i>Back to home</a>
  </section>
  <section class="login-form">
    <div class="auth-card">
      <a class="auth-brand d-lg-none" href="index.php"><span class="logo"><i class="bi bi-boxes"></i></span> <?= site_name() ?></a>
      <h1><?= e($heading) ?></h1>
      <p class="text-secondary mb-4"><?= e($sub) ?></p>
      <?php if ($flash): ?><div class="alert alert-<?= e($flash[1]) ?> py-2" role="alert"><?= e($flash[0]) ?></div><?php endif; ?>
<?php }

function auth_end() { ?>
    </div>
  </section>
</div>
<script src="assets/js/auth.js"></script>
</body></html>
<?php }
