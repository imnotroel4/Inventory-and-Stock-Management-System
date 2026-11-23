<?php
// Barcode helpers for the Add product scanner (product_scan.php) and the save check in products.php.
// Nothing in this file writes to the database. A barcode only identifies a product: it never contains the
// name, price or category, so those come from the user's own inventories, an open product database, or manual entry.
//
// Optional: add   const BARCODE_ONLINE_LOOKUP = false;   to config.php to stop sending barcode numbers to the
// open product databases (the scanner then only looks in the user's own inventories).

const BARCODE_FORMATS = ['EAN_13' => 'EAN-13', 'EAN_8' => 'EAN-8', 'UPC_A' => 'UPC-A', 'UPC_E' => 'UPC-E', 'CODE_128' => 'Code 128', 'CODE_39' => 'Code 39'];

// "EAN-13", "ean13", "EAN_13" ... -> 'EAN_13' (null when it is not one of the supported formats)
function barcode_format_key($fmt) {
    $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$fmt));
    $map = ['EAN13' => 'EAN_13', 'EAN8' => 'EAN_8', 'UPCA' => 'UPC_A', 'UPCE' => 'UPC_E', 'CODE128' => 'CODE_128', 'CODE39' => 'CODE_39'];
    return $map[$k] ?? null;
}

// GS1 check digit for the digits that come BEFORE the check digit (EAN-13, EAN-8, UPC-A all use the same rule).
function barcode_check_digit($body) {
    $sum = 0; $n = strlen($body);
    for ($i = 0; $i < $n; $i++) {
        $d = (int)$body[$n - 1 - $i];
        $sum += ($i % 2 === 0) ? $d * 3 : $d;
    }
    return (10 - $sum % 10) % 10;
}

function barcode_gs1_ok($code, $len) {
    return strlen($code) === $len && ctype_digit($code) && (int)$code[$len - 1] === barcode_check_digit(substr($code, 0, $len - 1));
}

// UPC-E (8 digits, zero-suppressed) -> the full 12-digit UPC-A it stands for.
function barcode_upce_to_upca($e) {
    $ns = $e[0]; $d = substr($e, 1, 6); $chk = $e[7]; $l = $d[5];
    if ($l <= '2')      $body = $d[0] . $d[1] . $l . '0000' . $d[2] . $d[3] . $d[4];
    elseif ($l === '3') $body = $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4];
    elseif ($l === '4') $body = $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4];
    else                $body = $d[0] . $d[1] . $d[2] . $d[3] . $d[4] . '0000' . $l;
    return $ns . $body . $chk;
}

// Checks a scanned value. $fmt is the format the scanner reported (may be empty: it is then worked out from the digits).
// Returns ['ok'=>true,'code','format','label'] or ['ok'=>false,'error'].
function barcode_validate($raw, $fmt = '') {
    $code = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$raw);
    $code = strtoupper(trim((string)$code));
    if ($code === '') return ['ok' => false, 'error' => 'The barcode is empty.'];
    if (strlen($code) > 50) return ['ok' => false, 'error' => 'This barcode is too long to be a product barcode.'];

    $key = barcode_format_key($fmt);                                 // null when the scanner gave no (or an unfamiliar) format name
    if ($key === null && ctype_digit($code)) {                       // so work it out from the digits; the check digit decides
        $n = strlen($code);
        if ($n === 13 && barcode_gs1_ok($code, 13))      $key = 'EAN_13';
        elseif ($n === 12 && barcode_gs1_ok($code, 12))  $key = 'UPC_A';
        elseif ($n === 8 && barcode_gs1_ok($code, 8))    $key = 'EAN_8';
        elseif ($n === 8 && ($code[0] === '0' || $code[0] === '1') && barcode_gs1_ok(barcode_upce_to_upca($code), 12)) $key = 'UPC_E';
    }
    if ($key === null) $key = 'CODE_128';

    $bad = 'This is not a valid ' . BARCODE_FORMATS[$key] . ' barcode';
    switch ($key) {
        case 'EAN_13': $ok = barcode_gs1_ok($code, 13); $why = ': it must be 13 digits with a correct check digit.'; break;
        case 'EAN_8':  $ok = barcode_gs1_ok($code, 8);  $why = ': it must be 8 digits with a correct check digit.'; break;
        case 'UPC_A':  $ok = barcode_gs1_ok($code, 12); $why = ': it must be 12 digits with a correct check digit.'; break;
        case 'UPC_E':  $ok = strlen($code) === 8 && ctype_digit($code) && ($code[0] === '0' || $code[0] === '1') && barcode_gs1_ok(barcode_upce_to_upca($code), 12);
                       $why = ': it must be 8 digits, start with 0 or 1 and have a correct check digit.'; break;
        case 'CODE_39': $ok = (bool)preg_match('/^[A-Z0-9 .\-$\/+%]{3,50}$/', $code); $why = ': it must be 3 to 50 letters, digits or - . $ / + % characters.'; break;
        default:        $ok = (bool)preg_match('/^[A-Z0-9 ._\-\/#+()$%:,@&]{3,50}$/', $code); $why = ': it must be 3 to 50 letters, digits or common symbols (- . _ / # + ( ) $ % : , @ &).';
    }
    if (!$ok) return ['ok' => false, 'error' => $bad . $why . ' Try scanning again with more light and a steadier hand, or enter the product manually.'];
    return ['ok' => true, 'code' => $code, 'format' => $key, 'label' => BARCODE_FORMATS[$key]];
}

