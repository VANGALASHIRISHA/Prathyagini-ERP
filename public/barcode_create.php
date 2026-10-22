<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_permission('manage_products');

$err = '';
$saved = null;
$received = null; // ['purchase_no','batch_id','qty','serials'=>[...]]
$role = current_user()['role'] ?? '';
$canPrice = in_array($role, ['super_admin', 'manager'], true);
$canCost = in_array($role, ['super_admin', 'manager'], true); // who can SEE the cost price
$canEditCost = $role === 'super_admin';                        // only the Super Admin can change it or receive purchased stock
$canReceive = has_permission('manage_purchases');
$P = fn(string $k): string => trim((string)($_POST[$k] ?? ''));
$invoiceNo = $P('invoice_no');
$labelCount = max(1, min(500, (int)($_POST['label_count'] ?? 1)));

$categories = db()->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
$suppliers = $canReceive ? db()->query('SELECT id,party_code,party_name FROM suppliers WHERE active=1 ORDER BY party_name')->fetchAll() : [];
$locations = $canReceive ? db()->query('SELECT id,code,name,rack,shelf,shelf_bin FROM locations WHERE active=1 ORDER BY code')->fetchAll() : [];
$sizeOptions = ['Free Size', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];

function bc_unique_barcode(): string {
    $chk = db()->prepare('SELECT 1 FROM products WHERE product_barcode=?');
    do {
        $code = 'PF' . date('ymd') . random_int(100000, 999999);
        $chk->execute([$code]);
    } while ($chk->fetchColumn());
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sku = $P('sku');
    $barcode = $P('product_barcode');
    $name = $P('product_name');
    $color = $P('color');
    $size = $P('size');
    $brand = $P('brand') ?: 'Pratyangi Fashions';
    $fabric = $P('fabric');
    $design = $P('design_model');
    $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
    $gst = $P('tax_rate') === '' ? null : (float)$P('tax_rate');
    $mrp = $P('mrp') === '' ? null : (float)$P('mrp');
    $selling = $P('selling_price') === '' ? null : (float)$P('selling_price');
    $cost = (!$canEditCost || $P('purchase_cost') === '') ? null : (float)$P('purchase_cost');
    $qty = (int)($_POST['quantity'] ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $receiving = $canReceive && $qty > 0;

    if (!form_once_claim('barcode_create')) {
        $err = 'This form was already submitted. Check the result below or reload the page before saving again.';
    } elseif ($sku === '' || $name === '') {
        $err = 'Product code (SKU) and product name are required.';
    } elseif ($barcode !== '' && !preg_match('/^[\x21-\x7E]{3,80}$/', $barcode)) {
        $err = 'Barcode must be 3-80 characters using only English letters, numbers and symbols, with no spaces, so it can be printed and scanned.';
    } elseif (($mrp !== null && $mrp < 0) || ($selling !== null && $selling < 0) || ($cost !== null && $cost < 0)) {
        $err = 'Prices cannot be negative.';
    } elseif ($canPrice && $mrp !== null && $selling !== null && $selling > $mrp) {
        $err = 'Selling price (' . number_format($selling, 2) . ') cannot be higher than MRP (' . number_format($mrp, 2) . '). Nothing was saved.';
    } elseif ($gst !== null && ($gst < 0 || $gst > 100)) {
        $err = 'GST rate must be between 0 and 100.';
    } elseif ($qty < 0 || $qty > 500) {
        $err = 'Quantity must be between 0 and 500.';
    } elseif ($receiving && (!$supplierId || !$locationId)) {
        $err = 'To receive stock, choose a supplier and a godown / branch.';
    } elseif ($receiving && !$canEditCost) {
        $err = 'Only the Super Admin can receive purchased stock (it needs the purchase price). Save the barcode without a quantity instead.';
    } elseif ($receiving && $cost === null) {
        $err = 'Purchase price is required to receive stock.';
    } else {
        try {
            db()->beginTransaction();
            $bySku = db()->prepare('SELECT id FROM products WHERE sku=? LIMIT 1 FOR UPDATE');
            $bySku->execute([$sku]);
            $skuId = (int)($bySku->fetchColumn() ?: 0);
            $barcodeId = 0;
            if ($barcode !== '') {
                $byBarcode = db()->prepare('SELECT id FROM products WHERE product_barcode=? LIMIT 1 FOR UPDATE');
                $byBarcode->execute([$barcode]);
                $barcodeId = (int)($byBarcode->fetchColumn() ?: 0);
            }
            if ($skuId && $barcodeId && $skuId !== $barcodeId) {
                throw new Exception('That product code and barcode belong to two different products.');
            }
            $id = $skuId ?: $barcodeId;
            if ($id) {
                // Existing product: never blank out stored values with empty form fields.
                db()->prepare('UPDATE products SET sku=?, product_barcode=COALESCE(NULLIF(?,\'\'),product_barcode), product_name=?, color=?, size=?, brand=?, fabric=?, design_model=?, category_id=?, tax_rate=COALESCE(?,tax_rate) WHERE id=?')
                    ->execute([$sku, $barcode, $name, $color, $size, $brand, $fabric ?: null, $design ?: null, $categoryId, $gst, $id]);
                if ($canPrice) {
                    if ($mrp !== null) db()->prepare('UPDATE products SET mrp=? WHERE id=?')->execute([$mrp, $id]);
                    if ($selling !== null) db()->prepare('UPDATE products SET retail_price=? WHERE id=?')->execute([$selling, $id]);
                }
                audit('BARCODE_UPDATE', 'product', $id);
            } else {
                if ($barcode === '') $barcode = bc_unique_barcode();
                $costCode = 'C' . strtoupper(bin2hex(random_bytes(3)));
                $newMrp = $canPrice ? ($mrp ?? $selling ?? 0) : 0;
                $newSell = $canPrice ? ($selling ?? $mrp ?? 0) : 0;
                db()->prepare('INSERT INTO products(sku,product_barcode,product_name,category_id,brand,fabric,design_model,color,size,unit,purchase_cost,cost_code,mrp,retail_price,wholesale_price,dealer_price,tax_rate,min_stock,serial_tracking) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
                    ->execute([$sku, $barcode, $name, $categoryId, $brand, $fabric ?: null, $design ?: null, $color, $size, 'PCS', 0, $costCode, $newMrp, $newSell, 0, 0, $gst ?? 0, 0]);
                $id = (int)db()->lastInsertId();
                audit('BARCODE_CREATE', 'product', $id);
            }

            if ($receiving) {
                $uid = (int)current_user()['id'];
                $chk = db()->prepare('SELECT 1 FROM suppliers WHERE id=? AND active=1');
                $chk->execute([$supplierId]);
                if (!$chk->fetchColumn()) throw new Exception('Supplier is missing or inactive.');
                $chk = db()->prepare('SELECT 1 FROM locations WHERE id=? AND active=1');
                $chk->execute([$locationId]);
                if (!$chk->fetchColumn()) throw new Exception('Godown / branch is missing or inactive.');
                $lineTotal = $qty * $cost;
                $purchaseId = 0;
                if ($invoiceNo !== '') {
                    // Same supplier invoice may carry several products: append to it.
                    $ex = db()->prepare('SELECT id,purchase_no FROM purchases WHERE supplier_id=? AND invoice_no=? LIMIT 1 FOR UPDATE');
                    $ex->execute([$supplierId, $invoiceNo]);
                    if ($row = $ex->fetch()) {
                        $purchaseId = (int)$row['id'];
                        $purchaseNo = $row['purchase_no'];
                        db()->prepare('UPDATE purchases SET total_cost=total_cost+? WHERE id=?')->execute([$lineTotal, $purchaseId]);
                    }
                }
                if (!$purchaseId) {
                    $purchaseNo = 'PUR-' . date('YmdHis') . '-' . random_int(100, 999);
                    db()->prepare('INSERT INTO purchases(purchase_no,supplier_id,invoice_no,invoice_date,total_cost,created_by) VALUES(?,?,?,?,?,?)')
                        ->execute([$purchaseNo, $supplierId, $invoiceNo, date('Y-m-d'), $lineTotal, $uid]);
                    $purchaseId = (int)db()->lastInsertId();
                }
                db()->prepare("INSERT INTO supplier_ledger(supplier_id,txn_type,credit,reference_type,reference_id,remarks,created_by) VALUES(?,'PURCHASE',?,'PURCHASE',?,?,?)")
                    ->execute([$supplierId, $lineTotal, $purchaseId, $purchaseNo, $uid]);
                $batchNo = $P('batch_no') ?: 'LOT-' . date('ymdHis');
                $batchBarcode = 'PFB-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($sku)) . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                db()->prepare('INSERT INTO batches(batch_no,batch_barcode,product_id,supplier_id,purchase_id,purchase_invoice_no,purchased_at,cost_price,cost_code,qty_received,qty_available) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$batchNo, $batchBarcode, $id, $supplierId, $purchaseId, $invoiceNo, date('Y-m-d'), $cost, 'BC-' . strtoupper(bin2hex(random_bytes(4))), $qty, $qty]);
                $batchId = (int)db()->lastInsertId();
                db()->prepare('INSERT INTO purchase_items(purchase_id,product_id,batch_id,location_id,quantity,cost_price) VALUES(?,?,?,?,?,?)')
                    ->execute([$purchaseId, $id, $batchId, $locationId, $qty, $cost]);
                db()->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')
                    ->execute([$id, $batchId, $locationId, $qty]);
                db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_in,reference_type,reference_id,unit_rate,remarks,created_by) VALUES('PURCHASE',?,?,?,?,'PURCHASE',?,?,?,?)")
                    ->execute([$id, $batchId, $locationId, $qty, $purchaseId, $cost, $invoiceNo, $uid]);
                db()->prepare('UPDATE products SET purchase_cost=? WHERE id=?')->execute([$cost, $id]);
                $serials = [];
                $ins = db()->prepare("INSERT INTO product_serials(serial_barcode,product_id,batch_id,location_id,status) VALUES(?,?,?,?,'AVAILABLE')");
                $check = db()->prepare('SELECT 1 FROM product_serials WHERE serial_barcode=?');
                for ($n = 1; $n <= $qty; $n++) {
                    do {
                        $serial = 'PFS-' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $sku)) . '-' . $batchId . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(1)));
                        $check->execute([$serial]);
                    } while ($check->fetchColumn());
                    $ins->execute([$serial, $id, $batchId, $locationId]);
                    $serials[] = $serial;
                }
                $received = ['purchase_no' => $purchaseNo, 'batch_id' => $batchId, 'qty' => $qty, 'serials' => $serials];
                audit('PURCHASE_INWARD', 'purchase', $purchaseId, ['purchase_no' => $purchaseNo, 'via' => 'barcode_create', 'qty' => $qty]);
            }
            db()->commit();
            $st = db()->prepare('SELECT id,sku,product_barcode,product_name,color,size,mrp,retail_price,serial_tracking FROM products WHERE id=?');
            $st->execute([$id]);
            $saved = $st->fetch();
            publish_event('BARCODE_CREATE', 'Barcode saved', $sku . ' · ' . $name, ['super_admin', 'manager'], 'product', $id, null);
            if ($received) publish_scoped_event('PURCHASE_STOCK_IN', 'New stock received', $received['purchase_no'] . ' · ' . $qty . ' piece(s)', ['manager', 'godown'], 'purchase', $purchaseId);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $received = null;
            $err = user_error_message($e, 'This product code or barcode is already used by another product.');
        }
    }
}

