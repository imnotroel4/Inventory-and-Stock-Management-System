<?php
// One purchase order: details, items, what has been received (with batch and expiry), and the actions that fit its status.
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $go = 'po_view.php?id=' . $id;
    try {
        $a = $_POST['action'] ?? '';
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND inventory_id=? FOR UPDATE'); $st->execute([$id, $iid]); $po = $st->fetch();
        if (!$po) throw new Exception('Purchase order not found in this inventory.');
        $st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(qty_received),0) r FROM purchase_order_items WHERE po_id=? AND inventory_id=?'); $st->execute([$id, $iid]); $sum = $st->fetch();
        $set = fn($status) => $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=? AND inventory_id=?')->execute([$status, $id, $iid]);
        if ($a === 'place') {
            if ($po['status'] !== 'draft') throw new Exception('Only a draft can be placed.');
            if (!(int)$sum['n']) throw new Exception('Add items to the order before placing it.');
            require_active_supplier($po['supplier_id'], $iid);
            $set('ordered'); log_activity('po_status', $po['po_number'] . ' placed'); flash($po['po_number'] . ' has been placed with the supplier.');
        } elseif ($a === 'cancel') {
            if (!in_array($po['status'], ['draft', 'ordered'], true) || (int)$sum['r'] > 0) throw new Exception('An order that already has received stock cannot be cancelled. Close it instead.');
            $set('cancelled'); log_activity('po_status', $po['po_number'] . ' cancelled'); flash($po['po_number'] . ' was cancelled.', 'warning');
        } elseif ($a === 'close') {
            if ($po['status'] !== 'partial') throw new Exception('Only a partially received order can be closed.');
            $set('received'); log_activity('po_status', $po['po_number'] . ' closed'); flash($po['po_number'] . ' was closed. The remaining units will not be received.');
        } elseif ($a === 'delete') {
            if ($po['status'] !== 'draft') throw new Exception('Only drafts can be deleted. Cancel the order instead.');
            $pdo->prepare('DELETE FROM purchase_orders WHERE id=? AND inventory_id=?')->execute([$id, $iid]);   // its items go with it
            log_activity('po_delete', $po['po_number']); flash($po['po_number'] . ' was deleted.'); $go = 'purchase_orders.php';
        } else throw new Exception('Unknown action.');
        $pdo->commit();
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'danger'); }
    redirect($go);
}

