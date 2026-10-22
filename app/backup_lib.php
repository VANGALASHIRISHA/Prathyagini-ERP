<?php
// Backup / restore helpers. Pure PHP (PDO), so it works on shared hosting without mysqldump.
require_once __DIR__ . '/zipwriter.php';

const BACKUP_MARK = '-- PRATYANGI BACKUP v1';
const BACKUP_SEP = "\n-- ;;END\n";

function backup_dir(): string {
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir)) {
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}
function backup_valid_name(string $n): bool { return (bool)preg_match('/^(db|files)_[A-Za-z0-9_]+\.(sql|zip)$/', $n); }

function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/*.{sql,zip}', GLOB_BRACE) ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    usort($out, fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

function backup_database(string $tag = ''): string {
    $name = 'db_' . ($tag !== '' ? $tag . '_' : '') . date('Ymd_His') . '.sql';
    $path = backup_dir() . '/' . $name;
    $fh = fopen($path, 'wb');
    if (!$fh) throw new RuntimeException('Cannot write the backup file. Check that storage/backups is writable.');
    $pdo = db();
    fwrite($fh, BACKUP_MARK . "\n-- Created " . date('Y-m-d H:i:s') . BACKUP_SEP);
    fwrite($fh, 'SET FOREIGN_KEY_CHECKS=0' . BACKUP_SEP . "SET NAMES utf8mb4" . BACKUP_SEP);
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS $q" . BACKUP_SEP . $create . BACKUP_SEP);
        $offset = 0; $chunk = 500;
        while (true) {
            $rows = $pdo->query("SELECT * FROM $q LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_NUM);
            if (!$rows) break;
            $vals = [];
            foreach ($rows as $r) $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            fwrite($fh, "INSERT INTO $q VALUES " . implode(',', $vals) . BACKUP_SEP);
            $offset += $chunk;
            if (count($rows) < $chunk) break;
        }
    }
    fwrite($fh, 'SET FOREIGN_KEY_CHECKS=1' . BACKUP_SEP);
    fclose($fh);
    return $name;
}

function backup_files(): ?string {
    // Product images and the original vendor bills (the bills live outside public/ and are private).
    $roots = ['uploads/' => dirname(__DIR__) . '/public/uploads', 'purchase_bills/' => dirname(__DIR__) . '/storage/purchase_bills'];
    $roots = array_filter($roots, 'is_dir');
    if (!$roots) return null;
    $name = 'files_' . date('Ymd_His') . '.zip';
    $zip = new SimpleZip(backup_dir() . '/' . $name);
    $n = 0;
    foreach ($roots as $prefix => $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getFilename() === '.htaccess') continue;
            $rel = $prefix . str_replace(chr(92), '/', substr($f->getPathname(), strlen($root) + 1));
            $zip->addFile($rel, $f->getPathname()); $n++;
        }
    }
    $zip->close();
    return $n ? $name : (@unlink(backup_dir() . '/' . $name) ? null : null);
}

// Runs a backup file. Returns number of statements. Throws on the first error.
function backup_run_sql(string $path): int {
    $raw = (string)file_get_contents($path);
    if (strncmp($raw, BACKUP_MARK, strlen(BACKUP_MARK)) !== 0) throw new RuntimeException('This is not a backup made by this ERP (missing header).');
    $pdo = db();
    $n = 0;
    foreach (explode(BACKUP_SEP, $raw) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || strncmp($stmt, '--', 2) === 0) continue;
        $pdo->exec($stmt);
        $n++;
    }
    return $n;
}

function backup_restore(string $path): array {
    $pre = backup_database('prerestore');          // safety copy of the current data
    try {
        $n = backup_run_sql($path);
        return ['ok' => true, 'statements' => $n, 'pre' => $pre];
    } catch (Throwable $e) {
        try { backup_run_sql(backup_dir() . '/' . $pre); $rolled = true; } catch (Throwable $e2) { $rolled = false; }
        return ['ok' => false, 'error' => $e->getMessage(), 'rolled_back' => $rolled, 'pre' => $pre];
    }
}
function backup_fmt_size(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format(max(1, $b) / 1024, 1) . ' KB'; }
