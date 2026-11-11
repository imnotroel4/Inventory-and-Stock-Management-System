<?php
// Pharmacy helpers: batch / lot numbers, expiry dates, near-expiry alerts, and the two functions that
// record a delivery (stock in) and a release (stock out) so that batches always stay in step with products.quantity.
// Loaded at the end of config.php, so $pdo, apply_stock(), make_ref() and the settings helpers already exist.
//
// How the numbers fit together
//   products.quantity          = everything on the shelf (this was always true and still is)
//   product_batches.qty_remaining = the part of that stock that belongs to a known batch with an expiry date
//   the rest                   = "unbatched" stock, recorded before batch tracking existed. It is never hidden or lost.

const EXPIRY_MAX_YEARS = 20;   // an expiry date further away than this is almost certainly a typing mistake

// ---------------------------------------------------------------- dates

// Returns the date as 'Y-m-d' when it is a real calendar date, otherwise null.
function parse_ymd($s) {
    $s = trim((string)$s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

// Whole days from today until $date. Negative = already past.
function days_until($date) {
    $a = new DateTime(date('Y-m-d'));
    $b = new DateTime(substr((string)$date, 0, 10));
    return (int)$a->diff($b)->format('%r%a');
}

// A medicine is valid through the whole of its expiry date; it is expired from the next day on.
// Returns ['expired' | 'near' | 'ok', days left]
function expiry_state($date, $alertDays = null) {
    $alertDays = (int)$alertDays > 0 ? (int)$alertDays : expiry_default_days();
    $d = days_until($date);
    if ($d < 0) return ['expired', $d];
    return [$d <= $alertDays ? 'near' : 'ok', $d];
}

function expiry_badge($date, $alertDays = null) {
    [$s, $d] = expiry_state($date, $alertDays);
    if ($s === 'expired') { $n = -$d; return '<span class="badge badge-danger">Expired ' . $n . ' day' . ($n === 1 ? '' : 's') . ' ago</span>'; }
    if ($s === 'near') return '<span class="badge badge-warning">' . ($d === 0 ? 'Expires today' : 'Expires in ' . $d . ' day' . ($d === 1 ? '' : 's')) . '</span>';
    return '<span class="badge badge-success">Valid</span>';
}

function fmt_date($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }

// ---------------------------------------------------------------- reading batch details from a form

// Returns [batch_no, expiry_date] (both normalised) or [null, null] when nothing was entered and nothing is required.
function read_batch_fields($batchNo, $expiry, $required, $suffix = '') {
    $batchNo = strtoupper(trim((string)$batchNo)); $expiry = trim((string)$expiry);
    if ($batchNo === '' && $expiry === '') {
        if ($required) throw new Exception('Batch / lot number and expiry date are required' . $suffix . '.');
        return [null, null];
    }
    if ($batchNo === '' || $expiry === '') throw new Exception('Enter both the batch / lot number and the expiry date' . $suffix . '.');
    if (!preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]{0,59}$/', $batchNo)) throw new Exception('The batch / lot number' . $suffix . ' may only contain letters, numbers, spaces and . _ / -');
    $d = parse_ymd($expiry);
    if (!$d) throw new Exception('Enter a valid expiry date' . $suffix . '.');
    if ($d < date('Y-m-d')) throw new Exception('The expiry date' . $suffix . ' (' . fmt_date($d) . ') has already passed. Expired medicine cannot be received into stock.');
    if ($d > date('Y-m-d', strtotime('+' . EXPIRY_MAX_YEARS . ' years'))) throw new Exception('The expiry date' . $suffix . ' is more than ' . EXPIRY_MAX_YEARS . ' years away. Please check it.');
    return [$batchNo, $d];
}

// ---------------------------------------------------------------- suppliers

function require_active_supplier($id, $iid) {
    global $pdo;
    $st = $pdo->prepare('SELECT name,status FROM suppliers WHERE id=? AND inventory_id=?');
    $st->execute([(int)$id, (int)$iid]);
    $s = $st->fetch();
    if (!$s) throw new Exception('Supplier not found in this inventory.');
    if ($s['status'] !== 'active') throw new Exception($s['name'] . ' is inactive. Set the supplier to Active before ordering from or receiving stock from them.');
    return $s;
}

function supplier_status_badge($status) {
    return $status === 'active' ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>';
}

// Next free supplier ID inside one inventory: SUP-0001, SUP-0002 ...
function next_supplier_code($iid) {
    global $pdo;
    $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(supplier_code,5) AS UNSIGNED)),0) FROM suppliers WHERE inventory_id=? AND supplier_code LIKE 'SUP-%'");
    $st->execute([(int)$iid]);
    return sprintf('SUP-%04d', (int)$st->fetchColumn() + 1);
}

// ---------------------------------------------------------------- batches

