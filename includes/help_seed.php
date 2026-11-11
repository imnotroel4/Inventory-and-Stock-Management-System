<?php
// Starter content for the Help Center. Loaded ONCE (first time a Help page is opened), then fully editable by admins.
// Article text uses the simple markup described in help_lib.php (## heading, - bullet, 1. step, > tip, **bold**).

function help_seed(PDO $pdo) {
    $cat = $pdo->prepare('INSERT INTO help_categories (name,slug,icon,description,sort_order) VALUES (?,?,?,?,?)');
    $cats = [
        ['Getting started', 'getting-started', 'rocket-takeoff', 'New here? Set up your account and your first inventory.', 1],
        ['Feature guides', 'features', 'grid-1x2', 'What every module does and how to use it.', 2],
        ['How-to guides', 'how-to', 'list-check', 'Step-by-step instructions for everyday operations.', 3],
        ['Troubleshooting', 'troubleshooting', 'wrench-adjustable', 'Fixes for common errors and messages.', 4],
        ['Accounts & roles', 'accounts-roles', 'people', 'Administrators, regular users and permissions.', 5],
    ];
    $id = [];
    foreach ($cats as $c) { $cat->execute($c); $id[$c[1]] = (int)$pdo->lastInsertId(); }

    $art = $pdo->prepare('INSERT INTO help_articles (category_id,title,slug,summary,body,is_published,is_featured,sort_order) VALUES (?,?,?,?,?,1,?,?)');
    $articles = [
// ---------------------------------------------------------------- getting started
['getting-started', 'Welcome: a 5-minute tour', 'welcome-tour', 'What the system does and where to find things.', 1, 1, <<<'T'
This system helps you keep track of **what you have, what is running low, and what is about to expire** — for one or several inventories.

## The menu at a glance
- **Dashboard** – statistics, charts and alerts for the inventory you are working in.
- **Products, Categories, Suppliers** – your catalog.
- **Purchase orders, Stock in, Stock out** – everything that moves stock.
- **Stock monitoring and Expiry tracking** – what needs your attention.
- **Reports** – summaries you can print or export to CSV.
- **My inventories and Profile & settings** – your workspace and account.

> Not sure where to start? Open **Demo mode** from the Help Center. It is a safe practice area with sample data, so nothing you click can change your real records.

## Where to go next
1. [Create your account and first inventory](help_article.php?slug=create-account-and-inventory)
2. [Add your first product](help_article.php?slug=add-first-product)
3. Follow a guided walkthrough in [Tutorials](help_tutorials.php)
T],
['getting-started', 'Create your account and first inventory', 'create-account-and-inventory', 'Register, sign in and set up the inventory you will work in.', 1, 2, <<<'T'
## Create an account
1. Click **Get Started** on the home page (or **Create an Account** on the login page).
2. Enter your **Full name**, **Username**, **Email address** and a **Password**, then confirm the password.
3. Click **Create Account**.

> Passwords need at least 8 characters with at least one letter and one number. If you do not see a registration option, the administrator has turned self-registration off — ask them to create an account for you.

## Create your first inventory
Every user works inside an *inventory* (for example "Main Pharmacy"). The first time you sign in you are asked to create one:
1. Enter an **Inventory name**.
2. Choose your **Type of business**.
3. Optionally add a **Description**, then click **Create inventory**.

You can own several inventories (up to the limit set by the administrator) and switch between them from the box at the top of the sidebar. Each inventory keeps its own products, suppliers and history.
T],
['getting-started', 'Add your first product', 'add-first-product', 'Create a product with prices, stock levels and optional expiry tracking.', 0, 3, <<<'T'
1. Open **Products** in the sidebar and click **Add product**.
2. Fill in **SKU / product code** and **Product name** (both required). The SKU must be unique inside your inventory.
3. Choose a **Category** (optional) and set the **Minimum stock level**. When the quantity reaches this number the product is flagged as *Low stock*.
4. Enter the **Cost price** and **Selling price**, and the **Opening quantity** you currently have on the shelf.
5. Switch on **Track batch / lot number and expiry date** for medicines or anything that expires. You will then enter an opening batch number and expiry date, and can set a per-product **Near-expiry alert** window.
6. Optionally add a description and a picture, then click save.

> Prefer to learn by doing? The [Adding a new product](help_tutorial.php?slug=adding-a-new-product) tutorial walks you through these steps with sample data.
T],
// ---------------------------------------------------------------- feature guides
['features', 'Dashboard', 'feature-dashboard', 'Statistics, charts, low-stock alerts and inventory summary.', 0, 1, <<<'T'
The dashboard is your daily starting point for the inventory you are working in.

## What you see
- **Statistic cards** – total products, stock value, low or out-of-stock items and expiry alerts.
- **Charts** – stock movement over time and a donut chart that splits products into *In stock*, *Low stock* and *Out of stock*.
- **Alerts** – the products that need restocking and batches that are expired or near expiry. The bell icon in the top bar shows the same alerts from every page.
- **Product table** – filter by stock status with the **Filter** menu.

> Numbers always describe the inventory selected in the sidebar switcher. Switch inventories to see another one.
T],
['features', 'Product management', 'feature-products', 'Add, edit, search, filter and manage products.', 0, 2, <<<'T'
**Products** lists everything you stock.

- **Search** by name or SKU with the search box, and narrow the list by category or stock status.
- **Add / edit** with the product form: SKU, name, category, prices, minimum stock, description, image and expiry tracking.
- **Status badges** – *In stock*, *Low stock* (quantity is at or below the minimum) and *Out of stock*. The small meter shows how full the shelf is compared with the healthy level (twice the minimum).
- **Delete** removes a product. Products that already have stock history may be protected from deletion so your records stay accurate.

Quantities change through **Stock in** and **Stock out**, so every change is recorded.
T],
['features', 'Inventory management: stock in, stock out and monitoring', 'feature-inventory', 'Record deliveries and releases and keep an eye on stock levels.', 0, 3, <<<'T'
## Stock in
Use **Stock in** when goods arrive. There are two ways:
- **Record delivery** – pick the product, supplier, batch/lot number and expiry date (for tracked products), quantity received and unit cost, then click **Add to inventory**.
- **Receive against a purchase order** – choose an open order, enter what actually arrived (or **Fill all remaining**) and click **Receive stock**. Partial deliveries are supported.

## Stock out
Use **Stock out** when goods leave. Choose the product, the **Reason** (*Sale*, *Released for use*, *Damaged or expired*, *Returned to supplier*), the quantity and an optional customer or recipient. For tracked products the system takes units from the batch that expires first, or the batch you pick. Expired batches cannot be sold.

> Adjustments: damaged, expired or returned goods are recorded as a stock-out with the matching reason, so the history always explains why a quantity changed.

## Stock monitoring
**Stock monitoring** lists every product with its level meter. Filter to products that need attention, change a product's minimum level with **Set**, and click **Restock** to jump to Stock in with the product pre-selected.
T],
['features', 'Category management', 'feature-categories', 'Create, edit and organize product categories.', 0, 4, <<<'T'
Categories group your products (for example *Pain relievers* or *Vitamins*) so lists and reports are easier to read.

- Click **Add category**, type a name and optional description, then save.
- Use the pencil icon to rename a category or the trash icon to delete it.
- Category names must be unique inside an inventory.
- Deleting a category never deletes products — they simply become **Uncategorized**.
T],
['features', 'Supplier management', 'feature-suppliers', 'Register suppliers and keep their contact details.', 0, 5, <<<'T'
Each supplier gets an automatic code such as **SUP-0001**.

## Register a supplier
Click **Add supplier** and enter the name, contact person, phone, email, address, status and registration date.

## Manage suppliers
- Open a supplier to see the medicines it supplies and its purchase orders, and to start a new order for it.
- Set a supplier to **Inactive** when you stop working with it. Inactive suppliers cannot be chosen for new orders or deliveries.
- A supplier that already has purchase orders or deliveries cannot be deleted — set it to Inactive instead so your history stays complete.
T],
['features', 'Purchase order management', 'feature-purchase-orders', 'Create orders, choose suppliers and receive products.', 0, 6, <<<'T'
Purchase orders track what you asked a supplier to deliver.

## Order life cycle
1. **Draft** – you can still edit or delete it.
2. **Ordered** – placed with the supplier. It can no longer be edited, but can be cancelled.
3. **Partial** – some items were received. You can receive the rest, or **Close** the order.
4. **Received** – everything has arrived.
5. **Cancelled** – an order with no received stock can be cancelled.

## Create and receive
- **New purchase order**: choose the supplier, order date, expected delivery date, notes and add the items with quantity and unit cost. Save it as a draft or place it.
- **Receive**: open **Stock in → receive against a purchase order**, enter the received quantities and confirm. Stock levels, batches and the order status update automatically.
- Orders can be printed from the order page.
T],
['features', 'Expiration and batch / lot tracking', 'feature-expiry', 'Monitor expiry dates, batch numbers and near-expiry alerts.', 1, 7, <<<'T'
Switch on **Track batch / lot number and expiry date** for a product and every delivery is stored as a batch with its own number, expiry date and remaining quantity.

## Expiry tracking page
- Batches are shown as **Expired**, **Near expiry** or **Valid**. A batch is valid through its expiry date and expired from the next day.
- Use the buttons to filter the list, search by medicine or batch, and open the Expiry report.
- **Write off** removes an expired batch from your stock. **Release** takes you to Stock out.

## Alerts
- The *near-expiry window* is 90 days by default. The administrator can change it in Settings, and you can override it per product.
- Expired and near-expiry batches appear in the sidebar counter, the bell menu and on the dashboard.

> Stock is taken from the earliest-expiring batch first (FEFO) so older stock leaves before newer stock.
T],
['features', 'Reports and analytics', 'feature-reports', 'Generate, filter, review and export inventory reports.', 0, 8, <<<'T'
1. Open **Reports**.
2. Pick a **Report** type from the list, then set the **From** and **To** dates (or the **Year** for yearly views).
3. Click **Generate**.
4. Review the table and totals. Use **CSV** to download the data for Excel, or **Print** for a paper copy.

Reports cover stock levels, deliveries, releases and sales, expiry, purchase orders and suppliers. They only ever show the inventory you are working in.
T],
['features', 'User management', 'feature-users', 'Administrator and regular user roles, permissions and restrictions.', 0, 9, <<<'T'
The system has two roles.

## Regular user
Owns one or more inventories and manages everything inside them: products, categories, suppliers, purchase orders, stock in/out, monitoring, expiry and reports. A regular user can never see another user's inventory.

## Administrator
Manages the platform: creates and edits users, sets account status (active, inactive, suspended), reads every inventory, product and transaction, runs platform-wide reports, reads the activity log and changes system settings. Administrators **inspect** other people's inventories but do not edit them.

> Security note: administrator pages are protected on the server. A regular user who types an administrator address simply sees *Access denied*.
T],
// ---------------------------------------------------------------- how-to
['how-to', 'How to record stock coming in', 'howto-stock-in', 'Add a delivery to your inventory.', 0, 1, <<<'T'
1. Open **Stock in**.
2. Choose the **Product**. The current quantity is shown beside it.
3. Choose the **Supplier**. This is required for tracked medicines so every batch can be traced.
4. For tracked products enter the **Batch / lot no.** and **Expiry date**.
5. Enter **Quantity received**, **Unit cost** and optional notes.
6. Click **Add to inventory**.

The quantity goes up straight away and a line is added to the Stock-in history.
T],
['how-to', 'How to record stock going out', 'howto-stock-out', 'Record a sale, release or write-off.', 0, 2, <<<'T'
1. Open **Stock out**.
2. Choose the **Product** and, if you want a specific batch, the **Batch / lot** (otherwise the earliest-expiring batch is used).
3. Choose the **Reason**: Sale, Released for use, Damaged or expired, or Returned to supplier.
4. Enter the **Quantity** and, for sales, the **Unit price**. Add a customer or recipient if useful.
5. Click **Deduct from inventory**.

> You cannot take out more than is on hand. If you see *Not enough stock*, check the quantity or choose another batch.
T],
['how-to', 'How to create and receive a purchase order', 'howto-purchase-order', 'From a draft order to received stock.', 0, 3, <<<'T'
1. Open **Purchase orders → New purchase order**.
2. Choose an active **Supplier**, set the **Order date** and **Expected delivery**.
3. Add each item with its quantity and unit cost. Add at least one item.
4. Save. Open the order and click **Place order** when you are ready.
5. When the delivery arrives, go to **Stock in**, choose **Receive against a purchase order**, pick the order and enter the received quantities.

Partial deliveries are fine: the order becomes *Partial* until everything has arrived.
T],
// ---------------------------------------------------------------- troubleshooting
['troubleshooting', 'Common error messages and what to do', 'troubleshooting-errors', 'Meaning and fix for the messages people see most.', 1, 1, <<<'T'
## "Not enough stock for … Available: N"
You tried to release more than you have. Lower the quantity, or record the delivery first.

## "Choose the supplier of this medicine so every batch can be traced back to its source."
The product tracks batches, so Stock in needs a supplier. Pick one (register it under **Suppliers** if needed).

## "A category with that name already exists." / "A supplier named … already exists."
Names must be unique per inventory. Use a different name or edit the existing record.

## "This supplier has purchase orders or deliveries on record, so it cannot be deleted."
Set the supplier to **Inactive** instead.

## "Only draft purchase orders can be edited."
Once placed, an order is locked. Cancel it and create a new one if the details were wrong.

## "Invalid request token. Go back and try again."
Your page was open for a long time or your session ended. Go back, reload the page and submit again.

## "Image upload failed."
Use a JPG, PNG, WEBP or GIF image within the size limit shown on the form.

## "Access denied. Administrators only."
The page is for administrators. Use the menu to return to your own pages.
T],
['troubleshooting', 'I cannot sign in', 'troubleshooting-login', 'Login problems and how to solve them.', 0, 2, <<<'T'
- **Wrong username or password** – check Caps Lock. Too many failed attempts temporarily slow down further tries, so wait a few minutes.
- **"Your account has been suspended / deactivated"** – only an administrator can reactivate it. [Contact support](help_contact.php).
- **"The system is under maintenance"** – the administrator turned maintenance mode on. Try again later.
- **Forgot your password** – use *Forgot password* on the login page. If that does not work, ask your administrator to set a temporary password; you will be asked to choose a new one at next sign-in.
- **Signed out unexpectedly** – sessions expire. Tick *Remember me* on trusted devices.
T],
['troubleshooting', 'Pages will not load or the database is not connected', 'troubleshooting-server', 'For whoever runs the server (XAMPP).', 0, 3, <<<'T'
If you see *Database connection failed*:
1. Open the **XAMPP Control Panel** and make sure both **Apache** and **MySQL** are running.
2. Confirm the database name and credentials in `config.php`.
3. Make sure `database.sql` was imported into phpMyAdmin.

If images do not upload, check that the `uploads` folder (and `uploads/help`) is writable.
T],
// ---------------------------------------------------------------- accounts & roles
['accounts-roles', 'Roles and permissions explained', 'roles-permissions', 'Exactly what administrators and regular users can do.', 0, 1, <<<'T'
| Area | Regular user | Administrator |
|---|---|---|
| Own inventories, products, suppliers, orders | Yes | Read only (inspect) |
| Stock in / stock out | Yes | No |
| Reports | Own inventory | Platform-wide |
| Create or suspend users | No | Yes |
| Activity log | No | Yes |
| System settings | No | Yes |

- **Hiding a menu item is not the security.** Every page re-checks your role on the server.
- Your role and account status are re-read from the database on every request, so a change by the administrator applies immediately.
- The last active administrator can never be removed or locked out.

Try the [Users demo](help_demo.php?module=users) to see what each role can open.
T],
['accounts-roles', 'Change your password and profile', 'profile-and-password', 'Keep your account details up to date.', 0, 2, <<<'T'
- **Profile** – open *Profile & settings* to change your name, email and photo.
- **Password** – choose *Change password* from the account menu (top right). Use 8–72 characters with a letter and a number.
- If an administrator created your account with a temporary password you must change it the first time you sign in.
T],
    ];
    foreach ($articles as [$c, $title, $slug, $sum, $feat, $ord, $body]) {
        // The role table above uses a pipe table; the renderer has no tables, so turn it into a list for display
        if ($slug === 'roles-permissions') $body = help_seed_table_to_list($body);
        $art->execute([$id[$c], $title, $slug, $sum, $body, $feat, $ord]);
    }

    // ---------------------------------------------------------------- FAQs
    $faq = $pdo->prepare('INSERT INTO help_faqs (category_id,question,answer,sort_order) VALUES (?,?,?,?)');
    $faqs = [
        ['getting-started', 'Is Demo mode safe? Will it change my real inventory?', 'Demo mode runs entirely in your browser with made-up sample data. It never sends anything to the server, so it cannot touch your products, stock or database. Use **Reset sample data** any time to start over.'],
        ['getting-started', 'Can I have more than one inventory?', 'Yes. Create more under **My inventories** (up to the limit the administrator sets) and switch between them from the sidebar. Each inventory is completely separate.'],
        ['features', 'How does the system know a product is low on stock?', 'Every product has a **Minimum stock level**. When the quantity is at or below it, the product shows as *Low stock* and appears in alerts. At zero it is *Out of stock*.'],
        ['features', 'What does "Track batch / lot number and expiry date" do?', 'It stores each delivery as a batch with its own number, expiry date and remaining quantity. Stock out then uses the earliest-expiring batch first and expired batches cannot be sold.'],
        ['features', 'How early am I warned about expiring medicines?', '90 days by default. The administrator can change the system default and you can set a different window on any product.'],
        ['features', 'How do I record damaged or expired goods?', 'Use **Stock out** and choose the reason *Damaged or expired* (or **Write off** an expired batch on the Expiry tracking page).'],
        ['features', 'Why can I not delete a supplier?', 'Suppliers with purchase orders or deliveries are kept for your records. Set them to **Inactive** to stop using them.'],
        ['features', 'Can I export reports?', 'Yes. Generate a report, then use **CSV** for a spreadsheet or **Print** for a paper copy.'],
        ['accounts-roles', 'What is the difference between an administrator and a regular user?', 'Regular users manage their own inventories. Administrators manage users and settings and can read everything, but do not edit other people\'s inventories.'],
        ['accounts-roles', 'Can other users see my products?', 'No. Every inventory is private to its owner. Only administrators can inspect inventories, read-only.'],
        ['troubleshooting', 'The page says "Invalid request token". What now?', 'Your session or page expired. Go back, reload and submit again.'],
        ['troubleshooting', 'How long does support take to reply?', 'Replies appear under **Help Center → My requests** (or via your private ticket link if you were not signed in). Technical issues and high-priority requests are looked at first.'],
    ];
    foreach ($faqs as $i => [$c, $q, $a]) $faq->execute([$id[$c], $q, $a, $i + 1]);

    // ---------------------------------------------------------------- tutorials (steps drive the Demo mode sandbox)
    // demo_action syntax: open:<product|supplier|po|receive>  close  tab:<in|out|monitor>  fill:<key>=<value>  click:<key>
    //                     several actions separated by ;     {+365d} in a value = a date 365 days from today
    $tut = $pdo->prepare('INSERT INTO help_tutorials (title,slug,description,icon,est_minutes,is_published,sort_order) VALUES (?,?,?,?,?,1,?)');
    $stp = $pdo->prepare('INSERT INTO help_tutorial_steps (tutorial_id,step_no,title,body,target_module,target_selector,demo_action) VALUES (?,?,?,?,?,?,?)');
    $tutorials = [
        ['Adding a new product', 'adding-a-new-product', 'Create a product with prices, stock level and expiry tracking.', 'box-seam', 3, [
            ['Open Products', 'Click **Products** in the sidebar to see every product in your inventory.', 'products', 'nav-products', ''],
            ['Start a new product', 'Click **Add product** to open the product form.', 'products', 'products-add', ''],
            ['Name and SKU', 'Enter a unique **SKU / product code** and the **Product name**. Both are required.', 'products', 'pm-name', 'open:product; fill:pm-name=Amoxicillin 500mg; fill:pm-sku=MED-101'],
            ['Category and prices', 'Pick a **Category**, then enter the **Cost price** (what you pay) and **Selling price** (what you charge).', 'products', 'pm-prices', 'fill:pm-category=4; fill:pm-cost=6; fill:pm-price=12'],
            ['Quantity and minimum level', 'Enter the **Opening quantity** you have now and the **Minimum stock level**. At or below the minimum the product is flagged *Low stock*.', 'products', 'pm-stock', 'fill:pm-qty=30; fill:pm-min=40'],
            ['Track expiry (optional)', 'Switch on **Track batch / lot number and expiry date** for medicines, then enter the opening **batch number** and **expiry date**.', 'products', 'pm-track', 'fill:pm-track=1; fill:pm-batch=B2611X; fill:pm-expiry={+540d}'],
            ['Save', 'Click **Save product**. The form checks required fields and duplicate SKUs before saving.', 'products', 'pm-save', ''],
            ['See your new product', 'The product now appears in the list. Because 30 is below the minimum of 40 it is marked **Low stock** — a good example of an alert.', 'products', 'products-table', 'click:pm-save'],
        ]],
        ['Registering a supplier', 'registering-a-supplier', 'Add a supplier so you can order from them and trace batches.', 'truck', 3, [
            ['Open Suppliers', 'Click **Suppliers** in the sidebar.', 'suppliers', 'nav-suppliers', ''],
            ['Add a supplier', 'Click **Add supplier**. Every supplier receives an automatic code such as SUP-0004.', 'suppliers', 'suppliers-add', ''],
            ['Name and contact person', 'Enter the supplier **name** and the **contact person**.', 'suppliers', 'sm-basic', 'open:supplier; fill:sm-name=HealthFirst Distributors; fill:sm-contact=Jon Cruz'],
            ['Phone and email', 'Add a **phone number** and **email**. The form validates the format for you.', 'suppliers', 'sm-contactinfo', 'fill:sm-phone=0917 555 0199; fill:sm-email=jon@healthfirst.example'],
            ['Save', 'Click **Save supplier**.', 'suppliers', 'sm-save', ''],
            ['Your supplier is registered', 'The supplier is listed as **Active** and can now be chosen for purchase orders and deliveries. Set it to Inactive later if you stop working with it.', 'suppliers', 'suppliers-table', 'click:sm-save'],
        ]],
        ['Recording incoming and outgoing stock', 'recording-stock-in-out', 'Add a delivery, then release stock and watch the history.', 'arrow-left-right', 4, [
            ['Open Inventory', 'Stock movements are handled in the **Inventory** area (Stock in and Stock out in your real menu).', 'inventory', 'nav-inventory', ''],
            ['Stock in', 'Use the **Stock in** tab when goods arrive from a supplier.', 'inventory', 'tab-in', 'tab:in'],
            ['Describe the delivery', 'Choose the **product**, the **supplier**, the **quantity received** and the **unit cost**.', 'inventory', 'in-form', 'fill:in-product=1; fill:in-supplier=1; fill:in-qty=100; fill:in-cost=2.5'],
            ['Batch and expiry', 'Tracked medicines need a **batch / lot number** and an **expiry date**, so each delivery can be traced.', 'inventory', 'in-batchbox', 'fill:in-batch=B2611A; fill:in-expiry={+365d}'],
            ['Add to inventory', 'Click **Add to inventory**. The quantity goes up and a line is written to the movement history.', 'inventory', 'movements', 'click:in-submit'],
            ['Stock out', 'When goods leave, use the **Stock out** tab.', 'inventory', 'tab-out', 'tab:out'],
            ['Describe the release', 'Pick the product, the **reason** (sale, released for use, damaged or expired, returned to supplier) and the **quantity**. You cannot release more than you have.', 'inventory', 'out-form', 'fill:out-product=1; fill:out-reason=sale; fill:out-qty=20'],
            ['Deduct from inventory', 'Click **Deduct from inventory**. For tracked products the batch that expires first is used first.', 'inventory', 'movements', 'click:out-submit'],
        ]],
        ['Creating purchase orders', 'creating-purchase-orders', 'Order from a supplier and receive the delivery.', 'receipt', 4, [
            ['Open Purchase orders', 'Click **Purchase orders** in the sidebar to see all orders and their status.', 'orders', 'nav-orders', ''],
            ['Create a new order', 'Click **New purchase order**.', 'orders', 'po-new', ''],
            ['Choose the supplier', 'Select the **supplier**. Only active suppliers can be chosen.', 'orders', 'po-supplier', 'open:po; fill:po-supplier=1'],
            ['Add items', 'Pick a product, enter the **quantity** and **unit cost**, then add the line. Repeat for every item.', 'orders', 'po-lines', 'fill:po-product=2; fill:po-qty=150; fill:po-cost=1.8; click:po-add-line'],
            ['Place the order', 'Save as a **draft** to edit later, or **Place order** to send it to the supplier. Placed orders can no longer be edited.', 'orders', 'po-place', ''],
            ['Order created', 'The order appears with the status **Ordered**.', 'orders', 'po-table', 'click:po-place'],
            ['Receive the delivery', 'When goods arrive, click **Receive**, check the quantities (or accept the remaining amounts).', 'orders', 'receive-form', 'open:receive'],
            ['Confirm', 'Confirm receipt. Stock rises, batches are created for tracked products, and the order becomes **Received** (or **Partial**).', 'orders', 'po-table', 'click:rc-confirm'],
        ]],
        ['Monitoring low-stock products', 'monitoring-low-stock', 'Find what needs restocking before you run out.', 'activity', 3, [
            ['Start at the Dashboard', 'The **Dashboard** is your overview of the inventory.', 'dashboard', 'nav-dashboard', ''],
            ['Read the statistics', 'These cards summarise products, stock value, low stock and expiry alerts.', 'dashboard', 'dash-stats', ''],
            ['Low-stock alerts', 'Products at or below their minimum level are listed here, with the quantity left.', 'dashboard', 'dash-alerts', ''],
            ['Open Stock monitoring', 'For the full picture open **Stock monitoring**.', 'inventory', 'tab-monitor', 'tab:monitor'],
            ['Filter what needs attention', 'Filter to **Needs attention** to see only low and out-of-stock products.', 'inventory', 'monitor-filter', 'fill:monitor-filter=attention'],
            ['Restock', 'Each row has a level meter. Click **Restock** to jump to Stock in with that product selected.', 'inventory', 'monitor-table', ''],
        ]],
        ['Tracking expiration dates', 'tracking-expiration-dates', 'Spot expired and near-expiry batches.', 'calendar-x', 3, [
            ['Open Expiry tracking', 'Click **Expiry tracking** in the sidebar.', 'expiry', 'nav-expiry', ''],
            ['Summary', 'These counters show **expired**, **near expiry** (inside the alert window) and **valid** batches.', 'expiry', 'expiry-summary', ''],
            ['Filter near-expiry batches', 'Use the filter to see only batches that expire soon, so you can sell or return them first.', 'expiry', 'expiry-filter', 'fill:expiry-filter=near'],
            ['Batch details', 'Every row shows the **batch number**, **expiry date**, quantity left and the days remaining.', 'expiry', 'expiry-table', ''],
            ['Write off expired stock', 'Show **Expired** batches, then use **Write off** to remove them from your stock so levels stay accurate.', 'expiry', 'expiry-table', 'fill:expiry-filter=expired'],
        ]],
        ['Generating inventory reports', 'generating-reports', 'Choose a report, filter by dates and export.', 'graph-up', 3, [
            ['Open Reports', 'Click **Reports** in the sidebar.', 'reports', 'nav-reports', ''],
            ['Choose the report', 'Pick what you want to see: stock levels, deliveries, releases, expiry, purchase orders or suppliers.', 'reports', 'report-type', 'fill:report-type=stockin'],
            ['Filter by date', 'Set the **From** and **To** dates.', 'reports', 'report-dates', ''],
            ['Generate', 'Click **Generate** to build the report.', 'reports', 'report-result', 'click:report-generate'],
            ['Export or print', 'Use **CSV** to open the data in Excel, or **Print** for a paper copy.', 'reports', 'report-export', ''],
        ]],
    ];
    foreach ($tutorials as $i => [$title, $slug, $desc, $icon, $min, $steps]) {
        $tut->execute([$title, $slug, $desc, $icon, $min, $i + 1]);
        $tid = (int)$pdo->lastInsertId();
        foreach ($steps as $n => [$st, $body, $mod, $sel, $act]) $stp->execute([$tid, $n + 1, $st, $body, $mod, $sel, $act]);
    }
}

function help_seed_table_to_list($body) {
    $out = [];
    foreach (preg_split('/\R/', $body) as $ln) {
        if (preg_match('/^\|\s*-/', $ln)) continue;
        if (preg_match('/^\|(.+)\|$/', trim($ln), $m)) {
            $c = array_map('trim', explode('|', $m[1]));
            if ($c[0] === 'Area') continue;
            $out[] = '- **' . $c[0] . '** – regular user: ' . $c[1] . '; administrator: ' . $c[2];
        } else $out[] = $ln;
    }
    return implode("\n", $out);
}
