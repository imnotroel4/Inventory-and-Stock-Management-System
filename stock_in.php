<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? 'direct'; $back = 'stock_in.php';
    try {
        if ($a === 'receive_po') {
            // ---------- receive (part of) a purchase order: one stock-in line per item, each with its own batch and expiry ----------
            $poId = (int)($_POST['po_id'] ?? 0); $back = 'stock_in.php?po=' . $poId;
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND inventory_id=? FOR UPDATE'); $st->execute([$poId, $iid]); $po = $st->fetch();
            if (!$po) throw new Exception('Purchase order not found in this inventory.');
            if (!in_array($po['status'], ['ordered', 'partial'], true)) throw new Exception('Purchase order ' . $po['po_number'] . ' is not open for receiving (status: ' . (PO_STATUS_LABEL[$po['status']] ?? $po['status']) . ').');
            $st = $pdo->prepare('SELECT i.*, p.name pname, p.track_expiry FROM purchase_order_items i JOIN products p ON p.id=i.product_id WHERE i.po_id=? AND i.inventory_id=? FOR UPDATE'); $st->execute([$poId, $iid]);
            $note = trim($_POST['notes'] ?? ''); $refs = []; $units = 0;
            foreach ($st->fetchAll() as $it) {
                $in = $_POST['line'][$it['id']] ?? []; $q = (int)($in['qty'] ?? 0);
                if ($q <= 0) continue;
                $left = (int)$it['quantity'] - (int)$it['qty_received'];
                if ($q > $left) throw new Exception($it['pname'] . ': only ' . $left . ' unit(s) are still to be received on this order.');
                [$bn, $ex] = read_batch_fields($in['batch_no'] ?? '', $in['expiry_date'] ?? '', (bool)$it['track_expiry'], ' for ' . $it['pname']);
                $cost = isset($in['unit_cost']) && $in['unit_cost'] !== '' ? max(0, (float)$in['unit_cost']) : (float)$it['unit_cost'];
                [$ref] = record_stock_in($pdo, $iid, (int)$it['product_id'], (int)$po['supplier_id'], $q, $cost, 'PO ' . $po['po_number'] . ($note !== '' ? ': ' . $note : ''), $bn, $ex, $poId, (int)$it['id']);
                $pdo->prepare('UPDATE purchase_order_items SET qty_received=qty_received+? WHERE id=? AND inventory_id=?')->execute([$q, $it['id'], $iid]);
                $refs[] = $ref; $units += $q;
            }
            if (!$units) throw new Exception('Enter a received quantity for at least one item.');
            $open = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(quantity-qty_received,0)),0) FROM purchase_order_items WHERE po_id=? AND inventory_id=?'); $open->execute([$poId, $iid]);
            $newStatus = (int)$open->fetchColumn() === 0 ? 'received' : 'partial';
            $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=? AND inventory_id=?')->execute([$newStatus, $poId, $iid]);
            $pdo->commit();
            log_activity('po_receive', $po['po_number'] . ': +' . $units . ' units (' . count($refs) . ' lines)');
            flash('Received ' . $units . ' unit(s) against ' . $po['po_number'] . '. ' . ($newStatus === 'received' ? 'The order is now complete.' : 'The order is partially received.'));
            if ($newStatus === 'received') $back = 'po_view.php?id=' . $poId;
        } else {
            // ---------- direct delivery (no purchase order) ----------
            $pid = (int)($_POST['product_id'] ?? 0); $qty = (int)($_POST['quantity'] ?? 0); $cost = max(0, (float)($_POST['unit_cost'] ?? 0));
            $sup = (int)($_POST['supplier_id'] ?? 0) ?: null;
            if (!$pid || $qty < 1) throw new Exception('Choose a product and enter a quantity of at least 1.');
            $pr = $pdo->prepare('SELECT track_expiry FROM products WHERE id=? AND inventory_id=?'); $pr->execute([$pid, $iid]); $pr = $pr->fetch();
            if (!$pr) throw new Exception('Product not found in this inventory.');
            $track = (bool)$pr['track_expiry'];
            if ($sup) require_active_supplier($sup, $iid);   // the supplier must belong to this inventory and be active
            elseif ($track) throw new Exception('Choose the supplier of this medicine so every batch can be traced back to its source.');
            [$bn, $ex] = read_batch_fields($_POST['batch_no'] ?? '', $_POST['expiry_date'] ?? '', $track);
            $pdo->beginTransaction();
            [$ref, $new] = record_stock_in($pdo, $iid, $pid, $sup, $qty, $cost, trim($_POST['notes'] ?? ''), $bn, $ex);
            $pdo->commit();
            log_activity('stock_in', "$ref: +$qty (product ID $pid)" . ($bn ? " batch $bn" : ''));
            flash("Stock in recorded ($ref). New balance: $new.");
        }
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'danger'); }
    redirect($back);
}

