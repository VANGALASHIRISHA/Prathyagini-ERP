<?php
/**
 * Read-only catalogue API for the online store (the storefront server calls it; browsers never do).
 *
 * Everything here is a plain function that takes the database and the request values, so it can be tested without a web
 * server (see tests/php/catalog_key_test.php and tests/php/catalog_api_mysql_http_test.php). The two endpoints
 * (public/api/catalog_products.php and catalog_stock.php) are thin wrappers that call these functions and print the result.
 *
 * What is exposed, and what is NOT:
 *  - exposed: sku, name, category, fabric, pattern, design model, colour, size, the three customer-facing prices that exist
 *    in the ERP (mrp, retail_price, offer_price) and tax_rate, each as its own field, and a safe image path;
 *  - never exposed: purchase_cost, cost_code, wholesale_price, dealer_price, barcodes, batches, serials, raw stock figures.
 * The ERP does NOT say which price the website sells at or whether prices include tax: the website decides that in ONE
 * place (apps/api/src/erp/http.ts) and the business has to confirm it. Nothing here assumes the prices are interchangeable.
 *
 * "Online stock" = stock in locations that are active AND have online_fulfillment_enabled = 1, counted exactly the way
 * online_create_and_reserve() (app/online_fulfillment.php) will reserve it: whole pieces per batch row, and for
 * serial-tracked products no more than the serials that are AVAILABLE in that batch and location.
 *
 * Warehouses: order_create.php refuses an order that does not name a warehouse (or location) when MORE THAN ONE warehouse is enabled for
 * online orders. So the stock endpoint takes an optional "warehouse" and then counts only that warehouse, which is the number an order
 * for that warehouse can actually reserve. A caller that sends "warehouse" in its orders must send the same one here.
 *
 * Key: cfg()['api']['catalogue_key'] ONLY, at least 32 characters. There is deliberately NO fallback to api.order_ingest_key,
 * and a catalogue key equal to the order key is refused too (503), so the key that can read the catalogue can never create orders.
 */

const CATALOG_DEFAULT_LIMIT = 100;
const CATALOG_MAX_LIMIT = 200;
const CATALOG_MAX_SKUS = 100;
const CATALOG_MAX_SKU_LENGTH = 60;
const CATALOG_MAX_WAREHOUSE_LENGTH = 120;
const CATALOG_MAX_BODY_BYTES = 20000;

/**
 * The key the storefront server must send as `Authorization: Bearer <key>`: api.catalogue_key and nothing else.
 * Returns '' when it is missing, or when it is the same as the order-create key (they must be two different keys).
 */
function catalog_api_key(array $cfg): string {
    $api = is_array($cfg['api'] ?? null) ? $cfg['api'] : [];
    $own = trim((string)($api['catalogue_key'] ?? ''));
    $order = trim((string)($api['order_ingest_key'] ?? ''));
    if ($own !== '' && $order !== '' && hash_equals($order, $own)) return '';
    return $own;
}

/** null when the request may continue, otherwise [status, body]. */
function catalog_api_authorize(array $server, string $key): ?array {
    if (strlen($key) < 32) return [503, ['ok' => false, 'error' => 'Catalogue API is disabled until a separate 32+ character catalogue key is configured.']];
    $auth = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $given = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : '';
    if ($given === '' || !hash_equals($key, $given)) return [401, ['ok' => false, 'error' => 'Unauthorized']];
    return null;
}

/** A product picture path the website may use: only a picture file inside uploads/products/. Anything else becomes null. */
function catalog_image_path(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '' || strpos($path, '..') !== false) return null;
    return preg_match('#^uploads/products/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif|avif)$#i', $path) === 1 ? $path : null;
}

/** DECIMAL columns as strings with two decimals ("1299.00"), so no floating-point rounding can reach the website. */
function catalog_decimal($value): ?string {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return number_format((float)$value, 2, '.', '');
}

