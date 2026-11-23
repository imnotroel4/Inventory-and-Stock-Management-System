<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/barcode.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function scan_reply($status, $message, $extra = []) {
    echo json_encode(['status' => $status, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); scan_reply('invalid', 'Invalid request.'); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); scan_reply('invalid', 'Your page expired. Reload it and try again.'); }

$inv = require_inventory(); $iid = (int)$inv['id']; $uid = (int)current_user()['id'];

// ---------- Barcode scan: EAN-13, EAN-8, UPC-A, UPC-E, Code 128, Code 39 ----------
// A barcode is only an identifier. It is used as the SKU; the other details come from the user's own inventories,
// an open product database, or manual entry. Order: duplicate in this inventory -> known in another inventory -> online -> code only.
if (($_POST['type'] ?? '') === 'barcode') {
    $bc = barcode_validate((string)($_POST['code'] ?? ''), (string)($_POST['format'] ?? ''));
    if (!$bc['ok']) scan_reply('invalid', $bc['error']);
    $code = $bc['code']; $barcode = ['format' => $bc['format'], 'label' => $bc['label'], 'code' => $code];
    try {
        $variants = barcode_variants($code, $bc['format']);

        
        if ($dupe = barcode_find_in_inventory($pdo, $iid, $variants)) {
            scan_reply('duplicate', '“' . $dupe['name'] . '” (' . $dupe['sku'] . ') already uses this barcode in this inventory, so no new product was prepared.', [
                'product' => ['id' => (int)$dupe['id'], 'sku' => $dupe['sku'], 'name' => $dupe['name'], 'quantity' => (int)$dupe['quantity']],
                'barcode' => $barcode,
            ]);
        }

        // 2. Known in another inventory of the same user -> offer those details as a starting point.
        $in = implode(',', array_fill(0, count($variants), '?'));
        $st = $pdo->prepare('SELECT p.*, i.name AS inventory_name FROM products p JOIN inventories i ON i.id=p.inventory_id WHERE i.user_id=? AND p.sku IN (' . $in . ') ORDER BY p.id LIMIT 1');
        $st->execute(array_merge([$uid], $variants));
        $hit = $st->fetch() ?: null;

        $f = ['sku' => $code]; $source = 'code_only'; $note = ''; $from = '';
        if ($hit) {
            $f = ['sku' => $code, 'name' => $hit['name'], 'description' => (string)$hit['description'],
                  'cost_price' => (float)$hit['cost_price'], 'selling_price' => (float)$hit['selling_price'], 'min_stock' => (int)$hit['min_stock'],
                  'track_expiry' => (int)$hit['track_expiry']] + ($hit['expiry_alert_days'] !== null ? ['expiry_alert_days' => (int)$hit['expiry_alert_days']] : []);
            if ($hit['category_id']) {                               // categories are per inventory: match by name, never copy ids across
                $st = $pdo->prepare('SELECT name FROM categories WHERE id=? AND inventory_id=?'); $st->execute([$hit['category_id'], $hit['inventory_id']]);
                if (($cn = (string)$st->fetchColumn()) !== '') {
                    $st = $pdo->prepare('SELECT id FROM categories WHERE inventory_id=? AND name=?'); $st->execute([$iid, $cn]);
                    if ($cid = $st->fetchColumn()) $f['category_id'] = (int)$cid;
                }
            }
            $source = 'found'; $from = $hit['inventory_name'];
        } else {
            // 3. Not known here -> ask an open product database (only the barcode number is sent). Free the session lock first.
            $gtin = barcode_gtin($code, $bc['format']);
            $lookup = ['product' => null, 'reached' => null];
            if ($gtin !== null) { session_write_close(); $lookup = barcode_online_lookup($gtin); }
            if ($lookup['product']) {
                $f['name'] = $lookup['product']['name'];
                if ($lookup['product']['description'] !== '') $f['description'] = $lookup['product']['description'];
                $source = 'online'; $from = $lookup['product']['source'];
            } elseif ($lookup['reached'] === true) {
                $note = ' It was not found in the open product databases either.';
            } elseif ($lookup['reached'] === false) {
                $note = ' The online product lookup could not be reached just now.';
            }
        }

        // Same name under a different code in this inventory: not blocked, but the user is warned.
        $similar = null;
        if (isset($f['name']) && $source !== 'code_only') {
            $st = $pdo->prepare('SELECT id,sku,name FROM products WHERE inventory_id=? AND name=? LIMIT 1'); $st->execute([$iid, $f['name']]);
            if ($row = $st->fetch()) $similar = ['id' => (int)$row['id'], 'sku' => $row['sku'], 'name' => $row['name']];
        }

        $msg = [
            'found' => '“' . ($f['name'] ?? '') . '” was found in your inventory “' . $from . '”. Details were filled in. Review them, set the opening quantity and save.',
            'online' => 'Barcode ' . $code . ' (' . $bc['label'] . ') was found in ' . $from . ': “' . ($f['name'] ?? '') . '”. The name was filled in. Barcodes do not hold prices, category or stock, so complete those yourself and check the details before saving.',
            'code_only' => 'Barcode ' . $code . ' (' . $bc['label'] . ') is new: it is not in any of your inventories.' . $note . ' It was filled in as the SKU. Enter the product name, prices and the rest manually.',
        ][$source];
        if ($similar) $msg .= ' Note: a product named “' . $similar['name'] . '” (' . $similar['sku'] . ') already exists here under a different code. Make sure this is not the same item.';
        scan_reply($source, $msg, ['fields' => $f, 'barcode' => $barcode] + ($similar ? ['similar' => $similar] : []));
    } catch (Throwable $e) {
        http_response_code(500);
        scan_reply('invalid', 'The lookup failed. You can still enter the product manually.');
    }
}

