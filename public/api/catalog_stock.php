<?php
// POST /api/catalog_stock.php  {"skus":["SKU-1","SKU-2"]}  (Authorization: Bearer <key>)  -> online-sellable pieces per SKU.
// All logic is in app/catalog_api.php so it can be tested without a web server.
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/catalog_api.php';
catalog_send(catalog_stock_handle($_SERVER, (string)file_get_contents('php://input', false, null, 0, CATALOG_MAX_BODY_BYTES + 1), db(), cfg()));
