<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php';
$err='';$info='';
if(current_user()){
  $expected=panel_host_role();
  if($expected!==null && (current_user()['role']??'')!==$expected){
    $right=panel_url_for_role((string)current_user()['role']);
    $err='This address is only for the '.panel_host_title().' panel.'.($right?' Use '.$right:'');
  } else redirect(role_home_url());
}
if(isset($_GET['expired'])) $info='Session expired. Please login again.';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check(); $u=trim($_POST['username']??''); $p=$_POST['password']??''; $ip=$_SERVER['REMOTE_ADDR']??'';
 db()->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)");
 $st=db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at >= (NOW()-INTERVAL 15 MINUTE) AND (username=? OR ip_address=?)");
 $st->execute([$u,$ip]);
 if((int)$st->fetchColumn()>=5){$err='Too many failed attempts. Login is temporarily locked for security.';}
 else {
  $st=db()->prepare('SELECT * FROM users WHERE username=? AND active=1 LIMIT 1'); $st->execute([$u]); $row=$st->fetch();
  $ok=$row && password_verify($p,$row['password_hash']);
  db()->prepare('INSERT INTO login_attempts(username,ip_address,success) VALUES(?,?,?)')->execute([$u,$ip,$ok?1:0]);
  if($ok){
    $expected=panel_host_role();
    if($expected!==null && $row['role']!==$expected){
      $right=panel_url_for_role((string)$row['role']);
      $err='This address is only for the '.panel_host_title().' panel.'.($right?' Use '.$right:'');
    } else {
      session_regenerate_id(true); $_SESSION['user']=['id'=>(int)$row['id'],'name'=>$row['name'],'username'=>$row['username'],'role'=>$row['role'],'auth_version'=>(int)$row['auth_version']];$_SESSION['last_activity']=time();
      db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$row['id']]); audit('LOGIN','user',(int)$row['id']); redirect(role_home_url($row['role']));
    }
  } else {$err='Invalid username or password.';}
 }
}
page_header(panel_host_role() ? panel_host_title().' login' : 'Login'); if($info)echo '<div class="notice">'.e($info).'</div>'; if($err) echo '<div class="notice error">'.e($err).'</div>'; ?>
<div class="panel" style="max-width:480px;margin:auto"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Username</label><input name="username" autocomplete="username" required><br><br><label>Password</label><input type="password" name="password" autocomplete="current-password" required><br><br><button class="btn" style="width:100%">Login</button></form><p style="margin-top:14px;text-align:right"><a href="forgot_password.php">Forgot password?</a></p></div>
<?php page_footer();
