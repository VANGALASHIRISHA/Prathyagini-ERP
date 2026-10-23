<?php
/**
 * Tests for app/catalog_api.php. Run:  php tests/php/catalog_api_test.php
 *
 * No web server and NO real database: everything runs on an in-memory SQLite database with a minimal copy of the tables
 * involved, so nothing here can read or change ERP data. The SQL in catalog_api.php is plain ANSI on purpose, so it runs
 * on both. (Column names and meanings follow sql/database.sql.)
 */
require __DIR__ . '/../../app/catalog_api.php';

$GLOBALS['__fails'] = 0;
$GLOBALS['__checks'] = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    $GLOBALS['__checks']++;
    if (!$ok) { $GLOBALS['__fails']++; echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
function same(string $name, $expected, $actual): void {
    check($name, $expected === $actual, 'expected ' . json_encode($expected) . ' got ' . json_encode($actual));
}

function new_db(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE categories(id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
    $db->exec('CREATE TABLE locations(id INTEGER PRIMARY KEY, code TEXT, warehouse TEXT, online_fulfillment_enabled INTEGER DEFAULT 0, active INTEGER DEFAULT 1)');
    $db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY, sku TEXT UNIQUE, product_name TEXT, product_image TEXT, category_id INTEGER, fabric TEXT, pattern_name TEXT, color TEXT, size TEXT, design_model TEXT,
        purchase_cost REAL DEFAULT 0, cost_code TEXT, mrp REAL DEFAULT 0, retail_price REAL DEFAULT 0, wholesale_price REAL DEFAULT 0, dealer_price REAL DEFAULT 0, offer_price REAL, tax_rate REAL DEFAULT 0,
        serial_tracking INTEGER DEFAULT 1, active INTEGER DEFAULT 1, product_barcode TEXT)');
    $db->exec('CREATE TABLE inventory_balances(product_id INTEGER, batch_id INTEGER, location_id INTEGER, qty REAL, reserved_qty REAL DEFAULT 0)');
    $db->exec('CREATE TABLE product_serials(id INTEGER PRIMARY KEY, product_id INTEGER, batch_id INTEGER, location_id INTEGER, status TEXT DEFAULT \'AVAILABLE\')');
    $db->exec('CREATE TABLE online_orders(id INTEGER PRIMARY KEY, order_no TEXT UNIQUE, source TEXT, external_order_id TEXT, status TEXT, UNIQUE(source, external_order_id))');
    $db->exec("INSERT INTO categories(id,name) VALUES(1,'Sarees'),(2,'Kurtis')");
    // 1 = online warehouse, 2 = shop floor (not online), 3 = online but switched off
    $db->exec("INSERT INTO locations(id,code,warehouse,online_fulfillment_enabled,active) VALUES(1,'ONL','Main',1,1),(2,'SHOP','Shop',0,1),(3,'OLD','Main',1,0)");
    return $db;
}

function add_product(PDO $db, array $f): int {
    $f += ['product_image' => null, 'category_id' => 1, 'fabric' => 'Silk', 'pattern_name' => null, 'color' => 'Maroon', 'size' => null, 'design_model' => null,
           'mrp' => 1500, 'retail_price' => 1299, 'offer_price' => null, 'tax_rate' => 5, 'serial_tracking' => 0, 'active' => 1, 'wholesale_price' => 700, 'dealer_price' => 800, 'purchase_cost' => 500, 'cost_code' => 'SECRETCODE'];
    $st = $db->prepare('INSERT INTO products(sku,product_name,product_image,category_id,fabric,pattern_name,color,size,design_model,purchase_cost,cost_code,mrp,retail_price,wholesale_price,dealer_price,offer_price,tax_rate,serial_tracking,active,product_barcode)
        VALUES(:sku,:name,:product_image,:category_id,:fabric,:pattern_name,:color,:size,:design_model,:purchase_cost,:cost_code,:mrp,:retail_price,:wholesale_price,:dealer_price,:offer_price,:tax_rate,:serial_tracking,:active,:barcode)');
    $st->execute([':sku' => $f['sku'], ':name' => $f['name'] ?? $f['sku'], ':product_image' => $f['product_image'], ':category_id' => $f['category_id'], ':fabric' => $f['fabric'], ':pattern_name' => $f['pattern_name'],
        ':color' => $f['color'], ':size' => $f['size'], ':design_model' => $f['design_model'], ':purchase_cost' => $f['purchase_cost'], ':cost_code' => $f['cost_code'], ':mrp' => $f['mrp'], ':retail_price' => $f['retail_price'],
        ':wholesale_price' => $f['wholesale_price'], ':dealer_price' => $f['dealer_price'], ':offer_price' => $f['offer_price'], ':tax_rate' => $f['tax_rate'], ':serial_tracking' => $f['serial_tracking'],
        ':active' => $f['active'], ':barcode' => 'BC-' . $f['sku']]);
    return (int)$db->lastInsertId();
}
function stock(PDO $db, int $product, int $location, float $qty, float $reserved = 0, int $batch = 1): void {
    $db->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty,reserved_qty) VALUES(?,?,?,?,?)')->execute([$product, $batch, $location, $qty, $reserved]);
}
function serials(PDO $db, int $product, int $location, int $count, string $status = 'AVAILABLE', int $batch = 1): void {
    for ($i = 0; $i < $count; $i++) $db->prepare('INSERT INTO product_serials(product_id,batch_id,location_id,status) VALUES(?,?,?,?)')->execute([$product, $batch, $location, $status]);
}

$KEY = str_repeat('k', 40);
$ORDER_KEY = str_repeat('o', 40);
$cfg = ['api' => ['order_ingest_key' => $ORDER_KEY, 'catalogue_key' => $KEY]];
$get = fn(?string $key = null, string $method = 'GET') => ['REQUEST_METHOD' => $method] + ($key === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $key]);

