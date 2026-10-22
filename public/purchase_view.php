<?php
// One purchase bill: full detail, print, workflow actions, receipts and payments.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
pm_require_view();

$id = (int)($_GET['id'] ?? 0);
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? $id);
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'submit':
                if (!pm_can_manage()) throw new Exception('Access denied.');
                pm_submit_bill($id); flash('ok', 'Bill submitted for approval.'); break;
            case 'reopen':
                if (!pm_can_manage() && !pm_can_approve()) throw new Exception('Access denied.');
                $st = db()->prepare("UPDATE purchases SET bill_status='DRAFT',updated_at=NOW() WHERE id=? AND entry_mode='MODULE' AND bill_status='SUBMITTED'");
                $st->execute([$id]);
                if ($st->rowCount() !== 1) throw new Exception('Only a submitted bill can be sent back to draft.');
                audit('PURCHASE_BILL_REOPEN', 'purchase', $id); flash('ok', 'Bill is a draft again and can be edited.'); break;
            case 'approve':
                if (!pm_can_approve()) throw new Exception('You do not have permission to approve bills.');
                pm_approve_bill($id); flash('ok', 'Bill approved. Record the goods receipt when the goods arrive.'); break;
            case 'cancel':
                if (!pm_can_approve()) throw new Exception('You do not have permission to cancel bills.');
                pm_cancel_bill($id, (string)($_POST['reason'] ?? '')); flash('ok', 'Bill cancelled. The record is kept for audit.'); break;
            case 'pay':
                if (!pm_can_pay()) throw new Exception('You do not have permission to record payments.');
                if (!form_once_claim('vendor_pay')) throw new Exception('This payment form was already submitted. Check the payments list before saving again.');
                $b = pm_load_bill($id);
                if (!$b) throw new Exception('Bill not found.');
                db()->beginTransaction();
                try {
                    pm_insert_payment((int)$b['supplier_id'], $id, (float)($_POST['amount'] ?? 0), strtoupper((string)($_POST['method'] ?? '')), trim((string)($_POST['reference'] ?? '')), (string)($_POST['paid_on'] ?? date('Y-m-d')), trim((string)($_POST['remarks'] ?? '')));
                    db()->commit();
                } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
                audit('VENDOR_PAYMENT', 'purchase', $id, ['amount' => (float)($_POST['amount'] ?? 0)]);
                flash('ok', 'Payment recorded.'); break;
            case 'reverse_pay':
                if (!pm_can_pay()) throw new Exception('You do not have permission to reverse payments.');
                pm_reverse_payment((int)($_POST['payment_id'] ?? 0), (string)($_POST['reason'] ?? '')); flash('ok', 'Payment reversed.'); break;
            default: throw new Exception('Unknown action.');
        }
        redirect('purchase_view.php?id=' . $id);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $err = user_error_message($e);
    }
}

$b = pm_load_bill($id);
if (!$b) { http_response_code(404); exit('Purchase not found'); }
$lines = pm_bill_lines($id);
$legacyItems = [];
if ($b['entry_mode'] === 'LEGACY') {
    $st = db()->prepare('SELECT pi.quantity,pi.cost_price,p.sku,p.product_name,ba.batch_no,l.code location_code FROM purchase_items pi JOIN products p ON p.id=pi.product_id JOIN batches ba ON ba.id=pi.batch_id JOIN locations l ON l.id=pi.location_id WHERE pi.purchase_id=?');
    $st->execute([$id]);
    $legacyItems = $st->fetchAll();
}
$st = db()->prepare('SELECT gr.*,l.code location_code,l.name location_name,l.rack,l.shelf_bin,u.name receiver FROM goods_receipts gr JOIN locations l ON l.id=gr.location_id JOIN users u ON u.id=gr.received_by WHERE gr.purchase_id=? ORDER BY gr.id');
$st->execute([$id]);
$receipts = $st->fetchAll();
$st = db()->prepare('SELECT * FROM vendor_payments WHERE purchase_id=? ORDER BY id');
$st->execute([$id]);
$pays = $st->fetchAll();
$module = $b['entry_mode'] === 'MODULE';
$balance = pm_money($b['grand_total'] - $b['amount_paid']);

