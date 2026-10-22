<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/integrity_lib.php';
require __DIR__ . '/../app/backup_lib.php';
require_role(['super_admin']);

$checks = []; // [group, title, status(PASS|WARN|FAIL), detail]
$add = function (string $group, string $title, string $status, string $detail = '') use (&$checks) { $checks[] = [$group, $title, $status, $detail]; };
$root = dirname(__DIR__);
$cfg = cfg();
$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// --- Server
$add('Server', 'PHP version 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'PASS' : 'FAIL', 'PHP ' . PHP_VERSION);
foreach (['pdo_mysql' => true, 'mbstring' => true, 'openssl' => true, 'curl' => true, 'json' => true, 'gd' => false, 'zip' => false] as $ext => $required) {
    $has = extension_loaded($ext);
    $add('Server', 'PHP extension ' . $ext . ($required ? '' : ' (optional)'), $has ? 'PASS' : ($required ? 'FAIL' : 'WARN'), $has ? 'loaded' : ($required ? 'missing: enable it in cPanel → Select PHP Version' : 'not loaded (the ERP does not need it)'));
}
$tz = date_default_timezone_get();
$add('Server', 'Server time zone is India (Asia/Kolkata)', $tz === 'Asia/Kolkata' ? 'PASS' : 'WARN', $tz . ' · now ' . date('d-m-Y H:i:s'));

// --- Folders
$sess = session_save_path() ?: sys_get_temp_dir();
foreach (['public/uploads/products' => 'Product images', 'storage/backups' => 'Backups'] as $rel => $label) {
    $path = $root . '/' . $rel;
    if ($rel === 'storage/backups') backup_dir();
    $ok = is_dir($path) && is_writable($path);
    $add('Folders', $label . ' folder is writable (' . $rel . ')', $ok ? 'PASS' : 'FAIL', $ok ? 'writable' : 'missing or not writable: set permission 775');
}
$add('Folders', 'Session folder is writable', is_writable($sess) ? 'PASS' : 'FAIL', $sess);

// --- Database
try {
    $tableCount = (int)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
    $add('Database', 'Database connection', 'PASS', 'connected · ' . $tableCount . ' tables');
    $need = ['users', 'products', 'batches', 'inventory_balances', 'stock_ledger', 'product_serials', 'purchases', 'purchase_items', 'sales', 'sale_items', 'stock_transfers', 'stock_transfer_items', 'stock_count_sessions', 'stock_count_exceptions', 'online_orders', 'audit_logs', 'settings', 'license_client_state'];
    $have = db()->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    $miss = array_diff($need, array_map('strtolower', $have));
    $add('Database', 'All required tables exist', $miss ? 'FAIL' : 'PASS', $miss ? 'missing: ' . implode(', ', $miss) . ' (run the SQL upgrade files)' : count($need) . ' core tables found');
    $col = db()->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_transfers' AND column_name='status'")->fetchColumn();
    $add('Database', 'Transfer workflow upgrade applied (v1.6)', str_contains((string)$col, 'DISPATCHED') ? 'PASS' : 'FAIL', str_contains((string)$col, 'DISPATCHED') ? 'Draft → Approved → Dispatched → Received available' : 'run sql/migration_v1_6_transfer_workflow.sql');
    foreach ([['product_serials', 'serial_barcode', 'Every garment barcode is unique'], ['products', 'sku', 'Every SKU is unique'], ['sales', 'invoice_no', 'Every invoice number is unique']] as [$t, $c, $label]) {
        $u = (int)db()->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='$t' AND column_name='$c' AND non_unique=0 AND seq_in_index=1")->fetchColumn();
        $add('Database', $label . ' (database rule)', $u ? 'PASS' : 'FAIL', $u ? 'UNIQUE index on ' . $t . '.' . $c : 'missing UNIQUE index');
    }
    $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND active=1")->fetchColumn();
    $add('Database', 'At least one active Super Admin', $admins ? 'PASS' : 'FAIL', $admins . ' active');
    $locs = (int)db()->query("SELECT COUNT(*) FROM locations WHERE active=1")->fetchColumn();
    $add('Setup data', 'At least one active location (godown / branch)', $locs ? 'PASS' : 'FAIL', $locs . ' active');
    $sup = (int)db()->query("SELECT COUNT(*) FROM suppliers WHERE active=1")->fetchColumn();
    $add('Setup data', 'At least one supplier', $sup ? 'PASS' : 'WARN', $sup . ' active (needed before Purchase Inward)');
    $prod = (int)db()->query("SELECT COUNT(*) FROM products WHERE active=1")->fetchColumn();
    $add('Setup data', 'At least one product', $prod ? 'PASS' : 'WARN', $prod . ' active');
} catch (Throwable $e) {
    error_log('preflight db: ' . $e->getMessage());
    $add('Database', 'Database checks', 'FAIL', 'could not run the database checks');
}

// --- Company settings
$set = fn($k) => trim((string)(setting($k, '') ?? ''));
$add('Company settings', 'Company name', $set('company_name') !== '' ? 'PASS' : 'FAIL', $set('company_name'));
$add('Company settings', 'State code (billing is blocked without it)', $set('company_state_code') !== '' ? 'PASS' : 'FAIL', $set('company_state_code') !== '' ? $set('company_state_code') : 'Settings → Company State Code (for Andhra Pradesh: 37)');
$add('Company settings', 'GSTIN printed on invoices', $set('company_gstin') !== '' ? 'PASS' : 'WARN', $set('company_gstin') !== '' ? 'filled' : 'empty: add your GSTIN in Company Settings');
$add('Company settings', 'Address printed on invoices', $set('company_address') !== '' ? 'PASS' : 'WARN', $set('company_address') !== '' ? 'filled' : 'empty');

// --- Security / config
$add('Security', 'Debug mode is off', empty($cfg['app']['debug']) ? 'PASS' : ($isLocal ? 'WARN' : 'FAIL'), empty($cfg['app']['debug']) ? 'APP_DEBUG=false' : 'APP_DEBUG is true: set it to false on the live server');
$bypass = !empty($cfg['license']['local_development_bypass']);
$add('Security', 'Local licence bypass is off', !$bypass ? 'PASS' : ($isLocal ? 'WARN' : 'FAIL'), !$bypass ? 'APP_LOCAL_LICENSE_BYPASS=false' : 'bypass is ON (it only works on localhost, but turn it off on every real server)');
$add('Security', 'Site is served over HTTPS', $https ? 'PASS' : ($isLocal ? 'WARN' : 'FAIL'), $https ? 'https' : ($isLocal ? 'http is fine on your own computer' : 'install the SSL certificate (AutoSSL)'));
$pk = @file_get_contents((string)($cfg['license']['public_key_path'] ?? ''));
$add('Security', 'Licence public key file is valid', ($pk && @openssl_pkey_get_public($pk)) ? 'PASS' : 'FAIL', ($pk && @openssl_pkey_get_public($pk)) ? 'license/public_key.pem readable' : 'missing or not a valid key');
if (PHP_SAPI === 'cli-server') {
    $add('Security', 'Private files are not downloadable', 'WARN', 'skipped: this test cannot run on the PHP built-in server (try it on the real server)');
} else {
    $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/';
    $leaks = [];
    foreach (['.env', 'config.php', 'sql/database.sql', 'app/bootstrap.php', 'storage/backups/', 'tests/'] as $p) {
        $ch = curl_init($base . $p); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => false]);
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && preg_match('/DB_PASS|CREATE TABLE|<\?php|Index of/i', $body)) $leaks[] = $p;
    }
    $add('Security', 'Private files are not downloadable (.env, config, sql, backups, tests)', $leaks ? 'FAIL' : 'PASS', $leaks ? 'exposed: ' . implode(', ', $leaks) : 'none of them can be read from the web');
}