// ---- authentication -----------------------------------------------------------------------------------------------------
$db = new_db();
same('wrong method -> 405', 405, catalog_products_handle($get($KEY, 'POST'), [], $db, $cfg)[0]);
same('no Authorization header -> 401', 401, catalog_products_handle($get(null), [], $db, $cfg)[0]);
same('wrong key -> 401', 401, catalog_products_handle($get(str_repeat('x', 40)), [], $db, $cfg)[0]);
same('empty bearer -> 401', 401, catalog_products_handle(['REQUEST_METHOD' => 'GET', 'HTTP_AUTHORIZATION' => 'Bearer '], [], $db, $cfg)[0]);
same('Basic scheme -> 401', 401, catalog_products_handle(['REQUEST_METHOD' => 'GET', 'HTTP_AUTHORIZATION' => 'Basic ' . $KEY], [], $db, $cfg)[0]);
same('correct key -> 200', 200, catalog_products_handle($get($KEY), [], $db, $cfg)[0]);
same('key through REDIRECT_HTTP_AUTHORIZATION -> 200', 200, catalog_products_handle(['REQUEST_METHOD' => 'GET', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $KEY], [], $db, $cfg)[0]);
same('the order key is NOT accepted when a dedicated read key is configured', 401, catalog_products_handle($get($ORDER_KEY), [], $db, $cfg)[0]);
$orderKeyOnly = ['api' => ['order_ingest_key' => $ORDER_KEY]];
// Changed from the bundle: the catalogue key must be its own key. No fallback to api.order_ingest_key.
same('NO fallback to the order key when there is no dedicated key -> 503', 503, catalog_products_handle($get($ORDER_KEY), [], $db, $orderKeyOnly)[0]);
same('no key configured -> 503 even with a header', 503, catalog_products_handle($get('anything-at-all-anything-at-all-1234'), [], $db, ['api' => []])[0]);
same('short key configured -> 503', 503, catalog_products_handle($get('short'), [], $db, ['api' => ['catalogue_key' => 'short']])[0]);
$denied = catalog_products_handle($get(str_repeat('x', 40)), [], $db, $cfg);
check('the 401 body does not contain any key', strpos(json_encode($denied), $KEY) === false && strpos(json_encode($denied), $ORDER_KEY) === false);
same('stock: GET -> 405', 405, catalog_stock_handle($get($KEY, 'GET'), '{"skus":["A"]}', $db, $cfg)[0]);
same('stock: no key -> 401', 401, catalog_stock_handle($get(null, 'POST'), '{"skus":["A"]}', $db, $cfg)[0]);