$full = null;
if ($saved) {
    $st = db()->prepare('SELECT * FROM products WHERE id=?');
    $st->execute([$saved['id']]);
    $full = $st->fetch();
}
$V = fn(string $k, $fallback = '') => $full[$k] ?? ($_POST[$k] ?? $fallback);
$val = [
    'sku' => $V('sku'), 'product_barcode' => $V('product_barcode'), 'product_name' => $V('product_name'),
    'color' => $V('color'), 'size' => $V('size'), 'brand' => $V('brand', 'Pratyangi Fashions'),
    'fabric' => $V('fabric'), 'design_model' => $V('design_model'), 'category_id' => $V('category_id'),
    'tax_rate' => $V('tax_rate'), 'mrp' => $V('mrp'), 'selling_price' => $full['retail_price'] ?? ($_POST['selling_price'] ?? ''),
];
// A successful save keeps the saved values in the form; stock fields reset so a refresh cannot double-receive.
$keepStock = !$saved;

page_header('Barcode Creation');
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
$printUrl = $saved ? 'barcode_print.php?' . http_build_query(['id' => (int)$saved['id'], 'count' => $labelCount, 'invoice' => $invoiceNo]) : '';
if ($saved) {
    echo '<div class="notice success">Barcode <b>' . e($saved['product_barcode']) . '</b> saved for <b>' . e($saved['sku'] . ' · ' . $saved['product_name']) . '</b>.';
    if ($received) echo ' Stock received: <b>' . (int)$received['qty'] . '</b> piece(s) on ' . e($received['purchase_no']) . ', each with its own unique barcode. <a href="barcode_labels.php?batch_id=' . (int)$received['batch_id'] . '" target="_blank">Open piece-label page</a>.';
    echo ' <button type="button" class="btn" onclick="window.print()">Print Barcode</button></div>';
}
if ($saved && !$canPrice) echo '<div class="notice">Prices are set by the Super Admin / Manager. The label shows the current saved MRP.</div>';
if ($saved && !$received && (int)$saved['serial_tracking'] === 1) echo '<div class="notice"><b>Product-level label:</b> POS, Pick and Pack need the individual piece barcode. Enter a quantity (with supplier + godown) to create one unique barcode per piece, or print them later from <a href="purchases.php">Purchase History</a>.</div>';
?>
<form method="post" class="panel" id="barcodeForm" autocomplete="off">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="form_once" value="<?= e(form_once_token('barcode_create')) ?>">
<div id="barcodeMatch" class="notice" hidden></div>
<h3>Product</h3>
<div class="row3">
<div><label>Product Name *</label><input name="product_name" id="product_name" required value="<?= e($val['product_name']) ?>"></div>
<div><label>Product Code / SKU *</label><input name="sku" id="sku" required value="<?= e($val['sku']) ?>"></div>
<div><label>Barcode Number</label>
 <div style="display:flex;gap:6px"><input name="product_barcode" id="product_barcode" data-scanner class="scanbox" value="<?= e($val['product_barcode']) ?>" placeholder="Auto-generated if empty"><button type="button" class="btn secondary" id="genBarcode">Generate</button></div></div>
