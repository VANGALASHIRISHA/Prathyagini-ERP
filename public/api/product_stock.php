<?php
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/product_finder.php';
pf_api_guard();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') pf_json(['ok' => false, 'error' => 'Method not allowed'], 405);

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) pf_json(['ok' => false, 'error' => 'Invalid product.'], 422);

try {
    $detail = pf_product_detail($id, pf_visibility());
    if (!$detail) pf_json(['ok' => false, 'error' => 'Product not found.'], 404);
    pf_json(['ok' => true] + $detail);
} catch (Throwable $e) {
    error_log('product_stock: ' . $e->getMessage());
    pf_json(['ok' => false, 'error' => 'Could not load stock details. Please try again.'], 500);
}
