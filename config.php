<?php
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
date_default_timezone_set('Asia/Manila');

const DB_HOST = 'localhost';
const DB_NAME = 'inventory_db';
const DB_USER = 'root';
const DB_PASS = '';
const CURRENCY = '₱';
const UPLOAD_DIR = __DIR__ . '/uploads/';
const REMEMBER_DAYS = 30;          // how long "Remember me" keeps someone signed in
const ADMIN_HOME = 'admin_dashboard.php'; // where administrators land after login

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit('Database connection failed. Import database.sql in phpMyAdmin and check the settings in config.php.');
}

// Upgrades older databases to the multi-user structure (does nothing once finished)
require_once __DIR__ . '/includes/migrate.php';
try { run_migrations($pdo); }
catch (Throwable $e) { exit('Database upgrade failed: ' . htmlspecialchars($e->getMessage()) . ' (Restore your backup, fix the problem, then reload.)'); }

// ---- System settings (edited by the admin on the Settings page, stored in the `settings` table) ----
const SETTING_DEFAULTS = [
    'site_name' => 'StockRoom', 'allow_registration' => '1', 'max_inventories' => '10', 'default_min_stock' => '10',
    'maintenance_mode' => '0', 'maintenance_message' => 'The system is under maintenance. Please try again later.',
    'expiry_alert_days' => '90',   // medicines are flagged "near expiry" this many days before the expiry date
];
$SETTINGS = $pdo->query('SELECT key_name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
function setting($key) { global $SETTINGS; return $SETTINGS[$key] ?? SETTING_DEFAULTS[$key] ?? null; }
function site_name() { return setting('site_name'); }
function allow_registration() { return setting('allow_registration') === '1'; }
function max_inventories() { return max(1, (int)setting('max_inventories')); }
function default_min_stock() { return max(0, (int)setting('default_min_stock')); }
function expiry_default_days() { return max(1, (int)setting('expiry_alert_days')); }
function maintenance_on() { return setting('maintenance_mode') === '1'; }

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n) { return CURRENCY . number_format((float)$n, 2); }
function avatar_html($name, $img = null, $cls = '') {
    if ($img && is_file(UPLOAD_DIR . $img)) return '<img class="avatar ' . $cls . '" src="uploads/' . e($img) . '" alt="">';
    $p = preg_split('/\s+/', trim((string)$name));
    $ini = mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
    return '<span class="avatar ' . $cls . '">' . e($ini) . '</span>';
}
function redirect($url) { header('Location: ' . $url); exit; }

function current_user() {
    if (!isset($_SESSION['user'])) remember_login(); // restores the session from a valid Remember-me cookie
    return $_SESSION['user'] ?? null;
}

// The account row as it is in the database RIGHT NOW (role and status are never trusted from the session).
function fresh_account($id) {
    global $pdo; static $cache = [];
    if (!isset($cache[$id])) {
        $st = $pdo->prepare('SELECT id,role,status,must_change FROM users WHERE id=?');
        $st->execute([$id]);
        $cache[$id] = $st->fetch() ?: null;
    }
    return $cache[$id];
}

function force_logout($msg) {
    remember_forget();
    $_SESSION = [];
    session_regenerate_id(true);
    flash($msg, 'warning');
    redirect('login.php');
}

// Every protected page starts here: must be signed in, account must still be active, password change if forced.
function require_login() {
    $u = current_user();
    if (!$u) redirect('login.php');
    $acc = fresh_account($u['id']);
    if (!$acc || $acc['status'] !== 'active') {
        force_logout(!$acc ? 'This account no longer exists.' : ($acc['status'] === 'suspended' ? 'Your account has been suspended. Please contact the administrator.' : 'Your account has been deactivated. Please contact the administrator.'));
    }
    if (maintenance_on() && $acc['role'] !== 'admin') force_logout(setting('maintenance_message'));
    $_SESSION['user']['role'] = $acc['role'];                 // a role change by an admin applies immediately
    if ($acc['must_change']) $_SESSION['must_change'] = 1;
    if (!empty($_SESSION['must_change']) && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') redirect('change_password.php');
}
function is_admin() { return (current_user()['role'] ?? '') === 'admin'; }

// Pages only an administrator may open. This is the real lock; hiding the menu link is just cosmetics.
function require_admin() {
    require_login();
    if (!is_admin()) { http_response_code(403); exit('Access denied. Administrators only.'); }
}

function csrf_token() { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field() { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid request token. Go back and try again.'); }
}

function flash($msg, $type = 'success') { $_SESSION['flash'] = [$msg, $type]; }

// Writes to the activity log. The current inventory (if any) is recorded too, so admins can filter by it.
function log_activity($action, $details = '') {
    global $pdo;
    $pdo->prepare('INSERT INTO activity_logs (user_id,inventory_id,action,details,ip_address) VALUES (?,?,?,?,?)')
        ->execute([current_user()['id'] ?? null, current_inventory()['id'] ?? null, $action, mb_substr((string)$details, 0, 255), $_SERVER['REMOTE_ADDR'] ?? '']);
}

function stock_status($qty, $min) {
    if ($qty <= 0) return ['Out of stock', 'danger'];
    if ($qty <= $min) return ['Low stock', 'warning'];
    return ['In stock', 'success'];
}
function status_badge($qty, $min) { [$l, $c] = stock_status($qty, $min); return '<span class="badge badge-' . $c . '">' . $l . '</span>'; }

// Share of the "healthy" level (twice the minimum) that is on the shelf
function level_meter($qty, $min) {
    [, $c] = stock_status($qty, $min);
    $pct = $min > 0 ? min(100, round($qty / ($min * 2) * 100)) : ($qty > 0 ? 100 : 0);
    return '<div class="meter" title="' . (int)$qty . ' on hand, minimum ' . (int)$min . '"><span class="meter-' . $c . '" style="width:' . $pct . '%"></span><i style="left:50%"></i></div>';
}

function make_ref($prefix) { return $prefix . '-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); }

// Must run inside a DB transaction. Locks the product row, applies the change and records the movement.
// The product must belong to $invId, so one user can never change another user's stock.
function apply_stock($pdo, $invId, $productId, $type, $qty, $ref) {
    $st = $pdo->prepare('SELECT quantity,name FROM products WHERE id=? AND inventory_id=? FOR UPDATE');
    $st->execute([$productId, $invId]);
    $p = $st->fetch();
    if (!$p) throw new Exception('Product not found in this inventory.');
    $new = $type === 'out' ? $p['quantity'] - $qty : $p['quantity'] + $qty;
    if ($new < 0) throw new Exception('Not enough stock for ' . $p['name'] . '. Available: ' . $p['quantity']);
    $pdo->prepare('UPDATE products SET quantity=? WHERE id=? AND inventory_id=?')->execute([$new, $productId, $invId]);
    $pdo->prepare('INSERT INTO stock_movements (inventory_id,product_id,type,quantity,balance_after,reference_no,user_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$invId, $productId, $type, $qty, $new, $ref, current_user()['id'] ?? null]);
    return $new;
}

function upload_image($file, $old = null) {
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) return $old;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) throw new Exception('Image upload failed. Maximum size is 2 MB.');
    $info = @getimagesize($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) throw new Exception('Only JPG, PNG, WEBP or GIF images are allowed.');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $name)) throw new Exception('Could not save the image. Check the uploads folder permissions.');
    if ($old && is_file(UPLOAD_DIR . $old)) @unlink(UPLOAD_DIR . $old);
    return $name;
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/pharmacy.php';
