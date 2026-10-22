<?php
// Product Finder: search, barcode resolution and scoped stock availability.
// Stock source of truth is inventory_balances (one row per product+batch+location);
// product_serials only identifies individual pieces and is never added to quantities.

const PF_MAX_QUERY_LEN = 100;
const PF_MIN_PARTIAL_LEN = 2;
const PF_MIN_SERIAL_PARTIAL_LEN = 4;
const PF_RESULT_LIMIT = 30;
const PF_SERIAL_LIMIT = 300;

function pf_visibility(): array {
    $role = current_user()['role'] ?? '';
    return [
        'prices' => in_array($role, ['super_admin', 'manager', 'cashier'], true),
        'reserved' => in_array($role, ['super_admin', 'manager', 'godown', 'auditor'], true),
        'sellable_only' => $role === 'cashier',
        'inactive' => has_permission('manage_products'),
    ];
}

function pf_clean_query(string $raw): string {
    $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? '';
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    return mb_substr($q, 0, PF_MAX_QUERY_LEN);
}

function pf_like(string $v): string { return '%' . addcslashes($v, '%_\\') . '%'; }

function pf_serial_statuses(array $vis): array {
    return $vis['sellable_only'] ? ['AVAILABLE'] : ['AVAILABLE', 'RESERVED'];
}

function pf_safe_image(?string $path): ?string {
    if ($path === null || $path === '') return null;
    return preg_match('#^uploads/products/[A-Za-z0-9._-]+$#', $path) ? $path : null;
}

function pf_stock_status(float $sellable, float $minStock): string {
    if ($sellable <= 0) return 'OUT';
    if ($minStock > 0 && $sellable <= $minStock) return 'LOW';
    return 'IN';
}

function pf_product_columns(): string {
    return 'p.id,p.sku,p.product_barcode,p.product_name,p.product_image,p.brand,p.color,p.size,p.serial_tracking,p.min_stock,p.active,c.name category_name';
}

/** Product ids that exactly match a scanned/typed code, most specific first. */
function pf_exact_matches(string $code): array {
    $matches = [];
    $add = function (int $pid, string $type, array $extra = []) use (&$matches) {
        if (!isset($matches[$pid])) $matches[$pid] = ['product_id' => $pid, 'match' => $type] + $extra;
    };

    $st = db()->prepare('SELECT ps.id,ps.product_id,ps.location_id,ps.status,b.batch_no,l.code location_code FROM product_serials ps JOIN batches b ON b.id=ps.batch_id JOIN locations l ON l.id=ps.location_id WHERE ps.serial_barcode=? LIMIT 1');
    $st->execute([$code]);
    if ($s = $st->fetch()) {
        $inScope = can_access_location((int)$s['location_id']);
        $add((int)$s['product_id'], 'serial_barcode', ['scan' => [
            'type' => 'serial', 'code' => $code, 'in_scope' => $inScope,
            'status' => $inScope ? $s['status'] : null,
            'batch_no' => $inScope ? $s['batch_no'] : null,
            'location_code' => $inScope ? $s['location_code'] : null,
        ]]);
    }
    $st = db()->prepare('SELECT product_id,batch_no FROM batches WHERE batch_barcode=? LIMIT 1');
    $st->execute([$code]);
    if ($b = $st->fetch()) $add((int)$b['product_id'], 'batch_barcode', ['scan' => ['type' => 'batch', 'code' => $code, 'batch_no' => $b['batch_no']]]);
    $st = db()->prepare('SELECT id FROM products WHERE product_barcode=? LIMIT 1');
    $st->execute([$code]);
    if ($pid = $st->fetchColumn()) $add((int)$pid, 'product_barcode', ['scan' => ['type' => 'product', 'code' => $code]]);
    $st = db()->prepare('SELECT id FROM products WHERE sku=? LIMIT 1');
    $st->execute([$code]);
    if ($pid = $st->fetchColumn()) $add((int)$pid, 'sku', ['scan' => ['type' => 'sku', 'code' => $code]]);

    return array_values($matches);
}

