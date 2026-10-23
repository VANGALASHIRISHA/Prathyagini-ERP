<?php
// Scan & Find API: resolves a scanned code to a product (with every godown location holding it
// and the online orders waiting for it) or to a location (with everything stored there).
// GET ?code=XXXX  ->  JSON. Read-only; stock comes from inventory_balances, scoped per user.
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/product_finder.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') pf_json(['ok' => false, 'error' => 'Method not allowed'], 405);
pf_api_guard();

$raw = $_GET['code'] ?? '';
if (!is_string($raw)) pf_json(['ok' => false, 'error' => 'Invalid code'], 422);
$code = pf_clean_query($raw);
if ($code === '') pf_json(['ok' => false, 'error' => 'Scan or type a code'], 422);

$vis = pf_visibility();
$canSeeOrders = has_permission('fulfill_online_orders') || has_permission('manage_online_orders');

/** Exact place, labelled: "Godown > Floor > Section > Rack > Shelf > Bin"; falls back to the location name. */
function sf_location_path(array $l): string {
    $parts = [];
    foreach (['warehouse' => '', 'floor_name' => 'Floor ', 'section_name' => 'Section ', 'rack' => 'Rack ', 'shelf' => 'Shelf ', 'shelf_bin' => 'Bin '] as $k => $label) {
        $v = trim((string)($l[$k] ?? ''));
        if ($v !== '') $parts[] = $label . $v;
    }
    // Staff must never think "the godown" is the exact place when the rack, shelf and bin were never filled in.
    if (trim((string)($l['rack'] ?? '')) === '' && trim((string)($l['shelf'] ?? '')) === '' && trim((string)($l['shelf_bin'] ?? '')) === '') $parts[] = 'Rack / shelf / bin not assigned';
    return $parts ? implode(' > ', $parts) : (string)$l['name'];
}

function sf_location_payload(array $l): array {
    return [
        'id' => (int)$l['id'],
        'code' => $l['code'],
        'name' => $l['name'],
        'path' => sf_location_path($l),
        'warehouse' => $l['warehouse'], 'rack' => $l['rack'], 'shelf' => $l['shelf'] ?? null, 'bin' => $l['shelf_bin'],
    ];
}

/** Quantities are hidden from roles that may only see sellable stock (same rule as stock.php). */
function sf_qty_payload(array $r, array $vis): array {
    $qty = (float)$r['qty'];
    $reserved = (float)$r['reserved_qty'];
    return [
        'qty' => $vis['reserved'] ? $qty : null,
        'reserved_qty' => $vis['reserved'] ? $reserved : null,
        'sellable_qty' => max($qty - $reserved, 0),
    ];
}

function sf_product_payload(array $p, array $vis): array {
    return [
        'id' => (int)$p['id'],
        'name' => $p['product_name'],
        'sku' => $p['sku'],
        'barcode' => $p['product_barcode'],
        'color' => $p['color'],
        'size' => $p['size'],
        'image' => pf_safe_image($p['product_image']),
        'serial_tracking' => (bool)$p['serial_tracking'],
        'mrp' => $vis['prices'] ? (float)$p['mrp'] : null,
        'retail_price' => $vis['prices'] ? (float)$p['retail_price'] : null,
    ];
}

/** Every in-scope location holding this product, summed across batches, fullest first. */
function sf_product_locations(int $productId, ?int $batchId, array $vis): array {
    [$scopeSql, $scopeParams] = location_scope_sql('ib.location_id');
    $batchSql = $batchId !== null ? ', SUM(CASE WHEN ib.batch_id=? THEN ib.qty-ib.reserved_qty ELSE 0 END) batch_sellable' : '';
    $sql = 'SELECT l.id,l.code,l.name,l.warehouse,l.floor_name,l.section_name,l.rack,l.shelf,l.shelf_bin,
                   SUM(ib.qty) qty, SUM(ib.reserved_qty) reserved_qty' . $batchSql . '
            FROM inventory_balances ib JOIN locations l ON l.id=ib.location_id
            WHERE ib.product_id=?' . $scopeSql . '
            GROUP BY l.id,l.code,l.name,l.warehouse,l.floor_name,l.section_name,l.rack,l.shelf,l.shelf_bin
            HAVING SUM(ib.qty) > 0 OR SUM(ib.reserved_qty) > 0
            ORDER BY SUM(ib.qty-ib.reserved_qty) DESC, l.code';
    $params = $batchId !== null ? [$batchId, $productId] : [$productId];
    $st = db()->prepare($sql);
    $st->execute(array_merge($params, $scopeParams));
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $row = sf_location_payload($r) + sf_qty_payload($r, $vis);
        if ($batchId !== null) $row['batch_sellable_qty'] = max((float)$r['batch_sellable'], 0);
        $rows[] = $row;
    }
    return $rows;
}

