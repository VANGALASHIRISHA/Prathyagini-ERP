<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
pm_require_view();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM suppliers WHERE id=?');
$st->execute([$id]);
$v = $st->fetch();
if (!$v) { http_response_code(404); exit('Vendor not found'); }
$stats = pm_vendor_stats($id);

$st = db()->prepare('SELECT id,purchase_no,invoice_no,invoice_date,entry_mode,bill_status,receipt_status,payment_status,grand_total,amount_paid FROM purchases WHERE supplier_id=? ORDER BY id DESC LIMIT 200');
$st->execute([$id]);
$bills = $st->fetchAll();
$st = db()->prepare('SELECT * FROM vendor_payments WHERE supplier_id=? ORDER BY id DESC LIMIT 100');
$st->execute([$id]);
$pays = $st->fetchAll();

page_header('Vendor: ' . $v['party_name']);
if (isset($_GET['saved'])) echo '<div class="notice success">Vendor saved.</div>';
?>
<div class="actions no-print">
  <a class="btn secondary" href="vendors.php">&larr; Vendors</a>
  <?php if (has_permission('manage_suppliers')): ?><a class="btn secondary" href="vendor_form.php?id=<?= $id ?>">Edit</a><?php endif; ?>
  <?php if (pm_can_manage() && $v['active']): ?><a class="btn" href="purchase_bill.php?supplier_id=<?= $id ?>">+ Add Purchase</a><?php endif; ?>
  <?php if (pm_can_pay()): ?><a class="btn" href="vendor_payments.php?supplier_id=<?= $id ?>">Record Payment</a><?php endif; ?>
</div>
<div class="panel">
  <div class="row3">
    <div><b>Code</b><br><?= e($v['party_code']) ?> <?= pm_status_badge($v['active'] ? 'ACTIVE' : 'CANCELLED') ?></div>
    <div><b>Type</b><br><?= e($v['vendor_type'] ?: '—') ?></div>
    <div><b>Contact</b><br><?= e($v['contact_person'] ?: '—') ?> · <?= e($v['mobile'] ?: '—') ?><br><?= e($v['email'] ?: '') ?></div>
  </div>
  <p><b>Address:</b> <?= e(trim(implode(', ', array_filter([$v['address'], $v['area'], $v['city'], $v['district'], $v['state'] ? $v['state'] . ' (' . $v['state_code'] . ')' : '', $v['pincode']])))) ?: '—' ?><br>
  <b>GSTIN:</b> <?= e($v['gstin'] ?: '—') ?><?= $v['remarks'] ? '<br><b>Remarks:</b> ' . e($v['remarks']) : '' ?></p>
</div>
<div class="row3">
  <div class="panel"><b>Bills</b><h2><?= (int)$stats['bills'] ?></h2></div>
  <div class="panel"><b>Total purchases</b><h2>₹<?= e(number_format($stats['total'], 2)) ?></h2></div>
  <div class="panel"><b>Outstanding balance</b><h2>₹<?= e(number_format($stats['outstanding'], 2)) ?></h2></div>
</div>
<h3>Purchase history</h3>
<div style="overflow-x:auto"><table>
<tr><th>ERP ID</th><th>Vendor bill</th><th>Date</th><th>Type</th><th>Bill</th><th>Goods</th><th>Payment</th><th>Total</th><th>Paid</th><th></th></tr>
<?php foreach ($bills as $b): ?>
<tr><td><?= e($b['purchase_no']) ?></td><td><?= e($b['invoice_no']) ?></td><td><?= e($b['invoice_date']) ?></td>
<td><?= $b['entry_mode'] === 'LEGACY' ? '<span class="muted">Quick inward</span>' : 'Module' ?></td>
<td><?= pm_status_badge($b['bill_status']) ?></td><td><?= pm_status_badge($b['receipt_status']) ?></td>
<td><?= $b['entry_mode'] === 'MODULE' ? pm_status_badge($b['payment_status']) : '<span class="muted">via ledger</span>' ?></td>
<td>₹<?= e(number_format((float)$b['grand_total'], 2)) ?></td><td><?= $b['entry_mode'] === 'MODULE' ? '₹' . e(number_format((float)$b['amount_paid'], 2)) : '—' ?></td>
<td><a class="btn secondary" href="purchase_view.php?id=<?= (int)$b['id'] ?>">Open</a></td></tr>
<?php endforeach; if (!$bills): ?><tr><td colspan="10" class="muted">No purchases yet.</td></tr><?php endif; ?>
</table></div>
<h3>Payments</h3>
<div style="overflow-x:auto"><table>
<tr><th>Payment</th><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th></tr>
<?php foreach ($pays as $p): ?>
<tr><td><?= e($p['payment_no']) ?></td><td><?= e($p['paid_on']) ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference']) ?></td><td>₹<?= e(number_format((float)$p['amount'], 2)) ?></td><td><?= pm_status_badge($p['status']) ?></td></tr>
<?php endforeach; if (!$pays): ?><tr><td colspan="6" class="muted">No payments recorded in the Payments module.</td></tr><?php endif; ?>
</table></div>
<?php page_footer();
