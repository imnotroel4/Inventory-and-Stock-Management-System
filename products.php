<?php
require_once __DIR__ . '/includes/layout.php';
$inv = require_inventory(); $iid = (int)$inv['id'];   // every query below is limited to this inventory
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $sku = strtoupper(trim($_POST['sku'] ?? '')); $name = trim($_POST['name'] ?? '');
            $cost = (float)($_POST['cost_price'] ?? 0); $price = (float)($_POST['selling_price'] ?? 0);
            $min = max(0, (int)($_POST['min_stock'] ?? 0)); $cat = (int)($_POST['category_id'] ?? 0) ?: null;
            $track = isset($_POST['track_expiry']) ? 1 : 0;                       // 1 = medicine tracked by batch / lot and expiry date
            $alertDays = trim($_POST['expiry_alert_days'] ?? '') === '' ? null : (int)$_POST['expiry_alert_days'];
            if ($alertDays !== null && ($alertDays < 1 || $alertDays > 3650)) throw new Exception('The near-expiry alert window must be between 1 and 3650 days.');
            if ($sku === '' || $name === '') throw new Exception('SKU and product name are required.');
            if ($cost < 0 || $price < 0) throw new Exception('Prices cannot be negative.');
            if ($cat) require_owned('categories', $cat, $iid, 'Category');   // the category must be yours too
            $open = max(0, (int)($_POST['opening_qty'] ?? 0)); $ob = [null, null];
            if (!$id && $track && $open > 0) $ob = read_batch_fields($_POST['opening_batch'] ?? '', $_POST['opening_expiry'] ?? '', true, ' for the opening stock');
            $pdo->beginTransaction();
            if ($id) {
                require_owned('products', $id, $iid, 'Product');
                $old = $pdo->prepare('SELECT image FROM products WHERE id=? AND inventory_id=?'); $old->execute([$id, $iid]);
                $img = upload_image($_FILES['image'] ?? [], $old->fetchColumn() ?: null);
                $pdo->prepare('UPDATE products SET sku=?,name=?,category_id=?,description=?,image=?,cost_price=?,selling_price=?,min_stock=?,track_expiry=?,expiry_alert_days=? WHERE id=? AND inventory_id=?')
                    ->execute([$sku, $name, $cat, trim($_POST['description'] ?? ''), $img, $cost, $price, $min, $track, $alertDays, $id, $iid]);
                log_activity('product_update', "$sku $name"); $msg = 'Product updated.';
            } else {
                $img = upload_image($_FILES['image'] ?? [], null);
                $pdo->prepare('INSERT INTO products (inventory_id,sku,name,category_id,description,image,cost_price,selling_price,quantity,min_stock,track_expiry,expiry_alert_days) VALUES (?,?,?,?,?,?,?,?,0,?,?,?)')
                    ->execute([$iid, $sku, $name, $cat, trim($_POST['description'] ?? ''), $img, $cost, $price, $min, $track, $alertDays]);
                $newId = (int)$pdo->lastInsertId();
                if ($open > 0) {
                    apply_stock($pdo, $iid, $newId, 'opening', $open, 'OPENING');
                    if ($ob[0] !== null) upsert_batch($pdo, $iid, $newId, null, $ob[0], $ob[1], $open, $cost);   // opening stock of a tracked medicine becomes its first batch
                }
                log_activity('product_add', "$sku $name"); $msg = 'Product added.';
            }
            $pdo->commit(); flash($msg);
        } elseif ($a === 'delete') {
            require_owned('products', $id, $iid, 'Product');
            $img = $pdo->prepare('SELECT image FROM products WHERE id=? AND inventory_id=?'); $img->execute([$id, $iid]); $img = $img->fetchColumn();
            $pdo->prepare('DELETE FROM products WHERE id=? AND inventory_id=?')->execute([$id, $iid]);
            if ($img && is_file(UPLOAD_DIR . $img)) @unlink(UPLOAD_DIR . $img);
            log_activity('product_delete', "ID $id"); flash('Product deleted.');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getCode() == 23000 ? ($a === 'delete' ? 'This product has stock history and cannot be deleted. Set its minimum to 0 or keep it for reporting.' : 'That SKU is already used by another product.') : 'Database error.', 'danger');
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'danger'); }
    redirect('products.php');
}
page_start('Products', 'products');
$cats = $pdo->prepare('SELECT id,name FROM categories WHERE inventory_id=? ORDER BY name'); $cats->execute([$iid]); $cats = $cats->fetchAll();
$q = trim($_GET['q'] ?? ''); $cf = (int)($_GET['category'] ?? 0);
$sql = 'SELECT p.*, c.name category,
    (SELECT MIN(b.expiry_date) FROM product_batches b WHERE b.product_id=p.id AND b.qty_remaining>0) next_expiry,
    (SELECT COUNT(*) FROM product_batches b WHERE b.product_id=p.id AND b.qty_remaining>0) batch_count
    FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.inventory_id=?'; $args = [$iid];