/** Online orders still to be picked/packed that hold allocations of this product in the user's locations. */
function sf_pending_orders(int $productId): array {
    [$scopeSql, $scopeParams] = location_scope_sql('a.location_id');
    $st = db()->prepare("SELECT o.id,o.order_no,o.customer_name,o.status,l.code location_code,
                                SUM(a.quantity) qty, SUM(a.picked_qty) picked_qty
                         FROM online_order_allocations a
                         JOIN online_orders o ON o.id=a.online_order_id
                         JOIN locations l ON l.id=a.location_id
                         WHERE a.product_id=? AND o.status IN ('ALLOCATED','PICKING','PICKED')
                           AND a.status IN ('RESERVED','PICKED')" . $scopeSql . "
                         GROUP BY o.id,o.order_no,o.customer_name,o.status,l.code,o.created_at
                         ORDER BY o.created_at, o.id
                         LIMIT 100");
    $st->execute(array_merge([$productId], $scopeParams));
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'order_id' => (int)$r['id'],
            'order_no' => $r['order_no'],
            'customer_name' => $r['customer_name'],
            'status' => $r['status'],
            'location_code' => $r['location_code'],
            'qty' => (float)$r['qty'],
            'picked_qty' => (float)$r['picked_qty'],
        ];
    }
    return $rows;
}

function sf_product_response(int $productId, array $scan, ?int $batchId, array $vis, bool $canSeeOrders): void {
    $st = db()->prepare('SELECT id,sku,product_barcode,product_name,product_image,color,size,mrp,retail_price,serial_tracking,active FROM products WHERE id=? LIMIT 1');
    $st->execute([$productId]);
    $p = $st->fetch();
    if (!$p || (!(int)$p['active'] && !$vis['inactive'])) pf_json(['ok' => false, 'error' => 'Not found'], 404);

    pf_json([
        'ok' => true,
        'kind' => 'product',
        'scan' => $scan,
        'product' => sf_product_payload($p, $vis),
        'locations' => sf_product_locations($productId, $batchId, $vis),
        'pending_orders' => $canSeeOrders ? sf_pending_orders($productId) : [],
        'orders_visible' => $canSeeOrders,
    ]);
}

// 1) Serial barcode: one exact garment. Its location/status are only revealed inside the user's scope.
$st = db()->prepare('SELECT ps.product_id,ps.batch_id,ps.location_id,ps.status,b.batch_no,
                            l.id,l.code,l.name,l.warehouse,l.floor_name,l.section_name,l.rack,l.shelf,l.shelf_bin
                     FROM product_serials ps
                     JOIN batches b ON b.id=ps.batch_id
                     JOIN locations l ON l.id=ps.location_id
                     WHERE ps.serial_barcode=? LIMIT 1');
$st->execute([$code]);
if ($s = $st->fetch()) {
    $inScope = can_access_location((int)$s['location_id']);
    $scan = ['type' => 'serial', 'code' => $code, 'in_scope' => $inScope];
    if ($inScope) {
        $scan['status'] = $s['status'];
        $scan['batch_no'] = $s['batch_no'];
        $scan['location'] = sf_location_payload($s);
    }
    sf_product_response((int)$s['product_id'], $scan, null, $vis, $canSeeOrders);
}

// 2) Batch barcode: product locations plus how much of this batch sits in each.
$st = db()->prepare('SELECT id,product_id,batch_no FROM batches WHERE batch_barcode=? LIMIT 1');
$st->execute([$code]);
if ($b = $st->fetch()) {
    sf_product_response((int)$b['product_id'], ['type' => 'batch', 'code' => $code, 'batch_no' => $b['batch_no']], (int)$b['id'], $vis, $canSeeOrders);
}

// 3) Product barcode, then 4) SKU.
foreach (['product_barcode' => 'product', 'sku' => 'sku'] as $column => $type) {
    $st = db()->prepare("SELECT id FROM products WHERE $column=? LIMIT 1");
    $st->execute([$code]);
    if ($pid = $st->fetchColumn()) sf_product_response((int)$pid, ['type' => $type, 'code' => $code], null, $vis, $canSeeOrders);
}

// 5) Location (rack/bin) label: everything stored there.
$st = db()->prepare('SELECT id,code,name,warehouse,floor_name,section_name,rack,shelf,shelf_bin,active FROM locations WHERE code=? LIMIT 1');
$st->execute([$code]);
if ($l = $st->fetch()) {
    if (!can_access_location((int)$l['id'])) {
        pf_json(['ok' => false, 'error' => 'This location is not assigned to you'], 403);
    }
    $st = db()->prepare('SELECT p.id,p.sku,p.product_barcode,p.product_name,p.product_image,p.color,p.size,p.mrp,p.retail_price,p.serial_tracking,
                                SUM(ib.qty) qty, SUM(ib.reserved_qty) reserved_qty
                         FROM inventory_balances ib JOIN products p ON p.id=ib.product_id
                         WHERE ib.location_id=?
                         GROUP BY p.id,p.sku,p.product_barcode,p.product_name,p.product_image,p.color,p.size,p.mrp,p.retail_price,p.serial_tracking
                         HAVING SUM(ib.qty) > 0
                         ORDER BY p.product_name, p.size
                         LIMIT 500');
    $st->execute([(int)$l['id']]);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ['product' => sf_product_payload($r, $vis)] + sf_qty_payload($r, $vis);

    pf_json([
        'ok' => true,
        'kind' => 'location',
        'scan' => ['type' => 'location', 'code' => $code],
        'location' => sf_location_payload($l) + ['active' => (bool)$l['active']],
        'items' => $items,
    ]);
}

pf_json(['ok' => false, 'error' => 'Not found'], 404);
