<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
require __DIR__ . '/../app/purchase_reports_lib.php';
pm_require_view();

[$from, $to] = pr_dates();
$summary = pr_run('gst', $from, $to);
$regHead = ['Bill date', 'Vendor bill', 'Vendor', 'Vendor GSTIN', 'State', 'GST type', 'Taxable', 'CGST', 'SGST', 'IGST', 'Invoice total'];
$reg = pr_gst_register($from, $to);

$fmt = $_GET['export'] ?? '';
if (in_array($fmt, ['xlsx', 'csv'], true)) {
    if (($_GET['part'] ?? '') === 'register') pr_export('gst_input_register', $regHead, $reg, $fmt);
    pr_export('gst_summary', $summary['head'], $summary['rows'], $fmt);
}
$sum = array_fill(0, 4, 0.0);
foreach ($reg as $r) { $sum[0] += $r[6]; $sum[1] += $r[7]; $sum[2] += $r[8]; $sum[3] += $r[9]; }
$qs = http_build_query(['from' => $from, 'to' => $to]);
page_header('GST Reports (Purchases)');
?>
<form method="get" class="actions no-print">
  <input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn">Show</button>
  <a class="btn secondary" href="?<?= e($qs) ?>&export=xlsx">Excel (summary)</a><a class="btn secondary" href="?<?= e($qs) ?>&part=register&export=xlsx">Excel (bill register)</a>
  <a class="btn secondary" href="?<?= e($qs) ?>&export=csv">CSV</a><button type="button" class="btn secondary" onclick="window.print()">Print / Save PDF</button>
</form>
<div class="notice">Input GST on purchases from <?= e($from) ?> to <?= e($to) ?>. Rates come from the bills; the allowed rates are configured in <?= is_super_admin() ? '<a href="purchase_settings.php">Purchase Settings</a>' : 'Purchase Settings (Super Admin)' ?>. This is a working summary, not a filed return. Check it with your accountant.</div>
<h3>GST-rate-wise summary</h3>
<div style="overflow-x:auto"><table>
<tr><?php foreach ($summary['head'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
<?php foreach ($summary['rows'] as $row): ?><tr><?php foreach ($row as $v): ?><td><?= e(pr_cell($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$summary['rows']): ?><tr><td colspan="7" class="muted">No GST purchases in this period.</td></tr><?php endif; ?>
</table></div>
<h3>Vendor bill register (input tax credit)</h3>
<div style="overflow-x:auto"><table>
<tr><?php foreach ($regHead as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
<?php foreach ($reg as $row): ?><tr><?php foreach ($row as $i => $v): ?><td><?= e($i === 5 ? str_replace('_', ' + ', (string)$v) : pr_cell($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if ($reg): ?><tr><td colspan="6"><b>Total</b></td><td><b><?= e(number_format($sum[0], 2)) ?></b></td><td><b><?= e(number_format($sum[1], 2)) ?></b></td><td><b><?= e(number_format($sum[2], 2)) ?></b></td><td><b><?= e(number_format($sum[3], 2)) ?></b></td><td></td></tr>
<?php else: ?><tr><td colspan="11" class="muted">No bills in this period.</td></tr><?php endif; ?>
</table></div>
<p class="muted"><?= e($summary['note']) ?></p>
<?php page_footer();