// ---------- 1. Read the raw QR text ----------
$raw = trim((string)($_POST['code'] ?? ''));
$raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';
if ($raw === '') scan_reply('invalid', 'The QR code is empty.');
if (mb_strlen($raw) > 2000) scan_reply('invalid', 'This QR code holds too much data to be a product label.');

// ---------- 2. Work out what the text contains: a bare code, JSON, a link, or key:value lines ----------
function scan_clean($v, $max) {
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$v) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, $max);
}
function scan_alias($k) {
    $k = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$k));
    static $map = [
        'id' => 'id', 'productid' => 'id', 'pid' => 'id', 'product' => 'id',
        'sku' => 'sku', 'code' => 'sku', 'productcode' => 'sku', 'barcode' => 'sku',
        'name' => 'name', 'productname' => 'name', 'title' => 'name',
        'category' => 'category', 'categoryname' => 'category',
        'description' => 'description', 'desc' => 'description',
        'cost' => 'cost_price', 'costprice' => 'cost_price',
        'price' => 'selling_price', 'sellingprice' => 'selling_price', 'srp' => 'selling_price',
        'min' => 'min_stock', 'minstock' => 'min_stock', 'minimumstock' => 'min_stock', 'reorderlevel' => 'min_stock',
        'trackexpiry' => 'track_expiry', 'expiry' => 'track_expiry',
        'expiryalertdays' => 'expiry_alert_days', 'alertdays' => 'expiry_alert_days',
    ];
    return $map[$k] ?? null;
}

$kv = []; $bare = null;
if ($raw[0] === '{' && is_array($j = json_decode($raw, true))) {
    foreach ($j as $k => $v) if (is_scalar($v) && ($a = scan_alias($k)) && !isset($kv[$a])) $kv[$a] = (string)$v;
} elseif (preg_match('#^https?://#i', $raw)) {
    $q = []; parse_str((string)parse_url($raw, PHP_URL_QUERY), $q);
    foreach ($q as $k => $v) if (is_scalar($v) && ($a = scan_alias($k)) && !isset($kv[$a])) $kv[$a] = (string)$v;
    if (!$kv && preg_match('#/(?:product|products|p)/([A-Za-z0-9._\-]{1,50})/?$#', (string)parse_url($raw, PHP_URL_PATH), $m)) $kv['sku'] = $m[1];
} elseif (preg_match('/^[^\r\n:=;|]{1,50}$/u', $raw)) {
    $bare = $raw;                                                   // a plain SKU / product code
} else {
    foreach (preg_split('/[\r\n;|]+/', $raw) as $part) {
        if (preg_match('/^\s*([A-Za-z _]{1,30})\s*[:=]\s*(.*)$/su', $part, $m) && ($a = scan_alias($m[1])) && !isset($kv[$a])) $kv[$a] = $m[2];
    }
}
if ($bare !== null) $kv['sku'] = $bare;

