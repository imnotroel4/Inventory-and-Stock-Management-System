<?php
// Small helpers shared by the admin pages. Admin pages only READ other people's data;
// nothing in here writes to another user's inventory.

// Keeps the current filters and changes only what you pass: qs(['page' => 2])
function qs(array $override = []) {
    return '?' . http_build_query(array_filter(array_merge($_GET, $override), fn($v) => $v !== '' && $v !== null));
}

function valid_date($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }
// Returns [from, to] as Y-m-d strings taken from ?from=&to= (falls back to the defaults when missing or invalid)
function date_range($defFrom, $defTo) {
    $from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : $defFrom;
    $to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : $defTo;
    if ($from > $to) [$from, $to] = [$to, $from];
    return [$from, $to];
}

// Pagination: returns [page, pages, offset]
function paginate($total, $per = 25) {
    $pages = max(1, (int)ceil($total / $per));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    return [$page, $pages, ($page - 1) * $per];
}
function pager_html($page, $pages) {
    if ($pages <= 1) return '';
    $h = '<nav class="p-3 border-top no-print"><ul class="pagination pagination-sm mb-0 justify-content-end">';
    $link = fn($p, $label, $dis = false, $act = false) => '<li class="page-item' . ($dis ? ' disabled' : '') . ($act ? ' active' : '') . '"><a class="page-link" href="' . e(qs(['page' => $p])) . '">' . $label . '</a></li>';
    $h .= $link($page - 1, '&laquo;', $page <= 1);
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) $h .= $link($i, $i, false, $i === $page);
    $h .= $link($page + 1, '&raquo;', $page >= $pages);
    return $h . '</ul></nav>';
}

// SQL condition for a stock-status filter on the products table (alias p)
function status_condition($status) {
    return ['low' => 'p.quantity>0 AND p.quantity<=p.min_stock', 'out' => 'p.quantity<=0', 'attention' => 'p.quantity<=p.min_stock', 'ok' => 'p.quantity>p.min_stock'][$status] ?? null;
}

// The last $n month keys ('2026-06', ...) oldest first, and a helper to fill a [month => value] map
function last_months($n = 12) { $m = []; for ($i = $n - 1; $i >= 0; $i--) $m[date('Y-m', strtotime("first day of -$i month"))] = 0; return $m; }
function month_counts(PDO $pdo, $sql, array $args = []) {
    $m = last_months(); $st = $pdo->prepare($sql); $st->execute($args);
    foreach ($st as $r) if (isset($m[$r['m']])) $m[$r['m']] = (float)$r['v'];
    return $m;
}
function month_labels($m) { return array_map(fn($k) => date('M', strtotime($k . '-01')), array_keys($m)); }

function csv_download($filename, array $cols, array $rows, array $foot = []) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");   // BOM so Excel shows accents and the peso sign correctly
    $safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v) ? "'" . $v : $v;   // stops spreadsheet formula injection
    fputcsv($o, $cols);
    foreach ($rows as $r) fputcsv($o, array_map($safe, $r));
    if ($foot) fputcsv($o, $foot);
    fclose($o); exit;
}
