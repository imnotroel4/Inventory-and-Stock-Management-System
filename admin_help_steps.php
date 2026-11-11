<?php
// Admin: edit the steps of one tutorial (text, highlighted element, scripted demo action, optional screenshot).
require_once __DIR__ . '/includes/help_layout.php';
require_admin();
help_ensure_schema();

const HELP_DEMO_MODULES = ['dashboard' => 'Dashboard', 'products' => 'Products', 'inventory' => 'Stock in / out', 'categories' => 'Categories', 'suppliers' => 'Suppliers', 'orders' => 'Purchase orders', 'expiry' => 'Expiry tracking', 'reports' => 'Reports', 'users' => 'User management'];

$tid = (int)($_GET['id'] ?? $_POST['tid'] ?? 0);
$st = $pdo->prepare('SELECT * FROM help_tutorials WHERE id=?'); $st->execute([$tid]); $tut = $st->fetch();
if (!$tut) { flash('Tutorial not found.', 'danger'); redirect('admin_help_tutorials.php'); }

function help_renumber($tid) {
    global $pdo; $ids = $pdo->prepare('SELECT id FROM help_tutorial_steps WHERE tutorial_id=? ORDER BY step_no, id'); $ids->execute([$tid]);
    $u = $pdo->prepare('UPDATE help_tutorial_steps SET step_no=? WHERE id=?'); $n = 1; foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) $u->execute([$n++, $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'save') {
            $title = trim(preg_replace('/\s+/', ' ', $_POST['title'] ?? '')); $body = trim($_POST['body'] ?? ''); $mod = $_POST['target_module'] ?? ''; $sel = trim($_POST['target_selector'] ?? ''); $act = trim($_POST['demo_action'] ?? '');
            if (!help_len_ok($title, 2, 160)) throw new Exception('Step title must be 2 to 160 characters.');
            if (!help_len_ok($body, 2, 2000)) throw new Exception('Step text must be 2 to 2,000 characters.');
            if ($mod !== '' && !isset(HELP_DEMO_MODULES[$mod])) throw new Exception('Choose a valid demo screen.');
            if (strlen($sel) > 190 || preg_match('/[<>{}]/', $sel)) throw new Exception('The highlight target is not valid.');
            if (strlen($act) > 500) throw new Exception('The demo action is too long (500 characters maximum).');
            $old = null;
            if ($id) { $o = $pdo->prepare('SELECT image FROM help_tutorial_steps WHERE id=? AND tutorial_id=?'); $o->execute([$id, $tid]); $old = $o->fetchColumn() ?: null; }
            $img = $old; $newImg = help_upload_image($_FILES['image'] ?? []);
            if ($newImg) { $img = $newImg; if ($old) help_delete_image($old); }
            elseif ($old && !empty($_POST['remove_image'])) { help_delete_image($old); $img = null; }
            if ($id) { $pdo->prepare('UPDATE help_tutorial_steps SET title=?,body=?,target_module=?,target_selector=?,demo_action=?,image=? WHERE id=? AND tutorial_id=?')->execute([$title, $body, $mod ?: null, $sel ?: null, $act ?: null, $img, $id, $tid]); flash('Step updated.'); }
            else { $n = $pdo->prepare('SELECT COALESCE(MAX(step_no),0)+1 FROM help_tutorial_steps WHERE tutorial_id=?'); $n->execute([$tid]);
                $pdo->prepare('INSERT INTO help_tutorial_steps (tutorial_id,step_no,title,body,target_module,target_selector,demo_action,image) VALUES (?,?,?,?,?,?,?,?)')->execute([$tid, (int)$n->fetchColumn(), $title, $body, $mod ?: null, $sel ?: null, $act ?: null, $img]); flash('Step added.'); }
        } elseif ($a === 'move') {
            $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1; help_renumber($tid);
            $c = $pdo->prepare('SELECT id,step_no FROM help_tutorial_steps WHERE id=? AND tutorial_id=?'); $c->execute([$id, $tid]); $cur = $c->fetch();
            if ($cur) { $o = $pdo->prepare('SELECT id,step_no FROM help_tutorial_steps WHERE tutorial_id=? AND step_no=?'); $o->execute([$tid, $cur['step_no'] + $dir]); $oth = $o->fetch();
                if ($oth) { $u = $pdo->prepare('UPDATE help_tutorial_steps SET step_no=? WHERE id=?'); $u->execute([$oth['step_no'], $cur['id']]); $u->execute([$cur['step_no'], $oth['id']]); } }
        } elseif ($a === 'delete') {
            $o = $pdo->prepare('SELECT image FROM help_tutorial_steps WHERE id=? AND tutorial_id=?'); $o->execute([$id, $tid]); help_delete_image($o->fetchColumn() ?: null);
            $pdo->prepare('DELETE FROM help_tutorial_steps WHERE id=? AND tutorial_id=?')->execute([$id, $tid]); help_renumber($tid); flash('Step deleted.');
            $n = $pdo->prepare('SELECT COUNT(*) FROM help_tutorial_steps WHERE tutorial_id=?'); $n->execute([$tid]);
            if (!(int)$n->fetchColumn()) { $pdo->prepare('UPDATE help_tutorials SET is_published=0 WHERE id=?')->execute([$tid]); }
        }
    } catch (PDOException $e) { flash('Database error. Please try again.', 'danger'); }
    catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('admin_help_steps.php?id=' . $tid);
}