page_header('Purchase ' . $b['purchase_no']);
if ($m = flash('ok')) echo '<div class="notice success">' . e($m) . '</div>';
if ($m = flash('error')) echo '<div class="notice error">' . e($m) . '</div>';
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
$csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="id" value="' . $id . '">';
?>
<div class="actions no-print">
  <a class="btn secondary" href="purchase_history.php">&larr; Purchase History</a>
  <button class="btn secondary" type="button" onclick="window.print()">Print</button>
  <?php if (!empty($b['attachment_path'])): ?><a class="btn secondary" href="purchase_file.php?id=<?= $id ?>">Download vendor bill (<?= e($b['attachment_name']) ?>)</a><?php endif; ?>
  <?php if ($module && $b['bill_status'] === 'DRAFT' && pm_can_manage()): ?><a class="btn" href="purchase_bill.php?id=<?= $id ?>">Edit draft</a>
    <form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="submit"><button class="btn">Submit for approval</button></form><?php endif; ?>
  <?php if ($module && $b['bill_status'] === 'SUBMITTED'): ?>
    <?php if (pm_can_approve()): ?><form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="approve"><button class="btn">Approve bill</button></form><?php endif; ?>
    <?php if (pm_can_manage() || pm_can_approve()): ?><form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="reopen"><button class="btn secondary">Send back to draft</button></form><?php endif; ?><?php endif; ?>
  <?php if ($module && $b['bill_status'] === 'APPROVED' && $b['receipt_status'] !== 'RECEIVED' && (pm_can_receive() || pm_can_manage())): ?><a class="btn" href="goods_receipt.php?id=<?= $id ?>">Record goods receipt</a><?php endif; ?>
</div>

<div class="panel">
  <div class="row3">
    <div><b>ERP Purchase ID</b><br><?= e($b['purchase_no']) ?><?= $module ? '' : ' <span class="muted">(quick inward)</span>' ?></div>
    <div><b>Vendor bill no.</b><br><?= e($b['invoice_no'] ?: '—') ?><?= $b['po_number'] ? '<br><span class="muted">PO ' . e($b['po_number']) . '</span>' : '' ?></div>
    <div><b>Status</b><br>Bill <?= pm_status_badge($b['bill_status']) ?> Goods <?= pm_status_badge($b['receipt_status']) ?><?= $module ? ' Payment ' . pm_status_badge($b['payment_status']) : '' ?></div>
  </div>
  <div class="row3">
    <div><b>Vendor</b><br><a href="vendor_view.php?id=<?= (int)$b['supplier_id'] ?>"><?= e($b['vendor_name_snap'] ?: $b['party_name']) ?></a> <span class="muted">(<?= e($b['party_code']) ?>)</span><br><?= e($b['vendor_area_snap'] ?: trim(($b['vendor_area'] ?? '') . ' ' . ($b['vendor_city'] ?? ''))) ?><br>GSTIN: <?= e($b['vendor_gstin_snap'] ?: $b['vendor_gstin'] ?: '—') ?></div>
    <div><b>Bill date</b> <?= e($b['invoice_date']) ?><br><b>Entered</b> <?= e($b['created_at']) ?><br><b>Goods received</b> <?= e($b['received_date'] ?: '—') ?></div>
    <div><b>GST type</b> <?= e($b['gst_type'] ? str_replace('_', ' + ', $b['gst_type']) : '—') ?><?= $b['short_closed'] ? '<br><span class="notice error" style="padding:2px 6px">Closed short: some goods never arrived</span>' : '' ?><?= $b['duplicate_override_reason'] ? '<br><span class="muted">Duplicate-invoice exception: ' . e($b['duplicate_override_reason']) . '</span>' : '' ?><?= $b['bill_status'] === 'CANCELLED' ? '<br><b>Cancelled:</b> ' . e($b['cancel_reason']) : '' ?></div>
  </div>
  <?php if ($b['remarks']): ?><p><b>Remarks:</b> <?= e($b['remarks']) ?></p><?php endif; ?>
