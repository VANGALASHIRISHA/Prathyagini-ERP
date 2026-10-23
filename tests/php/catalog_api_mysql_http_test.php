<?php
/**
 * Verifies public/api/catalog_products.php and catalog_stock.php against REAL MySQL, through a REAL PHP web server.
 *
 *   php tests/php/catalog_api_mysql_http_test.php seed      # fills a disposable test database with fake products and stock
 *   php tests/php/catalog_api_mysql_http_test.php verify    # calls the running endpoints and checks the answers against the database
 *
 * Settings come from the environment, never from the ERP's own config:
 *   ERP_TEST_DB_HOST, ERP_TEST_DB_PORT, ERP_TEST_DB_NAME, ERP_TEST_DB_USER, ERP_TEST_DB_PASS
 *   ERP_TEST_BASE_URL (e.g. http://127.0.0.1:8099), ERP_TEST_API_KEY (the catalogue key the test server was started with),
 *   ERP_TEST_ORDER_KEY (the order key; must be REJECTED by the catalogue endpoints), ERP_TEST_NOKEY_URL (a server started with no key)
 *
 * SAFETY: it refuses to run unless the database name ends in _test and the host is this machine, because "seed" deletes
 * the products, stock and batches in that database. It never prints the passwords or keys.
 * It does NOT call order_create.php (no orders are made).
 */
$mode = $argv[1] ?? '';
$need = fn(string $k) => (string)(getenv($k) ?: '');
$dbName = $need('ERP_TEST_DB_NAME');
$dbHost = $need('ERP_TEST_DB_HOST') ?: '127.0.0.1';
if (!in_array($mode, ['seed', 'verify'], true)) { fwrite(STDERR, "usage: seed | verify\n"); exit(2); }
if (!preg_match('/_test$/', $dbName)) { fwrite(STDERR, "Refusing to run: ERP_TEST_DB_NAME must end in _test.\n"); exit(2); }
if (!in_array($dbHost, ['127.0.0.1', 'localhost'], true)) { fwrite(STDERR, "Refusing to run: the test database must be on this machine.\n"); exit(2); }

$db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int)($need('ERP_TEST_DB_PORT') ?: 3306), $dbName), $need('ERP_TEST_DB_USER'), $need('ERP_TEST_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
// second guard: ask the server which database we are really in
if (!preg_match('/_test$/', (string)$db->query('SELECT DATABASE()')->fetchColumn())) { fwrite(STDERR, "Refusing to run: connected database is not a _test database.\n"); exit(2); }

$fails = 0; $checks = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fails, $checks; $checks++; if (!$ok) { $fails++; echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; } }
function same(string $name, $expected, $actual): void { check($name, $expected === $actual, 'expected ' . json_encode($expected) . ' got ' . json_encode($actual)); }

