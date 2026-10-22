<?php
// Print-only page for product barcode labels. Read-only: never creates or changes a barcode.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/barcode.php';
require_permission('manage_products');

function clamp_num($v, float $min, float $max, float $default): float {
    if (!is_numeric($v)) return $default;
    return max($min, min($max, (float)$v));
}

$id = (int)($_GET['id'] ?? 0);
$count = (int)clamp_num($_GET['count'] ?? 1, 1, 50, 1);
$invoice = mb_substr(trim((string)($_GET['invoice'] ?? '')), 0, 60);
$mode = ($_GET['mode'] ?? 'sheet') === 'roll' ? 'roll' : 'sheet';
$w = clamp_num($_GET['w'] ?? 50, 25, 120, 50);
$h = clamp_num($_GET['h'] ?? 30, 15, 80, 30);
$gap = clamp_num($_GET['gap'] ?? 2, 0, 10, 2);
$margin = clamp_num($_GET['margin'] ?? 5, 0, 20, 5);
$autoprint = ($_GET['autoprint'] ?? '') === '1';

$err = '';
$product = null;
$svg = '';
try {
    if ($id <= 0) throw new RuntimeException('No product selected. Save the barcode on Barcode Creation first.');
    $st = db()->prepare('SELECT id,sku,product_barcode,product_name,color,size,mrp,retail_price FROM products WHERE id=?');
    $st->execute([$id]);
    $product = $st->fetch();
    if (!$product) throw new RuntimeException('Product not found. It may have been deleted.');
    if (!code128_valid((string)$product['product_barcode'])) throw new RuntimeException('The saved barcode "' . $product['product_barcode'] . '" contains characters that cannot be printed as a barcode. Fix it in Barcode Creation or Product Master.');
    $svg = code128_svg((string)$product['product_barcode']);
} catch (RuntimeException $e) {
    $err = $e->getMessage();
} catch (Throwable $e) {
    $err = user_error_message($e, 'Could not load the product for printing. Please try again.');
}

