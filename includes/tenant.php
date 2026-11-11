<?php
// Inventory ownership helpers. This file is what keeps one user's data away from every other user.
// Rule of thumb used on every page:  $inv = require_inventory();  then every query filters on  inventory_id = $inv['id'].

const INVENTORY_TYPES = ['Grocery / Retail store', 'Computer & electronics shop', 'Clothing & apparel', 'Pharmacy / Health', 'Hardware & construction', 'Restaurant / Food service', 'Office supplies', 'Warehouse', 'Other'];

function user_inventories($userId) {
    global $pdo;
    $st = $pdo->prepare('SELECT i.*, (SELECT COUNT(*) FROM products p WHERE p.inventory_id=i.id) AS product_count FROM inventories i WHERE i.user_id=? ORDER BY i.id');
    $st->execute([$userId]);
    return $st->fetchAll();
}

// The inventory the signed-in user is working in. Always re-checked against the database, so a tampered
// session value (or an inventory deleted meanwhile) can never give access to somebody else's inventory.
function current_inventory($refresh = false) {
    global $pdo; static $cache = null, $loaded = false;
    $u = current_user();
    if (!$u || ($u['role'] ?? '') !== 'user') return null;
    if ($refresh) $loaded = false;
    if ($loaded) return $cache;
    $loaded = true; $cache = null;
    $wanted = (int)($_SESSION['inventory_id'] ?? 0);
    if ($wanted) {
        $st = $pdo->prepare('SELECT * FROM inventories WHERE id=? AND user_id=?');
        $st->execute([$wanted, $u['id']]);
        $cache = $st->fetch() ?: null;
    }
    if (!$cache) { // nothing chosen yet (first login) or the chosen one is gone: use the user's oldest inventory
        $st = $pdo->prepare('SELECT * FROM inventories WHERE user_id=? ORDER BY id LIMIT 1');
        $st->execute([$u['id']]);
        $cache = $st->fetch() ?: null;
    }
    if ($cache) $_SESSION['inventory_id'] = (int)$cache['id']; else unset($_SESSION['inventory_id']);
    return $cache;
}

// Guard for every regular-user page. Returns the active inventory row.
function require_inventory() {
    require_login();
    if (is_admin()) redirect(ADMIN_HOME);              // admins do not own inventories; they inspect them from the admin area
    $inv = current_inventory();
    if (!$inv) redirect('create_inventory.php');       // first-time experience: no inventory yet
    return $inv;
}

// Where a person should land after signing in
function home_url() {
    if (is_admin()) return ADMIN_HOME;
    return current_inventory(true) ? 'dashboard.php' : 'create_inventory.php';
}

// Throws unless row $id of $table belongs to inventory $invId. Use it on every id that arrives from a form.
function require_owned($table, $id, $invId, $label = 'Record') {
    global $pdo;
    if (!in_array($table, ['products', 'categories', 'suppliers', 'purchase_orders', 'product_batches'], true)) throw new InvalidArgumentException('Unknown table.');
    $st = $pdo->prepare("SELECT id FROM $table WHERE id=? AND inventory_id=?");
    $st->execute([(int)$id, (int)$invId]);
    if (!$st->fetchColumn()) throw new Exception("$label not found in this inventory.");
    return (int)$id;
}

// Removes an inventory and everything in it. Child rows go first because stock history protects products from deletion.
function delete_inventory_cascade($invId) {
    global $pdo;
    $imgs = $pdo->prepare('SELECT image FROM products WHERE inventory_id=? AND image IS NOT NULL');
    $imgs->execute([$invId]);
    $files = $imgs->fetchAll(PDO::FETCH_COLUMN);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        foreach (['stock_out_batches', 'stock_movements', 'stock_in', 'stock_out', 'product_batches', 'purchase_order_items', 'purchase_orders', 'products', 'categories', 'suppliers'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE inventory_id=?")->execute([$invId]);
        }
        $pdo->prepare('DELETE FROM inventories WHERE id=?')->execute([$invId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach ($files as $f) if (is_file(UPLOAD_DIR . $f)) @unlink(UPLOAD_DIR . $f);
}

// Deletes a user together with every inventory they own.
function delete_user_cascade($userId) {
    global $pdo;
    $ids = $pdo->prepare('SELECT id FROM inventories WHERE user_id=?');
    $ids->execute([$userId]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $invId) delete_inventory_cascade((int)$invId);
    $av = $pdo->prepare('SELECT avatar FROM users WHERE id=?'); $av->execute([$userId]); $avatar = $av->fetchColumn();
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    if ($avatar && is_file(UPLOAD_DIR . $avatar)) @unlink(UPLOAD_DIR . $avatar);
}