// ---------------------------------------------------------------------------------------------------------------------------
if ($mode === 'seed') {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['online_order_allocations', 'online_order_items', 'online_orders', 'product_serials', 'inventory_balances', 'stock_ledger', 'batches', 'products', 'suppliers'] as $t) {
        try { $db->exec("DELETE FROM $t"); } catch (Throwable $e) { /* a table this ERP version does not have */ }
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $db->exec("DELETE FROM locations WHERE code NOT IN ('MAIN-GDN','SHOWROOM')");
    $db->exec("INSERT INTO locations(code,name,warehouse,online_fulfillment_enabled,active) VALUES('ONL-2','Second online location','Main Godown',1,1),('OLD-ONL','Switched-off online store','Old',1,0)");
    $loc = fn(string $code) => (int)$db->query("SELECT id FROM locations WHERE code=" . $db->quote($code))->fetchColumn();
    $cat = fn(string $name) => (int)$db->query("SELECT id FROM categories WHERE name=" . $db->quote($name))->fetchColumn();
    $db->exec("INSERT INTO suppliers(party_code,party_name) VALUES('IT-SUP','Test supplier')");
    $sup = (int)$db->lastInsertId();
    $n = 0;
    $product = function (string $sku, string $name, array $f = []) use ($db, $cat, &$n): int {
        $f += ['category' => 'Sarees', 'retail' => 1000, 'mrp' => 1000, 'offer' => null, 'tax' => 5, 'image' => null, 'serial' => 0, 'active' => 1, 'color' => 'Maroon', 'size' => null, 'fabric' => 'Silk'];
        $db->prepare('INSERT INTO products(sku,product_barcode,product_name,product_image,category_id,fabric,color,size,design_model,pattern_name,purchase_cost,cost_code,mrp,retail_price,wholesale_price,dealer_price,offer_price,tax_rate,serial_tracking,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$sku, 'IT-BC-' . (++$n), $name, $f['image'], $cat($f['category']), $f['fabric'], $f['color'], $f['size'], 'DM-' . $n, 'PAT-' . $n, 111.11, 'SECRETCOSTCODE', $f['mrp'], $f['retail'], 222.22, 333.33, $f['offer'], $f['tax'], $f['serial'], $f['active']]);
        return (int)$db->lastInsertId();
    };
    $batchNo = 0;
    $stock = function (int $pid, string $locCode, float $qty, float $reserved = 0, int $serialsAvail = 0, int $serialsReserved = 0) use ($db, $loc, $sup, &$batchNo) {
        $b = ++$batchNo;
        $db->prepare('INSERT INTO batches(batch_no,batch_barcode,product_id,supplier_id,purchased_at,cost_price,qty_received,qty_available) VALUES(?,?,?,?,CURDATE(),?,?,?)')->execute(["B$b", "IT-BATCH-$b", $pid, $sup, 100, $qty, $qty]);
        $bid = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty,reserved_qty) VALUES(?,?,?,?,?)')->execute([$pid, $bid, $loc($locCode), $qty, $reserved]);
        for ($i = 0; $i < $serialsAvail + $serialsReserved; $i++) {
            $db->prepare('INSERT INTO product_serials(serial_barcode,product_id,batch_id,location_id,status) VALUES(?,?,?,?,?)')->execute(["IT-SN-$b-$i", $pid, $bid, $loc($locCode), $i < $serialsAvail ? 'AVAILABLE' : 'RESERVED']);
        }
    };
    $p = $product('IT-SAR-1', 'Kanjivaram Silk Saree – test ✓', ['retail' => 12999, 'mrp' => 14999, 'offer' => 11499.50, 'image' => 'uploads/products/it-sar-1.jpg', 'size' => 'Free']);
    $stock($p, 'MAIN-GDN', 10, 3);       // 7 online
    $stock($p, 'ONL-2', 4);              // +4 in a second online location = 11
    $stock($p, 'SHOWROOM', 50);          // not online
    $p = $product('IT SAR 2/B', 'Cotton Saree', ['retail' => 2499, 'mrp' => 2499, 'color' => 'Indigo', 'fabric' => 'Cotton']);
    $stock($p, 'MAIN-GDN', 25);          // 25
    $p = $product('IT-KUR-1', 'Printed Kurti', ['category' => 'Kurtis', 'retail' => 1199, 'mrp' => 1499, 'offer' => 0.00, 'color' => 'Teal', 'size' => 'M', 'tax' => 12]);
    $stock($p, 'MAIN-GDN', 0);           // sold out
    $p = $product('IT-SER-1', 'Serial tracked saree', ['serial' => 1, 'retail' => 5000, 'mrp' => 6000]);
    $stock($p, 'MAIN-GDN', 5, 0, 2, 3);  // 5 on hand but only 2 AVAILABLE serials
    $p = $product('IT-FRAC-1', 'Fractional stock', ['retail' => 100, 'mrp' => 100]);
    $stock($p, 'MAIN-GDN', 2.9);         // 2
    $stock($p, 'MAIN-GDN', 3.0, 0.5);    // 2   (second batch)
    $p = $product('IT-OFF-1', 'Showroom only', ['retail' => 700, 'mrp' => 700]);
    $stock($p, 'SHOWROOM', 20);
    $stock($p, 'OLD-ONL', 9);            // online location is switched off
    $p = $product('IT-GONE-1', 'Withdrawn product', ['active' => 0, 'retail' => 900, 'mrp' => 900]);
    $stock($p, 'MAIN-GDN', 5);
    $product('IT-BADIMG-1', 'Bad image path one', ['image' => '../../etc/passwd']);
    $product('IT-BADIMG-2', 'Bad image path two', ['image' => 'uploads/products/x.php']);
    $product('IT-BADIMG-3', 'Bad image url', ['image' => 'https://evil.example/uploads/products/a.jpg']);
    $product('IT-ZERO-1', 'Zero price', ['retail' => 0, 'mrp' => 0]);
    $product('IT-NOLOC-1', 'No stock rows at all', ['retail' => 300, 'mrp' => 300]);
    $product('IT-BIG-1', 'Very dear', ['retail' => 99999999.99, 'mrp' => 99999999.99]);
    for ($i = 1; $i <= 210; $i++) { $q = $product(sprintf('IT-BULK-%03d', $i), "Bulk item $i", ['retail' => 10 + $i, 'mrp' => 10 + $i]); $stock($q, 'MAIN-GDN', $i % 7); }
    $total = (int)$db->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
    echo "seeded: $total active products in $dbName\n";
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------------------
$base = rtrim($need('ERP_TEST_BASE_URL'), '/');
$key = $need('ERP_TEST_API_KEY');
$orderKey = $need('ERP_TEST_ORDER_KEY');
function call(string $method, string $url, ?string $key, ?string $body = null, array $extra = []): array {
    $ch = curl_init($url);
    $headers = $extra;
    if ($key !== null) $headers[] = 'Authorization: Bearer ' . $key;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $size);
    $text = substr($raw, $size);
    return ['status' => $status, 'headers' => strtolower($head), 'text' => $text, 'json' => json_decode($text, true)];
}
$P = $base . '/api/catalog_products.php';
$S = $base . '/api/catalog_stock.php';

