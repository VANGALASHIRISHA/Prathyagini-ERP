<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
pm_require_view();

$f = [
    'vendor' => trim($_GET['vendor'] ?? ''), 'area' => trim($_GET['area'] ?? ''), 'bill' => trim($_GET['bill'] ?? ''),
    'product' => trim($_GET['product'] ?? ''), 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'month' => $_GET['month'] ?? '',
    'pay' => $_GET['pay'] ?? '', 'recv' => $_GET['recv'] ?? '', 'status' => $_GET['status'] ?? '',
];
$like = fn($v) => '%' . addcslashes($v, '%_\\') . '%';
$where = ['1=1']; $args = [];
if ($f['vendor'] !== '') { $where[] = '(s.party_name LIKE ? OR s.party_code LIKE ?)'; array_push($args, $like($f['vendor']), $like($f['vendor'])); }
if ($f['area'] !== '') { $where[] = '(COALESCE(pu.vendor_area_snap,"") LIKE ? OR s.city LIKE ? OR s.area LIKE ?)'; array_push($args, $like($f['area']), $like($f['area']), $like($f['area'])); }
if ($f['bill'] !== '') { $where[] = '(pu.invoice_no LIKE ? OR pu.purchase_no LIKE ?)'; array_push($args, $like($f['bill']), $like($f['bill'])); }
if ($f['product'] !== '') {
    $where[] = '(EXISTS (SELECT 1 FROM purchase_bill_lines x WHERE x.purchase_id=pu.id AND (x.name_snap LIKE ? OR x.sku_snap LIKE ?)) OR EXISTS (SELECT 1 FROM purchase_items xi JOIN products xp ON xp.id=xi.product_id WHERE xi.purchase_id=pu.id AND (xp.product_name LIKE ? OR xp.sku LIKE ?)))';
    array_push($args, $like($f['product']), $like($f['product']), $like($f['product']), $like($f['product']));
}
if (preg_match('/^\d{4}-\d{2}$/', $f['month'])) { $where[] = "DATE_FORMAT(pu.invoice_date,'%Y-%m')=?"; $args[] = $f['month']; }
else {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) { $where[] = 'pu.invoice_date>=?'; $args[] = $f['from']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) { $where[] = 'pu.invoice_date<=?'; $args[] = $f['to']; }
}
if (in_array($f['pay'], ['UNPAID', 'PARTIAL', 'PAID'], true)) { $where[] = "pu.entry_mode='MODULE' AND pu.payment_status=?"; $args[] = $f['pay']; }
if (in_array($f['recv'], ['PENDING', 'PARTIAL', 'RECEIVED'], true)) { $where[] = 'pu.receipt_status=?'; $args[] = $f['recv']; }
if (in_array($f['status'], ['DRAFT', 'SUBMITTED', 'APPROVED', 'CANCELLED'], true)) { $where[] = 'pu.bill_status=?'; $args[] = $f['status']; }

$sql = "SELECT pu.*, s.party_code, s.party_name, s.city vendor_city,
  COALESCE((SELECT GROUP_CONCAT(CONCAT(l.name_snap, IF(l.description IS NULL OR l.description='', '', CONCAT(' (', l.description, ')'))) ORDER BY l.line_no SEPARATOR '; ') FROM purchase_bill_lines l WHERE l.purchase_id=pu.id),
           (SELECT GROUP_CONCAT(p.product_name ORDER BY pi.id SEPARATOR '; ') FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=pu.id)) products,
  COALESCE((SELECT SUM(l.quantity) FROM purchase_bill_lines l WHERE l.purchase_id=pu.id),
           (SELECT SUM(pi.quantity) FROM purchase_items pi WHERE pi.purchase_id=pu.id), 0) qty
  FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE " . implode(' AND ', $where) . ' ORDER BY pu.id DESC LIMIT ' . (isset($_GET['export']) ? 5000 : 300);
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$gstOf = fn($r) => (float)$r['cgst_total'] + (float)$r['sgst_total'] + (float)$r['igst_total'];
if (isset($_GET['export'])) {
    $out = [['ERP ID', 'Vendor', 'Area', 'Vendor Bill No', 'Bill Date', 'Received Date', 'Products', 'Qty', 'Taxable', 'GST', 'Invoice Total', 'Paid', 'Balance', 'Bill Status', 'Goods Status', 'Payment Status']];
    foreach ($rows as $r) {
        $mod = $r['entry_mode'] === 'MODULE';
        $out[] = [$r['purchase_no'], $r['party_name'], $r['vendor_area_snap'] ?: $r['vendor_city'], $r['invoice_no'], $r['invoice_date'], $r['received_date'], $r['products'], (float)$r['qty'], (float)$r['taxable_total'], $gstOf($r), (float)$r['grand_total'], $mod ? (float)$r['amount_paid'] : '', $mod ? round($r['grand_total'] - $r['amount_paid'], 2) : '', $r['bill_status'], $r['receipt_status'], $mod ? $r['payment_status'] : 'via ledger'];
    }
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="purchase_history_' . date('Ymd_His') . '.csv"');
        $o = fopen('php://output', 'w');
        foreach ($out as $row) fputcsv($o, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
        exit;
    }
    require __DIR__ . '/../app/xlsx.php';
    xlsx_download('purchase_history_' . date('Ymd_His') . '.xlsx', $out, 'Purchases');
}

