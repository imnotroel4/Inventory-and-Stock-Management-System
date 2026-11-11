<?php
// Logging out changes state, so it only accepts a POST with a valid CSRF token
// (a plain link or an <img src="logout.php"> on another site cannot sign you out).
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) redirect(current_user() ? home_url() : 'index.php');
if (current_user()) log_activity('logout', 'Signed out');
remember_forget();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect('index.php');
