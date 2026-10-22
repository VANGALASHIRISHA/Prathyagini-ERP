<?php
function role_label(string $role): string {
    return match($role) {
        'super_admin' => 'Super Admin',
        'manager' => 'Manager',
        'godown' => 'Godown',
        'cashier' => 'Cashier',
        'auditor' => 'Auditor',
        default => $role,
    };
}
function role_panel_links(): array {
    $role = current_user()['role'] ?? '';
    $notes = 'notifications.php';
    return match($role) {
        'super_admin' => [
            ['Dashboard', 'super_admin_dashboard.php'],
            ['Company Settings', 'settings.php'],
            ['Locations', 'locations.php'],
            ['Users & Roles', 'users.php'],
            ['Products', 'products.php'],
            ['Barcode Creation', 'barcode_create.php'],
            ['Suppliers', 'suppliers.php'],
            ['Categories', 'categories.php'],
            ['All-branch Stock', 'stock.php'],
            ['Scan & Find', 'scan_find.php'],
            ['Product Finder', 'product_finder.php'],
            ['Purchase Inward', 'purchase_new.php'],
            ['Vendor Register', 'vendors.php'],
            ['Add Purchase', 'purchase_bill.php'],
            ['Purchase History', 'purchase_history.php'],
            ['Goods Receipt', 'goods_receipt.php'],
            ['Vendor Payments', 'vendor_payments.php'],
            ['Purchase Returns', 'purchase_return.php'],
            ['Purchase Reports', 'purchase_reports.php'],
            ['GST Reports', 'gst_reports.php'],
            ['Purchase Settings', 'purchase_settings.php'],
            ['Bulk Stock Import', 'purchase_import.php'],
            ['POS Billing', 'pos.php'],
            ['Online Orders', 'online_orders.php'],
            ['Sales Reports', 'reports.php'],
            ['Audit Log', 'audit.php'],
            ['Integrity Report', 'inventory_integrity.php'],
            ['Backup & Restore', 'backup.php'],
            ['Pre-flight Check', 'preflight.php'],
            ['License', 'license_status.php'],
            ['Notifications', $notes],
        ],
        'manager' => [
            ['Dashboard', 'manager_dashboard.php'],
            ['Assigned Stock', 'stock.php'],
            ['Scan & Find', 'scan_find.php'],
            ['Product Finder', 'product_finder.php'],
            ['Stock Reports', 'stock_reports.php'],
            ['Transfer', 'stock_transfer.php'],
            ['Approve Transfer', 'stock_transfer_approve.php'],
            ['Dispatch Transfer', 'stock_transfer_dispatch.php'],
            ['Receive Transfer', 'stock_transfer_receive.php'],
            ['Products', 'products.php'],
            ['Barcode Creation', 'barcode_create.php'],
            ['Customers', 'customers.php'],
            ['Staff Listing', 'staff_listing.php'],
            ['POS Billing', 'pos.php'],
            ['Online Orders', 'online_orders.php'],
            ['Pick', 'online_pick.php'],
            ['Pack', 'online_pack.php'],
            ['Dispatch', 'online_dispatch.php'],
            ['Delivery / RTO', 'online_after_dispatch.php'],
            ['Physical Count', 'stock_count.php'],
            ['Sales Reports', 'reports.php'],
            ['Notifications', $notes],
        ],
        'godown' => [
            ['Dashboard', 'godown_dashboard.php'],
            ['Stock Identification', 'stock.php'],
            ['Scan & Find', 'scan_find.php'],
            ['Stock Report', 'stock_reports.php'],
            ['Product Finder', 'product_finder.php'],
            ['Move Stock', 'stock_transfer.php'],
            ['Approve Transfer', 'stock_transfer_approve.php'],
            ['Dispatch Transfer', 'stock_transfer_dispatch.php'],
            ['Receive Transfer', 'stock_transfer_receive.php'],
            ['Goods Receipt', 'goods_receipt.php'],
            ['Pick', 'online_pick.php'],
            ['Pack', 'online_pack.php'],
            ['Return / RTO Receive', 'online_return_receive.php'],
            ['Physical Count', 'stock_count.php'],
            ['Rack Labels', 'location_labels.php'],
            ['Notifications', $notes],
        ],
        'cashier' => [
            ['Dashboard', 'cashier_dashboard.php'],
            ['POS Billing', 'pos.php'],
            ['Available Stock', 'stock.php'],
            ['My Invoices', 'cashier_invoices.php'],
            ['Product Finder', 'product_finder.php'],
            ['Notifications', $notes],
        ],
        'auditor' => [
            ['Dashboard', 'auditor_dashboard.php'],
            ['Audit Log', 'audit.php'],
            ['Integrity Report', 'inventory_integrity.php'],
            ['Purchase History', 'purchase_history.php'],
            ['Vendor Register', 'vendors.php'],
            ['Goods Receipt', 'goods_receipt.php'],
            ['Vendor Payments', 'vendor_payments.php'],
            ['Purchase Reports', 'purchase_reports.php'],
            ['GST Reports', 'gst_reports.php'],
            ['Stock', 'stock.php'],
            ['Stock Reports', 'stock_reports.php'],
            ['Scan & Find', 'scan_find.php'],
            ['Product Finder', 'product_finder.php'],
            ['Sales Reports', 'reports.php'],
            ['Notifications', $notes],
        ],
        default => [
            ['Dashboard', role_home_url()],
            ['Stock', 'stock.php'],
            ['Stock Reports', 'stock_reports.php'],
            ['Product Finder', 'product_finder.php'],
            ['Sales Reports', 'reports.php'],
            ['Notifications', $notes],
        ],
    };
}
function page_header(string $title): void { $u=current_user(); $company=setting('company_name','Pratyangi Fashions')??'Pratyangi Fashions';
    $role=$u['role']??''; $bodyClass=$u?'has-panel role-'.preg_replace('/[^a-z_]/','',$role):'';
    $current=basename(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:''); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> - <?=e($company)?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="<?=e($bodyClass)?>">
<header><div class="brand"><?php if($u): ?><button type="button" id="navToggle" class="nav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="sideNav">&#9776;</button><?php endif; ?><?=e(strtoupper($company))?> <span>ERP</span></div><?php if($u): ?><div class="user"><?=e($u['name'])?> · <?=e(role_label($role))?> · <a href="logout.php">Logout</a></div><?php endif;?></header>
<?php if($u): ?><div class="nav-backdrop" id="navBackdrop"></div><div class="app-shell"><aside class="side" id="sideNav"><div class="role-kicker"><?=e(role_label($role))?> panel</div><?php foreach(role_panel_links() as [$label,$href]): ?><a class="<?=$current===$href?'active':''?>" href="<?=e($href)?>"><?=e($label)?><?php if($href==='notifications.php'):?> (<span id="notificationCount"><?=e(unread_event_count())?></span>)<?php endif;?></a><?php endforeach; ?></aside><div class="app-main"><?php endif; ?>
<?php if(function_exists('license_banner_html')) echo license_banner_html(); ?>
<main><h1><?=e($title)?></h1>
<?php }
function page_footer(): void { ?></main><?php if(current_user()): ?></div></div><?php endif; ?><footer>Pratyangi Fashions · Barcode Inventory & POS</footer><script src="assets/js/app.js"></script></body></html><?php }
function flash(string $key, ?string $value=null): ?string {
    if($value!==null){$_SESSION['flash'][$key]=$value;return null;}
    $v=$_SESSION['flash'][$key]??null; unset($_SESSION['flash'][$key]); return $v;
}