function catalog_text($value): ?string {
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

/**
 * One page of active products in id order. Keyset paging: pass the previous page's next_cursor as $afterId.
 * @return array{products: array<int,array<string,mixed>>, next_cursor: ?string}
 */
function catalog_products_page(PDO $db, int $afterId, int $limit): array {
    $st = $db->prepare(
        'SELECT p.id, p.sku, p.product_name, p.product_image, p.fabric, p.pattern_name, p.color, p.size, p.design_model, '
        . 'p.mrp, p.retail_price, p.offer_price, p.tax_rate, c.name AS category_name '
        . 'FROM products p LEFT JOIN categories c ON c.id = p.category_id '
        . 'WHERE p.active = 1 AND p.id > :after ORDER BY p.id LIMIT :lim'
    );
    $st->bindValue(':after', $afterId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit + 1, PDO::PARAM_INT); // one extra row tells us whether there is another page
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    $products = [];
    foreach ($rows as $r) {
        $products[] = [
            'sku' => (string)$r['sku'],
            'name' => (string)$r['product_name'],
            'category' => catalog_text($r['category_name']),
            'fabric' => catalog_text($r['fabric']),
            'pattern_name' => catalog_text($r['pattern_name']),
            'design_model' => catalog_text($r['design_model']),
            'color' => catalog_text($r['color']),
            'size' => catalog_text($r['size']),
            'mrp' => catalog_decimal($r['mrp']),
            'retail_price' => catalog_decimal($r['retail_price']),
            'offer_price' => catalog_decimal($r['offer_price']),
            'tax_rate' => catalog_decimal($r['tax_rate']),
            'image_path' => catalog_image_path($r['product_image']),
        ];
    }
    $last = $rows ? (int)$rows[count($rows) - 1]['id'] : null;
    return ['products' => $products, 'next_cursor' => ($more && $last !== null) ? (string)$last : null];
}

/** Whole pieces of one batch row that an online order could reserve, never negative. Same arithmetic as online_create_and_reserve(). */
function catalog_row_pieces($qty, $reserved): int {
    return (int)floor(max(0.0, (float)$qty - (float)$reserved) + 1e-9);
}

/**
 * Online-sellable pieces for the given SKUs. Only known, active SKUs are returned. With $warehouse, only that warehouse's online locations count.
 * @param string[] $skus
 * @return array<int,array{sku:string,available_pieces:int}>
 */
function catalog_availability(PDO $db, array $skus, ?string $warehouse = null): array {
    $skus = array_values(array_unique($skus));
    if (!$skus) return [];
    $ph = implode(',', array_fill(0, count($skus), '?'));
    $st = $db->prepare("SELECT id, sku, serial_tracking FROM products WHERE active = 1 AND sku IN ($ph)");
    $st->execute($skus);
    $products = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$products) return [];
    $ids = array_map(fn($p) => (int)$p['id'], $products);
    $idPh = implode(',', array_fill(0, count($ids), '?'));

    $whSql = $warehouse !== null ? ' AND l.warehouse = ?' : '';
    $whArgs = $warehouse !== null ? [$warehouse] : [];
    $st = $db->prepare(
        'SELECT ib.product_id, ib.batch_id, ib.location_id, ib.qty, ib.reserved_qty FROM inventory_balances ib '
        . 'JOIN locations l ON l.id = ib.location_id '
        . "WHERE l.active = 1 AND l.online_fulfillment_enabled = 1$whSql AND ib.product_id IN ($idPh)"
    );
    $st->execute([...$whArgs, ...$ids]);
    $balances = $st->fetchAll(PDO::FETCH_ASSOC);

    $serials = [];
    $st = $db->prepare(
        'SELECT ps.product_id, ps.batch_id, ps.location_id, COUNT(*) AS n FROM product_serials ps '
        . 'JOIN locations l ON l.id = ps.location_id '
        . "WHERE l.active = 1 AND l.online_fulfillment_enabled = 1$whSql AND ps.status = 'AVAILABLE' AND ps.product_id IN ($idPh) "
        . 'GROUP BY ps.product_id, ps.batch_id, ps.location_id'
    );
    $st->execute([...$whArgs, ...$ids]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $serials[$r['product_id'] . ':' . $r['batch_id'] . ':' . $r['location_id']] = (int)$r['n'];

    $serialTracked = [];
    foreach ($products as $p) $serialTracked[(int)$p['id']] = (int)$p['serial_tracking'] === 1;

    $pieces = array_fill_keys($ids, 0);
    foreach ($balances as $b) {
        $pid = (int)$b['product_id'];
        $avail = catalog_row_pieces($b['qty'], $b['reserved_qty']);
        if ($serialTracked[$pid] ?? false) $avail = min($avail, $serials[$pid . ':' . $b['batch_id'] . ':' . $b['location_id']] ?? 0);
        $pieces[$pid] += $avail;
    }
    $out = [];
    foreach ($products as $p) $out[] = ['sku' => (string)$p['sku'], 'available_pieces' => $pieces[(int)$p['id']]];
    return $out;
}

/** If an order with this source and external id already exists, returns [id, order_no, status]; used to answer a repeated request without a second order. */
function catalog_find_order(PDO $db, string $source, string $externalId): ?array {
    if ($externalId === '') return null;
    $st = $db->prepare('SELECT id, order_no, status FROM online_orders WHERE source = ? AND external_order_id = ? LIMIT 1');
    $st->execute([$source, $externalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** True when a PDOException is MySQL's "duplicate key" (1062) or the standard SQLSTATE 23000 unique violation. */
function catalog_is_duplicate_key(Throwable $e): bool {
    if (!$e instanceof PDOException) return false;
    $info = $e->errorInfo ?? [];
    return ($info[1] ?? null) === 1062 || (string)($e->getCode()) === '23000';
}

// ---- request handlers: [status, body] -------------------------------------------------------------------------------------

function catalog_products_handle(array $server, array $query, PDO $db, array $cfg): array {
    if (($server['REQUEST_METHOD'] ?? '') !== 'GET') return [405, ['ok' => false, 'error' => 'GET only']];
    if ($denied = catalog_api_authorize($server, catalog_api_key($cfg))) return $denied;

    $limit = CATALOG_DEFAULT_LIMIT;
    if (array_key_exists('limit', $query)) {
        $raw = $query['limit'];
        if (!is_string($raw) || !preg_match('/^\d{1,3}$/', $raw) || (int)$raw < 1 || (int)$raw > CATALOG_MAX_LIMIT) {
            return [422, ['ok' => false, 'error' => 'limit must be a whole number from 1 to ' . CATALOG_MAX_LIMIT]];
        }
        $limit = (int)$raw;
    }
    $after = 0;
    if (array_key_exists('after_id', $query)) {
        $raw = $query['after_id'];
        if (!is_string($raw) || !preg_match('/^\d{1,10}$/', $raw)) return [422, ['ok' => false, 'error' => 'after_id must be a whole number']];
        $after = (int)$raw;
    }
    try {
        $page = catalog_products_page($db, $after, $limit);
    } catch (Throwable $e) {
        error_log('catalog_api products: ' . $e->getMessage());
        return [500, ['ok' => false, 'error' => 'Catalogue is unavailable']];
    }
    return [200, ['ok' => true, 'products' => $page['products'], 'next_cursor' => $page['next_cursor']]];
}

/** POST {"skus": ["A", "B"], "warehouse": "Main Godown"} (at most 100 SKUs; "warehouse" is optional). A POST body keeps SKUs with spaces or symbols out of the address. */
function catalog_stock_handle(array $server, string $rawBody, PDO $db, array $cfg): array {
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return [405, ['ok' => false, 'error' => 'POST only']];
    if ($denied = catalog_api_authorize($server, catalog_api_key($cfg))) return $denied;
    if (strlen($rawBody) > CATALOG_MAX_BODY_BYTES) return [413, ['ok' => false, 'error' => 'Request is too large']];
    $body = json_decode($rawBody, true);
    if (!is_array($body) || !isset($body['skus']) || !is_array($body['skus'])) return [400, ['ok' => false, 'error' => 'Send JSON like {"skus": ["SKU-1"]}']];
    $skus = [];
    foreach ($body['skus'] as $sku) {
        if (!is_string($sku)) return [422, ['ok' => false, 'error' => 'Every SKU must be text']];
        $sku = trim($sku);
        if ($sku === '' || strlen($sku) > CATALOG_MAX_SKU_LENGTH) return [422, ['ok' => false, 'error' => 'Every SKU must be 1 to ' . CATALOG_MAX_SKU_LENGTH . ' characters']];
        $skus[] = $sku;
    }
    $skus = array_values(array_unique($skus));
    if (!$skus || count($skus) > CATALOG_MAX_SKUS) return [422, ['ok' => false, 'error' => 'Send between 1 and ' . CATALOG_MAX_SKUS . ' SKUs']];
    $warehouse = null;
    if (array_key_exists('warehouse', $body) && $body['warehouse'] !== null) {
        if (!is_string($body['warehouse'])) return [422, ['ok' => false, 'error' => 'warehouse must be text']];
        $warehouse = trim($body['warehouse']);
        if ($warehouse === '' || strlen($warehouse) > CATALOG_MAX_WAREHOUSE_LENGTH) return [422, ['ok' => false, 'error' => 'warehouse must be 1 to ' . CATALOG_MAX_WAREHOUSE_LENGTH . ' characters']];
    }
    try {
        $stock = catalog_availability($db, $skus, $warehouse);
    } catch (Throwable $e) {
        error_log('catalog_api stock: ' . $e->getMessage());
        return [500, ['ok' => false, 'error' => 'Stock is unavailable']];
    }
    return [200, ['ok' => true, 'stock' => $stock]];
}

/** Prints a handler result. Kept here so both endpoints behave the same. */
function catalog_send(array $result): never {
    [$status, $body] = $result;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