// ---- products page ------------------------------------------------------------------------------------------------------
$db = new_db();
add_product($db, ['sku' => 'SAR-1', 'name' => 'Kanjivaram Silk Saree', 'offer_price' => 1099.5, 'size' => 'Free', 'design_model' => 'KJ-01', 'pattern_name' => 'Temple', 'product_image' => 'uploads/products/sar-1.jpg']);
add_product($db, ['sku' => 'SAR 2/B', 'name' => 'Cotton Saree', 'category_id' => 2, 'retail_price' => 999, 'mrp' => 999, 'tax_rate' => 12, 'color' => '', 'fabric' => null]);
add_product($db, ['sku' => 'OLD-1', 'active' => 0]);
add_product($db, ['sku' => 'SAR-3', 'product_image' => '../../etc/passwd']);
add_product($db, ['sku' => 'SAR-4', 'product_image' => 'uploads/products/x.php']);
add_product($db, ['sku' => 'SAR-5', 'product_image' => 'https://evil.example/uploads/products/a.jpg']);
add_product($db, ['sku' => 'SAR-6', 'product_image' => 'uploads/other/a.jpg', 'category_id' => null]);
add_product($db, ['sku' => 'SAR-7', 'product_image' => 'uploads/products/ok.PNG']);

$res = catalog_products_handle($get($KEY), [], $db, $cfg);
same('page: 200', 200, $res[0]);
$byId = [];
foreach ($res[1]['products'] as $p) $byId[$p['sku']] = $p;
same('page: inactive products are left out', false, isset($byId['OLD-1']));
same('page: 7 active products', 7, count($res[1]['products']));
same('page: no more pages', null, $res[1]['next_cursor']);
same('page: stable field set', ['category', 'color', 'design_model', 'fabric', 'image_path', 'mrp', 'name', 'offer_price', 'pattern_name', 'retail_price', 'size', 'sku', 'tax_rate'], (function () use ($byId) { $k = array_keys($byId['SAR-1']); sort($k); return $k; })());
same('page: prices are exact two-decimal strings', ['mrp' => '1500.00', 'retail_price' => '1299.00', 'offer_price' => '1099.50', 'tax_rate' => '5.00'], array_intersect_key($byId['SAR-1'], array_flip(['mrp', 'retail_price', 'offer_price', 'tax_rate'])));
same('page: no offer price is null, not zero', null, $byId['SAR 2/B']['offer_price']);
same('page: category name comes from the category table', 'Kurtis', $byId['SAR 2/B']['category']);
same('page: no category is null', null, $byId['SAR-6']['category']);
same('page: empty text becomes null', [null, null], [$byId['SAR 2/B']['color'], $byId['SAR 2/B']['fabric']]);
same('page: a SKU with a space and a slash is returned as is', 'SAR 2/B', $byId['SAR 2/B']['sku']);
same('page: design model and pattern are exposed for the grouping decision', ['KJ-01', 'Temple'], [$byId['SAR-1']['design_model'], $byId['SAR-1']['pattern_name']]);
same('page: a real picture path is kept', 'uploads/products/sar-1.jpg', $byId['SAR-1']['image_path']);
same('page: an uppercase extension is kept', 'uploads/products/ok.PNG', $byId['SAR-7']['image_path']);
same('page: ../ path is dropped', null, $byId['SAR-3']['image_path']);
same('page: a .php file is dropped', null, $byId['SAR-4']['image_path']);
same('page: an absolute URL is dropped', null, $byId['SAR-5']['image_path']);
same('page: a path outside uploads/products is dropped', null, $byId['SAR-6']['image_path']);
$json = json_encode($res[1]);
foreach (['purchase_cost', 'cost_code', 'wholesale', 'dealer', 'barcode', 'SECRETCODE', 'BC-SAR', '"500', '700', '800'] as $leak) {
    check("page: never exposes $leak", strpos($json, $leak) === false);
}

