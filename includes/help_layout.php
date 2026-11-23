<?php
// Page wrapper for the Help Center.
//  - Signed-in users and admins get the normal sidebar/topbar from layout.php (page_start), so the design is identical.
//  - Visitors who are not signed in get a light public header, so potential users can read the Help Center too.
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/help_lib.php';

function help_is_guest() { return !current_user(); }

function help_start($title, $active = 'help_center') {
    help_ensure_schema();
    if (!help_is_guest()) {
        page_start($title, $active, 'account');          // handles login checks, suspended accounts, forced password change
    } else {
        $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
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
<header class="hlp-guestbar">
  <div class="hlp-guestbar-in">
    <a class="brand" href="index.php"><span class="logo"><i class="bi bi-boxes"></i></span> <?= e(site_name()) ?></a>
    <span class="badge badge-info ms-1">Help Center</span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-light btn-sm btn-pill px-3" href="login.php">Login</a>
      <?php if (allow_registration()): ?><a class="btn btn-primary btn-sm btn-pill px-3" href="register.php">Get started</a><?php endif; ?>
    </div>
  </div>
</header>
<div class="hlp-guestpage">
<?php if ($flash): ?><div class="alert alert-<?= e($flash[1]) ?> alert-dismissible fade show"><?= e($flash[0]) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif;
    }
    echo '<link href="assets/css/help.css" rel="stylesheet">' . "\n";   // a stylesheet link is valid inside <body>
    echo '<div class="hlp-root">';
}

// $extraJs: array of script paths to load after Bootstrap
function help_end(array $extraJs = []) {
    echo '</div>';   // .hlp-root
    foreach ($extraJs as $js) echo '<script src="' . e($js) . '"></script>' . "\n";
    if (help_is_guest()) {
        echo '</div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>';
        return;
    }
    page_end();
}

// Tab bar shown at the top of every Help Center page
function help_subnav($active) {
    $items = ['center' => ['help_center.php', 'Help Center', 'life-preserver'], 'showcase' => ['help_showcase.php', 'Feature showcase', 'window-stack'],
        'tutorials' => ['help_tutorials.php', 'Tutorials', 'mortarboard'], 'demo' => ['help_demo.php', 'Demo mode', 'play-circle'],
        'faq' => ['help_faq.php', 'FAQ', 'patch-question'], 'contact' => ['help_contact.php', 'Contact support', 'chat-dots']];
    if (!help_is_guest()) $items['tickets'] = ['help_tickets.php', 'My requests', 'inbox'];
    echo '<nav class="hlp-subnav no-print" aria-label="Help Center sections">';
    foreach ($items as $k => [$href, $label, $icon])
        echo '<a href="' . $href . '" class="' . ($k === $active ? 'active' : '') . '"' . ($k === $active ? ' aria-current="page"' : '') . '><i class="bi bi-' . $icon . '"></i><span>' . e($label) . '</span></a>';
    echo '</nav>';
}

// Search box used on the hero and the results page
function help_search_box($q = '', $big = false) {
    return '<form class="hlp-search' . ($big ? ' big' : '') . '" action="help_center.php" method="get" role="search" autocomplete="off">
      <i class="bi bi-search"></i><input type="search" name="q" id="hlpQ" value="' . e($q) . '" maxlength="100" placeholder="Search for features, instructions or errors…" aria-label="Search help">
      <button class="btn btn-primary btn-pill px-4">Search</button><div class="hlp-suggest" id="hlpSuggest" hidden></div></form>';
}
