<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_post.php';
require_permission('manage_purchases');
if ((current_user()['role'] ?? '') !== 'super_admin') { http_response_code(403); exit('Super Admin only (purchase rates are confidential).'); }

const PI_COLUMNS = ['sku', 'location_code', 'quantity', 'purchase_rate', 'batch_no'];
if (($_GET['template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_import_template.csv"');
    echo "sku,location_code,quantity,purchase_rate,batch_no\r\nSAR-001,MAIN-GDN,10,3200,LOT-001\r\n";
    exit;
}

$suppliers = db()->query('SELECT id,party_code,party_name FROM suppliers WHERE active=1 ORDER BY party_name')->fetchAll();
$err = '';
$done = null;
$preview = null; // ['rows'=>[], 'errors'=>int, 'supplier_id','invoice','date']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $step = $_POST['step'] ?? '';
    try {
        if ($step === 'preview') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $invoice = trim((string)($_POST['invoice_no'] ?? ''));
            $date = (string)($_POST['invoice_date'] ?? date('Y-m-d'));
            if (!$supplierId) throw new Exception('Choose a supplier.');
            if ($invoice === '') throw new Exception('Supplier invoice number is required for an import.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Invoice date is not valid.');
            $f = $_FILES['csv'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new Exception('Choose a CSV file to upload.');
            if ($f['size'] > 1024 * 1024) throw new Exception('File is larger than 1 MB.');
            $fh = fopen($f['tmp_name'], 'r');
            $head = fgetcsv($fh);
            if (!$head) throw new Exception('The file is empty.');
            $head = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), $head);
            $missing = array_diff(['sku', 'location_code', 'quantity', 'purchase_rate'], $head);
            if ($missing) throw new Exception('Missing column(s): ' . implode(', ', $missing) . '. Download the template for the exact format.');
            $idx = array_flip($head);
            $prod = db()->prepare('SELECT id,sku,product_name,serial_tracking FROM products WHERE sku=? AND active=1');
            $loc = db()->prepare('SELECT id,code FROM locations WHERE code=? AND active=1');
            $rows = [];
            $seen = [];
            $n = 1;
            while (($r = fgetcsv($fh)) !== false) {
                $n++;
                if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
                if (count($rows) >= 500) throw new Exception('Maximum 500 rows per import. Split the file.');
                $g = fn($k) => trim((string)($r[$idx[$k] ?? -1] ?? ''));
                $row = ['line' => $n, 'sku' => $g('sku'), 'loc' => $g('location_code'), 'qty' => $g('quantity'), 'rate' => $g('purchase_rate'), 'batch' => $g('batch_no'), 'errors' => []];
                $prod->execute([$row['sku']]);
                $p = $prod->fetch();
                $loc->execute([$row['loc']]);
                $l = $loc->fetch();
                if (!$p) $row['errors'][] = 'SKU not found / inactive';
                if (!$l) $row['errors'][] = 'Location code not found / inactive';
                if (!is_numeric($row['qty']) || (float)$row['qty'] <= 0) $row['errors'][] = 'Quantity must be a positive number';
                elseif ($p && (int)$p['serial_tracking'] === 1 && (float)$row['qty'] != floor((float)$row['qty'])) $row['errors'][] = 'Whole pieces only for unique-piece products';
                elseif ((float)$row['qty'] > 5000) $row['errors'][] = 'Quantity too large (max 5000 per row)';
                if (!is_numeric($row['rate']) || (float)$row['rate'] < 0) $row['errors'][] = 'Purchase rate must be 0 or more';
                if ($row['batch'] === '') $row['batch'] = 'IMP-' . date('ymdHis') . '-' . $n;
                $key = $row['sku'] . '|' . $row['batch'];
                if (isset($seen[$key])) $row['errors'][] = 'Duplicate SKU + batch (also on line ' . $seen[$key] . ')';
                $seen[$key] = $n;
                $row['product_id'] = $p['id'] ?? 0;
                $row['location_id'] = $l['id'] ?? 0;
                $row['name'] = $p['product_name'] ?? '';
                $rows[] = $row;
            }
            fclose($fh);
            if (!$rows) throw new Exception('The file has no data rows.');
            $dup = db()->prepare('SELECT purchase_no FROM purchases WHERE supplier_id=? AND invoice_no=? LIMIT 1');
            $dup->execute([$supplierId, $invoice]);
            if ($d = $dup->fetchColumn()) throw new Exception('Supplier invoice ' . $invoice . ' is already posted as ' . $d . '.');
            $errors = array_sum(array_map(fn($r) => count($r['errors']), $rows));
            $token = bin2hex(random_bytes(16));
            $_SESSION['purchase_import'] = ['token' => $token, 'supplier_id' => $supplierId, 'invoice' => $invoice, 'date' => $date, 'rows' => $rows, 'errors' => $errors];
            $preview = $_SESSION['purchase_import'];
        } elseif ($step === 'post') {
            $pi = $_SESSION['purchase_import'] ?? null;
            unset($_SESSION['purchase_import']); // one-time: a refresh cannot post twice
            if (!$pi || !hash_equals($pi['token'], (string)($_POST['token'] ?? ''))) throw new Exception('This import was already posted or expired. Check Purchase History before trying again.');
            if ($pi['errors'] > 0) throw new Exception('The file still has errors. Fix them and upload again. Nothing was posted.');
            $lines = array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'location_id' => (int)$r['location_id'], 'qty' => (float)$r['qty'], 'cost' => (float)$r['rate'], 'batch' => $r['batch']], $pi['rows']);
            $done = purchase_post_lines((int)$pi['supplier_id'], $pi['invoice'], $pi['date'], $lines);
            publish_event('PURCHASE_INWARD', 'Purchase posted (CSV import)', $done['purchase_no'] . ' · Total ₹' . number_format($done['total'], 2), ['super_admin'], 'purchase', $done['purchase_id'], null);
            publish_scoped_event('PURCHASE_STOCK_IN', 'New stock received', $done['purchase_no'] . ' · ' . count($lines) . ' line(s) received', ['manager', 'godown'], 'purchase', $done['purchase_id']);
        }
    } catch (Throwable $e) {
        $err = user_error_message($e, 'A batch number is already used for one of these products.');
    }
}

