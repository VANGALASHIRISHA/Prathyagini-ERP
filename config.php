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
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }
}

// Order: .env file, then real environment variables (Docker / Render / Railway), then the default.
$pick = static function (string $key, $default) use ($env) {
    if (array_key_exists($key, $env)) return $env[$key];
    $real = getenv($key);
    return $real !== false ? $real : $default;
};

$debug = filter_var(
    $pick('APP_DEBUG', 'false'),
    FILTER_VALIDATE_BOOLEAN
);

return [
    'db' => [
        'host' => $pick('DB_HOST', 'localhost'),
        'port' => (int) $pick('DB_PORT', 3306),
        'name' => $pick('DB_NAME', 'pimfi131_pratyangiportal'),
        'user' => $pick('DB_USER', 'pimfi131_pratyangiportal'),
        'pass' => $pick('DB_PASS', 'YOUR_DATABASE_PASSWORD'),
        'charset' => 'utf8mb4',
    ],

    'api' => [
        // Leave blank to disable server-to-server online order ingest.
        // In production, use a random 40+ character secret.
        // Send it as Authorization: Bearer <key>.
        'order_ingest_key' => '',
    ],

    'app' => [
        'name' => 'Pratyangi Fashions ERP',
        'base_url' => $pick(
            'APP_BASE_URL',
            'https://superadmin.pratyangi.com'
        ),
        'timezone' => $pick('APP_TIMEZONE', 'Asia/Kolkata'),
        'session_name' => 'PRATYANGI_ERP',
        'debug' => $debug,
    ],

    'license' => [
        'server_url' => $pick('LICENSE_SERVER_URL', 'https://centrallicense.pratyangi.com'),
        'public_key_path' => __DIR__ . '/license/public_key.pem',
        'installation_domain' => '',
        'retry_after_seconds' => 3600,

        // Development-only license bypass.
        // app/license_client.php must restrict this to localhost,
        // 127.0.0.1, and ::1.
        // Controlled by APP_LOCAL_LICENSE_BYPASS in .env.
        // Defaults to false when unset or invalid.
        'local_development_bypass' => filter_var(
            $pick('APP_LOCAL_LICENSE_BYPASS', 'false'),
            FILTER_VALIDATE_BOOLEAN
        ),

        'allow_insecure_http_for_local_testing' => $debug,
    ],
];