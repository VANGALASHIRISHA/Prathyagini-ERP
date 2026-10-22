<?php
// Streams the stored vendor bill. The file lives outside public/ and is never reachable by URL.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/purchase_module.php';
pm_require_view();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT attachment_path,attachment_name FROM purchases WHERE id=?');
$st->execute([$id]);
$row = $st->fetch();
$name = $row ? basename((string)$row['attachment_path']) : '';
$path = __DIR__ . '/../storage/purchase_bills/' . $name;
if ($name === '' || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $name) || !is_file($path)) { http_response_code(404); exit('File not found'); }

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
$ext = pathinfo($name, PATHINFO_EXTENSION);
audit('PURCHASE_BILL_FILE_DOWNLOAD', 'purchase', $id);
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$row['attachment_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