// Adds units to the batch (creating it if new). The same batch number must always carry the same expiry date.
function upsert_batch(PDO $pdo, $iid, $pid, $supplierId, $batchNo, $expiry, $qty, $cost) {
    $st = $pdo->prepare('SELECT id,expiry_date FROM product_batches WHERE inventory_id=? AND product_id=? AND batch_no=? FOR UPDATE');
    $st->execute([$iid, $pid, $batchNo]);
    $b = $st->fetch();
    if ($b) {
        if ($b['expiry_date'] !== $expiry) {
            throw new Exception('Batch ' . $batchNo . ' already exists for this medicine with expiry ' . fmt_date($b['expiry_date']) . '. Use that expiry date, or enter a different batch number.');
        }
        $pdo->prepare('UPDATE product_batches SET qty_received=qty_received+?, qty_remaining=qty_remaining+? WHERE id=? AND inventory_id=?')->execute([$qty, $qty, $b['id'], $iid]);
        return (int)$b['id'];
    }
    $pdo->prepare('INSERT INTO product_batches (inventory_id,product_id,supplier_id,batch_no,expiry_date,qty_received,qty_remaining,unit_cost) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$iid, $pid, $supplierId ?: null, $batchNo, $expiry, $qty, $qty, $cost]);
    return (int)$pdo->lastInsertId();
}

// ---------------------------------------------------------------- stock in

// Must run inside a DB transaction. Records one delivery line: stock, batch, history row and cost price.
// Returns [reference_no, new balance].
function record_stock_in(PDO $pdo, $iid, $pid, $supplierId, $qty, $cost, $notes, $batchNo = null, $expiry = null, $poId = null, $poItemId = null) {
    $ref = make_ref('SI');
    $new = apply_stock($pdo, $iid, $pid, 'in', $qty, $ref);      // throws if the product is not in this inventory
    $batchId = $batchNo !== null ? upsert_batch($pdo, $iid, $pid, $supplierId, $batchNo, $expiry, $qty, $cost) : null;
    $pdo->prepare('INSERT INTO stock_in (inventory_id,reference_no,product_id,supplier_id,quantity,unit_cost,notes,user_id,batch_id,batch_no,expiry_date,po_id,po_item_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$iid, $ref, $pid, $supplierId ?: null, $qty, $cost, mb_substr((string)$notes, 0, 255), current_user()['id'] ?? null, $batchId, $batchNo, $expiry, $poId, $poItemId]);
    if ($cost > 0) $pdo->prepare('UPDATE products SET cost_price=? WHERE id=? AND inventory_id=?')->execute([$cost, $pid, $iid]);
    return [$ref, $new];
}

// ---------------------------------------------------------------- stock out

// Decides which batches a release comes from. Must run inside a transaction (it locks rows).
//   - a batch picked by the user is used as is
//   - otherwise earliest expiry first (FEFO); expired batches are skipped unless the reason is damaged / returned
//   - whatever is still needed after the batches comes from unbatched (older) stock
// Returns [[batch_id, qty], ...]  (empty for a product that has no batches, so those products behave exactly as before)
function allocate_batches(PDO $pdo, $iid, $pid, $qty, $reason, $batchId = null) {
    $st = $pdo->prepare('SELECT quantity,name FROM products WHERE id=? AND inventory_id=? FOR UPDATE');
    $st->execute([$pid, $iid]);
    $p = $st->fetch();
    if (!$p) throw new Exception('Product not found in this inventory.');
    $st = $pdo->prepare('SELECT id,batch_no,expiry_date,qty_remaining FROM product_batches WHERE inventory_id=? AND product_id=? AND qty_remaining>0 ORDER BY expiry_date, id FOR UPDATE');
    $st->execute([$iid, $pid]);
    $batches = $st->fetchAll();
    $today = date('Y-m-d');
    $writeOff = in_array($reason, ['damaged', 'returned'], true);     // these reasons may touch expired stock

    if ($batchId) {
        foreach ($batches as $b) {
            if ((int)$b['id'] !== (int)$batchId) continue;
            if (!$writeOff && $b['expiry_date'] < $today) throw new Exception('Batch ' . $b['batch_no'] . ' expired on ' . fmt_date($b['expiry_date']) . ' and cannot be sold or released. Record it as "Damaged or expired" instead.');
            if ($qty > (int)$b['qty_remaining']) throw new Exception('Batch ' . $b['batch_no'] . ' has only ' . (int)$b['qty_remaining'] . ' unit(s) left.');
            return [[(int)$b['id'], (int)$qty]];
        }
        throw new Exception('That batch has no stock left for ' . $p['name'] . '.');
    }
    if (!$batches) return [];

    $batched = 0; $expiredUnits = 0; $sellable = 0;
    foreach ($batches as $b) {
        $batched += (int)$b['qty_remaining'];
        if ($b['expiry_date'] < $today) $expiredUnits += (int)$b['qty_remaining']; else $sellable += (int)$b['qty_remaining'];
    }
    $unbatched = max(0, (int)$p['quantity'] - $batched);
    $alloc = []; $need = (int)$qty;
    foreach ($batches as $b) {
        if ($need <= 0) break;
        if (!$writeOff && $b['expiry_date'] < $today) continue;
        $take = min($need, (int)$b['qty_remaining']);
        $alloc[] = [(int)$b['id'], $take]; $need -= $take;
    }
    if ($need > $unbatched) {
        $avail = ($writeOff ? $batched : $sellable) + $unbatched;
        $msg = 'Not enough ' . ($writeOff ? '' : 'sellable ') . 'stock for ' . $p['name'] . '. Available: ' . $avail . '.';
        if (!$writeOff && $expiredUnits > 0) $msg .= ' ' . $expiredUnits . ' unit(s) are expired; record them as "Damaged or expired".';
        throw new Exception($msg);
    }
    return $alloc;
}

function apply_batch_allocations(PDO $pdo, $iid, $stockOutId, array $alloc) {
    $dec = $pdo->prepare('UPDATE product_batches SET qty_remaining=qty_remaining-? WHERE id=? AND inventory_id=? AND qty_remaining>=?');
    $log = $pdo->prepare('INSERT INTO stock_out_batches (inventory_id,stock_out_id,batch_id,quantity) VALUES (?,?,?,?)');
    foreach ($alloc as [$bid, $q]) {
        $dec->execute([$q, $bid, $iid, $q]);
        if ($dec->rowCount() !== 1) throw new Exception('The batch stock changed while saving. Please try again.');
        $log->execute([$iid, $stockOutId, $bid, $q]);
    }
}

// Must run inside a DB transaction. Records a release / sale / write-off and takes the units out of the right batches.
// Returns [reference_no, new balance].
function record_stock_out(PDO $pdo, $iid, $pid, $qty, $price, $reason, $customer, $notes, $batchId = null) {
    $ref = make_ref('SO');
    $alloc = allocate_batches($pdo, $iid, $pid, $qty, $reason, $batchId);
    $new = apply_stock($pdo, $iid, $pid, 'out', $qty, $ref);     // throws if the product is not yours or stock would go negative
    $pdo->prepare('INSERT INTO stock_out (inventory_id,reference_no,product_id,quantity,unit_price,reason,customer,notes,user_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$iid, $ref, $pid, $qty, $reason === 'sale' ? $price : 0, $reason, mb_substr((string)$customer, 0, 120), mb_substr((string)$notes, 0, 255), current_user()['id'] ?? null]);
    apply_batch_allocations($pdo, $iid, (int)$pdo->lastInsertId(), $alloc);
    return [$ref, $new];
}

// ---------------------------------------------------------------- alerts

// Batches that still have stock and are expired or inside their alert window, soonest first.
function expiry_alert_rows($iid, $limit = 0) {
    global $pdo;
    $def = expiry_default_days();
    $sql = 'SELECT b.*, p.name pname, p.sku, COALESCE(p.expiry_alert_days, ?) alert_days, s.name sname
            FROM product_batches b JOIN products p ON p.id=b.product_id LEFT JOIN suppliers s ON s.id=b.supplier_id
            WHERE b.inventory_id=? AND b.qty_remaining>0 AND b.expiry_date <= DATE_ADD(?, INTERVAL COALESCE(p.expiry_alert_days, ?) DAY)
            ORDER BY b.expiry_date, b.id' . ($limit ? ' LIMIT ' . (int)$limit : '');
    $st = $pdo->prepare($sql);
    $st->execute([$def, (int)$iid, date('Y-m-d'), $def]);
    return $st->fetchAll();
}

// ['expired' => batches, 'near' => batches, 'expired_units' => n, 'near_units' => n]
function expiry_counts($iid) {
    global $pdo;
    $def = expiry_default_days(); $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT COALESCE(SUM(b.expiry_date < ?),0) expired, COALESCE(SUM(b.expiry_date >= ?),0) near,
            COALESCE(SUM(IF(b.expiry_date < ?, b.qty_remaining, 0)),0) expired_units, COALESCE(SUM(IF(b.expiry_date >= ?, b.qty_remaining, 0)),0) near_units
        FROM product_batches b JOIN products p ON p.id=b.product_id
        WHERE b.inventory_id=? AND b.qty_remaining>0 AND b.expiry_date <= DATE_ADD(?, INTERVAL COALESCE(p.expiry_alert_days, ?) DAY)');
    $st->execute([$today, $today, $today, $today, (int)$iid, $today, $def]);
    $r = $st->fetch();
    return ['expired' => (int)$r['expired'], 'near' => (int)$r['near'], 'expired_units' => (int)$r['expired_units'], 'near_units' => (int)$r['near_units']];
}

// ---------------------------------------------------------------- purchase orders

const PO_STATUS_TONE = ['draft' => 'muted', 'ordered' => 'info', 'partial' => 'warning', 'received' => 'success', 'cancelled' => 'danger'];
const PO_STATUS_LABEL = ['draft' => 'Draft', 'ordered' => 'Ordered', 'partial' => 'Partially received', 'received' => 'Received', 'cancelled' => 'Cancelled'];
function po_badge($status) { return '<span class="badge badge-' . (PO_STATUS_TONE[$status] ?? 'muted') . '">' . e(PO_STATUS_LABEL[$status] ?? $status) . '</span>'; }
