<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/backup_lib.php';
require_role(['super_admin']);

$msg = '';
$err = '';
$dir = backup_dir();

if (isset($_GET['download'])) {
    $n = (string)$_GET['download'];
    if (!backup_valid_name($n) || !is_file($dir . '/' . $n)) { http_response_code(404); exit('Not found'); }
    audit('BACKUP_DOWNLOAD', 'backup', null, ['file' => $n]);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $n . '"');
    header('Content-Length: ' . filesize($dir . '/' . $n));
    header('Cache-Control: no-store');
    readfile($dir . '/' . $n);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('The folder storage/backups is not writable. Make it writable (chmod 775) and try again.');
        if ($action === 'create') {
            set_time_limit(300);
            $db = backup_database();
            $files = !empty($_POST['with_files']) ? backup_files() : null;
            audit('BACKUP_CREATE', 'backup', null, ['db' => $db, 'files' => $files]);
            $msg = 'Backup created: ' . $db . ($files ? ' and ' . $files : '') . '.';
        } elseif ($action === 'delete') {
            $n = (string)($_POST['name'] ?? '');
            if (!backup_valid_name($n) || !is_file($dir . '/' . $n)) throw new RuntimeException('File not found.');
            unlink($dir . '/' . $n);
            audit('BACKUP_DELETE', 'backup', null, ['file' => $n]);
            $msg = 'Deleted ' . $n . '.';
        } elseif ($action === 'restore') {
            if (trim((string)($_POST['confirm'] ?? '')) !== 'RESTORE') throw new RuntimeException('Type RESTORE (capital letters) to confirm. Nothing was changed.');
            set_time_limit(600);
            $src = '';
            if (!empty($_FILES['sqlfile']['tmp_name']) && is_uploaded_file($_FILES['sqlfile']['tmp_name'])) {
                if ($_FILES['sqlfile']['size'] > 100 * 1024 * 1024) throw new RuntimeException('File is larger than 100 MB.');
                $src = $_FILES['sqlfile']['tmp_name'];
            } else {
                $n = (string)($_POST['name'] ?? '');
                if (!preg_match('/^db_[A-Za-z0-9_]+\.sql$/', $n) || !is_file($dir . '/' . $n)) throw new RuntimeException('Choose a database backup (.sql) to restore.');
                $src = $dir . '/' . $n;
            }
            $res = backup_restore($src);
            @file_put_contents($dir . '/restore.log', date('Y-m-d H:i:s') . ' user=' . (current_user()['username'] ?? '?') . ' ' . json_encode($res) . "\n", FILE_APPEND);
            if ($res['ok']) $msg = 'Restore complete (' . $res['statements'] . ' statements). A safety copy of the previous data was saved as ' . $res['pre'] . '. If you are logged out, sign in again.';
            else $err = 'Restore FAILED: ' . $res['error'] . '. ' . ($res['rolled_back'] ? 'Your previous data was put back automatically (' . $res['pre'] . ').' : 'Automatic roll-back also failed. Restore ' . $res['pre'] . ' manually.');
        } else throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$list = backup_list();
page_header('Backup & Restore');
if ($msg) echo '<div class="notice success">' . e($msg) . '</div>';
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
?>
<div class="notice"><b>Super Admin only.</b> Backups are saved in the <code>storage/backups</code> folder, outside the public web folder, and can be downloaded here. Keep a copy somewhere else (your computer or cloud drive) as well. Make a backup <b>every day</b> and before every upgrade.</div>
<form method="post" class="panel">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
<h3>Create backup</h3>
<label><input type="checkbox" name="with_files" value="1" checked style="width:auto"> Also back up uploaded files (product images)</label><br><br>
<button class="btn" id="mkBtn" onclick="this.form.submit();this.disabled=true;this.textContent='Backing up…';return false;">Create Backup Now</button>
</form>
<br>
<h3>Saved backups</h3>
<div style="overflow-x:auto"><table>
<tr><th>File</th><th>Type</th><th>Size</th><th>Created</th><th></th></tr>
<?php foreach ($list as $f): ?>
<tr><td><?= e($f['name']) ?></td><td><?= str_ends_with($f['name'], '.sql') ? 'Database' : 'Uploaded files' ?></td><td><?= e(backup_fmt_size((int)$f['size'])) ?></td><td><?= e(date('d-m-Y H:i:s', $f['time'])) ?></td>
<td><a class="btn secondary" href="backup.php?download=<?= e(urlencode($f['name'])) ?>">Download</a>
<form method="post" style="display:inline" onsubmit="return confirm('Delete this backup file?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($f['name']) ?>"><button class="btn danger">Delete</button></form></td></tr>
<?php endforeach; if (!$list): ?><tr><td colspan="5" class="muted">No backups yet. Click “Create Backup Now”.</td></tr><?php endif; ?>
</table></div>
<br>
<form method="post" enctype="multipart/form-data" class="panel" onsubmit="return confirm('This REPLACES all current data with the backup. A safety copy is made first. Continue?')">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restore">
<h3>Restore (roll back) from a database backup</h3>
<p class="muted">Restore replaces <b>all</b> current data with the backup. The system first saves a safety copy of the current data, and if the restore fails it puts that copy back automatically.</p>
<div class="row">
<div><label>Choose a saved backup</label><select name="name"><option value="">-- none --</option><?php foreach ($list as $f) if (str_ends_with($f['name'], '.sql')) echo '<option>' . e($f['name']) . '</option>'; ?></select></div>
<div><label>…or upload a backup (.sql made by this page)</label><input type="file" name="sqlfile" accept=".sql"></div>
</div><br>
<label>Type <b>RESTORE</b> to confirm</label><input name="confirm" autocomplete="off" placeholder="RESTORE" style="max-width:240px"><br><br>
<button class="btn danger">Restore Backup</button>
</form>
<div class="notice"><b>Step by step restore:</b> 1) Create a fresh backup first (optional, it is done automatically). 2) Choose the backup or upload the .sql file. 3) Type RESTORE and press the button. 4) Log in again and check Stock, Sales Reports and the Integrity Report.<br>Uploaded-files backups (<code>files_….zip</code>) are restored by unzipping them into <code>public/</code> on the server.</div>
<?php page_footer();