// --- authentication and methods, through the real web server ---
same('no key -> 401', 401, call('GET', $P, null)['status']);
same('wrong key -> 401', 401, call('GET', $P, str_repeat('z', 40))['status']);
same('the ORDER key is refused by the catalogue endpoint when a dedicated read key is configured', 401, call('GET', $P, $orderKey)['status']);
same('Basic auth -> 401', 401, call('GET', $P, null, null, ['Authorization: Basic ' . base64_encode('a:b')])['status']);
same('POST to products -> 405', 405, call('POST', $P, $key, '{}')['status']);
same('GET to stock -> 405', 405, call('GET', $S, $key)['status']);
same('stock without key -> 401', 401, call('POST', $S, null, '{"skus":["IT-SAR-1"]}')['status']);
$denied = call('GET', $P, str_repeat('z', 40));
check('the 401 answer is JSON and has no key in it', is_array($denied['json']) && strpos($denied['text'], $key) === false && strpos($denied['text'], $orderKey) === false);
$noKeyUrl = $need('ERP_TEST_NOKEY_URL');
if ($noKeyUrl !== '') same('a server with no key configured -> 503', 503, call('GET', rtrim($noKeyUrl, '/') . '/api/catalog_products.php', 'any-key-any-key-any-key-any-key-1234')['status']);