// pagination
$db = new_db();
for ($i = 1; $i <= 5; $i++) add_product($db, ['sku' => "P-$i"]);
add_product($db, ['sku' => 'P-GONE', 'active' => 0]);
$seen = [];
$cursor = null;
$pages = 0;
do {
    $q = ['limit' => '2'] + ($cursor !== null ? ['after_id' => $cursor] : []);
    $r = catalog_products_handle($get($KEY), $q, $db, $cfg);
    same("pagination: page $pages is 200", 200, $r[0]);
    foreach ($r[1]['products'] as $p) $seen[] = $p['sku'];
    $cursor = $r[1]['next_cursor'];
    $pages++;
} while ($cursor !== null && $pages < 10);
same('pagination: every active product exactly once, in order', ['P-1', 'P-2', 'P-3', 'P-4', 'P-5'], $seen);
same('pagination: 3 pages for 5 products at limit 2', 3, $pages);
$exact = catalog_products_handle($get($KEY), ['limit' => '5'], $db, $cfg);
same('pagination: exactly a full page has no next cursor', null, $exact[1]['next_cursor']);
same('pagination: default limit returns all small catalogues', 5, count(catalog_products_handle($get($KEY), [], $db, $cfg)[1]['products']));
same('pagination: after_id past the end is an empty page', [], catalog_products_handle($get($KEY), ['after_id' => '99999'], $db, $cfg)[1]['products']);
foreach (['0', '201', '-1', 'abc', '1.5', '', '1000', ' 5'] as $bad) {
    same("validation: limit '$bad' -> 422", 422, catalog_products_handle($get($KEY), ['limit' => $bad], $db, $cfg)[0]);
}
same('validation: limit as an array -> 422', 422, catalog_products_handle($get($KEY), ['limit' => ['5']], $db, $cfg)[0]);
foreach (['-1', 'x', '1.5', '12345678901', ''] as $bad) {
    same("validation: after_id '$bad' -> 422", 422, catalog_products_handle($get($KEY), ['after_id' => $bad], $db, $cfg)[0]);
}
same('validation: limit 200 is allowed', 200, catalog_products_handle($get($KEY), ['limit' => '200'], $db, $cfg)[0]);
$broken = new PDO('sqlite::memory:');
$broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$err = catalog_products_handle($get($KEY), [], $broken, $cfg);
same('a database failure -> 500 with a generic message', [500, 'Catalogue is unavailable'], [$err[0], $err[1]['error']]);
check('a database failure does not leak SQL', stripos(json_encode($err), 'products') === false && stripos(json_encode($err), 'SQLSTATE') === false);

