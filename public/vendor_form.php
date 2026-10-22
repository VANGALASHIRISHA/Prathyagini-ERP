<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/purchase_module.php';
require_permission('manage_suppliers');

$id = (int)($_GET['id'] ?? 0);
$v = ['party_code' => '', 'party_name' => '', 'vendor_type' => '', 'contact_person' => '', 'mobile' => '', 'email' => '', 'address' => '', 'area' => '', 'city' => '', 'district' => '', 'state' => '', 'state_code' => '', 'pincode' => '', 'gstin' => '', 'remarks' => '', 'active' => 1];
if ($id) {
    $st = db()->prepare('SELECT * FROM suppliers WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { http_response_code(404); exit('Vendor not found'); }
    $v = array_merge($v, $row);
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $f = [];
        foreach (['party_code', 'party_name', 'vendor_type', 'contact_person', 'mobile', 'email', 'address', 'area', 'city', 'district', 'state', 'state_code', 'pincode', 'gstin', 'remarks'] as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
        $f['gstin'] = strtoupper($f['gstin']);
        $active = isset($_POST['active']) ? 1 : 0;
        if ($f['party_name'] === '') throw new Exception('Vendor name is required.');
        if ($f['mobile'] !== '' && !preg_match('/^[0-9+\- ]{7,15}$/', $f['mobile'])) throw new Exception('Mobile number looks invalid.');
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) throw new Exception('Email address looks invalid.');
        if ($f['gstin'] !== '' && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $f['gstin'])) throw new Exception('GSTIN must be 15 characters in the standard format.');
        if ($f['state_code'] === '' && $f['gstin'] !== '') $f['state_code'] = substr($f['gstin'], 0, 2);
        if ($f['state_code'] !== '' && !preg_match('/^\d{2}$/', $f['state_code'])) throw new Exception('State code must be 2 digits (for example 37).');
        if ($f['gstin'] !== '' && $f['state_code'] !== '' && substr($f['gstin'], 0, 2) !== $f['state_code']) throw new Exception('The first two digits of the GSTIN do not match the state code.');
        if ($f['pincode'] !== '' && !preg_match('/^\d{6}$/', $f['pincode'])) throw new Exception('PIN code must be 6 digits.');
        if ($f['vendor_type'] !== '' && !in_array($f['vendor_type'], PM_VENDOR_TYPES, true)) throw new Exception('Choose a valid vendor type.');
        db()->beginTransaction();
        $vals = [$f['party_name'], $f['vendor_type'] ?: null, $f['contact_person'] ?: null, $f['mobile'] ?: null, $f['email'] ?: null, $f['address'] ?: null, $f['area'] ?: null, $f['city'] ?: null, $f['district'] ?: null, $f['state'] ?: null, $f['state_code'] ?: null, $f['pincode'] ?: null, $f['gstin'] ?: null, $f['remarks'] ?: null, $active];
        if ($id) {
            if ($f['party_code'] === '') throw new Exception('Vendor code is required.');
            db()->prepare('UPDATE suppliers SET party_name=?,vendor_type=?,contact_person=?,mobile=?,email=?,address=?,area=?,city=?,district=?,state=?,state_code=?,pincode=?,gstin=?,remarks=?,active=?,party_code=?,updated_at=NOW() WHERE id=?')
                ->execute([...$vals, $f['party_code'], $id]);
            audit('VENDOR_UPDATE', 'supplier', $id, ['active' => $active]);
        } else {
            if ($f['party_code'] === '') {
                $next = (int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM suppliers')->fetchColumn();
                $f['party_code'] = 'VND-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
            }
            db()->prepare('INSERT INTO suppliers(party_name,vendor_type,contact_person,mobile,email,address,area,city,district,state,state_code,pincode,gstin,remarks,active,party_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$vals, $f['party_code']]);
            $id = (int)db()->lastInsertId();
            audit('VENDOR_CREATE', 'supplier', $id, ['code' => $f['party_code']]);
        }
        db()->commit();
        redirect('vendor_view.php?id=' . $id . '&saved=1');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $err = user_error_message($e, 'This vendor code already exists. Use a different code.');
        $v = array_merge($v, $_POST, ['active' => isset($_POST['active']) ? 1 : 0]);
    }
}
page_header($id ? 'Edit Vendor' : 'Add Vendor');
if ($err) echo '<div class="notice error">' . e($err) . '</div>';
?>
<form method="post" class="panel">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<div class="row3">
  <div><label>Vendor Code <span class="muted">(blank = automatic)</span></label><input name="party_code" value="<?= e($v['party_code']) ?>" maxlength="40"></div>
  <div><label>Vendor / Company Name *</label><input name="party_name" value="<?= e($v['party_name']) ?>" required maxlength="160"></div>
  <div><label>Vendor Type</label><select name="vendor_type"><option value="">--</option><?php foreach (PM_VENDOR_TYPES as $t): ?><option <?= $v['vendor_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
</div>
<div class="row3">
  <div><label>Contact Person</label><input name="contact_person" value="<?= e($v['contact_person']) ?>" maxlength="120"></div>
  <div><label>Mobile</label><input name="mobile" value="<?= e($v['mobile']) ?>" maxlength="30"></div>
  <div><label>Email</label><input name="email" type="email" value="<?= e($v['email']) ?>" maxlength="150"></div>
</div>
<label>Complete Address</label><textarea name="address" rows="2"><?= e($v['address']) ?></textarea>
<div class="row3">
  <div><label>Area</label><input name="area" value="<?= e($v['area']) ?>" maxlength="120"></div>
  <div><label>City</label><input name="city" value="<?= e($v['city']) ?>" maxlength="100"></div>
  <div><label>District</label><input name="district" value="<?= e($v['district']) ?>" maxlength="100"></div>
</div>
<div class="row3">
  <div><label>State</label><input name="state" value="<?= e($v['state']) ?>" maxlength="100"></div>
  <div><label>State Code (GST) <span class="muted">e.g. 37</span></label><input name="state_code" value="<?= e($v['state_code']) ?>" maxlength="2"></div>
  <div><label>PIN Code</label><input name="pincode" value="<?= e($v['pincode']) ?>" maxlength="6"></div>
</div>
<div class="row3">
  <div><label>GSTIN</label><input name="gstin" value="<?= e($v['gstin']) ?>" maxlength="15"></div>
  <div><label>Remarks</label><input name="remarks" value="<?= e($v['remarks']) ?>" maxlength="500"></div>
  <div><label>Status</label><label style="font-weight:400"><input type="checkbox" name="active" value="1" <?= $v['active'] ? 'checked' : '' ?>> Active (untick to deactivate)</label></div>
</div>
<div class="actions"><button class="btn">Save Vendor</button><a class="btn secondary" href="vendors.php">Cancel</a></div>
<p class="muted">The State Code decides GST on purchases: same state as your company = CGST + SGST, different state = IGST. It is filled from the GSTIN if left blank.</p>
</form>
<?php page_footer();
