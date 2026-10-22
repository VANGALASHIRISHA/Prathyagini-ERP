<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php';
$count=(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if($count>0){ http_response_code(403); exit('Initial admin already exists. Delete/disable this file on production.'); }
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check(); $name=trim($_POST['name']??''); $username=trim($_POST['username']??''); $password=$_POST['password']??'';
 if(strlen($name)<2||strlen($username)<4||strlen($password)<10){$err='Name, username (4+) and password (10+) required.';}
 else { $st=db()->prepare("INSERT INTO users(name,username,password_hash,role,active) VALUES(?,?,?,'super_admin',1)"); $st->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT)]); redirect('login.php'); }
}
page_header('Create First Super Admin'); if($err) echo '<div class="notice error">'.e($err).'</div>'; ?>
<div class="panel" style="max-width:560px"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>Name</label><input name="name" required><br><br><label>Username</label><input name="username" required><br><br><label>Password (minimum 10 characters)</label><input type="password" name="password" minlength="10" required><br><br><button class="btn">Create Super Admin</button></form></div>
<?php page_footer();