if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($cf) { $sql .= ' AND p.category_id=?'; $args[] = $cf; }
$st = $pdo->prepare($sql . ' ORDER BY p.name'); $st->execute($args); $rows = $st->fetchAll();
?>
<div class="panel">
  <div class="panel-head">
    <form class="d-flex gap-2 flex-wrap" method="get">
      <input class="form-control form-control-sm" style="width:220px" name="q" value="<?= e($q) ?>" placeholder="Search name or SKU">
      <select class="form-select form-select-sm" style="width:180px" name="category" onchange="this.form.submit()"><option value="">All categories</option>
        <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $cf == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-light">Search</button>
    </form>
    <button class="btn btn-primary btn-sm" data-edit-modal="#prodModal" data-title-new="Add product" data-title-edit="Edit product"><i class="bi bi-plus-lg"></i> Add product</button>
  </div>
  <div class="table-responsive"><table class="table table-hover">
    <thead><tr><th></th><th>Product</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">On hand</th><th>Status</th><th>Expiry</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php if ($r['image']): ?><img class="thumb" src="uploads/<?= e($r['image']) ?>" alt=""><?php else: ?><div class="thumb"><i class="bi bi-image"></i></div><?php endif; ?></td>
        <td><div class="fw-medium"><?= e($r['name']) ?></div><small class="text-secondary"><?= e($r['sku']) ?></small></td>
        <td><?= e($r['category'] ?? 'Uncategorized') ?></td>
        <td class="text-end"><?= money($r['cost_price']) ?></td><td class="text-end"><?= money($r['selling_price']) ?></td>
        <td class="text-end fw-medium"><?= (int)$r['quantity'] ?></td><td><?= status_badge($r['quantity'], $r['min_stock']) ?></td>
        <td class="small"><?php if ($r['next_expiry']): ?><?= fmt_date($r['next_expiry']) ?><div><?= expiry_badge($r['next_expiry'], $r['expiry_alert_days']) ?></div><div class="text-secondary"><?= (int)$r['batch_count'] ?> batch<?= (int)$r['batch_count'] === 1 ? '' : 'es' ?></div>
          <?php elseif ($r['track_expiry']): ?><span class="text-secondary">No batches in stock</span><?php else: ?>—<?php endif; ?></td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#prodModal" data-title-edit="Edit product" data-title-new="Add product" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" data-confirm="Delete <?= e($r['name']) ?>? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">No products found. Add your first product to start tracking stock.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="modal fade" id="prodModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" enctype="multipart/form-data" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">SKU / product code</label><input class="form-control" name="sku" required maxlength="50"></div>
      <div class="col-md-8"><label class="form-label">Product name</label><input class="form-control" name="name" required maxlength="150"></div>
      <div class="col-md-6"><label class="form-label">Category</label><select class="form-select" name="category_id"><option value="">Uncategorized</option>
        <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Minimum stock level</label><input class="form-control" type="number" min="0" name="min_stock" value="<?= default_min_stock() ?>" required></div>
      <div class="col-md-4"><label class="form-label">Cost price</label><input class="form-control" type="number" step="0.01" min="0" name="cost_price" value="0" required></div>
      <div class="col-md-4"><label class="form-label">Selling price</label><input class="form-control" type="number" step="0.01" min="0" name="selling_price" value="0" required></div>
      <div class="col-md-4" data-new-only><label class="form-label">Opening quantity</label><input class="form-control" type="number" min="0" name="opening_qty" value="0"></div>
      <div class="col-md-6"><div class="form-check form-switch mt-md-4"><input class="form-check-input" type="checkbox" role="switch" id="trackExp" name="track_expiry" value="1" <?= ($inv['business_type'] ?? '') === 'Pharmacy / Health' ? 'checked' : '' ?>><label class="form-check-label fw-medium" for="trackExp">Track batch / lot number and expiry date</label></div></div>
      <div class="col-md-6"><label class="form-label">Near-expiry alert (days before expiry)</label><input class="form-control" type="number" min="1" max="3650" name="expiry_alert_days" placeholder="Default: <?= expiry_default_days() ?> days"></div>
      <div class="col-md-6" data-new-only><label class="form-label">Opening batch / lot no.</label><input class="form-control" name="opening_batch" maxlength="60" placeholder="e.g., LOT/BATCH-ABC-2026-001"></div>
      <div class="col-md-6" data-new-only><label class="form-label">Opening expiry date</label><input class="form-control" type="date" name="opening_expiry"></div>
      <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
      <div class="col-12 d-flex align-items-center gap-3"><div class="flex-grow-1"><label class="form-label">Product image (JPG, PNG, WEBP, max 2 MB)</label>
        <input class="form-control" type="file" name="image" accept="image/*" data-preview="#imgPrev"></div><img id="imgPrev" data-img class="thumb d-none" style="width:64px;height:64px" alt=""></div>
    </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save product</button></div>
</form></div></div>
<?php page_end();
