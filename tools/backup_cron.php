<?php
// Daily automatic backup. Run from cPanel > Cron Jobs, once a day, for example at 2 AM:
//   /usr/local/bin/php /home/YOURUSER/superadmin.pratyangi.com/tools/backup_cron.php
// Keeps the newest 14 database backups and 14 file backups; safety copies made before a restore are never deleted.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__) . '/public');
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/backup_lib.php';
try {
    $db = backup_database();
    $files = backup_files();
    echo date('Y-m-d H:i:s') . " backup ok: $db" . ($files ? " + $files" : '') . "\n";
    $dir = backup_dir();
    foreach (['db' => '/^db_\d{8}_\d{6}\.sql$/', 'files' => '/^files_\d{8}_\d{6}\.zip$/'] as $kind => $re) {
        $all = array_values(array_filter(array_map('basename', glob($dir . '/*') ?: []), fn($n) => preg_match($re, $n)));
        rsort($all);
        foreach (array_slice($all, 14) as $old) { @unlink($dir . '/' . $old); echo "removed old backup $old\n"; }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
