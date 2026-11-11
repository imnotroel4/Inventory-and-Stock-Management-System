<?php
// Create a purchase order, or edit one that is still a draft.
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $supplier = (int)($_POST['supplier_id'] ?? 0);
        if (!$supplier) throw new Exception('Choose a supplier.');
        require_active_supplier($supplier, $iid);                       // must be yours and Active
        $orderDate = parse_ymd($_POST['order_date'] ?? ''); if (!$orderDate) throw new Exception('Enter a valid order date.');
        $expected = trim($_POST['expected_date'] ?? '') === '' ? null : parse_ymd($_POST['expected_date']);
        if (trim($_POST['expected_date'] ?? '') !== '' && !$expected) throw new Exception('Enter a valid expected delivery date.');
        if ($expected && $expected < $orderDate) throw new Exception('The expected delivery date cannot be before the order date.');
        $notes = trim($_POST['notes'] ?? ''); if (mb_strlen($notes) > 255) throw new Exception('Notes can be up to 255 characters.');
        $items = [];
        foreach ((array)($_POST['items'] ?? []) as $row) {
            $pid = (int)($row['product_id'] ?? 0); $qty = (int)($row['quantity'] ?? 0); $cost = (float)($row['unit_cost'] ?? 0);
            if (!$pid && !$qty) continue;                                // a blank line is ignored
            if (!$pid || $qty < 1) throw new Exception('Every line needs a medicine and a quantity of at least 1.');
            if ($qty > 1000000) throw new Exception('A quantity of more than 1,000,000 on one line is probably a typing mistake.');
            if ($cost < 0 || $cost > 99999999) throw new Exception('Unit cost must be between 0 and 99,999,999.');
            require_owned('products', $pid, $iid, 'Product');
            if (isset($items[$pid])) { $items[$pid]['qty'] += $qty; $items[$pid]['cost'] = $cost; }   // the same medicine twice becomes one line
            else $items[$pid] = ['qty' => $qty, 'cost' => $cost];
        }
        if (!$items) throw new Exception('Add at least one item to the purchase order.');
        $status = ($_POST['mode'] ?? '') === 'order' ? 'ordered' : 'draft';

        $pdo->beginTransaction();
        if ($editId) {
            $st = $pdo->prepare('SELECT po_number,status FROM purchase_orders WHERE id=? AND inventory_id=? FOR UPDATE'); $st->execute([$editId, $iid]); $po = $st->fetch();
            if (!$po) throw new Exception('Purchase order not found in this inventory.');
            if ($po['status'] !== 'draft') throw new Exception('Only draft purchase orders can be edited.');
            $pdo->prepare('UPDATE purchase_orders SET supplier_id=?,order_date=?,expected_date=?,notes=?,status=? WHERE id=? AND inventory_id=?')->execute([$supplier, $orderDate, $expected, $notes, $status, $editId, $iid]);
            $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id=? AND inventory_id=?')->execute([$editId, $iid]);
            $poId = $editId; $number = $po['po_number'];
        } else {
            $number = make_ref('PO');
            $pdo->prepare('INSERT INTO purchase_orders (inventory_id,po_number,supplier_id,order_date,expected_date,status,notes,user_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$iid, $number, $supplier, $orderDate, $expected, $status, $notes, current_user()['id']]);
            $poId = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO purchase_order_items (po_id,inventory_id,product_id,quantity,unit_cost) VALUES (?,?,?,?,?)');
        foreach ($items as $pid => $it) $ins->execute([$poId, $iid, $pid, $it['qty'], $it['cost']]);
        $pdo->commit();
        log_activity($editId ? 'po_update' : 'po_create', $number . ' (' . $status . ', ' . count($items) . ' items)');
        flash($number . ($editId ? ' updated' : ' created') . ($status === 'ordered' ? ' and placed with the supplier.' : ' as a draft.'));
        redirect('po_view.php?id=' . $poId);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'danger'); redirect('po_form.php' . ($editId ? '?id=' . $editId : '')); }
}

// ---------- show the form ----------
$po = null; $lines = [];
if ($editId) {
    $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND inventory_id=?'); $st->execute([$editId, $iid]); $po = $st->fetch();
    if (!$po || $po['status'] !== 'draft') { flash($po ? 'Only draft purchase orders can be edited.' : 'Purchase order not found.', 'warning'); redirect($po ? 'po_view.php?id=' . $editId : 'purchase_orders.php'); }
    $st = $pdo->prepare('SELECT product_id, quantity, unit_cost FROM purchase_order_items WHERE po_id=? AND inventory_id=? ORDER BY id'); $st->execute([$editId, $iid]); $lines = $st->fetchAll();
}
page_start($po ? 'Edit ' . $po['po_number'] : 'New purchase order', 'purchase_orders');

