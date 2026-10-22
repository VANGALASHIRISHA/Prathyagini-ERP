<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/layout.php';
login_required();
if(!is_super_admin()){http_response_code(403);exit('Super Admin only');}
$msg='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    [$ok,$text]=license_refresh_now();
    if($ok)$msg=$text;else$err=$text;
}
$s=license_effective_state(false);
$p=$s['payload']??[];
$row=license_state_row();
page_header('License Status');
if($msg)echo '<div class="notice">'.e($msg).'</div>';
if($err)echo '<div class="notice error">'.e($err).'</div>';
?>
<div class="panel">
<table>
<tr><th>Status</th><td><b><?=e($s['status'])?></b></td></tr>
<tr><th>Shop</th><td><?=e($p['shop_name']??'')?></td></tr>
<tr><th>Customer Code</th><td><?=e($p['customer_code']??'')?></td></tr>
<tr><th>Plan</th><td><?=e($p['plan']??'')?></td></tr>
<tr><th>Start</th><td><?=e($p['starts_at']??'')?></td></tr>
<tr><th>Expiry</th><td><?=e($p['expires_at']??'')?></td></tr>
<tr><th>Grace Until</th><td><?=e($p['grace_until']??'')?></td></tr>
<tr><th>Max Users</th><td><?=e($p['max_users']??'')?></td></tr>
<tr><th>Max Branches (future metadata)</th><td><?=e($p['max_branches']??'')?></td></tr>
<tr><th>Features</th><td><?=e(implode(', ',$p['features']??[]))?></td></tr>
<tr><th>Installation ID</th><td><?=e(license_installation_id())?></td></tr>
<tr><th>Last Check</th><td><?=e($row['last_check_at']??'')?></td></tr>
<tr><th>Last Successful Check</th><td><?=e($row['last_ok_at']??'')?></td></tr>
<tr><th>Last Error</th><td><?=e($row['last_error']??'')?></td></tr>
</table><br>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="btn">Check / Refresh License Now</button></form>
</div>
<?php page_footer(); ?>