</div>

<h3>Products</h3>
<div style="overflow-x:auto"><table>
<?php if ($module): ?>
<tr><th>#</th><th>Product</th><th>Details</th><th>Lot</th><th>Qty</th><th>Rate</th><th>Disc.</th><th>Taxable</th><th>GST</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Total</th><th>Received</th><th>Damaged</th><th>Rejected</th><th>Pending</th></tr>
<?php foreach ($lines as $l): $pend = pm_line_pending($l); $excess = max(0, $l['received_qty'] + $l['damaged_qty'] + $l['rejected_qty'] - $l['quantity']); ?>
<tr><td><?= (int)$l['line_no'] ?></td><td><b><?= e($l['name_snap']) ?></b><div class="muted"><?= e($l['sku_snap']) ?></div></td>
<td><?= e(trim(implode(' · ', array_filter([$l['fabric_snap'], $l['color'], $l['design'], $l['pattern'], $l['size'], $l['quality'], $l['description']])))) ?></td>
<td><?= e($l['lot_no']) ?></td><td><?= e(rtrim(rtrim(number_format((float)$l['quantity'], 3, '.', ''), '0'), '.')) ?> <?= e($l['unit']) ?></td><td><?= e(number_format((float)$l['rate'], 2)) ?></td><td><?= e(number_format((float)$l['discount_amount'], 2)) ?></td>
<td><?= e(number_format((float)$l['taxable_value'], 2)) ?></td><td><?= e($l['gst_rate']) ?>%</td><td><?= e(number_format((float)$l['cgst_amount'], 2)) ?></td><td><?= e(number_format((float)$l['sgst_amount'], 2)) ?></td><td><?= e(number_format((float)$l['igst_amount'], 2)) ?></td><td><b><?= e(number_format((float)$l['line_total'], 2)) ?></b></td>
<td><?= e((float)$l['received_qty'] + 0) ?><?= $excess > 0 ? ' <span class="muted">(excess ' . e($excess + 0) . ')</span>' : '' ?></td><td><?= e((float)$l['damaged_qty'] + 0) ?></td><td><?= e((float)$l['rejected_qty'] + 0) ?></td><td><?= e($pend + 0) ?></td></tr>
<?php endforeach; ?>
<?php else: ?>
<tr><th>Product</th><th>Batch</th><th>Location</th><th>Qty</th><th>Cost</th></tr>
<?php foreach ($legacyItems as $l): ?><tr><td><?= e($l['sku'] . ' · ' . $l['product_name']) ?></td><td><?= e($l['batch_no']) ?></td><td><?= e($l['location_code']) ?></td><td><?= e((float)$l['quantity'] + 0) ?></td><td><?= e(number_format((float)$l['cost_price'], 2)) ?></td></tr><?php endforeach; ?>
<?php endif; ?>
</table></div>

