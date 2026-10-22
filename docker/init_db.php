<?php
// Imports the schema into an EMPTY database on first start. Never touches a database that already has tables.
$config = require __DIR__ . '/../config.php';
$d = $config['db'];
try {
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "init_db: cannot connect to the database (" . $e->getMessage() . ")\n");
    exit(1);
}
$has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
if ($has) { echo "init_db: database already initialised, nothing to do.\n"; exit(0); }
// database.sql already contains the license client tables; the v1.5-v1.7 files are idempotent upgrades.
foreach (['database.sql', 'migration_v1_5_location_shelf.sql', 'migration_v1_6_transfer_workflow.sql', 'migration_v1_7_count_exceptions_import.sql'] as $f) {
    $sql = file_get_contents(__DIR__ . '/../sql/' . $f);
    try { $pdo->exec($sql); echo "init_db: imported $f\n"; }
    catch (Throwable $e) { echo "init_db: $f skipped/failed: " . $e->getMessage() . "\n"; }
}
echo "init_db: done. Open /setup_admin.php to create the first Super Admin.\n";
