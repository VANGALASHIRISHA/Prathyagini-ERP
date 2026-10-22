<?php
function panel_http_host(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);
    return rtrim((string)$host, '.');
}

function panel_host_map(): array {
    return [
        'superadmin.pratyangi.com' => 'super_admin',
        'superadamin.pratyangi.com' => 'super_admin',
        'manager.pratyangi.com' => 'manager',
        'godown.pratyangi.com' => 'godown',
        'cashier.pratyangi.com' => 'cashier',
        'auditor.pratyangi.com' => 'auditor',
    ];
}

function panel_role_hosts(): array {
    return [
        'super_admin' => 'superadmin.pratyangi.com',
        'manager' => 'manager.pratyangi.com',
        'godown' => 'godown.pratyangi.com',
        'cashier' => 'cashier.pratyangi.com',
        'auditor' => 'auditor.pratyangi.com',
    ];
}

function panel_host_role(): ?string {
    return panel_host_map()[panel_http_host()] ?? null;
}

function panel_host_title(): string {
    return match(panel_host_role()) {
        'super_admin' => 'Super Admin',
        'manager' => 'Manager',
        'godown' => 'Godown',
        'cashier' => 'Cashier',
        'auditor' => 'Auditor',
        default => 'Pratyangi',
    };
}

function panel_url_for_role(string $role): ?string {
    $host = panel_role_hosts()[$role] ?? null;
    if ($host === null) return null;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    return ($https ? 'https://' : 'http://') . $host . '/login.php';
}

function panel_host_enforce(): void {
    $expected = panel_host_role();
    if ($expected === null) return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['login.php', 'logout.php'], true)) return;
    $user = current_user();
    if (!$user) return;
    if (($user['role'] ?? '') === $expected) return;
    $right = panel_url_for_role((string)($user['role'] ?? ''));
    http_response_code(403);
    $message = 'This address is the ' . panel_host_title() . ' panel.';
    if ($right) $message .= ' Open ' . $right;
    exit($message);
}
