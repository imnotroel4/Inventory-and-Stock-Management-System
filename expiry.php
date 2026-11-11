<?php
// Expiry tracking: every batch of every medicine, its expiry date and how much of it is left.
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (($_POST['action'] ?? '') !== 'writeoff') throw new Exception('Unknown action.');
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM product_batches WHERE id=? AND inventory_id=? FOR UPDATE'); $st->execute([(int)($_POST['batch_id'] ?? 0), $iid]); $b = $st->fetch();
        if (!$b) throw new Exception('Batch not found in this inventory.');
        if ((int)$b['qty_remaining'] < 1) throw new Exception('Batch ' . $b['batch_no'] . ' has no stock left.');
        if ($b['expiry_date'] >= date('Y-m-d')) throw new Exception('Batch ' . $b['batch_no'] . ' has not expired yet. Only expired batches can be written off here.');
        $units = (int)$b['qty_remaining'];
        [$ref, $new] = record_stock_out($pdo, $iid, (int)$b['product_id'], $units, 0, 'damaged', '', 'Expired batch write-off: ' . $b['batch_no'], (int)$b['id']);
        $pdo->commit();
        log_activity('batch_writeoff', "$ref: batch {$b['batch_no']} −$units (product ID {$b['product_id']})");
        flash("Batch {$b['batch_no']} written off: $units unit(s) removed from stock ($ref).", 'warning');
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'danger'); }
    redirect('expiry.php' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

page_start('Expiry tracking', 'expiry');
$status = $_GET['status'] ?? 'attention';
if (!in_array($status, ['attention', 'expired', 'near', 'ok', 'all', 'empty'], true)) $status = 'attention';
$q = trim($_GET['q'] ?? '');

$sql = 'SELECT b.*, p.name pname, p.sku, p.expiry_alert_days, s.name sname, s.supplier_code
        FROM product_batches b JOIN products p ON p.id=b.product_id LEFT JOIN suppliers s ON s.id=b.supplier_id
        WHERE b.inventory_id=? AND b.qty_remaining' . ($status === 'empty' ? '=0' : '>0'); $args = [$iid];
if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR b.batch_no LIKE ? OR s.name LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
$st = $pdo->prepare($sql . ' ORDER BY ' . ($status === 'empty' ? 'b.expiry_date DESC' : 'b.expiry_date ASC') . ', b.id LIMIT 1000'); $st->execute($args); $batches = $st->fetchAll();

// counts always come from the in-stock batches, whatever filter is showing
$sum = ['expired' => 0, 'near' => 0, 'ok' => 0, 'expired_units' => 0, 'near_units' => 0, 'd30' => 0];
$all = $pdo->prepare('SELECT b.expiry_date, b.qty_remaining, p.expiry_alert_days FROM product_batches b JOIN products p ON p.id=b.product_id WHERE b.inventory_id=? AND b.qty_remaining>0'); $all->execute([$iid]);
foreach ($all as $r) {
    [$s, $d] = expiry_state($r['expiry_date'], $r['expiry_alert_days']);
    $sum[$s]++; if ($s === 'expired') $sum['expired_units'] += $r['qty_remaining']; elseif ($s === 'near') $sum['near_units'] += $r['qty_remaining'];
    if ($s !== 'expired' && $d <= 30) $sum['d30']++;
}
$rows = [];
foreach ($batches as $r) {
    [$s] = expiry_state($r['expiry_date'], $r['expiry_alert_days']);
    if ($status === 'attention' && $s === 'ok') continue;
    if (in_array($status, ['expired', 'near', 'ok'], true) && $s !== $status) continue;
    $r['state'] = $s; $rows[] = $r;
}
$unb = $pdo->prepare('SELECT COUNT(*) FROM products p WHERE p.inventory_id=? AND p.track_expiry=1 AND p.quantity > COALESCE((SELECT SUM(b.qty_remaining) FROM product_batches b WHERE b.product_id=p.id),0)'); $unb->execute([$iid]); $unb = (int)$unb->fetchColumn();
$tabs = ['attention' => 'Needs attention', 'expired' => 'Expired', 'near' => 'Near expiry', 'ok' => 'Valid', 'all' => 'All in stock', 'empty' => 'Used up'];
?>
<?php if ($sum['expired']): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i> <strong><?= $sum['expired'] ?> expired batch<?= $sum['expired'] > 1 ? 'es' : '' ?> (<?= number_format($sum['expired_units']) ?> units) still in stock.</strong> Expired medicine cannot be sold or released. Write it off below.</div><?php endif; ?>
<?php if ($sum['near']): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong><?= $sum['near'] ?> batch<?= $sum['near'] > 1 ? 'es are' : ' is' ?> near expiry (<?= number_format($sum['near_units']) ?> units).</strong> Sell or return these first.</div><?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ([['Expired batches', $sum['expired'], number_format($sum['expired_units']) . ' units', $sum['expired'] ? 'down' : 'up', 'expired'], ['Near expiry', $sum['near'], number_format($sum['near_units']) . ' units', $sum['near'] ? 'warn' : 'up', 'near'],
      ['Expiring in 30 days', $sum['d30'], 'Not yet expired', $sum['d30'] ? 'warn' : 'up', 'near'], ['Valid batches', $sum['ok'], 'Outside alert window', 'up', 'ok']] as [$l, $v, $pill, $c, $href]): ?>
    <div class="col-6 col-xl-3"><a class="text-decoration-none" href="?status=<?= $href ?>"><div class="panel stat"><div class="top"><span><?= $l ?></span></div><div class="v"><?= number_format($v) ?></div><span class="trend <?= $c ?> mt-2 d-inline-block"><?= e($pill) ?></span></div></a></div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel-head">
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm flex-wrap"><?php foreach ($tabs as $k => $l): ?><a class="btn btn-<?= $status === $k ? 'dark' : 'outline-secondary' ?>" href="?status=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
      <form class="d-flex gap-2" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><input class="form-control form-control-sm" style="width:210px" name="q" value="<?= e($q) ?>" placeholder="Medicine, batch or supplier"><button class="btn btn-sm btn-light">Search</button></form>
    </div>
    <a class="btn btn-light btn-sm" href="reports.php?type=expiry"><i class="bi bi-graph-up"></i> Expiry report</a>
  </div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Medicine</th><th>Batch / lot</th><th>Supplier</th><th>Received</th><th>Expiry date</th><th>Status</th><th class="text-end">Remaining</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><div class="fw-medium"><?= e($r['pname']) ?></div><small class="text-secondary"><?= e($r['sku']) ?></small></td>
        <td class="fw-medium"><?= e($r['batch_no']) ?></td>
        <td><?= e($r['sname'] ?? '—') ?><?php if ($r['supplier_code']): ?><div class="small text-secondary"><?= e($r['supplier_code']) ?></div><?php endif; ?></td>
        <td class="text-nowrap"><?= fmt_date($r['received_at']) ?></td><td class="text-nowrap"><?= fmt_date($r['expiry_date']) ?></td>
        <td><?= $status === 'empty' ? '<span class="badge badge-muted">Used up</span>' : expiry_badge($r['expiry_date'], $r['expiry_alert_days']) ?></td>
        <td class="text-end fw-medium"><?= (int)$r['qty_remaining'] ?><div class="small text-secondary fw-normal">of <?= (int)$r['qty_received'] ?></div></td>
        <td class="text-end text-nowrap"><?php if ($status !== 'empty' && $r['state'] === 'expired'): ?>
          <form method="post" class="d-inline" data-confirm="Write off <?= (int)$r['qty_remaining'] ?> unit(s) of <?= e($r['pname']) ?>, batch <?= e($r['batch_no']) ?>? This removes them from stock and cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="writeoff"><input type="hidden" name="batch_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Write off</button></form>
          <?php elseif ($status !== 'empty' && $r['state'] === 'near'): ?><a class="btn btn-sm btn-outline-secondary" href="stock_out.php">Release</a><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty"><?= $status === 'attention' ? 'Nothing is expired or near expiry. 🎉' : 'No batches match this view.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<p class="small text-secondary mt-3">A batch counts as <strong>near expiry</strong> once it is within its alert window (set per product, otherwise <?= expiry_default_days() ?> days) and as <strong>expired</strong> the day after its expiry date.
  Stock-out takes units from the earliest-expiring batch first and never sells expired batches.<?php if ($unb): ?> <span class="text-body"><?= $unb ?> tracked product<?= $unb > 1 ? 's have' : ' has' ?> stock that is not assigned to a batch yet (recorded before batch tracking), so it is not listed here.</span><?php endif; ?></p>
<?php page_end();
