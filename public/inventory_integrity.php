<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/integrity_lib.php';
require_role(['super_admin', 'auditor']);

$checks = integrity_checks();
$bad = array_filter($checks, fn($c) => $c['count'] !== 0);

if (($_GET['export'] ?? '') === 'xlsx') {
    require __DIR__ . '/../app/xlsx.php';
    $rows = [['Check', 'Result', 'Problems', 'Example']];
    foreach ($checks as $c) {
        $rows[] = [$c['title'], $c['count'] === 0 ? 'PASS' : ($c['count'] < 0 ? 'ERROR' : 'FAIL'), $c['count'], $c['rows'] ? json_encode($c['rows'][0], JSON_UNESCAPED_UNICODE) : ''];
    }
    xlsx_download('inventory_integrity_' . date('Ymd_His') . '.xlsx', $rows, 'Integrity');
}

page_header('Inventory Integrity Report');
?>
<p class="muted">Read-only. Compares stock balances, the stock ledger, batches, unique-piece barcodes, bills and transfers, and tells you if any numbers disagree. Run it after a stock count, a restore or any suspicious result. Nothing is changed by this page.</p>
<div class="notice <?= $bad ? 'error' : 'success' ?>"><b><?= $bad ? count($bad) . ' of ' . count($checks) . ' checks need attention.' : 'All ' . count($checks) . ' checks passed. Stock, ledger and barcodes agree.' ?></b> · Checked <?= e(date('d-m-Y H:i:s')) ?> <a class="btn secondary" style="margin-left:8px" href="?export=xlsx">Export Excel</a> <button class="btn secondary" type="button" onclick="window.print()">Print / Save PDF</button></div>
<?php foreach ($checks as $c): ?>
<div class="panel" style="margin-bottom:12px">
<h3 style="margin-top:0"><?= $c['count'] === 0 ? '✅' : ($c['count'] < 0 ? '⚠️' : '❌') ?> <?= e($c['title']) ?> <span class="muted" style="font-weight:400">— <?= $c['count'] === 0 ? 'PASS' : ($c['count'] < 0 ? 'could not run' : $c['count'] . ' problem(s)') ?></span></h3>
<p class="muted" style="margin:0 0 6px"><?= e($c['explain']) ?></p>
<?php if ($c['rows']): $cols = array_keys($c['rows'][0]); ?>
<div style="overflow-x:auto"><table><tr><?php foreach ($cols as $k) echo '<th>' . e(str_replace('_', ' ', $k)) . '</th>'; ?></tr>
<?php foreach ($c['rows'] as $r): ?><tr><?php foreach ($cols as $k) echo '<td>' . e((string)$r[$k]) . '</td>'; ?></tr><?php endforeach; ?></table></div>
<?php if ($c['count'] > count($c['rows'])): ?><p class="muted">Showing the first <?= count($c['rows']) ?> of <?= (int)$c['count'] ?>.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
<div class="notice"><b>If something fails:</b> do not edit the database by hand. Investigate with Scan &amp; Find and the Stock Ledger (Stock Reports). Any correction should be posted as a Stock Adjustment by the Super Admin so it stays in the audit trail.</div>
<?php page_footer();