</div><br>
<div class="row3">
<div><label>Category</label><select name="category_id"><option value="">-- Select --</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (string)$val['category_id'] === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Brand Name</label><input name="brand" id="brand" value="<?= e($val['brand']) ?>"></div>
<div><label>Design / Model Number</label><input name="design_model" id="design_model" value="<?= e($val['design_model']) ?>"></div>
</div><br>
<div class="row3">
<div><label>Size</label><input name="size" id="size" list="sizeList" value="<?= e($val['size']) ?>"><datalist id="sizeList"><?php foreach ($sizeOptions as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
<div><label>Colour</label><input name="color" id="color" value="<?= e($val['color']) ?>"></div>
<div><label>Fabric Type</label><input name="fabric" id="fabric" value="<?= e($val['fabric']) ?>" placeholder="Cotton, Silk, ..."></div>
</div><br>
<h3>Prices</h3>
<div class="row3">
<div><label>MRP</label><input type="number" step="0.01" min="0" name="mrp" id="mrp" value="<?= e($val['mrp']) ?>"<?= $canPrice ? '' : ' readonly' ?>></div>
<div><label>Selling Price</label><input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="<?= e($val['selling_price']) ?>"<?= $canPrice ? '' : ' readonly' ?>></div>
<div><label>GST Rate %</label><input type="number" step="0.01" min="0" max="100" name="tax_rate" id="tax_rate" value="<?= e($val['tax_rate']) ?>"></div>
</div>
<?php if ($canCost): ?><br><div class="row3"><div><label>Purchase Price (cost)</label><input type="number" step="0.01" min="0" name="purchase_cost" id="purchase_cost"<?= $canEditCost ? '' : ' readonly' ?> value="<?= $keepStock ? e($_POST['purchase_cost'] ?? '') : '' ?>"></div></div><?php endif; ?>
<?php if ($canReceive && $canEditCost): ?>
<br><h3>Receive stock (optional) — creates one unique barcode per piece</h3>
<div class="row3">
<div><label>Supplier Name</label><select name="supplier_id"><option value="">-- Select --</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $keepStock && (int)($_POST['supplier_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['party_code'] . ' · ' . $s['party_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Purchase Invoice Number</label><input name="invoice_no" id="invoice_no" value="<?= $keepStock ? e($invoiceNo) : '' ?>"></div>
<div><label>Godown / Branch · Rack / Shelf</label><select name="location_id"><option value="">-- Select --</option><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $keepStock && (int)($_POST['location_id'] ?? 0) === (int)$l['id'] ? ' selected' : '' ?>><?= e($l['code'] . ' · ' . $l['name'] . ($l['rack'] ? ' · Rack ' . $l['rack'] : '') . ($l['shelf'] ? ' / Shelf ' . $l['shelf'] : '') . ($l['shelf_bin'] ? ' / Bin ' . $l['shelf_bin'] : '')) ?></option><?php endforeach; ?></select></div>
</div><br>
<div class="row3">
<div><label>Quantity (pieces)</label><input type="number" min="0" max="500" name="quantity" value="<?= $keepStock ? (int)($_POST['quantity'] ?? 0) : 0 ?>"></div>
<div><label>Batch / Lot (optional)</label><input name="batch_no" value="<?= $keepStock ? e($_POST['batch_no'] ?? '') : '' ?>" placeholder="Auto if empty"></div>
<div><label>Created Date</label><input value="<?= e(date('d-m-Y')) ?>" readonly></div>
</div>
<?php else: ?>
<br><div class="row3"><div><label>Invoice No (printed on label)</label><input name="invoice_no" id="invoice_no" value="<?= e($invoiceNo) ?>"></div><div><label>Created Date</label><input value="<?= e(date('d-m-Y')) ?>" readonly></div></div>
<?php endif; ?>
<br><div class="row3">
<div><label>Barcode Print Quantity</label><input type="number" name="label_count" id="label_count" min="1" max="500" value="<?= (int)$labelCount ?>"></div>
<div><label>Label width × height (mm)</label><div style="display:flex;gap:6px"><input type="number" id="lblW" min="20" max="200" value="50"><input type="number" id="lblH" min="15" max="150" value="30"></div></div>
</div><br>
<div class="actions">
<button class="btn" id="saveBtn">Save</button>
<button type="button" class="btn secondary" id="genBtn2">Generate Barcode</button>
<?php if ($saved): ?><button type="button" class="btn secondary" onclick="window.print()">Print Barcode</button><?php endif; ?>
<a class="btn secondary" href="barcode_create.php">Reset</a>
<a class="btn secondary" href="scan_find.php">Search Barcode</a>
</div>
</form>
<?php if ($saved): ?>
<div id="labels" class="labels"></div>
<?php endif; ?>
<style>
.labels{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}
.label{box-sizing:border-box;width:var(--lw,50mm);min-height:var(--lh,30mm);border:1px solid #aaa;padding:2mm;text-align:center;background:#fff;color:#000;break-inside:avoid;font-size:8pt;line-height:1.15;overflow:hidden}
.label .name{font-weight:800}
.label svg{width:100%;height:calc(var(--lh,30mm) * .36)}
@media print{
 @page{margin:3mm}
 header,footer,.side,.nav-toggle,.nav-backdrop,form,.notice,h1,.no-print{display:none!important}
 .app-shell,.app-main,main{display:block!important;margin:0!important;padding:0!important}
 .labels{margin:0;gap:0}.label{border:0;border-bottom:1px dashed #999;page-break-inside:avoid}
}
</style>
<script>
(function(){
 const $=id=>document.getElementById(id);
 function gen(){const d=new Date(),p=n=>String(n).padStart(2,'0');$('product_barcode').value='PF'+String(d.getFullYear()).slice(2)+p(d.getMonth()+1)+p(d.getDate())+Math.floor(100000+Math.random()*900000);}
 $('genBarcode').addEventListener('click',gen);$('genBtn2').addEventListener('click',gen);
 document.getElementById('barcodeForm').addEventListener('submit',function(){const b=$('saveBtn');b.disabled=true;b.textContent='Saving…';});
 const mrp=$('mrp'),sell=$('selling_price');
 function chk(){if(mrp&&sell&&!mrp.readOnly&&mrp.value!==''&&sell.value!==''&&parseFloat(sell.value)>parseFloat(mrp.value)){sell.setCustomValidity('Selling price cannot be higher than MRP');}else if(sell){sell.setCustomValidity('');}}
 if(mrp&&sell){mrp.addEventListener('input',chk);sell.addEventListener('input',chk);}
 const sku=$('sku'),barcode=$('product_barcode'),box=$('barcodeMatch');let timer;
 async function lookup(){
  const s=sku.value.trim(),b=barcode.value.trim();
  if(!s&&!b){box.hidden=true;return;}
  const r=await fetch('api/product_lookup.php?sku='+encodeURIComponent(s)+'&barcode='+encodeURIComponent(b),{credentials:'same-origin'});
  const j=await r.json();
  if(!j.ok||!j.found){box.hidden=true;return;}
  const p=j.product;
  // Fill only empty fields so typed values are never overwritten.
  const fill=(id,v)=>{const el=$(id);if(el&&!el.value.trim()&&v!==null&&v!==undefined&&v!=='')el.value=v;};
  fill('product_name',p.product_name);fill('color',p.color);fill('size',p.size);fill('brand',p.brand);fill('fabric',p.fabric);fill('design_model',p.design_model);
  fill('mrp',p.mrp);fill('selling_price',p.retail_price);fill('tax_rate',p.tax_rate);fill('sku',p.sku);fill('product_barcode',p.product_barcode);
  const cat=document.querySelector('[name=category_id]');if(cat&&!cat.value&&p.category_id)cat.value=p.category_id;
  fill('invoice_no',j.invoice_no);
  // Cost price appears automatically for the Super Admin (the API sends it only to them).
  const costEl=$('purchase_cost');const hasCost=p.purchase_cost!==undefined&&p.purchase_cost!==null;
  if(costEl&&hasCost&&!costEl.value.trim())costEl.value=Number(p.purchase_cost).toFixed(2);
  box.hidden=false;box.textContent='Existing product: '+p.sku+' · '+p.product_name+' · MRP ₹'+Number(p.mrp||0).toFixed(2)+' · Selling ₹'+Number(p.retail_price||0).toFixed(2)+(hasCost?' · Cost ₹'+Number(p.purchase_cost).toFixed(2):'')+'. Saving updates it (no duplicate product is created).';
 }
 function sched(){clearTimeout(timer);timer=setTimeout(lookup,300);}
 sku.addEventListener('input',sched);barcode.addEventListener('input',sched);
 function dims(){const w=Math.max(20,Math.min(200,+$('lblW').value||50)),h=Math.max(15,Math.min(150,+$('lblH').value||30));document.documentElement.style.setProperty('--lw',w+'mm');document.documentElement.style.setProperty('--lh',h+'mm');
  let st=document.getElementById('pageSize');if(!st){st=document.createElement('style');st.id='pageSize';document.head.appendChild(st);}st.textContent='@media print{@page{size:'+w+'mm '+h+'mm;margin:0}}';}
 $('lblW').addEventListener('input',dims);$('lblH').addEventListener('input',dims);dims();
})();
</script>
<?php if ($saved): ?>
<script src="assets/js/qrcode.js"></script><script src="assets/js/label_qr.js"></script>
<script>
const patterns=['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
function code128Svg(text){let vals=[104],sum=104;for(let i=0;i<text.length;i++){let c=text.charCodeAt(i);if(c<32||c>126)c=63;let v=c-32;vals.push(v);sum+=v*(i+1);}vals.push(sum%103,106);let x=10,bars='';for(const v of vals){const p=patterns[v];for(let i=0;i<p.length;i++){const w=parseInt(p[i])*2;if(i%2===0)bars+=`<rect x="${x}" y="0" width="${w}" height="45"/>`;x+=w;}}return `<svg viewBox="0 0 ${x+10} 60" xmlns="http://www.w3.org/2000/svg">${bars}<text x="${(x+10)/2}" y="58" font-size="10" text-anchor="middle">${text.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</text></svg>`;}
const data=<?= json_encode([
    'barcode' => $saved['product_barcode'], 'codes' => $received['serials'] ?? [], 'name' => $saved['product_name'], 'sku' => $saved['sku'],
    'size' => $saved['size'], 'color' => $saved['color'], 'mrp' => (float)$saved['mrp'], 'count' => $received ? count($received['serials']) : $labelCount,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
let html='';
for(let i=0;i<data.count;i++){const code=data.codes.length?data.codes[i]:data.barcode;html+=`<div class="label" data-code="${esc(code)}"><div class="name">PRATYANGI FASHIONS</div><div>${esc(data.name)}</div><div>${esc(data.sku)}${data.size?' · '+esc(data.size):''}${data.color?' · '+esc(data.color):''}</div>${code128Svg(code)}<div>${data.mrp>0?'MRP ₹'+data.mrp.toFixed(2):''}</div></div>`;}
document.getElementById('labels').innerHTML=html;if(window.PFLabelQR)PFLabelQR();
</script>
<?php endif; ?>
<?php page_footer();
