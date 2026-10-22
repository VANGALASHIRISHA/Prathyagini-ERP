<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
pm_require_view();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!pm_can_pay()) throw new Exception('You do not have permission to record payments.');
        $action = $_POST['action'] ?? 'pay';
        if ($action === 'reverse') {
            pm_reverse_payment((int)($_POST['payment_id'] ?? 0), (string)($_POST['reason'] ?? ''));
            flash('ok', 'Payment reversed.');
        } else {
            if (!form_once_claim('vendor_pay_main')) throw new Exception('This payment form was already submitted. Check the list below before saving again.');
            $sid = (int)($_POST['supplier_id'] ?? 0);
            $bid = (int)($_POST['purchase_id'] ?? 0) ?: null;
            $st = db()->prepare('SELECT 1 FROM suppliers WHERE id=?');
            $st->execute([$sid]);
            if (!$st->fetchColumn()) throw new Exception('Select a vendor.');
            db()->beginTransaction();
            try {
                pm_insert_payment($sid, $bid, (float)($_POST['amount'] ?? 0), strtoupper((string)($_POST['method'] ?? '')), trim((string)($_POST['reference'] ?? '')), (string)($_POST['paid_on'] ?? ''), trim((string)($_POST['remarks'] ?? '')));
                db()->commit();
            } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
            audit('VENDOR_PAYMENT', 'supplier', $sid, ['amount' => (float)($_POST['amount'] ?? 0), 'purchase_id' => $bid]);
            flash('ok', 'Payment recorded.');
        }
        redirect('vendor_payments.php');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $err = user_error_message($e);
    }
}

$prefSupplier = (int)($_POST['supplier_id'] ?? ($_GET['supplier_id'] ?? 0));
$vendors = db()->query('SELECT s.id,s.party_code,s.party_name,COALESCE((SELECT SUM(l.credit-l.debit) FROM supplier_ledger l WHERE l.supplier_id=s.id),0) outstanding FROM suppliers s WHERE s.active=1 ORDER BY s.party_name')->fetchAll();
$openBills = db()->query("SELECT id,supplier_id,purchase_no,invoice_no,grand_total,amount_paid FROM purchases WHERE entry_mode='MODULE' AND bill_status='APPROVED' AND grand_total-amount_paid>0.004 ORDER BY id")->fetchAll();

$fs = (int)($_GET['filter_supplier'] ?? 0);
$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
$where = ['1=1']; $args = [];
if ($fs) { $where[] = 'vp.supplier_id=?'; $args[] = $fs; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'vp.paid_on>=?'; $args[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'vp.paid_on<=?'; $args[] = $to; }
$st = db()->prepare('SELECT vp.*,s.party_name,pu.invoice_no,u.name by_user FROM vendor_payments vp JOIN suppliers s ON s.id=vp.supplier_id LEFT JOIN purchases pu ON pu.id=vp.purchase_id JOIN users u ON u.id=vp.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY vp.id DESC LIMIT 300');
$st->execute($args);
$pays = $st->fetchAll();

page_header('Vendor Payments');
if ($m = flash('ok')) echo '<div class="notice success">' . e($m) . '</div>';
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
if (pm_can_pay()):
?>
<form method="post" class="panel no-print" id="payForm">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="pay"><input type="hidden" name="form_once" value="<?= e(form_once_token('vendor_pay_main')) ?>">
<h3 style="margin-top:0">Record a payment</h3>
<div class="row3">
  <div><label>Vendor *</label><select name="supplier_id" id="pv" required><option value="">-- Select --</option><?php foreach ($vendors as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $prefSupplier === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['party_code'] . ' · ' . $v['party_name'] . ' · owed ₹' . number_format((float)$v['outstanding'], 2)) ?></option><?php endforeach; ?></select></div>
  <div><label>Against bill <span class="muted">(optional)</span></label><select name="purchase_id" id="pb"><option value="">On account (no specific bill)</option><?php foreach ($openBills as $b): ?><option value="<?= (int)$b['id'] ?>" data-s="<?= (int)$b['supplier_id'] ?>" data-due="<?= e(round($b['grand_total'] - $b['amount_paid'], 2)) ?>"><?= e($b['purchase_no'] . ' · ' . $b['invoice_no'] . ' · due ₹' . number_format($b['grand_total'] - $b['amount_paid'], 2)) ?></option><?php endforeach; ?></select></div>
  <div><label>Amount (₹) *</label><input type="number" step="0.01" min="0.01" name="amount" id="pa" required></div>
</div>
<div class="row3">
  <div><label>Method *</label><select name="method"><?php foreach (PM_PAY_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
  <div><label>Reference</label><input name="reference" maxlength="100"></div>
  <div><label>Paid on *</label><input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
</div>
<label>Remarks</label><input name="remarks" maxlength="255">
<div class="actions"><button class="btn">Save payment</button></div>
</form>
<script>
const pv=document.getElementById('pv'),pb=document.getElementById('pb'),pa=document.getElementById('pa');
function filt(){for(const o of pb.options){if(!o.value)continue;o.hidden=o.dataset.s!==pv.value;}if(pb.selectedOptions[0]&&pb.selectedOptions[0].hidden)pb.value='';}
pv.addEventListener('change',filt);pb.addEventListener('change',function(){const o=pb.selectedOptions[0];if(o&&o.dataset.due)pa.value=o.dataset.due;});filt();
document.getElementById('payForm').addEventListener('submit',function(){const b=this.querySelector('button.btn');b.disabled=true;});
</script>
<?php endif; ?>
<form method="get" class="actions no-print"><select name="filter_supplier"><option value="">All vendors</option><?php foreach ($vendors as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $fs === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['party_name']) ?></option><?php endforeach; ?></select><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn">Filter</button></form><br>
<div style="overflow-x:auto"><table>
<tr><th>Payment</th><th>Date</th><th>Vendor</th><th>Bill</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th><th>By</th><th class="no-print"></th></tr>
<?php foreach ($pays as $p): ?>
<tr><td><?= e($p['payment_no']) ?></td><td><?= e($p['paid_on']) ?></td><td><?= e($p['party_name']) ?></td><td><?= e($p['invoice_no'] ?: 'On account') ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference']) ?></td><td>₹<?= e(number_format((float)$p['amount'], 2)) ?></td>
<td><?= pm_status_badge($p['status']) ?><?= $p['reverse_reason'] ? '<div class="muted">' . e($p['reverse_reason']) . '</div>' : '' ?></td><td><?= e($p['by_user']) ?></td>
<td class="no-print"><?php if ($p['status'] === 'ACTIVE' && pm_can_pay()): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reverse"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><input name="reason" placeholder="Reason" required style="width:110px"><button class="btn secondary">Reverse</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$pays): ?><tr><td colspan="10" class="muted">No payments yet.</td></tr><?php endif; ?>
</table></div>
<p class="muted">Payments are never deleted. A wrong payment is reversed with a reason and both entries stay in the vendor ledger. Older payments posted from the Suppliers page appear in that vendor's ledger, not in this list.</p>
<?php page_footer();