page_start('Stock in', 'stock_in');
$mode = (isset($_GET['po']) || ($_GET['mode'] ?? '') === 'po') ? 'po' : 'direct';
$products = $pdo->prepare('SELECT id,sku,name,quantity,cost_price,track_expiry FROM products WHERE inventory_id=? ORDER BY name'); $products->execute([$iid]); $products = $products->fetchAll();
$suppliers = $pdo->prepare("SELECT id,supplier_code,name FROM suppliers WHERE inventory_id=? AND status='active' ORDER BY name"); $suppliers->execute([$iid]); $suppliers = $suppliers->fetchAll();
$pre = (int)($_GET['product'] ?? 0);
$hist = $pdo->prepare('SELECT i.*,p.name pname,p.sku,s.name sname,u.full_name,po.po_number FROM stock_in i JOIN products p ON p.id=i.product_id LEFT JOIN suppliers s ON s.id=i.supplier_id LEFT JOIN users u ON u.id=i.user_id LEFT JOIN purchase_orders po ON po.id=i.po_id WHERE i.inventory_id=? ORDER BY i.id DESC LIMIT 100'); $hist->execute([$iid]); $hist = $hist->fetchAll();

$po = null; $poItems = []; $openPos = [];
if ($mode === 'po') {
    $st = $pdo->prepare("SELECT po.id,po.po_number,po.status,s.name sname FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.inventory_id=? AND po.status IN ('ordered','partial') ORDER BY po.id DESC"); $st->execute([$iid]); $openPos = $st->fetchAll();
    if (!empty($_GET['po'])) {
        $st = $pdo->prepare("SELECT po.*, s.name sname, s.supplier_code FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=? AND po.inventory_id=? AND po.status IN ('ordered','partial')"); $st->execute([(int)$_GET['po'], $iid]); $po = $st->fetch() ?: null;
        if ($po) { $st = $pdo->prepare('SELECT i.*, p.name pname, p.sku, p.track_expiry FROM purchase_order_items i JOIN products p ON p.id=i.product_id WHERE i.po_id=? AND i.inventory_id=? ORDER BY p.name'); $st->execute([$po['id'], $iid]); $poItems = $st->fetchAll(); }
    }
}

$history = function () use ($hist) { ?>
  <div class="panel"><div class="panel-head"><h2>Stock-in history</h2><input class="form-control form-control-sm" style="width:200px" placeholder="Filter history" data-filter="#inTable"></div>
    <div class="table-responsive"><table class="table table-hover" id="inTable">
      <thead><tr><th>Date</th><th>Reference</th><th>Product</th><th>Supplier</th><th>Batch / expiry</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th><th>By</th></tr></thead><tbody>
      <?php foreach ($hist as $h): ?><tr><td><?= date('M d, Y h:i A', strtotime($h['created_at'])) ?></td>
        <td><?= e($h['reference_no']) ?><?php if ($h['po_number']): ?><div class="small text-secondary"><?= e($h['po_number']) ?></div><?php endif; ?></td>
        <td><?= e($h['pname']) ?></td><td><?= e($h['sname'] ?? '—') ?></td>
        <td><?php if ($h['batch_no']): ?><span class="fw-medium"><?= e($h['batch_no']) ?></span><div class="small text-secondary">Exp <?= fmt_date($h['expiry_date']) ?></div><?php else: ?>—<?php endif; ?></td>
        <td class="text-end">+<?= (int)$h['quantity'] ?></td><td class="text-end"><?= money($h['unit_cost']) ?></td><td><?= e($h['full_name'] ?? '—') ?></td></tr>
      <?php endforeach; if (!$hist): ?><tr><td colspan="8" class="empty">No deliveries recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php };
