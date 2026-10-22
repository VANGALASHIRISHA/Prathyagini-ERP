<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
require __DIR__ . '/../app/purchase_reports_lib.php';
pm_require_view();

$reports = pr_reports();
$key = $_GET['report'] ?? 'vendor';
if (!isset($reports[$key])) $key = 'vendor';
[$from, $to] = pr_dates();
$r = pr_run($key, $from, $to);

if (in_array($_GET['export'] ?? '', ['xlsx', 'csv'], true)) pr_export('purchase_' . $key, $r['head'], $r['rows'], $_GET['export']);

$qs = http_build_query(['report' => $key, 'from' => $from, 'to' => $to]);
page_header('Purchase Reports');
?>
<form method="get" class="actions no-print">
  <select name="report"><?php foreach ($reports as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === $key ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>">
  <button class="btn">Show</button>
  <a class="btn secondary" href="?<?= e($qs) ?>&export=xlsx">Excel</a><a class="btn secondary" href="?<?= e($qs) ?>&export=csv">CSV</a>
  <button type="button" class="btn secondary" onclick="window.print()">Print / Save PDF</button>
  <a class="btn secondary" href="gst_reports.php?from=<?= e($from) ?>&to=<?= e($to) ?>">GST reports</a>
</form>
<h3><?= e($r['title']) ?> <span class="muted" style="font-weight:400">· <?= e($from) ?> to <?= e($to) ?></span></h3>
<div style="overflow-x:auto"><table>
<tr><?php foreach ($r['head'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
<?php foreach ($r['rows'] as $row): ?><tr><?php foreach ($row as $v): ?><td><?= e(pr_cell($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$r['rows']): ?><tr><td colspan="<?= max(1, count($r['head'])) ?>" class="muted">No data in this period.</td></tr><?php endif; ?>
</table></div>
<?php if ($r['note']): ?><p class="muted"><?= e($r['note']) ?></p><?php endif; ?>
<p class="muted">Drafts and cancelled bills are not counted. Bill dates are used for periods (goods-received dates for damaged / rejected goods). PDF: use Print, then choose "Save as PDF".</p>
<?php page_footer();
