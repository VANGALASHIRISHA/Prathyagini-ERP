<?php
$envFile = __DIR__ . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }
}
$pick = static function (string $key, $default) use ($env) {
    return array_key_exists($key, $env) ? $env[$key] : $default;
};
$debug = filter_var($pick('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

return [
    'db' => [
        'host' => $pick('DB_HOST', 'localhost'),
        'port' => (int) $pick('DB_PORT', 3306),
        'name' => $pick('DB_NAME', 'pimfi131_pratyangiportal'),
        'user' => $pick('DB_USER', 'pimfi131_pratyangiportal'),
        'pass' => $pick('DB_PASS', 'h,I%M?T%EAmXoTl}'),
        'charset' => 'utf8mb4',
    ],
    'api' => [
        // Leave blank to disable server-to-server online order ingest.
        // In production use a random 40+ character secret and send it as Authorization: Bearer <key>.
        'order_ingest_key' => '',
    ],
    'app' => [
        'name' => 'Pratyangi Fashions ERP',
        'base_url' => $pick('APP_BASE_URL', 'https://superadmin.pratyangi.com'),
        'timezone' => $pick('APP_TIMEZONE', 'Asia/Kolkata'),
        'session_name' => 'PRATYANGI_ERP',
        'debug' => $debug,
    ],
    'license' => [
        'server_url' => 'https://centrallicense.pratyangi.com',
        'public_key_path' => __DIR__ . '/license/public_key.pem',
        'installation_domain' => '',
        'retry_after_seconds' => 3600,
        'allow_insecure_http_for_local_testing' => $debug,
    ],
];
