<?php
// Authentication helpers shared by login, register, logout and the Remember-me cookie.
// Loaded at the end of config.php, so $pdo and the other helpers already exist.

function is_local_request() { return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); }
function admin_exists() { global $pdo; return (bool)$pdo->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->fetchColumn(); }

function client_ip() { return $_SERVER['REMOTE_ADDR'] ?? ''; }

function remember_path() {
    $d = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $d . '/';
}
function remember_cookie_set($value, $expires) {
    setcookie('sr_remember', $value, ['expires' => $expires, 'path' => remember_path(), 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

// Signs the person in: new session id (prevents session fixation) + the data the app reads from $_SESSION.
function start_user_session(array $u) {
    session_regenerate_id(true);
    unset($_SESSION['inventory_id'], $_SESSION['csrf']);   // fresh start: the inventory is picked again from the database
    $_SESSION['user'] = ['id' => $u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'], 'role' => $u['role']];
    if (!empty($u['must_change'])) $_SESSION['must_change'] = 1; else unset($_SESSION['must_change']);
}

function remember_issue($userId) {
    global $pdo;
    $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    $selector = bin2hex(random_bytes(12)); $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    $pdo->prepare('INSERT INTO remember_tokens (user_id,selector,token_hash,expires_at) VALUES (?,?,?,?)')
        ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);
    remember_cookie_set($selector . ':' . $validator, $expires);
}

function remember_forget() {
    global $pdo;
    $c = $_COOKIE['sr_remember'] ?? '';
    if ($c !== '') {
        $pdo->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([explode(':', $c, 2)[0]]);
        remember_cookie_set('', time() - 3600);
        unset($_COOKIE['sr_remember']);
    }
}

function remember_login() {
    global $pdo; static $tried = false;
    if ($tried) return;
    $tried = true;
    $c = $_COOKIE['sr_remember'] ?? '';
    if ($c === '' || strpos($c, ':') === false) return;
    [$sel, $val] = explode(':', $c, 2);
    $st = $pdo->prepare('SELECT t.token_hash, u.* FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector=? AND t.expires_at > NOW()');
    $st->execute([$sel]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'active' || !hash_equals($row['token_hash'], hash('sha256', $val))) {
        if ($row) $pdo->prepare('DELETE FROM remember_tokens WHERE selector=?')->execute([$sel]);
        remember_cookie_set('', time() - 3600);
        return;
    }
    start_user_session($row);
    $pdo->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$row['id']]);
}

// Counts recent log entries from this IP address (login_failed, register, ...) to slow down guessing.
function too_many_attempts($action, $max = 8, $minutes = 15) {
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE action=? AND ip_address=? AND created_at > (NOW() - INTERVAL ' . (int)$minutes . ' MINUTE)');
    $st->execute([$action, client_ip()]);
    return (int)$st->fetchColumn() >= $max;
}

// Explains why a correct password was still refused
function account_block_message($status) {
    return $status === 'suspended'
        ? 'Your account has been suspended. Please contact the administrator.'
        : 'Your account has been deactivated. Please contact the administrator.';
}

function valid_username($u) { return (bool)preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u); }
function password_problem($pw) {
    if (strlen($pw) < 8) return 'Use at least 8 characters.';
    if (strlen($pw) > 72) return 'Use 72 characters or fewer.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Include at least one letter and one number.';
    return null;
}

// Shows the server-side error for a field, or the default browser-validation message.
function feedback($errors, $key, $default) {
    return '<div class="invalid-feedback">' . e($errors[$key] ?? $default) . '</div>';
}
function is_bad($errors, $key) { return isset($errors[$key]) ? ' is-invalid' : ''; }
function old($key) { return e($_POST[$key] ?? ''); }
