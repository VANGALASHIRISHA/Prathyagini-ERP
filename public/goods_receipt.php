<?php
// Goods Receipt: the physical check-in. Stock is added here, never when a bill is entered.
// No rates or amounts are shown on this page, so godown staff can use it.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
login_required();
if (!pm_can_receive() && !pm_can_view()) { http_response_code(403); exit('Access denied'); }
$canReceive = pm_can_receive();

$id = (int)($_GET['id'] ?? 0);
$err = ''; $done = null;

function gr_locations(): array {
    if (is_super_admin()) return db()->query('SELECT id,code,name,warehouse,rack,shelf_bin FROM locations WHERE active=1 ORDER BY code')->fetchAll();
    $ids = assigned_location_ids();
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id,code,name,warehouse,rack,shelf_bin FROM locations WHERE active=1 AND id IN ($ph) ORDER BY code");
    $st->execute($ids);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (!$canReceive) throw new Exception('You do not have permission to receive goods.');
        if (!form_once_claim('goods_receipt')) throw new Exception('This receipt form was already submitted. Open the bill to check whether the receipt was saved.');
        $rows = [];
        foreach (($_POST['acc'] ?? []) as $lineId => $acc) {
            $rows[(int)$lineId] = ['acc' => $acc, 'dam' => $_POST['dam'][$lineId] ?? 0, 'rej' => $_POST['rej'][$lineId] ?? 0, 'note' => $_POST['note'][$lineId] ?? ''];
        }
        $done = pm_post_receipt($id, (int)($_POST['location_id'] ?? 0), (string)($_POST['received_date'] ?? ''), $rows, trim((string)($_POST['remarks'] ?? '')), isset($_POST['short_close']), (string)($_POST['form_once'] ?? ''));
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $err = user_error_message($e, 'This receipt was already posted.');
    }
}

page_header('Goods Receipt');
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
if ($done) {
    echo '<div class="notice success"><b>' . e($done['receipt_no']) . '</b> confirmed. Bill goods status: <b>' . e($done['status']) . '</b>.' . ($done['serials'] ? ' ' . (int)$done['serials'] . ' unique barcodes were created.' : '') . ' <a href="purchase_view.php?id=' . (int)$id . '">Open bill</a>';
    if ($done['batch_ids']) { echo '<br>Print labels: '; foreach ($done['batch_ids'] as $bid) echo '<a class="btn secondary" target="_blank" href="barcode_labels.php?batch_id=' . (int)$bid . '">Batch #' . (int)$bid . '</a> '; }
    echo '</div>';
}

$bill = $id ? pm_load_bill($id) : null;
if ($bill && $bill['entry_mode'] === 'MODULE' && $bill['bill_status'] === 'APPROVED' && $bill['receipt_status'] !== 'RECEIVED' && $canReceive):
    $lines = pm_bill_lines($id);
    $locs = gr_locations();
?>
<div class="panel"><b><?= e($bill['purchase_no']) ?></b> · Vendor bill <b><?= e($bill['invoice_no']) ?></b> · <?= e($bill['vendor_name_snap'] ?: $bill['party_name']) ?> · Bill date <?= e($bill['invoice_date']) ?><br>
<span class="muted">Count what physically arrived and compare with the bill. Accepted pieces become stock; damaged and rejected pieces are only recorded.</span></div>
<?php if (!$locs): ?><div class="notice error">You have no assigned receiving location. Ask the Super Admin to assign one.</div><?php else: ?>
<form method="post" class="panel" id="grForm">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="form_once" value="<?= e(form_once_token('goods_receipt')) ?>">
<div class="row3">
  <div><label>Destination godown / rack / bin *</label><select name="location_id" required><?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['code'] . ' · ' . $l['name'] . ($l['rack'] ? ' · Rack ' . $l['rack'] : '') . ($l['shelf_bin'] ? ' · Bin ' . $l['shelf_bin'] : '')) ?></option><?php endforeach; ?></select></div>
  <div><label>Goods received date *</label><input type="date" name="received_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
  <div><label>Receipt remarks</label><input name="remarks" maxlength="500"></div>
</div>
<div style="overflow-x:auto"><table>
<tr><th>Product</th><th>Details</th><th>Billed</th><th>Already received</th><th>Damaged</th><th>Rejected</th><th>Pending</th><th>Accepted now</th><th>Damaged now</th><th>Rejected now</th><th>Remark</th></tr>
<?php foreach ($lines as $l): $pend = pm_line_pending($l); ?>
<tr><td><b><?= e($l['name_snap']) ?></b><div class="muted"><?= e($l['sku_snap']) ?></div></td>
<td><?= e(trim(implode(' · ', array_filter([$l['color'], $l['design'], $l['size'], $l['quality'], $l['lot_no']])))) ?></td>
<td><?= e((float)$l['quantity'] + 0) ?> <?= e($l['unit']) ?></td><td><?= e((float)$l['received_qty'] + 0) ?></td><td><?= e((float)$l['damaged_qty'] + 0) ?></td><td><?= e((float)$l['rejected_qty'] + 0) ?></td><td><b><?= e($pend + 0) ?></b></td>
<td><input type="number" step="0.001" min="0" name="acc[<?= (int)$l['id'] ?>]" value="<?= e($pend + 0) ?>" style="width:90px"></td>
<td><input type="number" step="0.001" min="0" name="dam[<?= (int)$l['id'] ?>]" value="0" style="width:80px"></td>
<td><input type="number" step="0.001" min="0" name="rej[<?= (int)$l['id'] ?>]" value="0" style="width:80px"></td>
<td><input name="note[<?= (int)$l['id'] ?>]" maxlength="255" placeholder="Required if more than pending"></td></tr>
<?php endforeach; ?></table></div>
<p class="muted">Tip: the Accepted box is pre-filled with the pending quantity. Change it to what you actually counted. If the vendor will never send the rest, tick "close short" so the shortage is recorded and the bill shows as received.</p>
<label style="font-weight:400"><input type="checkbox" name="short_close" value="1"> Close this bill short (the remaining pending quantity will not arrive)</label>
<div class="actions"><button class="btn">Confirm receipt &amp; update stock</button><a class="btn secondary" href="goods_receipt.php">Cancel</a></div>
</form>
<script>document.getElementById('grForm').addEventListener('submit',function(){const b=this.querySelector('button.btn');b.disabled=true;b.textContent='Posting…';});</script>
<?php endif;
elseif ($id && !$done): ?>
<div class="notice error">This bill cannot receive goods now (it must be an approved, not fully received bill from the Purchase module).</div>
<?php endif;

