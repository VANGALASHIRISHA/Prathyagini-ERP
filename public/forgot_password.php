<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php';
if(current_user()) redirect(role_home_url());
$info='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check(); $u=trim($_POST['username']??'');
 if($u===''){$err='Enter your username.';}
 else {
  $st=db()->prepare('SELECT id,name,username FROM users WHERE username=? AND active=1 LIMIT 1'); $st->execute([$u]); $row=$st->fetch();
  if($row){
   $st=db()->prepare("SELECT 1 FROM workflow_events WHERE event_key='PASSWORD_RESET_REQUEST' AND entity_type='user' AND entity_id=? AND created_at>=NOW()-INTERVAL 15 MINUTE LIMIT 1");
   $st->execute([$row['id']]);
   if(!$st->fetchColumn()){
    publish_event('PASSWORD_RESET_REQUEST','Password reset requested',$row['name'].' ('.$row['username'].') asked for a password reset. Reset it from Users > Edit User.',['super_admin'],'user',(int)$row['id'],null);
   }
  }
  // Same message either way, so usernames can't be guessed from the response.
  $info='If that account exists, your Super Admin has been notified and will reset your password for you.';
 }
}
page_header('Forgot password'); if($info)echo '<div class="notice success">'.e($info).'</div>'; if($err) echo '<div class="notice error">'.e($err).'</div>'; ?>
<div class="panel" style="max-width:480px;margin:auto">
<p>Enter your username. Your Super Admin will be notified to reset your password — this app does not send reset emails.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Username</label><input name="username" autocomplete="username" required><br><br><button class="btn" style="width:100%">Notify Super Admin</button></form>
<p style="margin-top:14px"><a href="login.php">Back to login</a></p>
</div>
<?php page_footer();
