<?php
// Database upgrade for the multi-user version.
// Runs automatically from config.php. A number saved in the `settings` table (schema_version) remembers
// that the upgrade is done, so after the first page load this costs just one tiny query per request.
// Every step checks first, so it is safe on a fresh install AND on your existing database.

const SCHEMA_VERSION = 3;

function db_table_exists(PDO $pdo, $t) {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$t]);
    return (bool)$s->fetchColumn();
}
function db_col_exists(PDO $pdo, $t, $c) {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$t, $c]);
    return (bool)$s->fetchColumn();
}
function db_index_exists(PDO $pdo, $t, $name) {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $s->execute([$t, $name]);
    return (bool)$s->fetchColumn();
}
function db_fk_exists(PDO $pdo, $t, $name) {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $s->execute([$t, $name]);
    return (bool)$s->fetchColumn();
}
// Name of a UNIQUE index that covers only this one column (or null)
function db_single_unique_index(PDO $pdo, $t, $col) {
    $s = $pdo->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0
        GROUP BY INDEX_NAME HAVING COUNT(*)=1 AND MAX(COLUMN_NAME)=? LIMIT 1');
    $s->execute([$t, $col]);
    return $s->fetchColumn() ?: null;
}

function run_migrations(PDO $pdo) {
    if (!db_table_exists($pdo, 'users')) {
        exit('The database is empty. Import database.sql in phpMyAdmin first (see README.md).');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key_name VARCHAR(60) NOT NULL PRIMARY KEY, value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $v = (int)$pdo->query("SELECT value FROM settings WHERE key_name='schema_version'")->fetchColumn();
    if ($v >= SCHEMA_VERSION) return;

    // ---- 1. users: email/avatar/must_change, a clear account status, and only two roles (admin, user) ----
    foreach (['email' => 'VARCHAR(120) NULL', 'avatar' => 'VARCHAR(255) NULL', 'must_change' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $col => $def) {
        if (!db_col_exists($pdo, 'users', $col)) $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
    }
    if (!db_col_exists($pdo, 'users', 'status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active' AFTER role");
        if (db_col_exists($pdo, 'users', 'is_active')) $pdo->exec("UPDATE users SET status = IF(is_active=1,'active','inactive')");
    }
    $role = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($role && strtolower(str_replace(' ', '', $role['Type'])) !== "enum('admin','user')") {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','staff','user') NOT NULL DEFAULT 'user'");
        $pdo->exec("UPDATE users SET role='user' WHERE role='staff'");   // the old Staff role no longer exists
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','user') NOT NULL DEFAULT 'user'");
    }
    if (!db_single_unique_index($pdo, 'users', 'email')) {
        try { $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)'); }
        catch (PDOException $e) { /* two accounts share an email: fix them by hand, then reload */ }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, selector CHAR(24) NOT NULL UNIQUE, token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");

    // ---- 2. inventories: one user can own many ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        business_type VARCHAR(60) NULL,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_inv_user_name (user_id, name),
        CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // ---- 3. every data table gets an inventory_id (the "owner tag" of each row) ----
    $tenant = ['categories', 'suppliers', 'products', 'stock_in', 'stock_out', 'stock_movements'];
    foreach ($tenant as $t) {
        if (db_table_exists($pdo, $t) && !db_col_exists($pdo, $t, 'inventory_id')) $pdo->exec("ALTER TABLE $t ADD COLUMN inventory_id INT NULL");
    }

    // ---- 4. data from the old single-store system moves into one inventory owned by a normal user ----
    $orphans = 0;
    foreach (['categories', 'suppliers', 'products'] as $t) $orphans += (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE inventory_id IS NULL")->fetchColumn();
    if ($orphans > 0) {
        $owner = $pdo->query("SELECT id FROM users WHERE role='user' ORDER BY (username='staff') DESC, id LIMIT 1")->fetchColumn();
        if (!$owner) { // no normal user exists: park the data on a locked account an admin can reactivate later
            $pdo->prepare("INSERT INTO users (full_name,username,password,role,status) VALUES ('Legacy Inventory Owner','legacy_owner',?, 'user','inactive')")
                ->execute([password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
            $owner = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO inventories (user_id,name,business_type,description) VALUES (?, 'Main Store (migrated)', 'Other', 'Created automatically from your data before the multi-user upgrade.')")
            ->execute([$owner]);
        $invId = (int)$pdo->lastInsertId();
        foreach (['categories', 'suppliers', 'products'] as $t) $pdo->prepare("UPDATE $t SET inventory_id=? WHERE inventory_id IS NULL")->execute([$invId]);
        foreach (['stock_in', 'stock_out', 'stock_movements'] as $t) {
            $pdo->exec("UPDATE $t x JOIN products p ON p.id=x.product_id SET x.inventory_id=p.inventory_id WHERE x.inventory_id IS NULL");
        }
    }

    // ---- 5. lock it in: NOT NULL + foreign key, and SKU / category names become unique per inventory ----
    foreach ($tenant as $t) {
        if (!db_table_exists($pdo, $t)) continue;
        $pdo->exec("ALTER TABLE $t MODIFY inventory_id INT NOT NULL");
        if (!db_fk_exists($pdo, $t, "fk_{$t}_inv")) {
            $pdo->exec("ALTER TABLE $t ADD CONSTRAINT fk_{$t}_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE");
        }
    }
    if ($old = db_single_unique_index($pdo, 'products', 'sku')) $pdo->exec("ALTER TABLE products DROP INDEX `$old`");
    if (!db_index_exists($pdo, 'products', 'uq_prod_inv_sku')) $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_inv_sku (inventory_id, sku)');
    if ($old = db_single_unique_index($pdo, 'categories', 'name')) $pdo->exec("ALTER TABLE categories DROP INDEX `$old`");
    if (!db_index_exists($pdo, 'categories', 'uq_cat_inv_name')) $pdo->exec('ALTER TABLE categories ADD UNIQUE KEY uq_cat_inv_name (inventory_id, name)');
    foreach (['stock_in' => 'idx_in_inv_date', 'stock_out' => 'idx_out_inv_date'] as $t => $ix) {
        if (!db_index_exists($pdo, $t, $ix)) $pdo->exec("ALTER TABLE $t ADD INDEX $ix (inventory_id, created_at)");
    }

    // ---- 6. activity log: remember which inventory an action belongs to; indexes for the admin filters ----
    if (!db_col_exists($pdo, 'activity_logs', 'inventory_id')) $pdo->exec('ALTER TABLE activity_logs ADD COLUMN inventory_id INT NULL AFTER user_id');
    if (!db_index_exists($pdo, 'activity_logs', 'idx_log_action')) $pdo->exec('ALTER TABLE activity_logs ADD INDEX idx_log_action (action, ip_address, created_at)');
    if (!db_index_exists($pdo, 'activity_logs', 'idx_log_created')) $pdo->exec('ALTER TABLE activity_logs ADD INDEX idx_log_created (created_at)');

    // ---- 7. pharmacy features (schema 3): supplier details, purchase orders, batches and expiry dates ----
    // 7a. suppliers: automatic supplier ID (SUP-0001 ... numbered inside each inventory), status and registration date
    if (!db_col_exists($pdo, 'suppliers', 'supplier_code')) $pdo->exec('ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(20) NULL AFTER id');
    if (!db_col_exists($pdo, 'suppliers', 'status')) $pdo->exec("ALTER TABLE suppliers ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER address");
    if (!db_col_exists($pdo, 'suppliers', 'registered_at')) $pdo->exec('ALTER TABLE suppliers ADD COLUMN registered_at DATE NULL AFTER status');
    $pdo->exec('UPDATE suppliers SET registered_at = DATE(created_at) WHERE registered_at IS NULL');
    $todo = $pdo->query('SELECT id, inventory_id FROM suppliers WHERE supplier_code IS NULL ORDER BY inventory_id, id')->fetchAll();
    if ($todo) {
        $next = [];
        $maxq = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(supplier_code,5) AS UNSIGNED)),0) FROM suppliers WHERE inventory_id=? AND supplier_code LIKE 'SUP-%'");
        $setq = $pdo->prepare('UPDATE suppliers SET supplier_code=? WHERE id=?');
        foreach ($todo as $r) {
            $k = (int)$r['inventory_id'];
            if (!isset($next[$k])) { $maxq->execute([$k]); $next[$k] = (int)$maxq->fetchColumn(); }
            $setq->execute([sprintf('SUP-%04d', ++$next[$k]), $r['id']]);
        }
    }
    $pdo->exec('ALTER TABLE suppliers MODIFY supplier_code VARCHAR(20) NOT NULL');
    if (!db_index_exists($pdo, 'suppliers', 'uq_sup_inv_code')) $pdo->exec('ALTER TABLE suppliers ADD UNIQUE KEY uq_sup_inv_code (inventory_id, supplier_code)');

    // 7b. products: which items are tracked by batch / expiry, and an optional per-product alert window
    if (!db_col_exists($pdo, 'products', 'track_expiry')) $pdo->exec('ALTER TABLE products ADD COLUMN track_expiry TINYINT(1) NOT NULL DEFAULT 0');
    if (!db_col_exists($pdo, 'products', 'expiry_alert_days')) $pdo->exec('ALTER TABLE products ADD COLUMN expiry_alert_days INT NULL');

    // 7c. purchase orders, their items, and the batches that deliveries create
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_id INT NOT NULL,
        po_number VARCHAR(30) NOT NULL UNIQUE,
        supplier_id INT NOT NULL,
        order_date DATE NOT NULL,
        expected_date DATE NULL,
        status ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',
        notes VARCHAR(255) NULL,
        user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_po_inv_status (inventory_id, status),
        CONSTRAINT fk_po_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE,
        CONSTRAINT fk_po_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_po_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        inventory_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        qty_received INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_poi_po_prod (po_id, product_id),
        CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_poi_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE,
        CONSTRAINT fk_poi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_id INT NOT NULL,
        product_id INT NOT NULL,
        supplier_id INT NULL,
        batch_no VARCHAR(60) NOT NULL,
        expiry_date DATE NOT NULL,
        qty_received INT NOT NULL,
        qty_remaining INT NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_batch_inv_prod_no (inventory_id, product_id, batch_no),
        INDEX idx_batch_expiry (inventory_id, expiry_date),
        CONSTRAINT fk_batch_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE,
        CONSTRAINT fk_batch_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_batch_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // 7d. stock_in remembers the batch, expiry date and purchase order of every delivery
    foreach (['batch_id' => 'INT NULL', 'batch_no' => 'VARCHAR(60) NULL', 'expiry_date' => 'DATE NULL', 'po_id' => 'INT NULL', 'po_item_id' => 'INT NULL'] as $col => $def) {
        if (!db_col_exists($pdo, 'stock_in', $col)) $pdo->exec("ALTER TABLE stock_in ADD COLUMN $col $def");
    }
    foreach (['fk_in_batch' => ['batch_id', 'product_batches'], 'fk_in_po' => ['po_id', 'purchase_orders'], 'fk_in_poi' => ['po_item_id', 'purchase_order_items']] as $fk => [$col, $ref]) {
        if (!db_fk_exists($pdo, 'stock_in', $fk)) $pdo->exec("ALTER TABLE stock_in ADD CONSTRAINT $fk FOREIGN KEY ($col) REFERENCES $ref(id) ON DELETE SET NULL");
    }
    if (!db_index_exists($pdo, 'stock_in', 'idx_in_supplier')) $pdo->exec('ALTER TABLE stock_in ADD INDEX idx_in_supplier (supplier_id)');

    // 7e. which batches a stock-out took its units from (one release can span several batches)
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_out_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inventory_id INT NOT NULL,
        stock_out_id INT NOT NULL,
        batch_id INT NOT NULL,
        quantity INT NOT NULL,
        INDEX idx_sob_out (stock_out_id),
        CONSTRAINT fk_sob_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id) ON DELETE CASCADE,
        CONSTRAINT fk_sob_out FOREIGN KEY (stock_out_id) REFERENCES stock_out(id) ON DELETE CASCADE,
        CONSTRAINT fk_sob_batch FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    $pdo->prepare("INSERT INTO settings (key_name,value) VALUES ('schema_version',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([SCHEMA_VERSION]);
}