page_header('Bulk Stock Import (CSV)');
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
if ($done) {
    echo '<div class="notice success"><b>' . e($done['purchase_no']) . '</b> posted: ' . count($done['batches']) . ' line(s), ' . (int)$done['serials'] . ' unique piece barcode(s) created. <a href="purchases.php">Open Purchase History</a><br>Print labels: ';
    foreach ($done['batches'] as $b) echo '<a class="btn secondary" target="_blank" href="barcode_labels.php?batch_id=' . (int)$b['id'] . '">' . e($b['label']) . '</a> ';
    echo '</div>';
}
?>
<div class="notice">Same result as <a href="purchase_new.php">Purchase Inward</a>: one purchase, stock ledger entries and a unique barcode for every piece. The whole file is checked first; <b>nothing is posted unless every row is valid</b>. <a href="purchase_import.php?template=1">Download CSV template</a> (columns: sku, location_code, quantity, purchase_rate, batch_no).</div>
<?php if ($preview): ?>
<div class="panel">
<p><b>Preview</b> · <?= count($preview['rows']) ?> row(s) · <?= (int)$preview['errors'] ?> problem(s) · Invoice <?= e($preview['invoice']) ?> · <?= e($preview['date']) ?></p>
<div style="overflow-x:auto"><table><tr><th>Line</th><th>SKU</th><th>Product</th><th>Location</th><th>Qty</th><th>Rate</th><th>Batch</th><th>Check</th></tr>
<?php foreach ($preview['rows'] as $r): ?><tr<?= $r['errors'] ? ' style="background:#fee2e2"' : '' ?>><td><?= (int)$r['line'] ?></td><td><?= e($r['sku']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['loc']) ?></td><td><?= e($r['qty']) ?></td><td><?= e($r['rate']) ?></td><td><?= e($r['batch']) ?></td><td><?= $r['errors'] ? e(implode('; ', $r['errors'])) : 'OK' ?></td></tr><?php endforeach; ?></table></div>
<?php if ($preview['errors'] === 0): ?>
<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="step" value="post"><input type="hidden" name="token" value="<?= e($preview['token']) ?>"><button class="btn" id="postBtn" onclick="this.form.submit();this.disabled=true;this.textContent='Posting…';return false;">POST PURCHASE &amp; CREATE STOCK</button> <a class="btn secondary" href="purchase_import.php">Cancel</a></form>
<?php else: ?><p><b>Fix the highlighted rows in your file and upload it again.</b> Nothing has been posted.</p><?php endif; ?>
</div><br>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="panel"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="step" value="preview">
<div class="row3">
<div><label>Supplier / Party</label><select name="supplier_id" required><option value="">-- Select --</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['party_code'] . ' · ' . $s['party_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Supplier Invoice No.</label><input name="invoice_no" required></div>
<div><label>Invoice Date</label><input type="date" name="invoice_date" value="<?= e(date('Y-m-d')) ?>" required></div>
</div><br>
<label>CSV file (max 500 rows, 1 MB)</label><input type="file" name="csv" accept=".csv,text/csv" required><br><br>
<button class="btn">Check File (Preview)</button></form>
<?php page_footer();