function pf_fetch_products(array $ids, array $vis): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT ' . pf_product_columns() . " FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id IN ($ph)";
    if (!$vis['inactive']) $sql .= ' AND p.active=1';
    $st = db()->prepare($sql);
    $st->execute(array_values($ids));
    $rows = [];
    foreach ($st->fetchAll() as $r) $rows[(int)$r['id']] = $r;
    return $rows;
}

/** Scoped stock totals per product, summed row-by-row from inventory_balances. */
function pf_stock_totals(array $ids): array {
    if (!$ids) return [];
    [$scope, $params] = location_scope_sql('ib.location_id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT ib.product_id,COALESCE(SUM(ib.qty),0) physical,COALESCE(SUM(ib.reserved_qty),0) reserved,COALESCE(SUM(GREATEST(ib.qty-ib.reserved_qty,0)),0) sellable FROM inventory_balances ib WHERE ib.product_id IN ($ph)" . $scope . ' GROUP BY ib.product_id');
    $st->execute([...array_values($ids), ...$params]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['product_id']] = ['physical' => (float)$r['physical'], 'reserved' => (float)$r['reserved'], 'sellable' => (float)$r['sellable']];
    return $out;
}

function pf_partial_search(string $q, array $vis): array {
    $like = pf_like($q);
    $prefix = addcslashes($q, '%_\\') . '%';
    $tokens = array_slice(array_values(array_filter(explode(' ', $q), fn($t) => $t !== '')), 0, 5);
    $textConds = [];
    $textParams = [];
    foreach ($tokens as $t) {
        $textConds[] = "CONCAT_WS(' ',p.product_name,p.brand,p.color,p.size,c.name) LIKE ?";
        $textParams[] = pf_like($t);
    }
    $where = ['(' . implode(' AND ', $textConds) . ')', 'p.sku LIKE ?', 'p.product_barcode LIKE ?', 'EXISTS(SELECT 1 FROM batches b WHERE b.product_id=p.id AND (b.batch_barcode LIKE ? OR b.batch_no LIKE ?))'];
    $whereParams = [...$textParams, $like, $like, $like, $like];
    if (mb_strlen($q) >= PF_MIN_SERIAL_PARTIAL_LEN) {
        [$scope, $scopeParams] = location_scope_sql('ps.location_id');
        $statuses = pf_serial_statuses($vis);
        $where[] = 'EXISTS(SELECT 1 FROM product_serials ps WHERE ps.product_id=p.id AND ps.serial_barcode LIKE ? AND ps.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')' . $scope . ')';
        $whereParams = [...$whereParams, $like, ...$statuses, ...$scopeParams];
    }
    $sql = 'SELECT ' . pf_product_columns() . ',
        CASE WHEN p.sku=? OR p.product_barcode=? THEN 0
             WHEN p.sku LIKE ? OR p.product_name LIKE ? THEN 1
             WHEN p.sku LIKE ? OR p.product_barcode LIKE ? OR p.product_name LIKE ? THEN 2
             ELSE 3 END rank_score
        FROM products p LEFT JOIN categories c ON c.id=p.category_id
        WHERE ' . ($vis['inactive'] ? '1=1' : 'p.active=1') . ' AND (' . implode(' OR ', $where) . ')
        ORDER BY rank_score,p.product_name,p.sku LIMIT ' . (int)PF_RESULT_LIMIT;
    $st = db()->prepare($sql);
    $st->execute([$q, $q, $prefix, $prefix, $like, $like, $like, ...$whereParams]);
    $rows = $st->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $match = 'batch_or_serial';
        if (mb_stripos((string)$r['sku'], $q) !== false) $match = 'sku';
        elseif (mb_stripos((string)$r['product_barcode'], $q) !== false) $match = 'product_barcode';
        else {
            $hay = implode(' ', [$r['product_name'], $r['brand'], $r['color'], $r['size'], $r['category_name']]);
            $all = true;
            foreach ($tokens as $t) if (mb_stripos($hay, $t) === false) { $all = false; break; }
            if ($all) $match = 'name';
        }
        $out[] = ['row' => $r, 'match' => $match];
    }
    return $out;
}