$shop = setting('company_name', 'Pratyangi Fashions') ?? 'Pratyangi Fashions';
$mrp = $product ? (float)$product['mrp'] : 0;
$retail = $product ? (float)$product['retail_price'] : 0;
$variant = $product ? trim($product['color'] . ($product['color'] !== '' && $product['size'] !== '' ? ' / ' : '') . $product['size']) : '';
$fmt = fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
$query = fn(array $extra): string => 'barcode_print.php?' . http_build_query(array_merge(['id' => $id, 'count' => $count, 'invoice' => $invoice, 'mode' => $mode, 'w' => $fmt($w), 'h' => $fmt($h), 'gap' => $fmt($gap), 'margin' => $fmt($margin)], $extra));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Print Barcode<?= $product ? ' - ' . e($product['sku']) : '' ?></title>
<style>
:root{--w:<?= $fmt($w) ?>mm;--h:<?= $fmt($h) ?>mm;--gap:<?= $fmt($gap) ?>mm;--margin:<?= $fmt($margin) ?>mm}
*{box-sizing:border-box}
body{margin:0;background:#eef1f5;font-family:Arial,Helvetica,sans-serif;color:#000}
.toolbar{background:#0f2f57;color:#fff;padding:12px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:end}
.toolbar label{display:block;font-size:12px;opacity:.85;margin-bottom:3px}
.toolbar input,.toolbar select{width:90px;padding:6px;border:0;border-radius:6px}
.toolbar .btn{background:#fff;color:#0f2f57;border:0;border-radius:6px;padding:9px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.toolbar .btn.primary{background:#ffcc33}
.info{padding:10px 16px;font-size:14px;background:#fff;border-bottom:1px solid #ccd}
.error{margin:16px;padding:14px;background:#fde8e8;border:1px solid #e0a0a0;border-radius:8px}
.sheet{background:#fff;margin:16px auto;padding:var(--margin);width:210mm;min-height:297mm;display:flex;flex-wrap:wrap;gap:var(--gap);align-content:flex-start}
.sheet.roll{width:auto;min-height:0;display:block;padding:16px;background:transparent}
.label{width:var(--w);height:var(--h);background:#fff;border:1px dashed #999;padding:1.2mm 1.5mm;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;text-align:center;break-inside:avoid;page-break-inside:avoid}
.roll .label{margin:0 auto 4mm}
.shop{font-weight:800;font-size:2.6mm;letter-spacing:.2mm;text-transform:uppercase;line-height:1.1}
.pname{font-size:2.5mm;font-weight:700;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.meta{font-size:2.2mm;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.barcode-svg{display:block;width:100%;height:calc(var(--h) * .34);min-height:7mm}
.code{font-family:"Courier New",monospace;font-size:2.4mm;font-weight:700;letter-spacing:.15mm;line-height:1}
.price{font-size:2.8mm;font-weight:800;line-height:1.1}
.price s{font-weight:400}
@media print{
  @page{<?= $mode === 'roll' ? 'size:' . $fmt($w) . 'mm ' . $fmt($h) . 'mm;margin:0' : 'size:A4;margin:' . $fmt($margin) . 'mm' ?>}
  body{background:#fff}
  .toolbar,.info,.error{display:none!important}
  .sheet{margin:0;padding:0;width:auto;min-height:0;box-shadow:none}
  .label{border:0}
  .roll .label{margin:0;page-break-after:always;break-after:page}
  .roll .label:last-child{page-break-after:auto;break-after:auto}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style></head><body>
<form class="toolbar" method="get" action="barcode_print.php">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="invoice" value="<?= e($invoice) ?>">
  <div><label>Labels</label><input type="number" name="count" min="1" max="50" value="<?= (int)$count ?>"></div>
  <div><label>Printer type</label><select name="mode" style="width:150px"><option value="sheet"<?= $mode === 'sheet' ? ' selected' : '' ?>>A4 sheet (normal printer)</option><option value="roll"<?= $mode === 'roll' ? ' selected' : '' ?>>Label roll (barcode printer)</option></select></div>
  <div><label>Label width (mm)</label><input type="number" step="0.5" min="25" max="120" name="w" value="<?= e($fmt($w)) ?>"></div>
  <div><label>Label height (mm)</label><input type="number" step="0.5" min="15" max="80" name="h" value="<?= e($fmt($h)) ?>"></div>
  <div><label>Gap (mm)</label><input type="number" step="0.5" min="0" max="10" name="gap" value="<?= e($fmt($gap)) ?>"></div>
  <div><label>Page margin (mm)</label><input type="number" step="0.5" min="0" max="20" name="margin" value="<?= e($fmt($margin)) ?>"></div>
  <div><button class="btn" type="submit">Apply</button></div>
  <?php if ($product): ?><div><button class="btn primary" type="button" id="printBtn">Print Barcode</button></div><?php endif; ?>
  <div><a class="btn" href="barcode_create.php">Back</a></div>
</form>
<?php if ($err): ?>
<div class="error"><b>Cannot print:</b> <?= e($err) ?></div>
<?php else: ?>
<div class="info"><b><?= (int)$count ?></b> label<?= $count === 1 ? '' : 's' ?> of <b><?= e($product['sku']) ?></b> · barcode <b><?= e($product['product_barcode']) ?></b>. Choose your printer in the print dialog. For a label printer, set the paper size to <?= e($fmt($w)) ?> × <?= e($fmt($h)) ?> mm and scale 100%.</div>
<div class="sheet <?= $mode ?>" id="labels">
<?php for ($i = 0; $i < $count; $i++): ?>
  <div class="label" data-code="<?= e($product['product_barcode']) ?>">
    <div class="shop"><?= e($shop) ?></div>
    <div class="pname"><?= e($product['product_name']) ?></div>
    <div class="meta"><?= e($product['sku']) ?><?= $variant !== '' ? ' · ' . e($variant) : '' ?></div>
    <?= $svg ?>
    <div class="code"><?= e($product['product_barcode']) ?></div>
    <div class="price"><?php if ($mrp > 0 && $retail > 0 && $retail < $mrp): ?>MRP <s>₹<?= e(number_format($mrp, 2)) ?></s> ₹<?= e(number_format($retail, 2)) ?><?php elseif ($mrp > 0 || $retail > 0): ?>MRP ₹<?= e(number_format($mrp > 0 ? $mrp : $retail, 2)) ?><?php endif; ?></div>
    <?php if ($invoice !== ''): ?><div class="meta">Inv <?= e($invoice) ?></div><?php endif; ?>
  </div>
<?php endfor; ?>
</div>
<script src="assets/js/qrcode.js"></script><script src="assets/js/label_qr.js"></script>
<script>
document.getElementById('printBtn').addEventListener('click', function () { window.print(); });
<?php if ($autoprint): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });<?php endif; ?>
</script>
<?php endif; ?>
</body></html>
