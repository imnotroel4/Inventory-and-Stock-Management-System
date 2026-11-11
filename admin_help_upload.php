<?php
// AJAX endpoint used by the article editor: uploads one instructional image/screenshot, returns JSON.
// Administrators only. CSRF-protected. Files go to uploads/help/ with a random name; type is verified from the file contents.
require_once __DIR__ . '/includes/help_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($arr, $code = 200) { http_response_code($code); echo json_encode($arr); exit; }

$u = current_user();
if (!$u) out(['error' => 'Please sign in again.'], 401);
$acc = fresh_account($u['id']);
if (!$acc || $acc['status'] !== 'active' || $acc['role'] !== 'admin') out(['error' => 'Administrators only.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST only.'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) out(['error' => 'Session expired. Reload the page and try again.'], 400);

try {
    help_ensure_schema();
    $rel = help_upload_image($_FILES['image'] ?? []);
    if (!$rel) throw new Exception('Choose an image first.');
    $alt = trim(preg_replace('/[\[\]()\r\n]+/', ' ', (string)($_POST['alt'] ?? '')));
    log_activity('help_upload', $rel);
    out(['path' => $rel, 'url' => 'uploads/' . $rel, 'markdown' => '![' . mb_substr($alt, 0, 120) . '](' . $rel . ')']);
} catch (Exception $e) { out(['error' => $e->getMessage()], 422); }