function pf_result_payload(array $p, array $totals, array $vis, string $match, ?array $scan = null): array {
    $t = $totals[(int)$p['id']] ?? ['physical' => 0.0, 'reserved' => 0.0, 'sellable' => 0.0];
    $item = [
        'id' => (int)$p['id'],
        'sku' => $p['sku'],
        'product_barcode' => $p['product_barcode'],
        'name' => $p['product_name'],
        'image' => pf_safe_image($p['product_image']),
        'brand' => $p['brand'],
        'category' => $p['category_name'],
        'color' => $p['color'],
        'size' => $p['size'],
        'tracking' => (int)$p['serial_tracking'] === 1 ? 'SERIAL' : 'BATCH',
        'active' => (int)$p['active'] === 1,
        'sellable' => $t['sellable'],
        'status' => pf_stock_status($t['sellable'], (float)$p['min_stock']),
        'match' => $match,
    ];
    if ($vis['reserved']) { $item['physical'] = $t['physical']; $item['reserved'] = $t['reserved']; }
    if ($scan) $item['scan'] = $scan;
    return $item;
}

function pf_search(string $q, bool $exact, array $vis): array {
    $results = [];
    $exactMatches = pf_exact_matches($q);
    $products = pf_fetch_products(array_column($exactMatches, 'product_id'), $vis);
    $totals = pf_stock_totals(array_keys($products));
    $seen = [];
    foreach ($exactMatches as $m) {
        $pid = $m['product_id'];
        if (!isset($products[$pid]) || isset($seen[$pid])) continue;
        $seen[$pid] = true;
        $results[] = pf_result_payload($products[$pid], $totals, $vis, $m['match'], $m['scan'] ?? null) + ['exact' => true];
    }
    if ($exact || mb_strlen($q) < PF_MIN_PARTIAL_LEN) return $results;

    $partial = array_filter(pf_partial_search($q, $vis), fn($r) => !isset($seen[(int)$r['row']['id']]));
    $totals = pf_stock_totals(array_map(fn($r) => (int)$r['row']['id'], $partial));
    foreach ($partial as $r) {
        if (count($results) >= PF_RESULT_LIMIT) break;
        $results[] = pf_result_payload($r['row'], $totals, $vis, $r['match']) + ['exact' => false];
    }
    return $results;
}

