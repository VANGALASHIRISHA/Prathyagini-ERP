<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_super_admin();
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$action=$_POST['action']??'add';
 try{
  if($action==='add'){$name=trim($_POST['name']??'');if($name==='')throw new Exception('Category name required.');$st=db()->prepare('INSERT INTO categories(name,active) VALUES(?,1)');$st->execute([$name]);audit('CATEGORY_CREATE','category',(int)db()->lastInsertId());}
  elseif($action==='toggle'){$id=(int)$_POST['id'];db()->prepare('UPDATE categories SET active=1-active WHERE id=?')->execute([$id]);audit('CATEGORY_TOGGLE','category',$id);}
  redirect('categories.php');
 }catch(Throwable $e){$err='Could not save category. Name may already exist.';}
}
$rows=db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();page_header('Category Master');if($err)echo '<div class="notice error">'.e($err).'</div>'; ?>
<form method="post" class="panel actions"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add"><input name="name" placeholder="Category name" required><button class="btn">Add Category</button></form><br>
<table><tr><th>Category</th><th>Status</th><th>Action</th></tr><?php foreach($rows as $r):?><tr><td><?=e($r['name'])?></td><td><?= $r['active']?'Active':'Inactive' ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn secondary"><?=$r['active']?'Deactivate':'Activate'?></button></form></td></tr><?php endforeach;?></table><?php page_footer();