// ---- availability -------------------------------------------------------------------------------------------------------
$db = new_db();
$a = add_product($db, ['sku' => 'A']);                       // plain stock
$b = add_product($db, ['sku' => 'B']);                       // only on the shop floor
$c = add_product($db, ['sku' => 'C']);                       // reserved down to zero
$d = add_product($db, ['sku' => 'D']);                       // fractions and two batches
$e = add_product($db, ['sku' => 'E', 'serial_tracking' => 1]); // limited by serials
$f = add_product($db, ['sku' => 'F']);                       // online location switched off
$g = add_product($db, ['sku' => 'G', 'active' => 0]);        // inactive
stock($db, $a, 1, 10, 3);                // 7 sellable
stock($db, $a, 2, 50);                   // shop-floor stock does not count
stock($db, $b, 2, 20);
stock($db, $c, 1, 4, 4);
stock($db, $c, 1, 2, 5, 2);              // reserved above qty never goes negative
stock($db, $d, 1, 2.9, 0, 1);            // 2 whole pieces
stock($db, $d, 1, 3.0, 0.5, 2);          // 2 whole pieces (2.5 -> 2)
stock($db, $e, 1, 5);
serials($db, $e, 1, 2);                  // only 2 available serials
serials($db, $e, 1, 3, 'RESERVED');
stock($db, $f, 3, 9);
stock($db, $g, 1, 9);
$av = fn(array $skus) => (function ($r) { $m = []; foreach ($r as $row) $m[$row['sku']] = $row['available_pieces']; return $m; })(catalog_availability($db, $skus));
same('stock: counts only online locations, minus reserved', 7, $av(['A'])['A']);
same('stock: shop-floor-only stock is 0 online', 0, $av(['B'])['B']);
same('stock: reserved never makes it negative', 0, $av(['C'])['C']);
same('stock: whole pieces per batch row, like the order endpoint', 4, $av(['D'])['D']);
same('stock: a serial-tracked product is limited to AVAILABLE serials', 2, $av(['E'])['E']);
same('stock: an inactive online location is ignored', 0, $av(['F'])['F']);
same('stock: inactive and unknown SKUs are not returned', ['A'], array_keys($av(['A', 'G', 'NOPE'])));
same('stock: duplicate SKUs are answered once', 1, count(catalog_availability($db, ['A', 'A', 'A'])));
same('stock: no SKUs -> nothing', [], catalog_availability($db, []));
// stock changes are seen immediately
$db->exec("UPDATE inventory_balances SET reserved_qty = 10 WHERE product_id = $a AND location_id = 1");
same('stock: a reservation is reflected at once', 0, $av(['A'])['A']);
$db->exec("UPDATE inventory_balances SET qty = 25, reserved_qty = 1 WHERE product_id = $a AND location_id = 1");
same('stock: a restock is reflected at once', 24, $av(['A'])['A']);

// handler validation
$ok = catalog_stock_handle($get($KEY, 'POST'), json_encode(['skus' => [' A ', 'B']]), $db, $cfg);
same('stock handler: 200 with trimmed SKUs', [200, ['A' => 24, 'B' => 0]], [$ok[0], (function ($r) { $m = []; foreach ($r['stock'] as $row) $m[$row['sku']] = $row['available_pieces']; return $m; })($ok[1])]);
foreach ([
    'invalid JSON' => ['{nope', 400],
    'missing skus' => ['{}', 400],
    'skus not a list' => ['{"skus":"A"}', 400],
    'empty list' => ['{"skus":[]}', 422],
    'number in list' => ['{"skus":["A",5]}', 422],
    'empty sku' => ['{"skus":["A"," "]}', 422],
    'sku too long' => [json_encode(['skus' => [str_repeat('x', 61)]]), 422],
    'too many skus' => [json_encode(['skus' => array_map(fn($i) => "S$i", range(1, 101))]), 422],
    'body too large' => [str_repeat(' ', CATALOG_MAX_BODY_BYTES + 1), 413],
] as $label => [$body, $status]) {
    same("stock handler: $label -> $status", $status, catalog_stock_handle($get($KEY, 'POST'), $body, $db, $cfg)[0]);
}
same('stock handler: 100 SKUs is allowed', 200, catalog_stock_handle($get($KEY, 'POST'), json_encode(['skus' => array_map(fn($i) => "S$i", range(1, 100))]), $db, $cfg)[0]);
$err = catalog_stock_handle($get($KEY, 'POST'), '{"skus":["A"]}', $broken, $cfg);
same('stock handler: a database failure -> 500', 500, $err[0]);

