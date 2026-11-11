<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $pid = (int)($_POST['product_id'] ?? 0); $qty = (int)($_POST['quantity'] ?? 0); $price = max(0, (float)($_POST['unit_price'] ?? 0));
        $reason = in_array($_POST['reason'] ?? '', ['sale', 'release', 'damaged', 'returned'], true) ? $_POST['reason'] : 'sale';
        $batchId = (int)($_POST['batch_id'] ?? 0) ?: null;
        if (!$pid || $qty < 1) throw new Exception('Choose a product and enter a quantity of at least 1.');
        $pdo->beginTransaction();
        // takes the units from the right batches (earliest expiry first unless one was picked) and throws if the product is not yours or stock would go negative
        [$ref, $new] = record_stock_out($pdo, $iid, $pid, $qty, $price, $reason, trim($_POST['customer'] ?? ''), trim($_POST['notes'] ?? ''), $batchId);
        $pdo->commit();
        log_activity('stock_out', "$ref: −$qty $reason (product ID $pid)");
        $m = $pdo->prepare('SELECT name,min_stock FROM products WHERE id=? AND inventory_id=?'); $m->execute([$pid, $iid]); $m = $m->fetch();
        if ($new <= 0) flash("Recorded ($ref). {$m['name']} is now out of stock.", 'warning');
        elseif ($new <= $m['min_stock']) flash("Recorded ($ref). {$m['name']} is low: $new left, minimum {$m['min_stock']}.", 'warning');
        else flash("Stock out recorded ($ref). New balance: $new.");
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'danger'); }
    redirect('stock_out.php');
}
page_start('Stock out', 'stock_out');
$products = $pdo->prepare('SELECT id,sku,name,quantity,selling_price FROM products WHERE inventory_id=? AND quantity>0 ORDER BY name'); $products->execute([$iid]); $products = $products->fetchAll();
$hist = $pdo->prepare('SELECT o.*,p.name pname,u.full_name,
    (SELECT GROUP_CONCAT(CONCAT(b.batch_no, \' ×\', sb.quantity) ORDER BY b.expiry_date SEPARATOR \', \') FROM stock_out_batches sb JOIN product_batches b ON b.id=sb.batch_id WHERE sb.stock_out_id=o.id) batches
    FROM stock_out o JOIN products p ON p.id=o.product_id LEFT JOIN users u ON u.id=o.user_id WHERE o.inventory_id=? ORDER BY o.id DESC LIMIT 100'); $hist->execute([$iid]); $hist = $hist->fetchAll();
$tone = ['sale' => 'success', 'release' => 'info', 'damaged' => 'danger', 'returned' => 'muted'];
// batches that still have stock, grouped by product, for the batch picker
$bq = $pdo->prepare('SELECT id,product_id,batch_no,expiry_date,qty_remaining FROM product_batches WHERE inventory_id=? AND qty_remaining>0 ORDER BY expiry_date,id'); $bq->execute([$iid]);
$batchMap = []; $today = date('Y-m-d');
foreach ($bq as $b) $batchMap[(int)$b['product_id']][] = ['id' => (int)$b['id'], 'no' => $b['batch_no'], 'exp' => fmt_date($b['expiry_date']), 'qty' => (int)$b['qty_remaining'], 'expired' => $b['expiry_date'] < $today];
?>
<div class="row g-3">
  <div class="col-lg-4"><div class="panel"><div class="panel-head"><h2>Record release or sale</h2></div>
    <form method="post" class="panel-body"><?= csrf_field() ?>
      <label class="form-label">Product</label>
      <select class="form-select mb-1" name="product_id" id="productSelect" required><option value="">Select a product</option>
        <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-qty="<?= $p['quantity'] ?>" data-price="<?= $p['selling_price'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option><?php endforeach; ?></select>
      <div class="small text-secondary mb-3" id="available">Only products with stock are listed.</div>
      <div class="d-none mb-3" id="batchBox"><label class="form-label">Batch / lot</label><select class="form-select" name="batch_id" id="batchSelect"></select><div class="small text-secondary mt-1" id="batchNote"></div></div>
      <div class="row g-3 mb-3"><div class="col-6"><label class="form-label">Reason</label><select class="form-select" name="reason" id="reasonSelect"><option value="sale">Sale</option><option value="release">Released for use</option><option value="damaged">Damaged or expired</option><option value="returned">Returned to supplier</option></select></div>
        <div class="col-6"><label class="form-label">Quantity</label><input class="form-control" type="number" min="1" name="quantity" id="qty" data-max="1" required></div></div>
      <label class="form-label">Unit price (sales only)</label><input class="form-control mb-3" type="number" step="0.01" min="0" name="unit_price" id="unitPrice" value="0">
      <label class="form-label">Customer or recipient</label><input class="form-control mb-3" name="customer" maxlength="120">
      <label class="form-label">Notes</label><input class="form-control mb-3" name="notes" maxlength="255">
      <button class="btn btn-primary w-100">Deduct from inventory</button>
    </form></div></div>
  <div class="col-lg-8"><div class="panel"><div class="panel-head"><h2>Stock-out history</h2><input class="form-control form-control-sm" style="width:200px" placeholder="Filter history" data-filter="#outTable"></div>
    <div class="table-responsive"><table class="table table-hover" id="outTable">
      <thead><tr><th>Date</th><th>Reference</th><th>Product</th><th>Batch</th><th>Reason</th><th class="text-end">Qty</th><th class="text-end">Total</th><th>To</th><th>By</th></tr></thead><tbody>
      <?php foreach ($hist as $h): ?><tr><td><?= date('M d, Y h:i A', strtotime($h['created_at'])) ?></td><td><?= e($h['reference_no']) ?></td><td><?= e($h['pname']) ?></td><td class="small"><?= e($h['batches'] ?? '—') ?></td>
        <td><span class="badge badge-<?= $tone[$h['reason']] ?>"><?= ucfirst($h['reason']) ?></span></td><td class="text-end">−<?= $h['quantity'] ?></td><td class="text-end"><?= $h['reason'] === 'sale' ? money($h['quantity'] * $h['unit_price']) : '—' ?></td><td><?= e($h['customer'] ?: '—') ?></td><td><?= e($h['full_name'] ?? '—') ?></td></tr>
      <?php endforeach; if (!$hist): ?><tr><td colspan="9" class="empty">No stock-out records yet.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
<script>
(() => {
  const BATCHES = <?= json_encode($batchMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const sel = document.getElementById('productSelect'), box = document.getElementById('batchBox'), bs = document.getElementById('batchSelect'),
        note = document.getElementById('batchNote'), reason = document.getElementById('reasonSelect'), qty = document.getElementById('qty');
  const hint = () => {
    const list = BATCHES[sel.value] || [], writeOff = reason.value === 'damaged' || reason.value === 'returned';
    const anyExpired = list.some(b => b.expired);
    note.textContent = !list.length ? '' : writeOff ? 'Write-offs and returns can use expired batches. Earliest expiry is used first.'
      : anyExpired ? 'Expired batches are skipped and cannot be sold. Use "Damaged or expired" to write them off.' : 'Earliest expiry is used first unless you pick a batch.';
  };
  const render = () => {
    const list = BATCHES[sel.value] || [];
    bs.innerHTML = ''; bs.add(new Option('Automatic: earliest expiry first', ''));
    list.forEach(b => bs.add(new Option(b.no + ' · exp ' + b.exp + ' · ' + b.qty + ' left' + (b.expired ? ' · EXPIRED' : ''), b.id)));
    box.classList.toggle('d-none', !list.length); hint();
  };
  sel.addEventListener('change', render);
  reason.addEventListener('change', hint);
  bs.addEventListener('change', () => {
    const b = (BATCHES[sel.value] || []).find(x => String(x.id) === bs.value), o = sel.selectedOptions[0];
    qty.max = b ? b.qty : (o && o.dataset.qty ? o.dataset.qty : '');
  });
  render();
})();
</script>
<?php page_end();