<div class="row3">
  <div class="panel"><table>
    <tr><td>Subtotal</td><td align="right"><?= e(number_format((float)$b['subtotal'], 2)) ?></td></tr>
    <tr><td>Discount</td><td align="right"><?= e(number_format((float)$b['discount_total'], 2)) ?></td></tr>
    <tr><td>Taxable amount</td><td align="right"><?= e(number_format((float)$b['taxable_total'], 2)) ?></td></tr>
    <tr><td>CGST</td><td align="right"><?= e(number_format((float)$b['cgst_total'], 2)) ?></td></tr>
    <tr><td>SGST</td><td align="right"><?= e(number_format((float)$b['sgst_total'], 2)) ?></td></tr>
    <tr><td>IGST</td><td align="right"><?= e(number_format((float)$b['igst_total'], 2)) ?></td></tr>
    <tr><td>Freight / Packing / Other</td><td align="right"><?= e(number_format((float)$b['freight'], 2)) ?> / <?= e(number_format((float)$b['packing'], 2)) ?> / <?= e(number_format((float)$b['other_charges'], 2)) ?></td></tr>
    <tr><td>Round off</td><td align="right"><?= e(number_format((float)$b['round_off'], 2)) ?></td></tr>
    <tr><td><b>Invoice total</b></td><td align="right"><b>₹<?= e(number_format((float)$b['grand_total'], 2)) ?></b></td></tr>
  </table></div>
  <?php if ($module): ?>
  <div class="panel"><b>Payment</b>
    <p>Paid ₹<?= e(number_format((float)$b['amount_paid'], 2)) ?> · <b>Balance ₹<?= e(number_format($balance, 2)) ?></b></p>
    <table><tr><th>Payment</th><th>Date</th><th>Method</th><th>Ref</th><th>Amount</th><th>Status</th><th class="no-print"></th></tr>
    <?php foreach ($pays as $p): ?><tr><td><?= e($p['payment_no']) ?></td><td><?= e($p['paid_on']) ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference']) ?></td><td><?= e(number_format((float)$p['amount'], 2)) ?></td><td><?= pm_status_badge($p['status']) ?></td>
    <td class="no-print"><?php if ($p['status'] === 'ACTIVE' && pm_can_pay()): ?><form method="post" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="reverse_pay"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><input name="reason" placeholder="Reason" required style="width:110px"><button class="btn secondary">Reverse</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$pays): ?><tr><td colspan="7" class="muted">No payment yet.</td></tr><?php endif; ?></table>
    <?php if ($b['bill_status'] === 'APPROVED' && $balance > 0.004 && pm_can_pay()): ?>
    <form method="post" class="no-print"><?= $csrf ?><input type="hidden" name="action" value="pay"><input type="hidden" name="form_once" value="<?= e(form_once_token('vendor_pay')) ?>">
      <div class="row3"><div><label>Amount</label><input type="number" step="0.01" min="0.01" max="<?= e($balance) ?>" name="amount" value="<?= e($balance) ?>" required></div>
      <div><label>Method</label><select name="method"><?php foreach (PM_PAY_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label>Date</label><input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div></div>
      <label>Reference</label><input name="reference" maxlength="100"><button class="btn">Record payment</button></form><?php endif; ?>
  </div>
  <?php else: ?>
  <div class="panel"><b>Payment</b><p class="muted">This purchase was posted through Purchase Inward / Bulk Import. Its payments are tracked in the vendor's ledger (see the vendor page).</p></div>
  <?php endif; ?>
  <div class="panel"><b>Goods receipts</b>
    <?php foreach ($receipts as $r): ?><p><b><?= e($r['receipt_no']) ?></b> · <?= e($r['received_date']) ?><br><?= e($r['location_code'] . ' · ' . $r['location_name']) ?><?= $r['rack'] ? ' · Rack ' . e($r['rack']) : '' ?><?= $r['shelf_bin'] ? ' · Bin ' . e($r['shelf_bin']) : '' ?><br>by <?= e($r['receiver']) ?><?= $r['short_closed'] ? ' · <b>closed short</b>' : '' ?><?= $r['remarks'] ? '<br><span class="muted">' . e($r['remarks']) . '</span>' : '' ?></p><?php endforeach; ?>
    <?php if (!$receipts): ?><p class="muted"><?= $module ? 'No goods received yet. Stock has not been added.' : 'Stock was added when this purchase was posted.' ?></p><?php endif; ?>
  </div>
</div>

<?php if ($module && pm_can_approve() && in_array($b['bill_status'], ['DRAFT', 'SUBMITTED', 'APPROVED'], true) && !$receipts): ?>
<form method="post" class="panel no-print" onsubmit="return confirm('Cancel this bill? It cannot be undone, but the record is kept.')"><?= $csrf ?><input type="hidden" name="action" value="cancel">
  <label>Cancel this bill (only possible before any goods are received and with no active payments)</label>
  <input name="reason" placeholder="Reason for cancellation" required maxlength="255"><button class="btn secondary">Cancel bill</button></form>
<?php endif; ?>
<?php page_footer();