function pf_product_detail(int $productId, array $vis): ?array {
    $st = db()->prepare('SELECT p.id,p.sku,p.product_barcode,p.product_name,p.product_image,p.brand,p.fabric,p.color,p.size,p.unit,p.mrp,p.retail_price,p.offer_price,p.min_stock,p.serial_tracking,p.active,c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? LIMIT 1');
    $st->execute([$productId]);
    $p = $st->fetch();
    if (!$p || (!(int)$p['active'] && !$vis['inactive'])) return null;
    $minStock = (float)$p['min_stock'];

    [$scope, $params] = location_scope_sql('ib.location_id');
    $filter = $vis['sellable_only'] ? '(ib.qty-ib.reserved_qty)>0' : '(ib.qty>0 OR ib.reserved_qty>0)';
    $st = db()->prepare("SELECT ib.batch_id,ib.location_id,ib.qty,ib.reserved_qty,GREATEST(ib.qty-ib.reserved_qty,0) sellable,b.batch_no,b.batch_barcode,b.purchased_at,l.code location_code,l.name location_name,l.warehouse,l.floor_name,l.section_name,l.rack,l.shelf_bin FROM inventory_balances ib JOIN batches b ON b.id=ib.batch_id JOIN locations l ON l.id=ib.location_id WHERE ib.product_id=? AND $filter" . $scope . ' ORDER BY l.code,b.purchased_at,b.id');
    $st->execute([$productId, ...$params]);
    $rows = $st->fetchAll();

    $totals = ['physical' => 0.0, 'reserved' => 0.0, 'sellable' => 0.0];
    $locations = [];
    $batches = [];
    foreach ($rows as $r) {
        $qty = (float)$r['qty']; $res = (float)$r['reserved_qty']; $sell = (float)$r['sellable'];
        $totals['physical'] += $qty; $totals['reserved'] += $res; $totals['sellable'] += $sell;

        $lid = (int)$r['location_id'];
        if (!isset($locations[$lid])) {
            $locations[$lid] = ['code' => $r['location_code'], 'name' => $r['location_name'], 'warehouse' => $r['warehouse'], 'floor' => $r['floor_name'], 'section' => $r['section_name'], 'rack' => $r['rack'], 'bin' => $r['shelf_bin'], 'physical' => 0.0, 'reserved' => 0.0, 'sellable' => 0.0];
        }
        $locations[$lid]['physical'] += $qty; $locations[$lid]['reserved'] += $res; $locations[$lid]['sellable'] += $sell;

        $batch = ['batch_no' => $r['batch_no'], 'batch_barcode' => $r['batch_barcode'], 'purchased_at' => $r['purchased_at'], 'location_code' => $r['location_code'], 'sellable' => $sell];
        if ($vis['reserved']) { $batch['physical'] = $qty; $batch['reserved'] = $res; }
        $batches[] = $batch;
    }
    $locations = array_values(array_map(function ($l) use ($vis, $minStock) {
        $l['status'] = pf_stock_status($l['sellable'], $minStock);
        if (!$vis['reserved']) unset($l['physical'], $l['reserved']);
        return $l;
    }, $locations));

    $serials = null;
    if ((int)$p['serial_tracking'] === 1) {
        [$sScope, $sParams] = location_scope_sql('ps.location_id');
        $statuses = pf_serial_statuses($vis);
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $base = " FROM product_serials ps JOIN batches b ON b.id=ps.batch_id JOIN locations l ON l.id=ps.location_id WHERE ps.product_id=? AND ps.status IN ($in)" . $sScope;
        $args = [$productId, ...$statuses, ...$sParams];
        $st = db()->prepare('SELECT COUNT(*)' . $base);
        $st->execute($args);
        $serialTotal = (int)$st->fetchColumn();
        $st = db()->prepare('SELECT ps.serial_barcode,ps.status,b.batch_no,l.code location_code' . $base . ' ORDER BY l.code,b.batch_no,ps.id LIMIT ' . (int)PF_SERIAL_LIMIT);
        $st->execute($args);
        $serials = ['total' => $serialTotal, 'shown' => 0, 'items' => $st->fetchAll()];
        $serials['shown'] = count($serials['items']);
    }

    $product = [
        'id' => (int)$p['id'],
        'sku' => $p['sku'],
        'product_barcode' => $p['product_barcode'],
        'name' => $p['product_name'],
        'image' => pf_safe_image($p['product_image']),
        'brand' => $p['brand'],
        'category' => $p['category_name'],
        'fabric' => $p['fabric'],
        'color' => $p['color'],
        'size' => $p['size'],
        'unit' => $p['unit'],
        'tracking' => (int)$p['serial_tracking'] === 1 ? 'SERIAL' : 'BATCH',
        'active' => (int)$p['active'] === 1,
        'min_stock' => $minStock,
    ];
    if ($vis['prices']) {
        $product['mrp'] = (float)$p['mrp'];
        $product['selling_price'] = (float)$p['retail_price'];
        $product['offer_price'] = $p['offer_price'] !== null ? (float)$p['offer_price'] : null;
    }
    $stock = ['sellable' => $totals['sellable'], 'status' => pf_stock_status($totals['sellable'], $minStock)];
    if ($vis['reserved']) { $stock['physical'] = $totals['physical']; $stock['reserved'] = $totals['reserved']; }

    return [
        'product' => $product,
        'stock' => $stock,
        'locations' => $locations,
        'batches' => $batches,
        'serials' => $serials,
        'scope' => is_super_admin() ? 'all' : 'assigned',
    ];
}

function pf_json(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON-friendly guard: same session/permission rules as require_permission(). */
function pf_api_guard(): void {
    if (!current_user()) pf_json(['ok' => false, 'error' => 'Your session has expired. Please log in again.', 'code' => 'auth'], 401);
    login_required();
    if (!has_permission('view_stock')) pf_json(['ok' => false, 'error' => 'You do not have permission to view stock.', 'code' => 'forbidden'], 403);
}