$st = $pdo->prepare('SELECT po.*, s.name sname, s.supplier_code, s.contact_person, s.phone, s.email, s.address, s.status sstatus, u.full_name made_by
    FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN users u ON u.id=po.user_id WHERE po.id=? AND po.inventory_id=?');
$st->execute([$id, $iid]); $po = $st->fetch();
if (!$po) { flash('Purchase order not found.', 'warning'); redirect('purchase_orders.php'); }
page_start($po['po_number'], 'purchase_orders');
$st = $pdo->prepare('SELECT i.*, p.name pname, p.sku, p.track_expiry FROM purchase_order_items i JOIN products p ON p.id=i.product_id WHERE i.po_id=? AND i.inventory_id=? ORDER BY i.id'); $st->execute([$id, $iid]); $items = $st->fetchAll();
$st = $pdo->prepare('SELECT t.*, p.name pname FROM stock_in t JOIN products p ON p.id=t.product_id WHERE t.po_id=? AND t.inventory_id=? ORDER BY t.id'); $st->execute([$id, $iid]); $receipts = $st->fetchAll();
$tq = 0; $tr = 0; $tv = 0; foreach ($items as $it) { $tq += $it['quantity']; $tr += $it['qty_received']; $tv += $it['quantity'] * $it['unit_cost']; }
$stt = $po['status'];
$act = fn($action, $label, $cls, $confirm) => '<form method="post" class="d-inline" data-confirm="' . e($confirm) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$po['id'] . '"><input type="hidden" name="action" value="' . $action . '"><button class="btn ' . $cls . '">' . $label . '</button></form>';
?>
<div class="mb-3 no-print d-flex flex-wrap gap-2 align-items-center">
  <a class="btn btn-light" href="purchase_orders.php"><i class="bi bi-arrow-left"></i> All orders</a>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($stt === 'draft'): ?>
      <a class="btn btn-light" href="po_form.php?id=<?= (int)$po['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
      <?= $act('place', 'Place order', 'btn-primary', 'Place this order with ' . $po['sname'] . '? It can no longer be edited.') ?>
      <?= $act('delete', '<i class="bi bi-trash"></i>', 'btn-light text-danger', 'Delete this draft?') ?>
    <?php elseif ($stt === 'ordered' || $stt === 'partial'): ?>
      <a class="btn btn-primary" href="stock_in.php?po=<?= (int)$po['id'] ?>"><i class="bi bi-box-arrow-in-down"></i> Receive stock</a>
      <?php if ($stt === 'ordered') echo $act('cancel', 'Cancel order', 'btn-light text-danger', 'Cancel this purchase order?'); else echo $act('close', 'Close order', 'btn-light', 'Close this order? The units that have not arrived will not be received.'); ?>
    <?php endif; ?>
    <button class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-body pt-4"><div class="print-title"><strong><?= e(site_name()) ?></strong> · <?= e($inv['name']) ?></div>
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><h2 class="h4 mb-0">Purchase order <?= e($po['po_number']) ?></h2><?= po_badge($stt) ?></div>
    <div class="row g-3 small">
      <div class="col-md-4"><div class="text-secondary">Supplier</div><div class="fw-medium"><?= e($po['sname']) ?> <span class="text-secondary">(<?= e($po['supplier_code']) ?>)</span><?= $po['sstatus'] === 'inactive' ? ' <span class="badge badge-muted">Inactive</span>' : '' ?></div>
        <div><?= e($po['contact_person']) ?><?= $po['phone'] ? ' · ' . e($po['phone']) : '' ?></div><div><?= e($po['email']) ?></div><div class="text-secondary"><?= e($po['address']) ?></div></div>
      <div class="col-md-2 col-6"><div class="text-secondary">Order date</div><div class="fw-medium"><?= fmt_date($po['order_date']) ?></div></div>
      <div class="col-md-2 col-6"><div class="text-secondary">Expected delivery</div><div class="fw-medium"><?= fmt_date($po['expected_date']) ?></div></div>
      <div class="col-md-2 col-6"><div class="text-secondary">Prepared by</div><div class="fw-medium"><?= e($po['made_by'] ?? '—') ?></div></div>
      <div class="col-md-2 col-6"><div class="text-secondary">Received</div><div class="fw-medium"><?= (int)$tr ?> of <?= (int)$tq ?> units</div></div>
      <?php if ($po['notes']): ?><div class="col-12"><div class="text-secondary">Notes</div><div><?= e($po['notes']) ?></div></div><?php endif; ?>
    </div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h2>Items</h2></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Medicine / product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th class="text-end">Unit cost</th><th class="text-end">Line total</th></tr></thead><tbody>
    <?php foreach ($items as $it): $left = max(0, $it['quantity'] - $it['qty_received']); ?>
      <tr><td class="fw-medium"><?= e($it['pname']) ?><div class="small text-secondary"><?= e($it['sku']) ?><?= $it['track_expiry'] ? ' · batch &amp; expiry tracked' : '' ?></div></td>
        <td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= (int)$it['qty_received'] ?></td><td class="text-end"><?= $left ?></td>
        <td class="text-end"><?= money($it['unit_cost']) ?></td><td class="text-end"><?= money($it['quantity'] * $it['unit_cost']) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= (int)$tq ?></td><td class="text-end"><?= (int)$tr ?></td><td class="text-end"><?= max(0, $tq - $tr) ?></td><td></td><td class="text-end"><?= money($tv) ?></td></tr></tfoot>
  </table></div>
</div>

<div class="panel">
  <div class="panel-head"><h2>Deliveries received</h2></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Date</th><th>Reference</th><th>Medicine</th><th>Batch / lot</th><th>Expiry date</th><th class="text-end">Quantity</th></tr></thead><tbody>
    <?php foreach ($receipts as $r): ?>
      <tr><td><?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></td><td><?= e($r['reference_no']) ?></td><td><?= e($r['pname']) ?></td><td><?= e($r['batch_no'] ?? '—') ?></td>
        <td><?= $r['expiry_date'] ? fmt_date($r['expiry_date']) : '—' ?></td><td class="text-end">+<?= (int)$r['quantity'] ?></td></tr>
    <?php endforeach; if (!$receipts): ?><tr><td colspan="6" class="empty">Nothing has been received against this order yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php page_end();
