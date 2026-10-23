<?php
// GET /api/catalog_products.php?limit=100&after_id=0  (Authorization: Bearer <key>)  -> read-only product list for the online store.
// All logic is in app/catalog_api.php so it can be tested without a web server.
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/catalog_api.php';
catalog_send(catalog_products_handle($_SERVER, $_GET, db(), cfg()));
