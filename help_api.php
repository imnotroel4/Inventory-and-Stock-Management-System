<?php
// JSON for the live search suggestions. Read-only, published help content only, no personal data.
require_once __DIR__ . '/includes/help_lib.php';
help_ensure_schema();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$items = [];
if (mb_strlen($q) >= 2) {
    $r = help_search($q, 5);
    foreach ($r['articles'] as $a) $items[] = ['type' => 'article', 'title' => $a['title'], 'url' => 'help_article.php?slug=' . rawurlencode($a['slug'])];
    foreach ($r['tutorials'] as $t) $items[] = ['type' => 'tutorial', 'title' => $t['title'], 'url' => 'help_tutorial.php?slug=' . rawurlencode($t['slug'])];
    foreach ($r['faqs'] as $f) $items[] = ['type' => 'faq', 'title' => $f['question'], 'url' => 'help_faq.php?q=' . rawurlencode($q) . '#faq' . (int)$f['id']];
}
echo json_encode(['items' => array_slice($items, 0, 8)], JSON_UNESCAPED_UNICODE);
