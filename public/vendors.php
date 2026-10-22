<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
pm_require_view();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'active';
$where = ['1=1']; $args = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(s.party_name LIKE ? OR s.party_code LIKE ? OR s.city LIKE ? OR s.area LIKE ? OR s.gstin LIKE ? OR s.mobile LIKE ?)';
    array_push($args, $like, $like, $like, $like, $like, $like);
}
if ($status === 'active') $where[] = 's.active=1';
elseif ($status === 'inactive') $where[] = 's.active=0';

$sql = "SELECT s.*,
  (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id=s.id AND p.bill_status NOT IN ('DRAFT','CANCELLED')) bills,
  (SELECT COALESCE(SUM(p.grand_total),0) FROM purchases p WHERE p.supplier_id=s.id AND p.bill_status NOT IN ('DRAFT','CANCELLED')) total_purchases,
  (SELECT COALESCE(SUM(l.credit-l.debit),0) FROM supplier_ledger l WHERE l.supplier_id=s.id) outstanding
  FROM suppliers s WHERE " . implode(' AND ', $where) . ' ORDER BY s.party_name LIMIT 500';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

if (($_GET['export'] ?? '') === 'xlsx') {
    require __DIR__ . '/../app/xlsx.php';
    $out = [['Vendor Code', 'Vendor', 'Contact', 'Mobile', 'Area', 'City', 'State', 'GSTIN', 'Status', 'Bills', 'Total Purchases', 'Outstanding']];
    foreach ($rows as $r) $out[] = [$r['party_code'], $r['party_name'], $r['contact_person'], $r['mobile'], $r['area'], $r['city'], $r['state'], $r['gstin'], $r['active'] ? 'Active' : 'Inactive', (int)$r['bills'], (float)$r['total_purchases'], (float)$r['outstanding']];
    xlsx_download('vendors_' . date('Ymd_His') . '.xlsx', $out, 'Vendors');
}

page_header('Vendor Register');
?>
<form method="get" class="actions no-print">
  <input name="q" value="<?= e($q) ?>" placeholder="Name, code, area, city, GSTIN, mobile">
  <select name="status">
    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
  </select>
  <button class="btn">Search</button>
  <?php if (has_permission('manage_suppliers')): ?><a class="btn" href="vendor_form.php">+ Add Vendor</a><?php endif; ?>
  <a class="btn secondary" href="?<?= e(http_build_query(['q' => $q, 'status' => $status, 'export' => 'xlsx'])) ?>">Export Excel</a>
</form><br>
<div style="overflow-x:auto"><table>
<tr><th>Code</th><th>Vendor</th><th>Area / City</th><th>GSTIN</th><th>Mobile</th><th>Bills</th><th>Total Purchases</th><th>Outstanding</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= e($r['party_code']) ?></td>
  <td><b><?= e($r['party_name']) ?></b><?= $r['contact_person'] ? '<div class="muted">' . e($r['contact_person']) . '</div>' : '' ?></td>
  <td><?= e(trim(($r['area'] ?? '') . ' ' . ($r['city'] ?? ''))) ?></td>
  <td><?= e($r['gstin']) ?></td>
  <td><?= e($r['mobile']) ?></td>
  <td><?= (int)$r['bills'] ?></td>
  <td>₹<?= e(number_format((float)$r['total_purchases'], 2)) ?></td>
  <td><b>₹<?= e(number_format((float)$r['outstanding'], 2)) ?></b></td>
  <td><?= pm_status_badge($r['active'] ? 'ACTIVE' : 'CANCELLED') ?></td>
  <td><a class="btn secondary" href="vendor_view.php?id=<?= (int)$r['id'] ?>">View</a>
    <?php if (has_permission('manage_suppliers')): ?><a class="btn secondary" href="vendor_form.php?id=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="10" class="muted">No vendors match.</td></tr><?php endif; ?>
</table></div>
<p class="muted">Outstanding = everything owed to the vendor (all bills, old and new) minus payments. Deactivating a vendor keeps all their history.</p>
<?php page_footer();
