<?php
// Add / edit a textile purchase bill (draft). Saving a bill NEVER adds stock; only a confirmed goods receipt does.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
require_permission('manage_purchases');

$id = (int)($_GET['id'] ?? 0);
$bill = null; $lines = [];
if ($id) {
    $bill = pm_load_bill($id);
    if (!$bill || $bill['entry_mode'] !== 'MODULE') { http_response_code(404); exit('Bill not found'); }
    if ($bill['bill_status'] !== 'DRAFT') { flash('error', 'Only a draft bill can be edited.'); redirect('purchase_view.php?id=' . $id); }
    $lines = pm_bill_lines($id);
}

$vendors = db()->query('SELECT id,party_code,party_name,area,city,gstin,state_code FROM suppliers WHERE active=1 ORDER BY party_name')->fetchAll();
$products = db()->query('SELECT p.id,p.sku,p.product_barcode,p.product_name,p.fabric,p.color,p.size,p.pattern_name,p.design_model,p.hsn_code,p.unit,p.tax_rate,p.serial_tracking FROM products p WHERE p.active=1 ORDER BY p.product_name')->fetchAll();
$slabs = pm_gst_rates();
$canApprove = pm_can_approve();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';
    try {
        if (!form_once_claim('purchase_bill')) throw new Exception('This form was already submitted. Open Purchase History to check before saving again.');
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $st = db()->prepare('SELECT * FROM suppliers WHERE id=? AND active=1');
        $st->execute([$supplierId]);
        $vendor = $st->fetch();
        if (!$vendor) throw new Exception('Select an active vendor.');
        $invoice = trim((string)($_POST['invoice_no'] ?? ''));
        if ($invoice === '') throw new Exception('The vendor invoice / bill number is required.');
        $invDate = (string)($_POST['invoice_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invDate) || $invDate > date('Y-m-d')) throw new Exception('The invoice date is invalid or in the future.');
        $po = trim((string)($_POST['po_number'] ?? ''));

        $gstType = pm_gst_type($vendor['state_code']);
        if (!$gstType) throw new Exception('GST type cannot be decided. Set the Company State Code in Company Settings and the State Code on this vendor.');

        $pids = $_POST['product_id'] ?? [];
        $in = [];
        foreach ($pids as $i => $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;
            $in[] = [
                'product_id' => $pid,
                'description' => trim((string)($_POST['description'][$i] ?? '')),
                'color' => trim((string)($_POST['color'][$i] ?? '')),
                'design' => trim((string)($_POST['design'][$i] ?? '')),
                'pattern' => trim((string)($_POST['pattern'][$i] ?? '')),
                'size' => trim((string)($_POST['size'][$i] ?? '')),
                'quality' => trim((string)($_POST['quality'][$i] ?? '')),
                'lot_no' => trim((string)($_POST['lot_no'][$i] ?? '')),
                'unit' => strtoupper(trim((string)($_POST['unit'][$i] ?? 'PCS'))),
                'qty' => round((float)($_POST['quantity'][$i] ?? 0), 3),
                'rate' => pm_money($_POST['rate'][$i] ?? 0),
                'discount' => pm_money($_POST['discount'][$i] ?? 0),
                'gst_rate' => round((float)($_POST['gst_rate'][$i] ?? 0), 2),
            ];
        }
        if (!$in) throw new Exception('Add at least one product line.');
        $pst = db()->prepare('SELECT id,sku,product_name,fabric,hsn_code,serial_tracking FROM products WHERE id=? AND active=1');
        foreach ($in as $n => &$l) {
            $pst->execute([$l['product_id']]);
            $p = $pst->fetch();
            if (!$p) throw new Exception('Line ' . ($n + 1) . ': the product is missing or inactive.');
            $l['p'] = $p;
            if ($l['qty'] <= 0) throw new Exception('Line ' . ($n + 1) . ': quantity must be greater than zero.');
            if ($l['rate'] < 0) throw new Exception('Line ' . ($n + 1) . ': rate cannot be negative.');
            if (!in_array($l['unit'], PM_UNITS, true)) throw new Exception('Line ' . ($n + 1) . ': choose a valid unit.');
            if (!in_array($l['gst_rate'], $slabs, true)) throw new Exception('Line ' . ($n + 1) . ': GST rate ' . $l['gst_rate'] . '% is not in the configured list (' . implode(', ', $slabs) . ').');
            if ((int)$p['serial_tracking'] === 1 && abs($l['qty'] - round($l['qty'])) > 0.0001) throw new Exception('Line ' . ($n + 1) . ' (' . $p['sku'] . '): garments tracked piece by piece need a whole-piece quantity.');
        }
        unset($l);

        $freight = pm_money($_POST['freight'] ?? 0); $packing = pm_money($_POST['packing'] ?? 0); $other = pm_money($_POST['other_charges'] ?? 0);
        if ($freight < 0 || $packing < 0 || $other < 0) throw new Exception('Charges cannot be negative.');
        $calc = pm_calculate($in, $gstType, $freight, $packing, $other);

        $payAmt = pm_money($_POST['pay_amount'] ?? 0);
        $payMethod = strtoupper(trim((string)($_POST['pay_method'] ?? '')));
        $payRef = trim((string)($_POST['pay_ref'] ?? ''));
        if ($payAmt < 0 || $payAmt > $calc['grand_total'] + 0.004) throw new Exception('The amount paid cannot be negative or more than the invoice total (₹' . number_format($calc['grand_total'], 2) . ').');
        if ($payAmt > 0 && !in_array($payMethod, PM_PAY_METHODS, true)) throw new Exception('Choose the payment method for the amount paid.');

        // Duplicate vendor invoice: blocked unless an approver records a reason (controlled exception).
        $key = pm_invoice_key($invoice);
        $override = trim((string)($_POST['dup_reason'] ?? ''));
        $dupSt = db()->prepare("SELECT purchase_no FROM purchases WHERE supplier_id=? AND invoice_key=? AND id<>? LIMIT 1");
        $dupSt->execute([$supplierId, $key, $id]);
        $dupNo = $dupSt->fetchColumn();
        $overrideReason = null;
        if ($dupNo) {
            if (!$canApprove || $override === '') throw new Exception('Vendor invoice ' . $invoice . ' is already entered as ' . $dupNo . ($canApprove ? '. If this is a legitimate second bill, tick the exception box and write the reason.' : '. Ask the Super Admin if this is a legitimate exception.'));
            $overrideReason = mb_substr($override, 0, 255);
            $key = null;
        }

        $attach = pm_save_attachment('bill_file');
        $uid = (int)current_user()['id'];
        $areaSnap = trim(($vendor['area'] ?? '') . ' ' . ($vendor['city'] ?? ''));
        db()->beginTransaction();
        try {
            $hdr = [$supplierId, $invoice, $invDate, $po ?: null, $vendor['party_name'], $areaSnap ?: null, $vendor['gstin'] ?: null, $vendor['state_code'] ?: null, $gstType,
                $calc['subtotal'], $calc['discount'], $calc['taxable'], $calc['cgst'], $calc['sgst'], $calc['igst'], $other, $freight, $packing, $calc['round_off'], $calc['grand_total'],
                $payAmt, $payAmt > 0 ? $payMethod : null, $payAmt > 0 ? ($payRef ?: null) : null, $key, $overrideReason, trim((string)($_POST['remarks'] ?? '')) ?: null];
            if ($id) {
                db()->prepare('UPDATE purchases SET supplier_id=?,invoice_no=?,invoice_date=?,po_number=?,vendor_name_snap=?,vendor_area_snap=?,vendor_gstin_snap=?,vendor_state_code_snap=?,gst_type=?,subtotal=?,discount_total=?,taxable_total=?,cgst_total=?,sgst_total=?,igst_total=?,other_charges=?,freight=?,packing=?,round_off=?,grand_total=?,entry_pay_amount=?,entry_pay_method=?,entry_pay_ref=?,invoice_key=?,duplicate_override_reason=?,remarks=?,total_cost=?,updated_at=NOW() WHERE id=? AND bill_status=\'DRAFT\'')
                    ->execute([...$hdr, $calc['grand_total'], $id]);
                db()->prepare('DELETE FROM purchase_bill_lines WHERE purchase_id=?')->execute([$id]);
                $purchaseId = $id;
            } else {
                $no = pm_unique_no('PB', 'purchases', 'purchase_no');
                db()->prepare("INSERT INTO purchases(supplier_id,invoice_no,invoice_date,po_number,vendor_name_snap,vendor_area_snap,vendor_gstin_snap,vendor_state_code_snap,gst_type,subtotal,discount_total,taxable_total,cgst_total,sgst_total,igst_total,other_charges,freight,packing,round_off,grand_total,entry_pay_amount,entry_pay_method,entry_pay_ref,invoice_key,duplicate_override_reason,remarks,total_cost,purchase_no,created_by,entry_mode,bill_status,receipt_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'MODULE','DRAFT','PENDING')")
                    ->execute([...$hdr, $calc['grand_total'], $no, $uid]);
                $purchaseId = (int)db()->lastInsertId();
            }
            if ($attach) {
                if ($id && !empty($bill['attachment_path'])) @unlink(pm_storage_dir() . '/' . basename($bill['attachment_path']));
                db()->prepare('UPDATE purchases SET attachment_path=?,attachment_name=? WHERE id=?')->execute([$attach[0], $attach[1], $purchaseId]);
            }
            $ins = db()->prepare('INSERT INTO purchase_bill_lines(purchase_id,line_no,product_id,sku_snap,name_snap,fabric_snap,hsn_snap,description,color,design,pattern,size,quality,lot_no,unit,quantity,rate,discount_amount,taxable_value,gst_rate,cgst_amount,sgst_amount,igst_amount,line_total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($in as $n => $l) {
                $c = $calc['lines'][$n];
                $ins->execute([$purchaseId, $n + 1, $l['product_id'], $l['p']['sku'], $l['p']['product_name'], $l['p']['fabric'], $l['p']['hsn_code'], $l['description'] ?: null, $l['color'] ?: null, $l['design'] ?: null, $l['pattern'] ?: null, $l['size'] ?: null, $l['quality'] ?: null, $l['lot_no'] ?: null, $l['unit'], $l['qty'], $l['rate'], $l['discount'], $c['taxable'], $l['gst_rate'], $c['cgst'], $c['sgst'], $c['igst'], $c['total']]);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            if ($attach) @unlink(pm_storage_dir() . '/' . $attach[0]);
            throw $e;
        }
        audit($id ? 'PURCHASE_BILL_UPDATE' : 'PURCHASE_BILL_CREATE', 'purchase', $purchaseId, ['invoice' => $invoice, 'total' => $calc['grand_total'], 'duplicate_override' => $overrideReason !== null]);
        if ($overrideReason !== null) audit('PURCHASE_DUPLICATE_INVOICE_OVERRIDE', 'purchase', $purchaseId, ['invoice' => $invoice, 'reason' => $overrideReason]);
        if ($action === 'submit' || $action === 'approve') pm_submit_bill($purchaseId);
        if ($action === 'approve') {
            if (!$canApprove) throw new Exception('You do not have permission to approve.');
            pm_approve_bill($purchaseId);
        }
        flash('ok', $action === 'approve' ? 'Bill saved and approved. Now record the goods receipt when the goods arrive.' : ($action === 'submit' ? 'Bill submitted for approval.' : 'Draft saved. No stock was added.'));
        redirect('purchase_view.php?id=' . $purchaseId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $err = user_error_message($e, 'This vendor invoice number is already entered for this vendor.');
    }
}

// Values used to (re)fill the form.
$f = $_POST ?: [];
$val = fn($k, $d = '') => $f[$k] ?? ($bill[$k] ?? $d);
$prefSupplier = (int)($f['supplier_id'] ?? ($bill['supplier_id'] ?? ($_GET['supplier_id'] ?? 0)));
$vendorMap = [];
foreach ($vendors as $v) $vendorMap[$v['id']] = ['area' => trim(($v['area'] ?? '') . ' ' . ($v['city'] ?? '')), 'gstin' => $v['gstin'] ?? '', 'state' => $v['state_code'] ?? '', 'gst' => pm_gst_type($v['state_code'])];
$prodMap = [];
foreach ($products as $p) $prodMap[$p['id']] = ['fabric' => $p['fabric'], 'color' => $p['color'], 'size' => $p['size'], 'pattern' => $p['pattern_name'], 'design' => $p['design_model'], 'unit' => $p['unit'], 'tax' => (float)$p['tax_rate'], 'serial' => (int)$p['serial_tracking']];
$scanMap = [];
foreach ($products as $p) { $scanMap[strtoupper($p['sku'])] = (int)$p['id']; if ($p['product_barcode'] !== '') $scanMap[strtoupper($p['product_barcode'])] = (int)$p['id']; }
$preLines = [];
if ($_POST) {
    foreach (($_POST['product_id'] ?? []) as $i => $pid) if ((int)$pid > 0) $preLines[] = ['product_id' => (int)$pid, 'description' => $_POST['description'][$i] ?? '', 'color' => $_POST['color'][$i] ?? '', 'design' => $_POST['design'][$i] ?? '', 'pattern' => $_POST['pattern'][$i] ?? '', 'size' => $_POST['size'][$i] ?? '', 'quality' => $_POST['quality'][$i] ?? '', 'lot_no' => $_POST['lot_no'][$i] ?? '', 'unit' => $_POST['unit'][$i] ?? 'PCS', 'quantity' => $_POST['quantity'][$i] ?? 1, 'rate' => $_POST['rate'][$i] ?? 0, 'discount_amount' => $_POST['discount'][$i] ?? 0, 'gst_rate' => $_POST['gst_rate'][$i] ?? 0];
} else {
    foreach ($lines as $l) $preLines[] = $l;
}
page_header($id ? 'Edit Purchase Bill (Draft)' : 'Add Purchase');
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
$companyState = pm_company_state_code();
?>
<div class="notice">Saving a bill <b>does not add stock</b>. Stock is added only when the goods are received and confirmed in <b>Goods Receipt</b>. A vendor invoice is not proof of delivery.<?= $companyState === '' ? ' <b>Company State Code is not set in Company Settings, so GST cannot be calculated yet.</b>' : '' ?></div>
<form class="panel" id="scanForm" autocomplete="off"><label for="scanIn">Scan product barcode / SKU to add a line</label><input id="scanIn" data-scanner placeholder="Scan or type and press Enter"><div class="muted" id="scanMsg"></div></form>
<form method="post" enctype="multipart/form-data" class="panel" id="billForm">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="form_once" value="<?= e(form_once_token('purchase_bill')) ?>">
<div class="row3">
  <div><label>Vendor *</label><select name="supplier_id" id="vendorSel" required><option value="">-- Select vendor --</option><?php foreach ($vendors as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $prefSupplier === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['party_code'] . ' · ' . $v['party_name']) ?></option><?php endforeach; ?></select>
    <div class="muted" id="vendorInfo"></div></div>
  <div><label>Vendor Invoice / Bill No. *</label><input name="invoice_no" value="<?= e($val('invoice_no')) ?>" required maxlength="80"></div>
  <div><label>Purchase Order No.</label><input name="po_number" value="<?= e($val('po_number')) ?>" maxlength="60"></div>
</div>
<div class="row3">
  <div><label>Invoice / Bill Date *</label><input type="date" name="invoice_date" value="<?= e($val('invoice_date', date('Y-m-d'))) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
  <div><label>ERP Purchase ID</label><input value="<?= e($bill['purchase_no'] ?? 'Generated on save') ?>" readonly></div>
  <div><label>Original Vendor Bill (PDF / JPG / PNG, max 5 MB)</label><input type="file" name="bill_file" accept=".pdf,.jpg,.jpeg,.png"><?php if (!empty($bill['attachment_name'])): ?><div class="muted">Current: <?= e($bill['attachment_name']) ?> (upload a new one to replace)</div><?php endif; ?></div>
</div>
<?php if ($canApprove): ?><div class="panel" style="background:#fffbe6"><label style="font-weight:400"><input type="checkbox" id="dupBox"> This vendor invoice number was already used, but this is a legitimate separate bill (exception)</label><input name="dup_reason" id="dupReason" placeholder="Reason (recorded in the audit log)" maxlength="255" style="display:none"></div><?php endif; ?>

<h3>Products on this bill</h3>
<div id="lines"></div>
<div class="actions"><button type="button" class="btn secondary" onclick="addLine()">+ Add Product</button></div>

<h3>Bill summary <span class="muted" style="font-weight:400">(recalculated on the server when you save)</span></h3>
<div class="row3">
  <div class="panel"><table>
    <tr><td>Subtotal</td><td align="right" id="tSub">0.00</td></tr>
    <tr><td>Total discount</td><td align="right" id="tDisc">0.00</td></tr>
    <tr><td>Taxable amount</td><td align="right" id="tTax">0.00</td></tr>
    <tr><td>CGST</td><td align="right" id="tCgst">0.00</td></tr>
    <tr><td>SGST</td><td align="right" id="tSgst">0.00</td></tr>
    <tr><td>IGST</td><td align="right" id="tIgst">0.00</td></tr>
  </table></div>
  <div class="panel">
    <label>Freight (₹)</label><input type="number" step="0.01" min="0" name="freight" value="<?= e($val('freight', '0')) ?>">
    <label>Packing (₹)</label><input type="number" step="0.01" min="0" name="packing" value="<?= e($val('packing', '0')) ?>">
    <label>Other charges (₹)</label><input type="number" step="0.01" min="0" name="other_charges" value="<?= e($val('other_charges', '0')) ?>">
    <div class="muted">Rounding adjustment: <b id="tRound">0.00</b> (nearest rupee)</div>
    <h3>Final invoice total: ₹<span id="tGrand">0.00</span></h3>
  </div>
  <div class="panel">
    <label>Amount paid with this bill (₹)</label><input type="number" step="0.01" min="0" name="pay_amount" id="payAmt" value="<?= e($val('entry_pay_amount', $f['pay_amount'] ?? '0')) ?>">
    <label>Payment method</label><select name="pay_method"><option value="">--</option><?php foreach (PM_PAY_METHODS as $m): ?><option <?= ($val('entry_pay_method', $f['pay_method'] ?? '')) === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
    <label>Payment reference</label><input name="pay_ref" value="<?= e($val('entry_pay_ref', $f['pay_ref'] ?? '')) ?>" maxlength="100">
    <div class="muted">Balance due: <b>₹<span id="tDue">0.00</span></b> · <b id="tStatus">UNPAID</b></div>
    <div class="muted">The payment is recorded when the bill is approved.</div>
  </div>
</div>
<label>Remarks</label><input name="remarks" value="<?= e($val('remarks')) ?>" maxlength="500">
<div class="actions">
  <button class="btn secondary" name="action" value="save">Save Draft</button>
  <button class="btn" name="action" value="submit">Save &amp; Submit for Approval</button>
  <?php if ($canApprove): ?><button class="btn" name="action" value="approve">Save, Submit &amp; Approve</button><?php endif; ?>
  <a class="btn secondary" href="purchase_history.php">Cancel</a>
</div>
</form>

<template id="lineTpl"><div class="panel pm-line" style="background:#fafafa">
  <div class="row3">
    <div><label>Product (name / SKU) *</label><select name="product_id[]" class="pSel"><option value="">-- Select --</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['sku'] . ' · ' . $p['product_name'] . ' · ' . $p['color'] . ' ' . $p['size']) ?></option><?php endforeach; ?></select></div>
    <div><label>Description</label><input name="description[]" maxlength="500" placeholder="Fabric type, design, lot…"></div>
    <div><label>Lot / Batch No.</label><input name="lot_no[]" maxlength="60"></div>
  </div>
  <div class="row3">
    <div><label>Colour</label><input name="color[]" maxlength="80"></div>
    <div><label>Design</label><input name="design[]" maxlength="100"></div>
    <div><label>Pattern</label><input name="pattern[]" maxlength="100"></div>
  </div>
  <div class="row3">
    <div><label>Size</label><input name="size[]" maxlength="40"></div>
    <div><label>Quality</label><input name="quality[]" maxlength="80"></div>
    <div><label>Unit</label><select name="unit[]"><?php foreach (PM_UNITS as $u): ?><option><?= e($u) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="row3">
    <div><label>Quantity *</label><input type="number" step="0.001" min="0.001" name="quantity[]" class="qIn" value="1"></div>
    <div><label>Purchase rate / unit (₹) *</label><input type="number" step="0.01" min="0" name="rate[]" class="rIn" value="0"></div>
    <div><label>Discount (₹)</label><input type="number" step="0.01" min="0" name="discount[]" class="dIn" value="0"></div>
  </div>
  <div class="row3">
    <div><label>GST %</label><select name="gst_rate[]" class="gIn"><?php foreach ($slabs as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?>%</option><?php endforeach; ?></select></div>
    <div><label>Taxable value</label><input class="tvOut" readonly></div>
    <div><label>Line total (incl. GST)</label><input class="ltOut" readonly></div>
  </div>
  <button type="button" class="btn secondary rm" onclick="this.closest('.pm-line').remove();recalc()">Remove line</button>
</div></template>
<script>
const VENDORS=<?= json_encode($vendorMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PRODS=<?= json_encode($prodMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SCAN=<?= json_encode($scanMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PRE=<?= json_encode($preLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const r2=v=>Math.round((v+Number.EPSILON)*100)/100;
function fill(row,l){const m={description:'description',color:'color',design:'design',pattern:'pattern',size:'size',quality:'quality',lot_no:'lot_no'};for(const k in m){const el=row.querySelector('[name="'+m[k]+'[]"]');if(el&&l[k]!=null)el.value=l[k];}
 row.querySelector('.pSel').value=String(l.product_id);row.querySelector('[name="unit[]"]').value=l.unit||'PCS';row.querySelector('.qIn').value=l.quantity;row.querySelector('.rIn').value=l.rate;row.querySelector('.dIn').value=l.discount_amount||0;
 const g=row.querySelector('.gIn');const want=String(parseFloat(l.gst_rate));for(const o of g.options){if(parseFloat(o.value)===parseFloat(want))g.value=o.value;}}
function addLine(pre){const c=document.getElementById('lineTpl').content.cloneNode(true);document.getElementById('lines').appendChild(c);const row=document.getElementById('lines').lastElementChild;
 row.querySelector('.pSel').addEventListener('change',function(){const p=PRODS[this.value];if(!p)return;const set=(n,v)=>{const el=row.querySelector('[name="'+n+'[]"]');if(el&&!el.value&&v)el.value=v;};set('color',p.color);set('size',p.size);set('pattern',p.pattern);set('design',p.design);set('description',p.fabric);
  const g=row.querySelector('.gIn');for(const o of g.options){if(parseFloat(o.value)===p.tax)g.value=o.value;}recalc();});
 row.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',recalc));if(pre)fill(row,pre);recalc();return row;}
function gstMode(){const v=VENDORS[document.getElementById('vendorSel').value];return v?v.gst:null;}
function recalc(){const mode=gstMode();let sub=0,disc=0,tax=0,cg=0,sg=0,ig=0,lt=0;
 document.querySelectorAll('.pm-line').forEach(row=>{const q=parseFloat(row.querySelector('.qIn').value)||0,r=parseFloat(row.querySelector('.rIn').value)||0,d=parseFloat(row.querySelector('.dIn').value)||0,g=parseFloat(row.querySelector('.gIn').value)||0;
  const gross=r2(q*r),tv=r2(gross-d);let c=0,s=0,i=0;if(mode==='IGST')i=r2(tv*g/100);else if(mode==='CGST_SGST'){c=r2(tv*g/200);s=r2(tv*g/200);}
  const tot=r2(tv+c+s+i);row.querySelector('.tvOut').value=tv.toFixed(2);row.querySelector('.ltOut').value=tot.toFixed(2);sub+=gross;disc+=d;tax+=tv;cg+=c;sg+=s;ig+=i;lt+=tot;});
 const f=k=>parseFloat(document.querySelector('[name="'+k+'"]').value)||0;const pre=r2(lt+f('freight')+f('packing')+f('other_charges'));const ro=r2(Math.round(pre)-pre);const grand=r2(pre+ro);
 const set=(id,v)=>document.getElementById(id).textContent=v.toFixed(2);set('tSub',sub);set('tDisc',disc);set('tTax',tax);set('tCgst',cg);set('tSgst',sg);set('tIgst',ig);set('tRound',ro);set('tGrand',grand);
 const paid=parseFloat(document.getElementById('payAmt').value)||0;set('tDue',Math.max(0,grand-paid));document.getElementById('tStatus').textContent=paid<=0?'UNPAID':(paid+0.004>=grand?'PAID':'PARTIALLY PAID');}
function vendorInfo(){const v=VENDORS[document.getElementById('vendorSel').value];document.getElementById('vendorInfo').textContent=v?('Area: '+(v.area||'—')+' · GSTIN: '+(v.gstin||'—')+' · '+(v.gst==='IGST'?'IGST (other state)':v.gst==='CGST_SGST'?'CGST + SGST (same state)':'GST type unknown: set state codes')):'';recalc();}
document.getElementById('vendorSel').addEventListener('change',vendorInfo);
['freight','packing','other_charges','pay_amount'].forEach(n=>document.querySelector('[name="'+n+'"]').addEventListener('input',recalc));
if(PRE.length)PRE.forEach(l=>addLine(l));else addLine();vendorInfo();
const dupBox=document.getElementById('dupBox');if(dupBox)dupBox.addEventListener('change',function(){const r=document.getElementById('dupReason');r.style.display=this.checked?'':'none';if(!this.checked)r.value='';});
document.getElementById('scanForm').addEventListener('submit',function(ev){ev.preventDefault();const inp=document.getElementById('scanIn'),msg=document.getElementById('scanMsg');const code=inp.value.trim().toUpperCase();inp.value='';if(!code)return;const pid=SCAN[code];
 if(!pid){msg.textContent='Not found: '+code+'. Create the product first (Barcode Creation).';return;}
 const sels=[...document.querySelectorAll('.pSel')];let s=sels.find(x=>x.value===String(pid));
 if(s){const q=s.closest('.pm-line').querySelector('.qIn');q.value=(parseFloat(q.value)||0)+1;recalc();}
 else{s=sels.find(x=>x.value==='');if(!s){const row=addLine();s=row.querySelector('.pSel');}s.value=String(pid);s.dispatchEvent(new Event('change'));}
 msg.textContent='Added: '+s.options[s.selectedIndex].text;inp.focus();});
document.getElementById('billForm').addEventListener('submit',function(ev){const btn=ev.submitter;document.querySelectorAll('#billForm button').forEach(b=>{if(b!==btn)b.disabled=true;});if(btn){const h=document.createElement('input');h.type='hidden';h.name='action';h.value=btn.value;this.appendChild(h);btn.disabled=true;}});
</script>
<?php page_footer();