// --- Licence
try {
    $st = license_effective_state(false);
    $add('Licence', 'ERP licence is active', in_array($st['status'], ['ACTIVE', 'EXPIRING', 'GRACE'], true) ? 'PASS' : ($isLocal && $bypass ? 'WARN' : 'FAIL'), $st['status'] . ' · ' . $st['message']);
    $payload = $st['payload'] ?? null;
    if ($payload) $add('Licence', 'Licence details', 'PASS', ($payload['shop_name'] ?? '') . ' · plan ' . ($payload['plan'] ?? '') . ' · expires ' . ($payload['expires_at'] ?? '') . ' · max users ' . ($payload['max_users'] ?? ''));
    $srv = function_exists('license_server_base') ? license_server_base() : '';
    if ($srv !== '') {
        $ch = curl_init($srv . '/api/v1/ping.php'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode($body, true);
        $add('Licence', 'Licence server reachable (' . parse_url($srv, PHP_URL_HOST) . ')', ($code === 200 && !empty($j['ok'])) ? 'PASS' : 'FAIL', ($code === 200 && !empty($j['ok'])) ? 'ping OK · server time ' . ($j['server_time'] ?? '') : 'no answer (HTTP ' . $code . '): check LICENSE_SERVER_URL and that the portal is running');
    }
} catch (Throwable $e) {
    $add('Licence', 'Licence checks', 'FAIL', 'could not run');
}

// --- Data health (re-uses the integrity report)
try {
    $ic = integrity_checks();
    $badI = array_filter($ic, fn($c) => $c['count'] !== 0);
    $add('Data health', 'Inventory integrity (stock = ledger = barcodes)', $badI ? 'WARN' : 'PASS', $badI ? count($badI) . ' of ' . count($ic) . ' checks need attention: open Inventory Integrity Report' : 'all ' . count($ic) . ' checks pass');
} catch (Throwable $e) { $add('Data health', 'Inventory integrity', 'WARN', 'could not run'); }
$bl = backup_list();
$lastDb = null; foreach ($bl as $f) if (str_starts_with($f['name'], 'db_')) { $lastDb = $f; break; }
$add('Data health', 'A recent database backup exists (last 7 days)', ($lastDb && $lastDb['time'] > time() - 7 * 86400) ? 'PASS' : 'WARN', $lastDb ? 'last: ' . date('d-m-Y H:i', $lastDb['time']) : 'no backup yet: open Backup & Restore');

$fail = count(array_filter($checks, fn($c) => $c[2] === 'FAIL'));
$warn = count(array_filter($checks, fn($c) => $c[2] === 'WARN'));
page_header('Pre-flight Check (before Go Live)');
$icon = ['PASS' => '✅', 'WARN' => '⚠️', 'FAIL' => '❌'];
?>
<div class="notice <?= $fail ? 'error' : ($warn ? '' : 'success') ?>"><b><?= $fail ? $fail . ' check(s) FAILED. Fix them before going live.' : ($warn ? 'No failures. ' . $warn . ' warning(s) to review.' : 'All checks pass. Ready for live use.') ?></b> · <?= count($checks) ?> checks · <?= e(date('d-m-Y H:i:s')) ?> · <a href="preflight.php">Run again</a> · <a href="inventory_integrity.php">Integrity report</a> · <a href="backup.php">Backup &amp; Restore</a></div>
<?php $group = ''; foreach ($checks as [$g, $t, $s, $d]): if ($g !== $group): if ($group !== '') echo '</table></div>'; $group = $g; ?>
<h3><?= e($g) ?></h3><div style="overflow-x:auto"><table><tr><th style="width:36px"></th><th>Check</th><th>Result</th></tr>
<?php endif; ?>
<tr><td><?= $icon[$s] ?></td><td><?= e($t) ?></td><td><b><?= e($s) ?></b> · <?= e($d) ?></td></tr>
<?php endforeach; if ($group !== '') echo '</table></div>'; ?>
<div class="notice">Warnings that only say “localhost” (HTTP, debug on) are normal on your own computer and must be green on the live server. Run this page again on the live site after every upload.</div>
<?php page_footer();