// ---- two online warehouses: the optional warehouse filter -------------------------------------------------------------------
$db = new_db();
$db->exec("INSERT INTO locations(id,code,warehouse,online_fulfillment_enabled,active) VALUES(4,'WH2','Second',1,1)");
$w = add_product($db, ['sku' => 'W']);
$ws = add_product($db, ['sku' => 'WS', 'serial_tracking' => 1]);
stock($db, $w, 1, 6);            // Main
stock($db, $w, 4, 4, 1);         // Second, 1 reserved -> 3
stock($db, $ws, 1, 5); serials($db, $ws, 1, 2);   // Main: 2 serials
stock($db, $ws, 4, 5, 0, 2); serials($db, $ws, 4, 3, 'AVAILABLE', 2);   // Second: 3 serials (batch 2)
$avw = fn(?string $wh) => (function ($r) { $m = []; foreach ($r as $row) $m[$row['sku']] = $row['available_pieces']; return $m; })(catalog_availability($db, ['W', 'WS'], $wh));
same('warehouses: without a filter every online warehouse counts', ['W' => 9, 'WS' => 5], $avw(null));
same('warehouses: the Main warehouse only', ['W' => 6, 'WS' => 2], $avw('Main'));
same('warehouses: the Second warehouse only', ['W' => 3, 'WS' => 3], $avw('Second'));
same('warehouses: an unknown warehouse has no stock (0, not an error)', ['W' => 0, 'WS' => 0], $avw('Nowhere'));
same('warehouses: names are matched exactly', ['W' => 0, 'WS' => 0], $avw('main'));
$okw = catalog_stock_handle($get($KEY, 'POST'), json_encode(['skus' => ['W'], 'warehouse' => ' Second ']), $db, $cfg);
same('warehouses: the handler trims the name and filters', [200, [['sku' => 'W', 'available_pieces' => 3]]], [$okw[0], $okw[1]['stock']]);
same('warehouses: null means no filter', 9, catalog_stock_handle($get($KEY, 'POST'), '{"skus":["W"],"warehouse":null}', $db, $cfg)[1]['stock'][0]['available_pieces']);
foreach (['number' => '{"skus":["W"],"warehouse":5}', 'list' => '{"skus":["W"],"warehouse":["Main"]}', 'empty' => '{"skus":["W"],"warehouse":"  "}', 'too long' => json_encode(['skus' => ['W'], 'warehouse' => str_repeat('w', 121)])] as $label => $body) {
    same("warehouses: a $label warehouse -> 422", 422, catalog_stock_handle($get($KEY, 'POST'), $body, $db, $cfg)[0]);
}
same('warehouses: a SQL-looking warehouse is just an unknown warehouse', ['W' => 0, 'WS' => 0], $avw("x' OR '1'='1"));

// ---- idempotent orders: finding an existing order, and recognising a duplicate-key error --------------------------------
$db = new_db();
$db->exec("INSERT INTO online_orders(order_no,source,external_order_id,status) VALUES('ONL-1','WEBSITE','PF-1001','ALLOCATED')");
same('existing order is found by source + external id', ['order_no' => 'ONL-1', 'status' => 'ALLOCATED'], (function ($r) { return ['order_no' => $r['order_no'], 'status' => $r['status']]; })(catalog_find_order($db, 'WEBSITE', 'PF-1001')));
same('another source does not match', null, catalog_find_order($db, 'MARKETPLACE', 'PF-1001'));
same('an unknown external id does not match', null, catalog_find_order($db, 'WEBSITE', 'PF-9'));
same('an empty external id never matches', null, catalog_find_order($db, 'WEBSITE', ''));
try {
    $db->exec("INSERT INTO online_orders(order_no,source,external_order_id,status) VALUES('ONL-2','WEBSITE','PF-1001','NEW')");
    check('a duplicate external id is rejected by the database', false);
} catch (Throwable $dup) {
    check('a duplicate-key error is recognised', catalog_is_duplicate_key($dup));
}
check('other errors are not mistaken for a duplicate', !catalog_is_duplicate_key(new RuntimeException('boom')));
try { $db->exec('SELECT * FROM missing_table'); } catch (Throwable $other) { check('a missing-table error is not a duplicate', !catalog_is_duplicate_key($other)); }

echo "\n{$GLOBALS['__checks']} checks, {$GLOBALS['__fails']} failed\n";
exit($GLOBALS['__fails'] === 0 ? 0 : 1);