// ---------- 3. Validate every value (never trust the QR code) ----------
$f = [];
if (isset($kv['sku'])) {
    $s = strtoupper(scan_clean($kv['sku'], 50));
    if ($s !== '' && preg_match('/^[\p{L}\p{N} ._\-\/#+()]{1,50}$/u', $s)) $f['sku'] = $s;
}
$pid = (isset($kv['id']) && preg_match('/^\d{1,10}$/', trim($kv['id']))) ? (int)trim($kv['id']) : 0;
if (isset($kv['name']) && ($n = scan_clean($kv['name'], 150)) !== '') $f['name'] = $n;
if (isset($kv['description']) && ($d = trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $kv['description']) ?? '', 0, 1000))) !== '') $f['description'] = $d;
foreach (['cost_price', 'selling_price'] as $k)
    if (isset($kv[$k]) && is_numeric($v = str_replace(',', '', trim($kv[$k]))) && $v >= 0 && $v <= 9999999999) $f[$k] = round((float)$v, 2);
if (isset($kv['min_stock']) && preg_match('/^\d{1,7}$/', trim($kv['min_stock']))) $f['min_stock'] = (int)trim($kv['min_stock']);
if (isset($kv['track_expiry'])) $f['track_expiry'] = in_array(strtolower(trim($kv['track_expiry'])), ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
if (isset($kv['expiry_alert_days']) && preg_match('/^\d{1,4}$/', trim($kv['expiry_alert_days'])) && ($v = (int)trim($kv['expiry_alert_days'])) >= 1 && $v <= 3650) $f['expiry_alert_days'] = $v;
$catName = isset($kv['category']) ? scan_clean($kv['category'], 100) : '';

if (!$pid && !isset($f['sku']) && !isset($f['name'])) scan_reply('invalid', 'This QR code does not contain a product code or name this system can read.');

// Resolves an existing product from a product ID or SKU. Only the signed-in user's own inventories are searched.
function scan_find($pdo, $uid, $iid, $pid, $sku) {
    $sel = 'SELECT p.*, i.name AS inventory_name FROM products p JOIN inventories i ON i.id=p.inventory_id WHERE i.user_id=?';
    $order = ' ORDER BY (p.inventory_id=?) DESC, p.id LIMIT 1';
    if ($pid) { $st = $pdo->prepare($sel . ' AND p.id=?' . $order); $st->execute([$uid, $pid, $iid]); if ($r = $st->fetch()) return $r; }
    if ($sku !== null) { $st = $pdo->prepare($sel . ' AND p.sku=?' . $order); $st->execute([$uid, $sku, $iid]); if ($r = $st->fetch()) return $r; }
    // a bare number may be a product ID instead of a SKU
    if ($sku !== null && !$pid && ctype_digit($sku) && strlen($sku) <= 10) { $st = $pdo->prepare($sel . ' AND p.id=?' . $order); $st->execute([$uid, (int)$sku, $iid]); if ($r = $st->fetch()) return $r; }
    return null;
}

try {
    $hit = scan_find($pdo, $uid, $iid, $pid, $f['sku'] ?? null);

    // Same product code already exists in THIS inventory -> never create a second one.
    $dupe = $hit && (int)$hit['inventory_id'] === $iid ? $hit : null;
    if (!$dupe && isset($f['sku'])) {
        $st = $pdo->prepare('SELECT * FROM products WHERE inventory_id=? AND sku=?'); $st->execute([$iid, $f['sku']]); $dupe = $st->fetch() ?: null;
    }
    if ($dupe) {
        scan_reply('duplicate', '“' . $dupe['name'] . '” (' . $dupe['sku'] . ') is already in this inventory, so it was not filled in again.', [
            'product' => ['id' => (int)$dupe['id'], 'sku' => $dupe['sku'], 'name' => $dupe['name'], 'quantity' => (int)$dupe['quantity']],
        ]);
    }

    // Known product from another inventory of the same user -> offer its details as a starting point.
    if ($hit) {
        $f = ['sku' => $hit['sku'], 'name' => $hit['name'], 'description' => (string)$hit['description'],
              'cost_price' => (float)$hit['cost_price'], 'selling_price' => (float)$hit['selling_price'], 'min_stock' => (int)$hit['min_stock'],
              'track_expiry' => (int)$hit['track_expiry']] + ($hit['expiry_alert_days'] !== null ? ['expiry_alert_days' => (int)$hit['expiry_alert_days']] : []);
        if ($hit['category_id']) {                                   // categories are per inventory: match by name, never copy ids across
            $st = $pdo->prepare('SELECT name FROM categories WHERE id=? AND inventory_id=?'); $st->execute([$hit['category_id'], $hit['inventory_id']]); $catName = (string)$st->fetchColumn();
        }
        $source = 'found';
    } else {
        if (!$f) scan_reply('invalid', 'No product with that ID was found in your inventories.');
        $source = count($f) > 1 ? 'parsed' : (isset($f['sku']) ? 'code_only' : 'parsed');
    }

    if ($catName !== '') {                                           // only an existing category of THIS inventory is ever suggested
        $st = $pdo->prepare('SELECT id FROM categories WHERE inventory_id=? AND name=?'); $st->execute([$iid, $catName]);
        if ($cid = $st->fetchColumn()) $f['category_id'] = (int)$cid;
    }

    // The QR has details but no product code: suggest a unique SKU (the user can change it).
    $generated = false;
    if (!isset($f['sku']) && isset($f['name'])) {
        $base = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($f['name'])), 0, 3) ?: 'PRD';
        $chk = $pdo->prepare('SELECT 1 FROM products WHERE inventory_id=? AND sku=?');
        for ($i = 0; $i < 25; $i++) {
            $try = $base . '-' . random_int(10000, 99999); $chk->execute([$iid, $try]);
            if (!$chk->fetchColumn()) { $f['sku'] = $try; $generated = true; break; }
        }
    }

    // Same name under a different code in this inventory: not blocked, but the user is warned.
    $similar = null;
    if ($source !== 'found' && isset($f['name'])) {
        $st = $pdo->prepare('SELECT id,sku,name FROM products WHERE inventory_id=? AND name=? LIMIT 1'); $st->execute([$iid, $f['name']]);
        if ($row = $st->fetch()) $similar = ['id' => (int)$row['id'], 'sku' => $row['sku'], 'name' => $row['name']];
    }

    $msg = [
        'found' => '“' . $f['name'] . '” was found in your inventory “' . $hit['inventory_name'] . '”. Details were filled in. Review them, set the opening quantity and save.',
        'parsed' => 'Product details were read from the QR code' . ($generated ? ' and a new SKU was suggested' : '') . '. Review them, fill in anything missing and save.',
        'code_only' => 'This QR code only holds a product code, and it is not in any of your inventories, so there are no other details to retrieve. The SKU was filled in. Enter the name, prices and the rest manually.',
    ][$source];
    if ($similar) $msg .= ' Note: a product named “' . $similar['name'] . '” (' . $similar['sku'] . ') already exists here. Make sure this is not the same item.';
    scan_reply($source, $msg, ['fields' => $f] + ($similar ? ['similar' => $similar] : []) + ($generated ? ['generated_sku' => true] : []));
} catch (Throwable $e) {
    http_response_code(500);
    scan_reply('invalid', 'The lookup failed. You can still enter the product manually.');
}