?>
<div class="btn-group mb-3 no-print">
  <a class="btn btn-<?= $mode === 'direct' ? 'dark' : 'outline-secondary' ?>" href="stock_in.php"><i class="bi bi-box-arrow-in-down me-1"></i>Direct delivery</a>
  <a class="btn btn-<?= $mode === 'po' ? 'dark' : 'outline-secondary' ?>" href="stock_in.php?mode=po"><i class="bi bi-receipt me-1"></i>Against a purchase order</a>
</div>

<?php if ($mode === 'direct'): ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="panel"><div class="panel-head"><h2>Record delivery</h2></div>
    <form method="post" class="panel-body"><?= csrf_field() ?><input type="hidden" name="action" value="direct">
      <label class="form-label">Product</label>
      <select class="form-select mb-1" name="product_id" id="productSelect" required><option value="">Select a product</option>
        <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-qty="<?= $p['quantity'] ?>" data-price="<?= $p['cost_price'] ?>" data-track="<?= (int)$p['track_expiry'] ?>" <?= $pre === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option><?php endforeach; ?></select>
      <div class="small text-secondary mb-3" id="available"></div>
      <label class="form-label">Supplier <span class="small text-secondary fw-normal" id="supNote">(optional)</span></label>
      <select class="form-select mb-3" name="supplier_id" id="supplierSelect"><option value="">No supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['supplier_code']) ?>)</option><?php endforeach; ?></select>
      <div class="d-none" data-batch-fields>
        <div class="row g-3 mb-3"><div class="col-6"><label class="form-label">Batch / lot no.</label><input class="form-control" name="batch_no" maxlength="60" placeholder="e.g. B2406-A"></div>
          <div class="col-6"><label class="form-label">Expiry date</label><input class="form-control" type="date" name="expiry_date" min="<?= date('Y-m-d') ?>"></div></div>
      </div>
      <div class="row g-3 mb-3"><div class="col-6"><label class="form-label">Quantity received</label><input class="form-control" type="number" min="1" name="quantity" id="qty" required></div>
        <div class="col-6"><label class="form-label">Unit cost</label><input class="form-control" type="number" step="0.01" min="0" name="unit_cost" id="unitPrice" value="0"></div></div>
      <label class="form-label">Notes</label><input class="form-control mb-3" name="notes" maxlength="255" placeholder="Invoice or delivery receipt no.">
      <button class="btn btn-primary w-100">Add to inventory</button>
      <?php if (!$suppliers): ?><div class="small text-secondary mt-2">No active suppliers yet. <a href="suppliers.php">Add a supplier</a> to trace where each medicine came from.</div><?php endif; ?>
    </form></div></div>
  <div class="col-lg-8"><?php $history(); ?></div>
