<?php
// Purchase orders: what was ordered from which supplier, and how much of it has arrived.
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];
page_start('Purchase orders', 'purchase_orders');

$f = $_GET['status'] ?? 'all'; if ($f !== 'all' && !isset(PO_STATUS_LABEL[$f])) $f = 'all';
$sup = (int)($_GET['supplier'] ?? 0); $q = trim($_GET['q'] ?? '');

$cnt = $pdo->prepare('SELECT status, COUNT(*) n FROM purchase_orders WHERE inventory_id=? GROUP BY status'); $cnt->execute([$iid]);
$counts = array_fill_keys(array_keys(PO_STATUS_LABEL), 0);
foreach ($cnt as $r) $counts[$r['status']] = (int)$r['n'];
$all = array_sum($counts);

$where = 'po.inventory_id=?'; $args = [$iid];
if ($f !== 'all') { $where .= ' AND po.status=?'; $args[] = $f; }
if ($sup) { $where .= ' AND po.supplier_id=?'; $args[] = $sup; }
if ($q !== '') { $where .= ' AND (po.po_number LIKE ? OR s.name LIKE ? OR s.supplier_code LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
$st = $pdo->prepare("SELECT po.*, s.name sname, s.supplier_code,
    (SELECT COUNT(*) FROM purchase_order_items i WHERE i.po_id=po.id) items,
    (SELECT COALESCE(SUM(i.quantity),0) FROM purchase_order_items i WHERE i.po_id=po.id) qty_ordered,
    (SELECT COALESCE(SUM(i.qty_received),0) FROM purchase_order_items i WHERE i.po_id=po.id) qty_rec,
    (SELECT COALESCE(SUM(i.quantity*i.unit_cost),0) FROM purchase_order_items i WHERE i.po_id=po.id) total
    FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE $where ORDER BY po.id DESC LIMIT 300");
$st->execute($args); $rows = $st->fetchAll();
$sups = $pdo->prepare('SELECT id,name,supplier_code FROM suppliers WHERE inventory_id=? ORDER BY name'); $sups->execute([$iid]); $sups = $sups->fetchAll();
$late = 0; foreach ($rows as $r) if (in_array($r['status'], ['ordered', 'partial'], true) && $r['expected_date'] && $r['expected_date'] < date('Y-m-d')) $late++;
?>
<?php if ($late): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong><?= $late ?> open order<?= $late > 1 ? 's are' : ' is' ?> past the expected delivery date.</strong></div><?php endif; ?>
<div class="panel">
  <div class="panel-head">
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm flex-wrap">
        <a class="btn btn-<?= $f === 'all' ? 'dark' : 'outline-secondary' ?>" href="<?= e('?status=all' . ($sup ? '&supplier=' . $sup : '') . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">All (<?= $all ?>)</a>
        <?php foreach (PO_STATUS_LABEL as $k => $l): ?><a class="btn btn-<?= $f === $k ? 'dark' : 'outline-secondary' ?>" href="<?= e('?status=' . $k . ($sup ? '&supplier=' . $sup : '') . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>"><?= e($l) ?> (<?= $counts[$k] ?>)</a><?php endforeach; ?>
      </div>
      <form class="d-flex gap-2" method="get"><input type="hidden" name="status" value="<?= e($f) ?>">
        <select class="form-select form-select-sm" style="width:190px" name="supplier" onchange="this.form.submit()" aria-label="Supplier"><option value="">All suppliers</option>
          <?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sup === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
        <input class="form-control form-control-sm" style="width:170px" name="q" value="<?= e($q) ?>" placeholder="PO no. or supplier"><button class="btn btn-sm btn-light">Search</button></form>
    </div>
    <a class="btn btn-primary btn-sm" href="po_form.php"><i class="bi bi-plus-lg"></i> New purchase order</a>
  </div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>PO no.</th><th>Supplier</th><th>Order date</th><th>Expected</th><th class="text-end">Items</th><th style="width:160px">Received</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $pct = $r['qty_ordered'] > 0 ? min(100, round($r['qty_rec'] / $r['qty_ordered'] * 100)) : 0;
          $overdue = in_array($r['status'], ['ordered', 'partial'], true) && $r['expected_date'] && $r['expected_date'] < date('Y-m-d'); ?>
      <tr><td class="fw-medium"><a class="text-decoration-none" href="po_view.php?id=<?= (int)$r['id'] ?>"><?= e($r['po_number']) ?></a></td>
        <td><?= e($r['sname']) ?><div class="small text-secondary"><?= e($r['supplier_code']) ?></div></td>
        <td class="text-nowrap"><?= fmt_date($r['order_date']) ?></td>
        <td class="text-nowrap"><?= fmt_date($r['expected_date']) ?><?= $overdue ? ' <span class="badge badge-warning">Overdue</span>' : '' ?></td>
        <td class="text-end"><?= (int)$r['items'] ?></td>
        <td><div class="meter" title="<?= (int)$r['qty_rec'] ?> of <?= (int)$r['qty_ordered'] ?> units received"><span class="meter-<?= $pct >= 100 ? 'success' : 'warning' ?>" style="width:<?= $pct ?>%"></span></div><div class="small text-secondary mt-1"><?= (int)$r['qty_rec'] ?> / <?= (int)$r['qty_ordered'] ?> units</div></td>
        <td class="text-end"><?= money($r['total']) ?></td><td><?= po_badge($r['status']) ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-light" href="po_view.php?id=<?= (int)$r['id'] ?>" aria-label="View"><i class="bi bi-eye"></i></a>
          <?php if (in_array($r['status'], ['ordered', 'partial'], true)): ?><a class="btn btn-sm btn-outline-secondary" href="stock_in.php?po=<?= (int)$r['id'] ?>">Receive</a><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty"><?= $all ? 'No purchase orders match this filter.' : 'No purchase orders yet. Create one to order medicines from a supplier.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php page_end();
