<?php
require __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
login_required();
if (!has_permission('manage_products')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

$sku = trim((string)($_GET['sku'] ?? ''));
$barcode = trim((string)($_GET['barcode'] ?? ''));
if ($sku === '' && $barcode === '') {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

$cols = 'p.id,p.sku,p.product_barcode,p.product_name,p.product_image,p.category_id,p.brand,p.fabric,p.pattern_name,p.gender,p.season,p.color,p.size,p.design_model,p.tax_rate,p.hsn_code,p.unit,p.min_stock,p.mrp,p.retail_price,p.wholesale_price,p.active,c.name AS category_name';
// Purchase cost is shown to the Super Admin and Managers only (never Cashier, Godown or Auditor).
if (in_array(current_user()['role'] ?? '', ['super_admin', 'manager'], true)) $cols .= ',p.purchase_cost';
$row = null;
if ($sku !== '' && $barcode !== '') {
    $st = db()->prepare("SELECT $cols FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.sku=? AND p.product_barcode=? LIMIT 1");
    $st->execute([$sku, $barcode]);
    $row = $st->fetch() ?: null;
}
if (!$row && $sku !== '') {
    $st = db()->prepare("SELECT $cols FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.sku=? LIMIT 1");
    $st->execute([$sku]);
    $row = $st->fetch() ?: null;
}
if (!$row && $barcode !== '') {
    $st = db()->prepare("SELECT $cols FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.product_barcode=? LIMIT 1");
    $st->execute([$barcode]);
    $row = $st->fetch() ?: null;
}

$invoice = null;
if ($row) {
    $st = db()->prepare('SELECT pu.invoice_no, pu.invoice_date FROM purchase_items pi JOIN purchases pu ON pu.id=pi.purchase_id WHERE pi.product_id=? ORDER BY pu.id DESC LIMIT 1');
    $st->execute([(int)$row['id']]);
    $invoice = $st->fetch() ?: null;
}

echo json_encode([
    'ok' => true,
    'found' => (bool)$row,
    'product' => $row,
    'invoice_no' => $invoice['invoice_no'] ?? '',
    'invoice_date' => $invoice['invoice_date'] ?? '',
], JSON_UNESCAPED_UNICODE);