page_start('Tutorial steps', 'admin_help_tutorials', 'admin');
$st = $pdo->prepare('SELECT * FROM help_tutorial_steps WHERE tutorial_id=? ORDER BY step_no, id'); $st->execute([$tid]); $rows = $st->fetchAll(); ?>
<div class="panel mb-3"><div class="panel-head"><div><h2><?= e($tut['title']) ?></h2><div class="small text-secondary mt-1"><?= $tut['is_published'] ? 'Published' : 'Unpublished' ?> · <?= count($rows) ?> steps</div></div>
  <div class="d-flex gap-2"><a class="btn btn-light btn-sm" href="admin_help_tutorials.php">Back</a><a class="btn btn-light btn-sm" target="_blank" rel="noopener" href="help_tutorial.php?slug=<?= e($tut['slug']) ?>"><i class="bi bi-play"></i> Try it</a>
  <button class="btn btn-primary btn-sm" data-edit-modal="#stepModal" data-title-new="Add step" data-title-edit="Edit step"><i class="bi bi-plus-lg"></i> Add step</button></div></div>
  <div class="panel-body pt-0"><details class="small text-secondary"><summary class="fw-medium">How to highlight things and script the demo</summary>
    <p class="mt-2 mb-1"><b>Highlight target</b>: a demo element key (for example <code>products-add</code>, <code>pm-name</code>, <code>nav-suppliers</code>, <code>po-table</code>, <code>report-generate</code>) or any CSS selector inside the demo. Leave empty for a centred message.</p>
    <p class="mb-1"><b>Demo action</b> (optional, separate several with <code>;</code>): <code>open:product</code> <code>open:supplier</code> <code>open:po</code> <code>open:receive</code> · <code>close</code> · <code>tab:in</code> <code>tab:out</code> <code>tab:monitor</code> · <code>fill:pm-name=Aspirin</code> · <code>click:pm-save</code>. Use <code>{+365d}</code> inside a value for a date one year from today.</p>
    <p class="mb-0">Steps are replayed from the start whenever the learner moves, so each step can rely on what the earlier steps did.</p></details></div>
  <div class="table-responsive"><table class="table table-hover"><thead><tr><th style="width:56px">#</th><th>Step</th><th>Screen</th><th>Highlights</th><th>Action</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $i => $r): ?><tr><td><?= (int)$r['step_no'] ?></td><td><span class="fw-medium"><?= e($r['title']) ?></span><?= $r['image'] ? ' <i class="bi bi-image text-secondary"></i>' : '' ?><small class="d-block text-secondary"><?= e(mb_strimwidth($r['body'], 0, 90, '…')) ?></small></td>
    <td class="small"><?= e(HELP_DEMO_MODULES[$r['target_module']] ?? '—') ?></td><td class="small"><code><?= e($r['target_selector'] ?? '') ?></code></td><td class="small"><code><?= e(mb_strimwidth((string)$r['demo_action'], 0, 40, '…')) ?></code></td>
    <td class="text-end text-nowrap">
      <?php foreach (['up' => ['arrow-up', $i === 0], 'down' => ['arrow-down', $i === count($rows) - 1]] as $d => [$ic, $dis]): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tid" value="<?= $tid ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="<?= $d ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light" <?= $dis ? 'disabled' : '' ?> aria-label="Move <?= $d ?>"><i class="bi bi-<?= $ic ?>"></i></button></form><?php endforeach; ?>
      <button class="btn btn-sm btn-light" aria-label="Edit" data-edit-modal="#stepModal" data-title-edit="Edit step" data-title-new="Add step" data-row='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
      <form method="post" class="d-inline" data-confirm="Delete this step?"><?= csrf_field() ?><input type="hidden" name="tid" value="<?= $tid ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-light text-danger" aria-label="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No steps yet. Add the first one.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="modal fade" id="stepModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" enctype="multipart/form-data" class="modal-content">
  <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id"><input type="hidden" name="tid" value="<?= $tid ?>">
    <div class="col-12"><label class="form-label">Step title</label><input class="form-control" name="title" required maxlength="160"></div>
    <div class="col-12"><label class="form-label">Instructions <span class="text-secondary fw-normal small">(**bold** is allowed)</span></label><textarea class="form-control" name="body" rows="3" required maxlength="2000"></textarea></div>
    <div class="col-md-4"><label class="form-label">Demo screen</label><select class="form-select" name="target_module"><option value="">(keep current)</option><?php foreach (HELP_DEMO_MODULES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-8"><label class="form-label">Highlight target</label><input class="form-control" name="target_selector" maxlength="190" placeholder="e.g. products-add"></div>
    <div class="col-12"><label class="form-label">Demo action <span class="text-secondary fw-normal small">(optional)</span></label><input class="form-control" name="demo_action" maxlength="500" placeholder="e.g. open:product; fill:pm-name=Aspirin"></div>
    <div class="col-12"><label class="form-label">Screenshot (optional)</label><input type="file" class="form-control" name="image" accept="image/jpeg,image/png,image/webp,image/gif"><div class="form-check mt-2 d-none" data-has-img><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="stRm"><label class="form-check-label" for="stRm">Remove the current screenshot</label></div></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save step</button></div>
</form></div></div>
<script>
document.querySelectorAll('[data-edit-modal="#stepModal"]').forEach(function (b) { b.addEventListener('click', function () {
  var row = b.dataset.row ? JSON.parse(b.dataset.row) : null, f = document.querySelector('#stepModal form');
  f.elements.target_module.value = row && row.target_module ? row.target_module : ''; f.elements.remove_image.checked = false;
  f.querySelector('[data-has-img]').classList.toggle('d-none', !(row && row.image));
}); });
</script>
<?php page_end();
