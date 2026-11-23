<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
        if ($a === 'save') {
            $name = trim($_POST['name'] ?? ''); $contact = trim($_POST['contact_person'] ?? ''); $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? ''); $address = trim($_POST['address'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $reg = parse_ymd($_POST['registered_at'] ?? '') ?: date('Y-m-d');
            if ($name === '' || mb_strlen($name) > 120) throw new Exception('Supplier name is required (up to 120 characters).');
            if ($contact === '') throw new Exception('Contact person is required.');
            if (!preg_match('/^[0-9+()\-\s.]{7,40}$/', $phone)) throw new Exception('Enter a valid phone number (digits, spaces, + - ( ) only; at least 7 characters).');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid supplier email address.');
            if ($reg > date('Y-m-d')) throw new Exception('The registration date cannot be in the future.');
            if (mb_strlen($address) > 255 || mb_strlen($contact) > 100 || mb_strlen($email) > 120) throw new Exception('One of the fields is too long.');
            $dup = $pdo->prepare('SELECT id FROM suppliers WHERE inventory_id=? AND name=? AND id<>?'); $dup->execute([$iid, $name, $id]);
            if ($dup->fetchColumn()) throw new Exception('A supplier named "' . $name . '" already exists in this inventory.');
            if ($id) {
                require_owned('suppliers', $id, $iid, 'Supplier');
                $pdo->prepare('UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,status=?,registered_at=? WHERE id=? AND inventory_id=?')
                    ->execute([$name, $contact, $phone, $email, $address, $status, $reg, $id, $iid]);
                flash('Supplier updated.');
            } else {
                for ($try = 1; ; $try++) {            // the supplier ID is generated here; a clash with a simultaneous save is retried
                    try {
                        $code = next_supplier_code($iid);
                        $pdo->prepare('INSERT INTO suppliers (supplier_code,inventory_id,name,contact_person,phone,email,address,status,registered_at) VALUES (?,?,?,?,?,?,?,?,?)')
                            ->execute([$code, $iid, $name, $contact, $phone, $email, $address, $status, $reg]);
                        break;
                    } catch (PDOException $e) { if ($e->getCode() != 23000 || $try >= 3) throw $e; }
                }
                flash("Supplier added with ID $code.");
            }
            log_activity('supplier_save', $name);
        } elseif ($a === 'toggle') {
            require_owned('suppliers', $id, $iid, 'Supplier');
            $pdo->prepare("UPDATE suppliers SET status=IF(status='active','inactive','active') WHERE id=? AND inventory_id=?")->execute([$id, $iid]);
            log_activity('supplier_status', "ID $id"); flash('Supplier status changed.');
        } elseif ($a === 'delete') {
            require_owned('suppliers', $id, $iid, 'Supplier');
            $n = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stock_in WHERE supplier_id=? AND inventory_id=?) + (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id=? AND inventory_id=?)');
            $n->execute([$id, $iid, $id, $iid]);
            if ((int)$n->fetchColumn() > 0) throw new Exception('This supplier has purchase orders or deliveries on record, so it cannot be deleted. Set it to Inactive instead.');
            $pdo->prepare('DELETE FROM suppliers WHERE id=? AND inventory_id=?')->execute([$id, $iid]); log_activity('supplier_delete', "ID $id"); flash('Supplier deleted.');
        }
    } catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('suppliers.php');
}

page_start('Suppliers', 'suppliers');

