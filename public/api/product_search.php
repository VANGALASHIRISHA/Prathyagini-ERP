<?php
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/product_finder.php';
pf_api_guard();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') pf_json(['ok' => false, 'error' => 'Method not allowed'], 405);

$raw = $_GET['q'] ?? '';
if (!is_string($raw)) pf_json(['ok' => false, 'error' => 'Invalid search text.'], 422);
$q = pf_clean_query($raw);
$exact = ($_GET['exact'] ?? '') === '1';
if ($q === '') pf_json(['ok' => true, 'query' => '', 'results' => []]);

try {
    $results = pf_search($q, $exact, pf_visibility());
    pf_json(['ok' => true, 'query' => $q, 'exact' => $exact, 'results' => $results, 'limit' => PF_RESULT_LIMIT]);
} catch (Throwable $e) {
    error_log('product_search: ' . $e->getMessage());
    pf_json(['ok' => false, 'error' => 'Search failed. Please try again.'], 500);
}
