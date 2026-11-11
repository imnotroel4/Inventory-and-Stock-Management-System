<?php
// System settings. Saved in the `settings` table; every change is written to the activity log.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $new = [
            'site_name' => trim(preg_replace('/\s+/', ' ', $_POST['site_name'] ?? '')),
            'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
            'max_inventories' => (string)(int)($_POST['max_inventories'] ?? 0),
            'default_min_stock' => (string)(int)($_POST['default_min_stock'] ?? -1),
            'expiry_alert_days' => (string)(int)($_POST['expiry_alert_days'] ?? 0),
            'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
            'maintenance_message' => trim($_POST['maintenance_message'] ?? ''),
        ];
        if (mb_strlen($new['site_name']) < 2 || mb_strlen($new['site_name']) > 50 || preg_match('/[<>]/', $new['site_name'])) throw new Exception('System name must be 2 to 50 characters and cannot contain < or >.');
        if ($new['max_inventories'] < 1 || $new['max_inventories'] > 50) throw new Exception('Inventories per user must be between 1 and 50.');
        if ($new['default_min_stock'] < 0 || $new['default_min_stock'] > 100000) throw new Exception('Default minimum stock must be between 0 and 100000.');
        if ($new['expiry_alert_days'] < 1 || $new['expiry_alert_days'] > 730) throw new Exception('The near-expiry alert window must be between 1 and 730 days.');
        if ($new['maintenance_message'] === '') $new['maintenance_message'] = SETTING_DEFAULTS['maintenance_message'];
        if (mb_strlen($new['maintenance_message']) > 200) throw new Exception('The maintenance message must be 200 characters or fewer.');
        $changes = [];
        $up = $pdo->prepare('INSERT INTO settings (key_name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
        foreach ($new as $k => $v) {
            if ((string)setting($k) !== $v) { $up->execute([$k, $v]); $changes[] = $k . ': ' . mb_substr((string)setting($k), 0, 30) . ' -> ' . mb_substr($v, 0, 30); }
        }
        if ($changes) log_activity('settings_update', implode('; ', $changes));
        flash($changes ? 'Settings saved.' : 'Nothing changed.');
    } catch (Exception $e) { flash($e->getMessage(), 'danger'); }
    redirect('admin_settings.php');
}
page_start('System settings', 'admin_settings', 'admin');
$dbv = $pdo->query('SELECT VERSION()')->fetchColumn();
$cnt = $pdo->query("SELECT (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM inventories) i, (SELECT COUNT(*) FROM products) p, (SELECT COUNT(*) FROM activity_logs) l")->fetch();
$sys = [['PHP version', PHP_VERSION], ['Database', $dbv], ['Schema version', setting('schema_version') ?? '—'], ['Uploads folder writable', is_writable(UPLOAD_DIR) ? 'Yes' : 'NO: fix folder permissions'], ['Time zone', date_default_timezone_get()], ['Server time', date('M d, Y h:i A')],
        ['Users / inventories / products', $cnt['u'] . ' / ' . $cnt['i'] . ' / ' . $cnt['p']], ['Log entries', number_format($cnt['l'])]];
?>
<div class="row g-3">
  <div class="col-lg-7"><form method="post" class="panel"><?= csrf_field() ?>
    <div class="panel-head"><h2>General</h2></div>
    <div class="panel-body">
      <label class="form-label">System name</label><input class="form-control mb-1" name="site_name" value="<?= e(site_name()) ?>" required maxlength="50">
      <div class="form-text mb-3">Shown in the navigation bar, page titles, login pages and footer.</div>
      <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" id="reg" name="allow_registration" <?= allow_registration() ? 'checked' : '' ?>><label class="form-check-label" for="reg">Allow public registration</label></div>
      <div class="form-text mb-3">When off, "Create an Account" disappears and register.php is blocked. You can still add users yourself.</div>
      <div class="row g-3 mb-3">
        <div class="col-6"><label class="form-label">Inventories per user (max)</label><input class="form-control" type="number" min="1" max="50" name="max_inventories" value="<?= max_inventories() ?>" required></div>
        <div class="col-6"><label class="form-label">Default minimum stock</label><input class="form-control" type="number" min="0" max="100000" name="default_min_stock" value="<?= default_min_stock() ?>" required></div>
      </div>
      <div class="mb-3"><label class="form-label">Near-expiry alert window (days)</label><input class="form-control" type="number" min="1" max="730" name="expiry_alert_days" value="<?= expiry_default_days() ?>" required>
        <div class="form-text">Medicines are flagged this many days before their expiry date. A product can override it in its own settings.</div></div>
      <hr>
      <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" id="mnt" name="maintenance_mode" <?= maintenance_on() ? 'checked' : '' ?>><label class="form-check-label fw-medium" for="mnt">Maintenance mode</label></div>
      <div class="form-text mb-2">Signs out and blocks every regular user. Administrators can still sign in and work.</div>
      <label class="form-label">Maintenance message</label><input class="form-control mb-4" name="maintenance_message" value="<?= e(setting('maintenance_message')) ?>" maxlength="200">
      <button class="btn btn-primary">Save settings</button>
    </div></form></div>
  <div class="col-lg-5"><div class="panel"><div class="panel-head"><h2>System information</h2></div><div class="panel-body small">
    <?php foreach ($sys as [$k, $v]): ?><div class="d-flex justify-content-between py-2 border-bottom"><span class="text-secondary"><?= e($k) ?></span><span class="text-end"><?= e($v) ?></span></div><?php endforeach; ?>
    <a class="btn btn-light mt-3" href="admin_logs.php?action=settings_update"><i class="bi bi-clock-history me-1"></i>Settings change history</a>
  </div></div></div>
</div>
<?php page_end();