$sups = $pdo->prepare("SELECT id,name,supplier_code FROM suppliers WHERE inventory_id=? AND (status='active'" . ($po ? ' OR id=?' : '') . ') ORDER BY name');
$sups->execute($po ? [$iid, $po['supplier_id']] : [$iid]); $sups = $sups->fetchAll();
$prods = $pdo->prepare('SELECT id,sku,name,cost_price,track_expiry FROM products WHERE inventory_id=? ORDER BY name'); $prods->execute([$iid]);
$prodList = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'] . ' (' . $p['sku'] . ')', 'cost' => (float)$p['cost_price']], $prods->fetchAll());
$chosenSupplier = (int)($po['supplier_id'] ?? $_GET['supplier'] ?? 0);
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<?php if (!$sups): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> There are no active suppliers. <a href="suppliers.php">Add or activate a supplier</a> before creating a purchase order.</div><?php endif; ?>
<?php if (!$prodList): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> There are no products yet. <a href="products.php">Add your medicines</a> first.</div><?php endif; ?>
<form method="post" id="poForm"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $editId ?>">
  <div class="panel mb-3"><div class="panel-head"><h2>Order details</h2><?php if ($po): ?><span class="text-secondary small"><?= e($po['po_number']) ?> · draft</span><?php endif; ?></div>
    <div class="panel-body"><div class="row g-3">
      <div class="col-md-4"><label class="form-label">Supplier</label><select class="form-select" name="supplier_id" required><option value="">Select a supplier</option>
        <?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $chosenSupplier === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e($s['supplier_code']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-md-4 col-6"><label class="form-label">Order date</label><input class="form-control" type="date" name="order_date" value="<?= e($po['order_date'] ?? date('Y-m-d')) ?>" required></div>
      <div class="col-md-4 col-6"><label class="form-label">Expected delivery</label><input class="form-control" type="date" name="expected_date" value="<?= e($po['expected_date'] ?? '') ?>"></div>
      <div class="col-12"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="255" value="<?= e($po['notes'] ?? '') ?>" placeholder="Delivery instructions, payment terms, etc."></div>
    </div></div></div>
  <div class="panel mb-3"><div class="panel-head"><h2>Items</h2><button type="button" class="btn btn-light btn-sm" id="addLine"><i class="bi bi-plus-lg"></i> Add item</button></div>
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Medicine / product</th><th style="width:130px">Quantity</th><th style="width:150px">Unit cost</th><th class="text-end" style="width:150px">Line total</th><th style="width:50px"></th></tr></thead>
      <tbody id="lines"></tbody>
      <tfoot><tr class="fw-semibold"><td colspan="3" class="text-end">Order total</td><td class="text-end" id="grand"><?= money(0) ?></td><td></td></tr></tfoot>
    </table></div></div>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-primary" name="mode" value="order">Save &amp; place order</button>
    <button class="btn btn-light" name="mode" value="draft">Save as draft</button>
    <a class="btn btn-light" href="<?= $po ? 'po_view.php?id=' . (int)$po['id'] : 'purchase_orders.php' ?>">Cancel</a>
  </div>
</form>
<script>
(() => {
  const PRODUCTS = <?= json_encode($prodList, $flags) ?>, INITIAL = <?= json_encode($lines ?: [['product_id' => '', 'quantity' => '', 'unit_cost' => '']], $flags) ?>, CUR = <?= json_encode(CURRENCY, $flags) ?>;
  const tbody = document.getElementById('lines'), grand = document.getElementById('grand'); let n = 0;
  const fmt = v => CUR + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const total = () => { let t = 0; tbody.querySelectorAll('tr').forEach(tr => { const q = parseFloat(tr.querySelector('.q').value) || 0, c = parseFloat(tr.querySelector('.c').value) || 0; tr.querySelector('.lt').textContent = fmt(q * c); t += q * c; }); grand.textContent = fmt(t); };
  const add = (d = {}) => {
    const i = n++, tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="form-select p" name="items[' + i + '][product_id]"></select></td>' +
      '<td><input class="form-control q" type="number" min="1" max="1000000" name="items[' + i + '][quantity]" aria-label="Quantity"></td>' +
      '<td><input class="form-control c" type="number" min="0" step="0.01" name="items[' + i + '][unit_cost]" aria-label="Unit cost"></td>' +
      '<td class="text-end lt"></td><td class="text-end"><button type="button" class="btn btn-sm btn-light text-danger" aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>';
    const sel = tr.querySelector('.p'); sel.add(new Option('Select a product', ''));
    PRODUCTS.forEach(p => sel.add(new Option(p.name, p.id)));
    sel.value = d.product_id || ''; tr.querySelector('.q').value = d.quantity || ''; tr.querySelector('.c').value = d.unit_cost !== undefined && d.unit_cost !== '' ? d.unit_cost : '';
    sel.addEventListener('change', () => { const p = PRODUCTS.find(x => String(x.id) === sel.value); if (p && !tr.querySelector('.c').dataset.touched) tr.querySelector('.c').value = p.cost; total(); });
    tr.querySelector('.c').addEventListener('input', e => { e.target.dataset.touched = 1; total(); });
    tr.querySelector('.q').addEventListener('input', total);
    tr.querySelector('button').addEventListener('click', () => { if (tbody.rows.length > 1) { tr.remove(); total(); } else { sel.value = ''; tr.querySelector('.q').value = ''; tr.querySelector('.c').value = ''; total(); } });
    tbody.appendChild(tr); total();
  };
  document.getElementById('addLine').addEventListener('click', () => add());
  INITIAL.forEach(add);
})();
</script>
<?php page_end();