// --- the first page ---
$r = call('GET', $P . '?limit=200', $key);
same('products: 200', 200, $r['status']);
check('products: JSON content type', strpos($r['headers'], 'application/json') !== false);
check('products: no-store', strpos($r['headers'], 'cache-control: no-store') !== false);
$all = [];
$cursor = null; $pages = 0;
do {
    $q = '?limit=200' . ($cursor !== null ? '&after_id=' . $cursor : '');
    $page = call('GET', $P . $q, $key);
    same("page $pages is 200", 200, $page['status']);
    foreach ($page['json']['products'] as $prod) $all[$prod['sku']] = $prod;
    $cursor = $page['json']['next_cursor'];
    $pages++;
} while ($cursor !== null && $pages < 10);
$activeCount = (int)$db->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
same('paging over MySQL returns every active product exactly once', $activeCount, count($all));
check('paging needed more than one page (limit 200)', $pages >= 2, "pages=$pages");
check('inactive product is not listed', !isset($all['IT-GONE-1']));
// order of the first page = id order
$ids = array_column($r['json']['products'], 'sku');
$expectedOrder = array_column($db->query('SELECT sku FROM products WHERE active = 1 ORDER BY id LIMIT 200')->fetchAll(), 'sku');
same('first page is in id order', $expectedOrder, $ids);
$small = call('GET', $P . '?limit=7', $key);
same('limit=7 returns 7', 7, count($small['json']['products']));
check('limit=7 has a cursor', is_string($small['json']['next_cursor']));
$next = call('GET', $P . '?limit=7&after_id=' . $small['json']['next_cursor'], $key);
check('the next page starts after the cursor and does not repeat', array_intersect(array_column($small['json']['products'], 'sku'), array_column($next['json']['products'], 'sku')) === []);
same('default limit is 100', 100, count(call('GET', $P, $key)['json']['products']));
foreach (['0', '201', 'abc', '-1', '1.5', '', '99999'] as $bad) same("limit '$bad' -> 422", 422, call('GET', $P . '?limit=' . urlencode($bad), $key)['status']);
foreach (['x', '-5', '1.5', ''] as $bad) same("after_id '$bad' -> 422", 422, call('GET', $P . '?after_id=' . urlencode($bad), $key)['status']);
same('limit[]=5 -> 422', 422, call('GET', $P . '?limit[]=5', $key)['status']);
same('after_id beyond the end -> empty page', [], call('GET', $P . '?after_id=999999999', $key)['json']['products']);

// --- fields, prices, images, secrets ---
/** A field of a product, or the string __absent__ when the product or the field is missing (so a real null is told apart from absence). */
function field(array $all, string $sku, string $name) { return (isset($all[$sku]) && array_key_exists($name, $all[$sku])) ? $all[$sku][$name] : '__absent__'; }
$sar = $all['IT-SAR-1'] ?? [];
same('field set', ['category', 'color', 'design_model', 'fabric', 'image_path', 'mrp', 'name', 'offer_price', 'pattern_name', 'retail_price', 'size', 'sku', 'tax_rate'], (function () use ($sar) { $k = array_keys($sar); sort($k); return $k; })());
same('prices come out as exact two-decimal strings', ['14999.00', '12999.00', '11499.50', '5.00'], [$sar['mrp'] ?? null, $sar['retail_price'] ?? null, $sar['offer_price'] ?? null, $sar['tax_rate'] ?? null]);
same('unicode survives', 'Kanjivaram Silk Saree – test ✓', $sar['name'] ?? null);
same('a good image path is kept', 'uploads/products/it-sar-1.jpg', $sar['image_path'] ?? null);
same('category name', 'Sarees', $sar['category'] ?? null);
same('a SKU with a space and a slash is returned as is', true, isset($all['IT SAR 2/B']));
same('a missing offer price is null', null, field($all, 'IT SAR 2/B', 'offer_price'));
same('a zero offer price is "0.00", not null (the website decides what it means)', '0.00', $all['IT-KUR-1']['offer_price'] ?? null);
same('a very large price is exact', '99999999.99', $all['IT-BIG-1']['retail_price'] ?? null);
same('zero price is listed as "0.00"', '0.00', $all['IT-ZERO-1']['retail_price'] ?? null);
foreach (['IT-BADIMG-1', 'IT-BADIMG-2', 'IT-BADIMG-3'] as $s) same("bad image path dropped ($s)", null, field($all, $s, 'image_path'));
foreach (['111.11', '222.22', '333.33', 'SECRETCOSTCODE', 'IT-BC-', 'IT-BATCH', 'IT-SN-', 'purchase_cost', 'wholesale', 'dealer', 'barcode'] as $secret) {
    check("products answer never contains $secret", strpos($r['text'], $secret) === false);
}

