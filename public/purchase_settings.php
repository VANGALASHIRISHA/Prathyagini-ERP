<?php
// Super Admin: the GST rates that may be chosen on purchase bills. Nothing is hardcoded in the calculations.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
require_role(['super_admin']);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $raw = trim((string)($_POST['rates'] ?? ''));
        $rates = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (!is_numeric($p) || (float)$p < 0 || (float)$p > 100) throw new Exception('"' . $p . '" is not a valid GST rate (use numbers between 0 and 100).');
            $rates[] = round((float)$p, 2);
        }
        $rates = array_values(array_unique($rates));
        sort($rates);
        if (!$rates) throw new Exception('Enter at least one GST rate.');
        db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['purchase_gst_rates', implode(',', $rates)]);
        audit('PURCHASE_GST_RATES_UPDATE', 'settings', null, ['rates' => $rates]);
        flash('ok', 'GST rates saved.');
        redirect('purchase_settings.php');
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
page_header('Purchase Settings');
if ($m = flash('ok')) echo '<div class="notice success">' . e($m) . '</div>';
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
?>
<form method="post" class="panel">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label>Allowed GST rates on purchase bills (%) — separate with commas</label>
<input name="rates" value="<?= e(implode(', ', pm_gst_rates())) ?>" required>
<p class="muted">Set these according to the current GST rules for the goods you buy; ask your accountant. A bill line can only use a rate in this list, and each product's own GST rate (Products page) is pre-selected when you pick it. Changing the list never changes bills already saved.</p>
<p class="muted">GST type is decided from the state codes: Company State Code (Company Settings) = <b><?= e(pm_company_state_code() ?: 'not set') ?></b>. Same state as the vendor = CGST + SGST, different state = IGST.</p>
<button class="btn">Save</button>
</form>
<?php page_footer();
