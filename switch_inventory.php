<?php
// Switches the inventory you are working in. Only inventories you own can be selected.
require_once __DIR__ . '/config.php';
require_login();
if (is_admin()) redirect(ADMIN_HOME);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = $pdo->prepare('SELECT id,name FROM inventories WHERE id=? AND user_id=?');
    $st->execute([(int)($_POST['id'] ?? 0), current_user()['id']]);
    if ($inv = $st->fetch()) { $_SESSION['inventory_id'] = (int)$inv['id']; flash('Now working in “' . $inv['name'] . '”.'); }
    else { flash('You can only open your own inventories.', 'danger'); }
}
redirect('dashboard.php');