// ---------- one supplier: details, medicines supplied (batch, quantity, expiry) and purchase orders ----------
if (!empty($_GET['view'])) {
    $st = $pdo->prepare('SELECT * FROM suppliers WHERE id=? AND inventory_id=?'); $st->execute([(int)$_GET['view'], $iid]); $s = $st->fetch();
    if (!$s) { echo '<div class="alert alert-warning">Supplier not found.</div>'; page_end(); exit; }
    $d = $pdo->prepare('SELECT i.*, p.name pname, p.sku, po.po_number FROM stock_in i JOIN products p ON p.id=i.product_id LEFT JOIN purchase_orders po ON po.id=i.po_id WHERE i.supplier_id=? AND i.inventory_id=? ORDER BY i.id DESC LIMIT 200');
    $d->execute([$s['id'], $iid]); $deliveries = $d->fetchAll();
    $o = $pdo->prepare('SELECT po.*, (SELECT COALESCE(SUM(quantity*unit_cost),0) FROM purchase_order_items WHERE po_id=po.id) total FROM purchase_orders po WHERE po.supplier_id=? AND po.inventory_id=? ORDER BY po.id DESC LIMIT 50');
    $o->execute([$s['id'], $iid]); $orders = $o->fetchAll();
    ?>
<div class="mb-3 no-print"><a class="btn btn-light btn-sm" href="suppliers.php"><i class="bi bi-arrow-left"></i> All suppliers</a></div>
<div class="panel mb-3">
  <div class="panel-head"><h2><?= e($s['name']) ?> <span class="text-secondary fw-normal fs-6 ms-1"><?= e($s['supplier_code']) ?></span></h2><?= supplier_status_badge($s['status']) ?></div>
  <div class="panel-body"><div class="row g-3 small">
    <div class="col-md-3"><div class="text-secondary">Contact person</div><div class="fw-medium"><?= e($s['contact_person'] ?: '—') ?></div></div>
    <div class="col-md-3"><div class="text-secondary">Phone</div><div class="fw-medium"><?= e($s['phone'] ?: '—') ?></div></div>
    <div class="col-md-3"><div class="text-secondary">Email</div><div class="fw-medium"><?= e($s['email'] ?: '—') ?></div></div>
    <div class="col-md-3"><div class="text-secondary">Registered</div><div class="fw-medium"><?= fmt_date($s['registered_at'] ?: $s['created_at']) ?></div></div>
    <div class="col-12"><div class="text-secondary">Business address</div><div class="fw-medium"><?= e($s['address'] ?: '—') ?></div></div>
  </div></div>
</div>
<div class="panel mb-3">
  <div class="panel-head"><h2>Medicines supplied</h2><span class="small text-secondary">Every delivery from this supplier, with its batch and expiry date</span></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Delivered</th><th>Medicine</th><th>Batch / lot</th><th>Expiry date</th><th class="text-end">Quantity</th><th>PO no.</th><th>Reference</th></tr></thead><tbody>
    <?php foreach ($deliveries as $r): ?>
      <tr><td><?= fmt_date($r['created_at']) ?></td><td class="fw-medium"><?= e($r['pname']) ?><div class="small text-secondary"><?= e($r['sku']) ?></div></td>
        <td><?= e($r['batch_no'] ?? '—') ?></td><td><?= $r['expiry_date'] ? fmt_date($r['expiry_date']) . ' ' . expiry_badge($r['expiry_date']) : '—' ?></td>
        <td class="text-end">+<?= (int)$r['quantity'] ?></td><td><?= e($r['po_number'] ?? '—') ?></td><td><?= e($r['reference_no']) ?></td></tr>
    <?php endforeach; if (!$deliveries): ?><tr><td colspan="7" class="empty">No deliveries recorded from this supplier yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="panel">
  <div class="panel-head"><h2>Purchase orders</h2><a class="btn btn-primary btn-sm" href="po_form.php?supplier=<?= (int)$s['id'] ?>"><i class="bi bi-plus-lg"></i> New purchase order</a></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>PO no.</th><th>Order date</th><th>Expected</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($orders as $r): ?>
      <tr><td class="fw-medium"><?= e($r['po_number']) ?></td><td><?= fmt_date($r['order_date']) ?></td><td><?= fmt_date($r['expected_date']) ?></td><td class="text-end"><?= money($r['total']) ?></td><td><?= po_badge($r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light" href="po_view.php?id=<?= (int)$r['id'] ?>">View</a></td></tr>
    <?php endforeach; if (!$orders): ?><tr><td colspan="6" class="empty">No purchase orders for this supplier yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php page_end(); exit; }

// ---------- list ----------
$f = $_GET['status'] ?? 'all'; if (!in_array($f, ['all', 'active', 'inactive'], true)) $f = 'all';
$q = trim($_GET['q'] ?? '');
$cnt = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(status='active'),0) b, COALESCE(SUM(status='inactive'),0) c FROM suppliers WHERE inventory_id=?"); $cnt->execute([$iid]); $cnt = $cnt->fetch();
$where = 's.inventory_id=?'; $args = [$iid];
if ($f !== 'all') { $where .= ' AND s.status=?'; $args[] = $f; }
if ($q !== '') { $where .= ' AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$rows = $pdo->prepare("SELECT s.*,
    (SELECT COALESCE(SUM(i.quantity),0) FROM stock_in i WHERE i.supplier_id=s.id AND i.inventory_id=s.inventory_id) delivered,
    (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id=s.id AND po.inventory_id=s.inventory_id) po_count
    FROM suppliers s WHERE $where ORDER BY s.supplier_code");
$rows->execute($args); $rows = $rows->fetchAll();
?>
<div class="panel">
  <div class="panel-head">
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <div class="btn-group btn-group-sm">
        <?php foreach (['all' => ['All', $cnt['a']], 'active' => ['Active', $cnt['b']], 'inactive' => ['Inactive', $cnt['c']]] as $k => [$l, $n]): ?>
          <a class="btn btn-<?= $f === $k ? 'dark' : 'outline-secondary' ?>" href="?status=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $l ?> (<?= (int)$n ?>)</a><?php endforeach; ?>
      </div>
      <form class="d-flex gap-2" method="get"><input type="hidden" name="status" value="<?= e($f) ?>">
        <input class="form-control form-control-sm" style="width:220px" name="q" value="<?= e($q) ?>" placeholder="Search ID, name, contact, phone"><button class="btn btn-sm btn-light">Search</button></form>
    </div>
    <button class="btn btn-primary btn-sm" data-edit-modal="#supModal" data-title-new="Add supplier" data-title-edit="Edit supplier"><i class="bi bi-plus-lg"></i> Add supplier</button></div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th>Supplier ID</th><th>Supplier</th><th>Contact person</th><th>Phone</th><th>Email</th><th>Status</th><th>Registered</th><th class="text-end">Units delivered</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="fw-medium text-nowrap"><?= e($r['supplier_code']) ?></td>
        <td><a class="fw-medium text-decoration-none" href="?view=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><div class="small text-secondary"><?= e($r['address']) ?></div></td>
        <td><?= e($r['contact_person']) ?></td><td class="text-nowrap"><?= e($r['phone']) ?></td><td><?= e($r['email']) ?></td>
        <td><?= supplier_status_badge($r['status']) ?></td><td class="text-nowrap"><?= fmt_date($r['registered_at'] ?: $r['created_at']) ?></td>
        <td class="text-end"><?= number_format($r['delivered']) ?><div class="small text-secondary"><?= (int)$r['po_count'] ?> PO<?= (int)$r['po_count'] === 1 ? '' : 's' ?></div></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-light" href="?view=<?= (int)$r['id'] ?>" title="View details and deliveries" aria-label="View"><i class="bi bi-eye"></i></a>
          <button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#supModal" data-title-edit="Edit supplier" data-title-new="Add supplier" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'active' ? 'Set this supplier to Inactive? It will no longer appear when creating purchase orders or recording deliveries.' : 'Set this supplier to Active again?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-light" title="<?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?>" aria-label="<?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $r['status'] === 'active' ? 'toggle-on text-success' : 'toggle-off' ?>"></i></button></form>
          <form method="post" class="d-inline" data-confirm="Delete this supplier? This is only possible when it has no purchase orders or deliveries."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty"><?= $q !== '' || $f !== 'all' ? 'No suppliers match this filter.' : 'No suppliers yet. Add the businesses you receive stock from.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="modal fade" id="supModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Supplier ID</label><input class="form-control" name="supplier_code" readonly placeholder="Generated automatically" tabindex="-1"></div>
      <div class="col-md-8"><label class="form-label">Supplier name</label><input class="form-control" name="name" required maxlength="120"></div>
      <div class="col-md-6"><label class="form-label">Contact person</label><input class="form-control" name="contact_person" required maxlength="100"></div>
      <div class="col-md-6"><label class="form-label">Phone number</label><input class="form-control" name="phone" type="tel" required maxlength="40" placeholder="e.g. +63 917 123 4567"></div>
      <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" name="email" type="email" maxlength="120"></div>
      <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div class="col-md-3"><label class="form-label">Registration date</label><input class="form-control" type="date" name="registered_at" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
      <div class="col-12"><label class="form-label">Business address</label><input class="form-control" name="address" maxlength="255"></div>
    </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save supplier</button></div>
</form></div></div>
<?php page_end();