// Pending list + recent receipts
$pending = db()->query("SELECT pu.id,pu.purchase_no,pu.invoice_no,pu.invoice_date,pu.receipt_status,pu.vendor_name_snap,s.party_name,
  (SELECT COALESCE(SUM(l.quantity-l.received_qty-l.damaged_qty-l.rejected_qty),0) FROM purchase_bill_lines l WHERE l.purchase_id=pu.id) pending_qty,
  (SELECT COUNT(*) FROM purchase_bill_lines l WHERE l.purchase_id=pu.id) lines_n
  FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE pu.entry_mode='MODULE' AND pu.bill_status='APPROVED' AND pu.receipt_status<>'RECEIVED' ORDER BY pu.id")->fetchAll();
$recent = [];
if (is_super_admin() || pm_can_view()) {
    $recent = db()->query('SELECT gr.id,gr.receipt_no,gr.received_date,gr.short_closed,gr.purchase_id,pu.invoice_no,l.code location_code,u.name receiver,(SELECT COALESCE(SUM(accepted_qty),0) FROM goods_receipt_items i WHERE i.receipt_id=gr.id) acc,(SELECT COALESCE(SUM(damaged_qty),0) FROM goods_receipt_items i WHERE i.receipt_id=gr.id) dam,(SELECT COALESCE(SUM(rejected_qty),0) FROM goods_receipt_items i WHERE i.receipt_id=gr.id) rej FROM goods_receipts gr JOIN purchases pu ON pu.id=gr.purchase_id JOIN locations l ON l.id=gr.location_id JOIN users u ON u.id=gr.received_by ORDER BY gr.id DESC LIMIT 30')->fetchAll();
} else {
    $ids = assigned_location_ids();
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT gr.id,gr.receipt_no,gr.received_date,gr.short_closed,gr.purchase_id,pu.invoice_no,l.code location_code,u.name receiver,(SELECT COALESCE(SUM(accepted_qty),0) FROM goods_receipt_items i WHERE i.receipt_id=gr.id) acc,(SELECT COALESCE(SUM(damaged_qty),0) FROM goods_receipt_items i WHERE i.receipt_id=gr.id) dam,(SELECT COALESCE(SUM(rejected_qty),0) FROM goods_receipt_items i WHERE i.receipt_id=gr.id) rej FROM goods_receipts gr JOIN purchases pu ON pu.id=gr.purchase_id JOIN locations l ON l.id=gr.location_id JOIN users u ON u.id=gr.received_by WHERE gr.location_id IN ($ph) ORDER BY gr.id DESC LIMIT 30");
        $st->execute($ids);
        $recent = $st->fetchAll();
    }
}
?>
<h3>Bills waiting for goods</h3>
<div style="overflow-x:auto"><table>
<tr><th>ERP ID</th><th>Vendor</th><th>Vendor bill</th><th>Bill date</th><th>Lines</th><th>Pending qty</th><th>Goods</th><th></th></tr>
<?php foreach ($pending as $p): ?>
<tr><td><?= e($p['purchase_no']) ?></td><td><?= e($p['vendor_name_snap'] ?: $p['party_name']) ?></td><td><?= e($p['invoice_no']) ?></td><td><?= e($p['invoice_date']) ?></td><td><?= (int)$p['lines_n'] ?></td><td><?= e((float)$p['pending_qty'] + 0) ?></td><td><?= pm_status_badge($p['receipt_status']) ?></td>
<td><?php if ($canReceive): ?><a class="btn" href="goods_receipt.php?id=<?= (int)$p['id'] ?>">Receive</a><?php endif; ?></td></tr>
<?php endforeach; if (!$pending): ?><tr><td colspan="8" class="muted">No approved bills are waiting for goods.</td></tr><?php endif; ?>
</table></div>
<h3>Recent receipts</h3>
<div style="overflow-x:auto"><table>
<tr><th>Receipt</th><th>Date</th><th>Vendor bill</th><th>Location</th><th>Accepted</th><th>Damaged</th><th>Rejected</th><th>By</th></tr>
<?php foreach ($recent as $r): ?>
<tr><td><?= e($r['receipt_no']) ?><?= $r['short_closed'] ? ' <span class="muted">(short)</span>' : '' ?></td><td><?= e($r['received_date']) ?></td><td><?= e($r['invoice_no']) ?></td><td><?= e($r['location_code']) ?></td><td><?= e((float)$r['acc'] + 0) ?></td><td><?= e((float)$r['dam'] + 0) ?></td><td><?= e((float)$r['rej'] + 0) ?></td><td><?= e($r['receiver']) ?></td></tr>
<?php endforeach; if (!$recent): ?><tr><td colspan="8" class="muted">No receipts yet.</td></tr><?php endif; ?>
</table></div>
<?php page_footer();
