<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/layout.php';
login_required();
if(!is_super_admin()){http_response_code(403);exit('Super Admin only');}

$state=license_effective_state(false);
$msg='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $key=trim($_POST['license_key']??'');
    if($key==='')$err='Enter license key.';
    else{
        [$ok,$text]=license_activate_key($key);
        if($ok){$msg=$text;$state=license_effective_state(false);}
        else $err=$text;
    }
}
page_header('ERP License Activation');
if($msg)echo '<div class="notice">'.e($msg).'</div>';
if($err)echo '<div class="notice error">'.e($err).'</div>';
?>
<div class="panel">
<p><b>Installation ID:</b> <?=e(license_installation_id())?></p>
<p><b>Current Status:</b> <?=e($state['status'])?></p>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>Shop License Key</label>
<input name="license_key" autocomplete="off" placeholder="PRAT-XXXXXX-XXXXXX-XXXXXX-XXXXXX" required>
<br><br><button class="btn">Activate License</button>
</form>
</div>
<?php page_footer(); ?>