// --- stock: compared with an independent MySQL calculation ---
$skus = array_keys($all);
$online = "l.active = 1 AND l.online_fulfillment_enabled = 1";
// independent: whole pieces per balance row (FLOOR of qty - reserved), limited per row by AVAILABLE serials for serial-tracked products
$expect = function () use ($db, $online): array {
    $rows = $db->query("SELECT p.sku, p.serial_tracking, ib.qty, ib.reserved_qty,
        (SELECT COUNT(*) FROM product_serials ps WHERE ps.product_id = ib.product_id AND ps.batch_id = ib.batch_id AND ps.location_id = ib.location_id AND ps.status = 'AVAILABLE') AS serials
        FROM products p LEFT JOIN inventory_balances ib ON ib.product_id = p.id LEFT JOIN locations l ON l.id = ib.location_id AND $online
        WHERE p.active = 1 AND l.id IS NOT NULL")->fetchAll();
    $out = [];
    foreach ($db->query('SELECT sku FROM products WHERE active = 1')->fetchAll() as $p) $out[$p['sku']] = 0;
    foreach ($rows as $row) {
        $pieces = (int)floor(max(0, (float)$row['qty'] - (float)$row['reserved_qty']) + 1e-9);
        if ((int)$row['serial_tracking'] === 1) $pieces = min($pieces, (int)$row['serials']);
        $out[$row['sku']] += $pieces;
    }
    return $out;
};
$ask = function (array $list) use ($S, $key): array {
    $res = call('POST', $S, $key, json_encode(['skus' => $list]));
    $m = [];
    foreach (($res['json']['stock'] ?? []) as $row) $m[$row['sku']] = $row['available_pieces'];
    return [$res, $m];
};
[$res, $got] = $ask(['IT-SAR-1', 'IT SAR 2/B', 'IT-KUR-1', 'IT-SER-1', 'IT-FRAC-1', 'IT-OFF-1', 'IT-GONE-1', 'IT-NOLOC-1', 'NO-SUCH-SKU']);
same('stock: 200', 200, $res['status']);
same('stock: Multi-location online stock minus reservations (7 + 4)', 11, $got['IT-SAR-1'] ?? null);
same('stock: plain stock', 25, $got['IT SAR 2/B'] ?? null);
same('stock: sold out is 0', 0, $got['IT-KUR-1'] ?? null);
same('stock: serial-tracked is limited to AVAILABLE serials', 2, $got['IT-SER-1'] ?? null);
same('stock: whole pieces per batch row', 4, $got['IT-FRAC-1'] ?? null);
same('stock: showroom-only and switched-off online locations count as 0', 0, $got['IT-OFF-1'] ?? null);
same('stock: no stock rows at all is 0', 0, $got['IT-NOLOC-1'] ?? null);
same('stock: an inactive product is not answered', false, isset($got['IT-GONE-1']));
same('stock: an unknown SKU is not answered', false, isset($got['NO-SUCH-SKU']));
// every product, in batches of 100, must match the independent calculation
$expected = $expect();
$mismatch = [];
foreach (array_chunk($skus, 100) as $chunk) { [, $m] = $ask($chunk); foreach ($chunk as $s) if (($m[$s] ?? null) !== $expected[$s]) $mismatch[] = "$s want {$expected[$s]} got " . json_encode($m[$s] ?? null); }
same('stock: every product matches an independent MySQL calculation', [], array_slice($mismatch, 0, 5));
check('stock answer never contains costs or barcodes', strpos($res['text'], 'IT-BC-') === false && strpos($res['text'], 'SECRET') === false && strpos($res['text'], 'qty') === false);

// reservations and restocks are seen at once
$pid = (int)$db->query("SELECT id FROM products WHERE sku='IT-SAR-1'")->fetchColumn();
$db->exec("UPDATE inventory_balances ib JOIN locations l ON l.id = ib.location_id SET ib.reserved_qty = 10 WHERE ib.product_id = $pid AND l.code = 'MAIN-GDN'");
same('stock: a reservation of all main-store pieces leaves only the second store (4)', 4, $ask(['IT-SAR-1'])[1]['IT-SAR-1'] ?? null);
$db->exec("UPDATE inventory_balances ib JOIN locations l ON l.id = ib.location_id SET ib.reserved_qty = 3, ib.qty = 12 WHERE ib.product_id = $pid AND l.code = 'MAIN-GDN'");
same('stock: a restock is reflected at once (9 + 4)', 13, $ask(['IT-SAR-1'])[1]['IT-SAR-1'] ?? null);
$db->exec("UPDATE inventory_balances ib JOIN locations l ON l.id = ib.location_id SET ib.reserved_qty = 3, ib.qty = 10 WHERE ib.product_id = $pid AND l.code = 'MAIN-GDN'");
$db->exec("UPDATE locations SET online_fulfillment_enabled = 0 WHERE code = 'ONL-2'");
same('stock: switching a location off online removes its stock (7)', 7, $ask(['IT-SAR-1'])[1]['IT-SAR-1'] ?? null);
$db->exec("UPDATE locations SET online_fulfillment_enabled = 1 WHERE code = 'ONL-2'");
same('stock: and back on (11)', 11, $ask(['IT-SAR-1'])[1]['IT-SAR-1'] ?? null);
$sn = (int)$db->query("SELECT id FROM product_serials WHERE product_id = (SELECT id FROM products WHERE sku='IT-SER-1') AND status='AVAILABLE' ORDER BY id LIMIT 1")->fetchColumn();
$db->exec("UPDATE product_serials SET status='RESERVED' WHERE id = $sn");
same('stock: reserving a serial lowers a serial-tracked product (1)', 1, $ask(['IT-SER-1'])[1]['IT-SER-1'] ?? null);
$db->exec("UPDATE product_serials SET status='AVAILABLE' WHERE id = $sn");
same('stock: and releasing it restores it (2)', 2, $ask(['IT-SER-1'])[1]['IT-SER-1'] ?? null);

// --- stock input validation ---
foreach ([
    'invalid JSON' => ['{nope', 400], 'no skus' => ['{}', 400], 'skus not a list' => ['{"skus":"A"}', 400], 'empty list' => ['{"skus":[]}', 422],
    'number in list' => ['{"skus":["A",5]}', 422], 'empty sku' => ['{"skus":["A"," "]}', 422], 'long sku' => [json_encode(['skus' => [str_repeat('x', 61)]]), 422],
    'too many skus' => [json_encode(['skus' => array_map(fn($i) => "S$i", range(1, 101))]), 422], 'huge body' => [str_repeat(' ', 25000), 413],
] as $label => [$body, $status]) same("stock input: $label -> $status", $status, call('POST', $S, $key, $body)['status']);
same('stock input: exactly 100 SKUs is accepted', 200, call('POST', $S, $key, json_encode(['skus' => array_map(fn($i) => "S$i", range(1, 100))]))['status']);
same('stock input: duplicates are fine', 200, call('POST', $S, $key, '{"skus":["IT-SAR-1","IT-SAR-1"]}')['status']);
same('stock input: a SQL-looking SKU is just an unknown SKU', [], $ask(["x' OR '1'='1"])[1]);
same('stock input: a SQL-looking SKU in a list is harmless', 4, $ask(["IT-FRAC-1", "'; DROP TABLE products; --"])[1]['IT-FRAC-1'] ?? null);
check('products table still exists after the injection attempt', (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0);

// --- two online warehouses (added for this check only, removed again) ---
$stockOf = fn(array $list, ?string $wh = null) => (function () use ($S, $key, $list, $wh) {
    $res = call('POST', $S, $key, json_encode(['skus' => $list] + ($wh !== null ? ['warehouse' => $wh] : [])));
    $m = []; foreach (($res['json']['stock'] ?? []) as $row) $m[$row['sku']] = $row['available_pieces']; return [$res['status'], $m];
})();
$db->exec("INSERT INTO locations(code,name,warehouse,online_fulfillment_enabled,active) VALUES('WH2-LOC','Second warehouse location','Second warehouse',1,1)");
$wh2 = (int)$db->lastInsertId();
$wbatch = (int)$db->query("SELECT id FROM batches WHERE product_id = (SELECT id FROM products WHERE sku='IT SAR 2/B') LIMIT 1")->fetchColumn();
$wprod = (int)$db->query("SELECT id FROM products WHERE sku='IT SAR 2/B'")->fetchColumn();
$db->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty,reserved_qty) VALUES(?,?,?,5,1)')->execute([$wprod, $wbatch, $wh2]);
[$st1, $all2] = $stockOf(['IT SAR 2/B']);
[$st2, $main2] = $stockOf(['IT SAR 2/B'], 'Main Godown');
[$st3, $second2] = $stockOf(['IT SAR 2/B'], 'Second warehouse');
[$st4, $none2] = $stockOf(['IT SAR 2/B'], 'No such warehouse');
same('two warehouses: no filter counts both (25 + 4)', 29, $all2['IT SAR 2/B'] ?? null);
same('two warehouses: Main Godown only (25)', 25, $main2['IT SAR 2/B'] ?? null);
same('two warehouses: the second warehouse only (5 - 1 reserved)', 4, $second2['IT SAR 2/B'] ?? null);
same('two warehouses: an unknown warehouse has none', 0, $none2['IT SAR 2/B'] ?? null);
same('two warehouses: all the requests answered 200', [200, 200, 200, 200], [$st1, $st2, $st3, $st4]);
same('two warehouses: a bad warehouse value -> 422', [422, 422, 422], [call('POST', $S, $key, '{"skus":["IT-SAR-1"],"warehouse":5}')['status'], call('POST', $S, $key, '{"skus":["IT-SAR-1"],"warehouse":""}')['status'], call('POST', $S, $key, json_encode(['skus' => ['IT-SAR-1'], 'warehouse' => str_repeat('w', 121)]))['status']]);
same('two warehouses: a SQL-looking warehouse is just unknown', 0, $stockOf(['IT SAR 2/B'], "x' OR '1'='1")[1]['IT SAR 2/B'] ?? null);
$db->exec("DELETE FROM inventory_balances WHERE location_id = $wh2");
$db->exec("DELETE FROM locations WHERE id = $wh2");
same('two warehouses: removed again, the numbers are back to normal (25)', 25, $stockOf(['IT SAR 2/B'])[1]['IT SAR 2/B'] ?? null);

// --- a real MySQL failure must give a safe answer ---
$db->exec('RENAME TABLE categories TO categories_hidden');
$err = call('GET', $P, $key);
$db->exec('RENAME TABLE categories_hidden TO categories');
same('a database failure -> 500', 500, $err['status']);
same('a database failure -> generic message', 'Catalogue is unavailable', $err['json']['error'] ?? null);
foreach (['SQLSTATE', 'categories', 'Table', 'mysql', 'PDO', '.php', 'Stack'] as $leak) check("a database failure does not leak '$leak'", stripos($err['text'], $leak) === false);
$db->exec('RENAME TABLE inventory_balances TO inventory_hidden');
$err2 = call('POST', $S, $key, '{"skus":["IT-SAR-1"]}');
$db->exec('RENAME TABLE inventory_hidden TO inventory_balances');
same('a stock database failure -> 500', 500, $err2['status']);
check('a stock database failure leaks nothing', stripos($err2['text'], 'SQLSTATE') === false && stripos($err2['text'], 'inventory') === false);
same('after the failure the endpoint works again', 200, call('GET', $P . '?limit=1', $key)['status']);

echo "\n$checks checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