// The 13-digit GTIN a validated code stands for (used by the online product databases), or null for in-house codes.
function barcode_gtin($code, $key) {
    if ($key === 'UPC_E') return '0' . barcode_upce_to_upca($code);
    if ($key === 'UPC_A') return '0' . $code;
    if ($key === 'EAN_13' || $key === 'EAN_8') return $code;
    if (ctype_digit($code)) {                                        // a Code 128 label that simply carries a GTIN
        foreach ([13, 12, 8] as $n) if (barcode_gs1_ok($code, $n)) return $n === 12 ? '0' . $code : $code;
    }
    return null;
}

// Every way the SAME product can be written: UPC-A 012345678905 is EAN-13 0012345678905, and a UPC-E is its expanded UPC-A.
// Used so one physical product can never be registered twice just because two scanners reported it differently.
function barcode_variants($code, $key) {
    $v = [$code];
    $g = barcode_gtin($code, $key);
    if ($g !== null) {
        $v[] = $g;
        if (strlen($g) === 13 && $g[0] === '0') $v[] = substr($g, 1);
    }
    return array_values(array_unique($v));
}

// The product of THIS inventory that already uses the barcode (in any of its equivalent forms), or null.
function barcode_find_in_inventory($pdo, $iid, array $variants) {
    $in = implode(',', array_fill(0, count($variants), '?'));
    $st = $pdo->prepare('SELECT id,sku,name,quantity FROM products WHERE inventory_id=? AND sku IN (' . $in . ') ORDER BY id LIMIT 1');
    $st->execute(array_merge([(int)$iid], $variants));
    return $st->fetch() ?: null;
}

function barcode_text($v, $max) {
    if (!is_scalar($v)) return '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$v) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 0, $max);
}

// Plain HTTPS GET with short timeouts. Returns the body, or null when the service could not be reached.
function barcode_http_get($url) {
    $ua = 'StockRoom-Inventory/1.0 (product barcode lookup)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true, 'header' => "User-Agent: $ua\r\nAccept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx); $http = 0;
        if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $http = (int)$m[1];
    } else {
        return null;
    }
    if ($body === false || $body === null) return null;
    return ($http >= 200 && $http < 300) || $http === 404 ? (string)$body : null;   // 404 = "no such product" (still a real answer)
}

// Looks a GTIN up in the free, community-run Open Food Facts family of databases (no account or key needed).
// Only the barcode number leaves the server. Returns ['product' => [name, description, source] | null, 'reached' => bool|null]
// ('reached' is null when the online lookup is switched off).
function barcode_online_lookup($gtin) {
    if (defined('BARCODE_ONLINE_LOOKUP') && !BARCODE_ONLINE_LOOKUP) return ['product' => null, 'reached' => null];
    if (!preg_match('/^\d{8,14}$/', (string)$gtin)) return ['product' => null, 'reached' => null];
    $sources = [
        ['Open Food Facts', 'https://world.openfoodfacts.org'],
        ['Open Products Facts', 'https://world.openproductsfacts.org'],
        ['Open Beauty Facts', 'https://world.openbeautyfacts.org'],
    ];
    $t0 = microtime(true); $reached = false;
    foreach ($sources as $s) {
        if (microtime(true) - $t0 > 6) break;
        $body = barcode_http_get($s[1] . '/api/v2/product/' . $gtin . '.json?fields=code,product_name,generic_name,brands,quantity');
        if ($body === null) continue;
        $reached = true;
        $j = json_decode($body, true);
        if (!is_array($j) || (int)($j['status'] ?? 0) !== 1 || !is_array($j['product'] ?? null)) continue;
        $p = $j['product'];
        $name = barcode_text($p['product_name'] ?? '', 150);
        $generic = barcode_text($p['generic_name'] ?? '', 200);
        if ($name === '') $name = mb_substr($generic, 0, 150);
        if ($name === '') continue;                                  // the barcode is known but has no usable name
        $brand = barcode_text(explode(',', (string)($p['brands'] ?? ''))[0], 60);
        if ($brand !== '' && mb_stripos($name, $brand) === false) $name = mb_substr($brand . ' ' . $name, 0, 150);
        $parts = [];
        foreach ([$brand, barcode_text($p['quantity'] ?? '', 60), $generic] as $part)
            if ($part !== '' && mb_stripos($name, $part) === false && !in_array($part, $parts, true)) $parts[] = $part;
        return ['product' => ['name' => $name, 'description' => implode(' · ', $parts), 'source' => $s[0]], 'reached' => true];
    }
    return ['product' => null, 'reached' => $reached];
}