</div>
<script>
(() => {
  const sel = document.getElementById('productSelect'), box = document.querySelector('[data-batch-fields]'), sup = document.getElementById('supplierSelect'), note = document.getElementById('supNote');
  const sync = () => {
    const o = sel.selectedOptions[0], tracked = !!o && o.dataset.track === '1';
    box.classList.toggle('d-none', !tracked);
    box.querySelectorAll('input').forEach(i => { i.required = tracked; if (!tracked) i.value = ''; });
    sup.required = tracked; note.textContent = tracked ? '(required for medicines)' : '(optional)';
  };
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php else: ?>
<div class="panel mb-3">
  <div class="panel-head"><h2>Receive against a purchase order</h2>
    <form method="get" class="d-flex gap-2"><select class="form-select form-select-sm" style="min-width:300px" name="po" onchange="this.form.submit()" aria-label="Purchase order">
      <option value="">Select an open purchase order</option>
      <?php foreach ($openPos as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $po && (int)$po['id'] === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['po_number']) ?> · <?= e($o['sname']) ?> · <?= e(PO_STATUS_LABEL[$o['status']]) ?></option><?php endforeach; ?></select></form></div>
  <?php if (!empty($_GET['po']) && !$po): ?>
    <div class="panel-body"><div class="alert alert-warning mb-0">That purchase order is not open for receiving. It may be complete, cancelled, or still a draft.</div></div>
  <?php elseif (!$po): ?>
    <div class="panel-body text-secondary"><?= $openPos ? 'Pick an order above to enter what arrived.' : 'There are no open purchase orders.' ?> <a href="purchase_orders.php">Go to purchase orders</a>.</div>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="receive_po"><input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
      <div class="panel-body pt-0"><div class="small text-secondary"><strong class="text-body"><?= e($po['po_number']) ?></strong> · <?= e($po['sname']) ?> (<?= e($po['supplier_code']) ?>) · ordered <?= fmt_date($po['order_date']) ?><?= $po['expected_date'] ? ' · expected ' . fmt_date($po['expected_date']) : '' ?> · <?= po_badge($po['status']) ?></div></div>
      <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Medicine</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th style="width:110px">Receive now</th><th style="width:170px">Batch / lot no.</th><th style="width:160px">Expiry date</th><th style="width:120px">Unit cost</th></tr></thead><tbody>
        <?php foreach ($poItems as $it): $left = (int)$it['quantity'] - (int)$it['qty_received']; $k = (int)$it['id']; ?>
          <tr><td class="fw-medium"><?= e($it['pname']) ?><div class="small text-secondary"><?= e($it['sku']) ?><?= $it['track_expiry'] ? ' · batch &amp; expiry tracked' : '' ?></div></td>
            <td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= (int)$it['qty_received'] ?></td><td class="text-end fw-medium"><?= $left ?></td>
            <?php if ($left <= 0): ?><td colspan="4" class="text-secondary small">Fully received</td>
            <?php else: ?>
            <td><input class="form-control form-control-sm" type="number" min="0" max="<?= $left ?>" name="line[<?= $k ?>][qty]" data-left="<?= $left ?>" placeholder="0" aria-label="Quantity received for <?= e($it['pname']) ?>"></td>
            <td><?php if ($it['track_expiry']): ?><input class="form-control form-control-sm" name="line[<?= $k ?>][batch_no]" maxlength="60" placeholder="Batch / lot" aria-label="Batch number for <?= e($it['pname']) ?>"><?php else: ?><span class="text-secondary small">Not tracked</span><?php endif; ?></td>
            <td><?php if ($it['track_expiry']): ?><input class="form-control form-control-sm" type="date" min="<?= date('Y-m-d') ?>" name="line[<?= $k ?>][expiry_date]" aria-label="Expiry date for <?= e($it['pname']) ?>"><?php else: ?><span class="text-secondary small">—</span><?php endif; ?></td>
            <td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="line[<?= $k ?>][unit_cost]" value="<?= e($it['unit_cost']) ?>" aria-label="Unit cost for <?= e($it['pname']) ?>"></td>
            <?php endif; ?></tr>
        <?php endforeach; ?></tbody></table></div>
      <div class="panel-body d-flex flex-wrap gap-2 align-items-end">
        <div class="flex-grow-1" style="min-width:240px"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="200" placeholder="Invoice or delivery receipt no."></div>
        <button type="button" class="btn btn-light" id="fillAll">Fill all remaining</button>
        <button class="btn btn-primary">Receive stock</button>
        <a class="btn btn-light" href="po_view.php?id=<?= (int)$po['id'] ?>">View order</a>
      </div>
    </form>
    <script>document.getElementById('fillAll').addEventListener('click', () => document.querySelectorAll('input[data-left]').forEach(i => i.value = i.dataset.left));</script>
  <?php endif; ?>
</div>
<?php $history(); ?>
<?php endif; ?>
<?php page_end();