$qs = http_build_query(array_filter($f, fn($v) => $v !== ''));
page_header('Purchase History');
if ($m = flash('ok')) echo '<div class="notice success">' . e($m) . '</div>';
?>
<form method="get" class="panel no-print">
  <div class="row3">
    <div><label>Vendor</label><input name="vendor" value="<?= e($f['vendor']) ?>" placeholder="Name or code"></div>
    <div><label>Vendor area / city</label><input name="area" value="<?= e($f['area']) ?>"></div>
    <div><label>Vendor bill / ERP no.</label><input name="bill" value="<?= e($f['bill']) ?>"></div>
  </div>
  <div class="row3">
    <div><label>Product name or SKU</label><input name="product" value="<?= e($f['product']) ?>"></div>
    <div><label>Bill date from / to</label><input type="date" name="from" value="<?= e($f['from']) ?>" style="width:49%"> <input type="date" name="to" value="<?= e($f['to']) ?>" style="width:49%"></div>
    <div><label>Month &amp; year (overrides dates)</label><input type="month" name="month" value="<?= e($f['month']) ?>"></div>
  </div>
  <div class="row3">
    <div><label>Payment</label><select name="pay"><option value="">All</option><?php foreach (['UNPAID' => 'Unpaid', 'PARTIAL' => 'Partially paid', 'PAID' => 'Paid'] as $k => $t): ?><option value="<?= $k ?>" <?= $f['pay'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div><label>Goods received</label><select name="recv"><option value="">All</option><?php foreach (['PENDING' => 'Pending', 'PARTIAL' => 'Partial', 'RECEIVED' => 'Received'] as $k => $t): ?><option value="<?= $k ?>" <?= $f['recv'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div><label>Bill status</label><select name="status"><option value="">All</option><?php foreach (['DRAFT', 'SUBMITTED', 'APPROVED', 'CANCELLED'] as $k): ?><option <?= $f['status'] === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="actions"><button class="btn">Filter</button><a class="btn secondary" href="purchase_history.php">Reset</a>
    <?php if (pm_can_manage()): ?><a class="btn" href="purchase_bill.php">+ Add Purchase</a><?php endif; ?>
    <a class="btn secondary" href="?<?= e($qs . ($qs ? '&' : '') . 'export=xlsx') ?>">Excel</a><a class="btn secondary" href="?<?= e($qs . ($qs ? '&' : '') . 'export=csv') ?>">CSV</a><button type="button" class="btn secondary" onclick="window.print()">Print / Save PDF</button></div>
</form>
<div style="overflow-x:auto"><table>
<tr><th>ERP ID</th><th>Vendor</th><th>Vendor bill</th><th>Bill date</th><th>Received</th><th>Products</th><th>Qty</th><th>Taxable</th><th>GST</th><th>Total</th><th>Paid</th><th>Balance</th><th>Bill</th><th>Goods</th><th>Payment</th><th class="no-print"></th></tr>
<?php $sumTotal = 0; $sumBal = 0; foreach ($rows as $r): $mod = $r['entry_mode'] === 'MODULE'; $cancel = $r['bill_status'] === 'CANCELLED'; if (!$cancel && $r['bill_status'] !== 'DRAFT') { $sumTotal += (float)$r['grand_total']; if ($mod) $sumBal += (float)$r['grand_total'] - (float)$r['amount_paid']; } ?>
<tr<?= $cancel ? ' style="opacity:.55"' : '' ?>>
  <td><?= e($r['purchase_no']) ?></td>
  <td><b><?= e($r['party_name']) ?></b><div class="muted"><?= e($r['vendor_area_snap'] ?: $r['vendor_city']) ?></div></td>
  <td><?= e($r['invoice_no']) ?></td><td><?= e($r['invoice_date']) ?></td><td><?= e($r['received_date'] ?: '—') ?></td>
  <td style="max-width:260px"><?= e(mb_strimwidth((string)$r['products'], 0, 120, '…')) ?></td>
  <td><?= e((float)$r['qty'] + 0) ?></td>
  <td><?= e(number_format((float)$r['taxable_total'], 2)) ?></td><td><?= e(number_format($gstOf($r), 2)) ?></td>
  <td><b><?= e(number_format((float)$r['grand_total'], 2)) ?></b></td>
  <td><?= $mod ? e(number_format((float)$r['amount_paid'], 2)) : '—' ?></td><td><?= $mod ? e(number_format($r['grand_total'] - $r['amount_paid'], 2)) : '—' ?></td>
  <td><?= pm_status_badge($r['bill_status']) ?></td><td><?= pm_status_badge($r['receipt_status']) ?></td><td><?= $mod ? pm_status_badge($r['payment_status']) : '<span class="muted">ledger</span>' ?></td>
  <td class="no-print"><a class="btn secondary" href="purchase_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="16" class="muted">No purchases match these filters.</td></tr><?php endif; ?>
</table></div>
<p class="muted">Showing <?= count($rows) ?> bill(s) · Total (excl. drafts and cancelled) ₹<?= e(number_format($sumTotal, 2)) ?> · Balance due on module bills ₹<?= e(number_format($sumBal, 2)) ?>. Older purchases posted through Purchase Inward show "ledger" because their payments are tracked per vendor, not per bill.</p>
<?php page_footer();
