<?php
/**
 * Tests the catalogue KEY rules of app/catalog_api.php. Needs no database and no web server:
 *   php tests/php/catalog_key_test.php
 * The catalogue key must be its own api.catalogue_key (32+ characters). It must never fall back to api.order_ingest_key,
 * and must not equal it. All keys below are fake test values.
 */
require __DIR__ . '/../../app/catalog_api.php';

$fails = 0; $checks = 0;
function same(string $name, $expected, $actual): void {
    global $fails, $checks; $checks++;
    if ($expected !== $actual) { $fails++; echo "FAIL  $name  (expected " . json_encode($expected) . ' got ' . json_encode($actual) . ")\n"; }
}

$ORDER = str_repeat('o', 40);
$CAT = str_repeat('c', 40);
$get = fn(?string $key) => ['REQUEST_METHOD' => 'GET'] + ($key === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $key]);
$status = function (array $cfg, ?string $sentKey): int {
    global $get;
    $r = catalog_api_authorize($get($sentKey), catalog_api_key($cfg));
    return $r === null ? 200 : $r[0];
};

// the key that is read
same('own key is used', $CAT, catalog_api_key(['api' => ['catalogue_key' => $CAT, 'order_ingest_key' => $ORDER]]));
same('NO fallback: only the order key configured -> no catalogue key', '', catalog_api_key(['api' => ['order_ingest_key' => $ORDER]]));
same('NO fallback: empty catalogue key + order key -> no catalogue key', '', catalog_api_key(['api' => ['catalogue_key' => '', 'order_ingest_key' => $ORDER]]));
same('NO fallback: blank (spaces) catalogue key + order key -> no catalogue key', '', catalog_api_key(['api' => ['catalogue_key' => '   ', 'order_ingest_key' => $ORDER]]));
same('nothing configured -> no key', '', catalog_api_key([]));
same('api is not an array -> no key', '', catalog_api_key(['api' => 'oops']));
same('a catalogue key equal to the order key is refused', '', catalog_api_key(['api' => ['catalogue_key' => $ORDER, 'order_ingest_key' => $ORDER]]));
same('a catalogue key that only looks like the order key is fine', $CAT, catalog_api_key(['api' => ['catalogue_key' => $CAT, 'order_ingest_key' => $CAT . 'x']]));

// what the endpoint answers
same('only the order key configured: sending the order key -> 503', 503, $status(['api' => ['order_ingest_key' => $ORDER]], $ORDER));
same('only the order key configured: sending no key -> 503', 503, $status(['api' => ['order_ingest_key' => $ORDER]], null));
same('nothing configured -> 503', 503, $status([], $CAT));
same('catalogue key of 31 characters -> 503', 503, $status(['api' => ['catalogue_key' => str_repeat('k', 31)]], str_repeat('k', 31)));
same('catalogue key of 32 characters + right bearer -> 200', 200, $status(['api' => ['catalogue_key' => str_repeat('k', 32)]], str_repeat('k', 32)));
same('catalogue key of 32 characters + wrong bearer -> 401', 401, $status(['api' => ['catalogue_key' => str_repeat('k', 32)]], str_repeat('j', 32)));
same('catalogue key equal to the order key -> 503 even with the right bearer', 503, $status(['api' => ['catalogue_key' => $ORDER, 'order_ingest_key' => $ORDER]], $ORDER));
same('both keys set: the catalogue key -> 200', 200, $status(['api' => ['catalogue_key' => $CAT, 'order_ingest_key' => $ORDER]], $CAT));
same('both keys set: the ORDER key is refused -> 401', 401, $status(['api' => ['catalogue_key' => $CAT, 'order_ingest_key' => $ORDER]], $ORDER));
same('both keys set: no key -> 401', 401, $status(['api' => ['catalogue_key' => $CAT, 'order_ingest_key' => $ORDER]], null));
same('empty bearer -> 401', 401, catalog_api_authorize(['HTTP_AUTHORIZATION' => 'Bearer '], $CAT)[0] ?? 0);
same('Basic scheme -> 401', 401, catalog_api_authorize(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('a:b')], $CAT)[0] ?? 0);
same('the REDIRECT_ header form also works', null, catalog_api_authorize(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $CAT], $CAT));
same('lower-case "bearer" is accepted, the key itself is exact', [null, 401], [catalog_api_authorize(['HTTP_AUTHORIZATION' => 'bearer ' . $CAT], $CAT), catalog_api_authorize(['HTTP_AUTHORIZATION' => 'bearer ' . strtoupper($CAT)], $CAT)[0] ?? 0]);

// no error answer ever contains a key
$bodies = json_encode([catalog_api_authorize($get($ORDER), catalog_api_key(['api' => ['order_ingest_key' => $ORDER]])), catalog_api_authorize($get('x'), $CAT)]);
same('no answer contains a key', false, strpos($bodies, $ORDER) !== false || strpos($bodies, $CAT) !== false);

// whole handlers: the order key must not open the endpoints even when no catalogue key exists
$db = new PDO('sqlite::memory:');
$onlyOrder = ['api' => ['order_ingest_key' => $ORDER]];
same('products handler: order key only -> 503', 503, catalog_products_handle($get($ORDER), [], $db, $onlyOrder)[0]);
same('stock handler: order key only -> 503', 503, catalog_stock_handle(['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer ' . $ORDER], '{"skus":["A"]}', $db, $onlyOrder)[0]);

echo $fails === 0 ? "OK $checks checks passed\n" : "$fails of $checks checks FAILED\n";
exit($fails === 0 ? 0 : 1);
