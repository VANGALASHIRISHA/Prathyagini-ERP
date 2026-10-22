<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require __DIR__.'/../app/online_fulfillment.php';
require_permission('dispatch_online_orders');
$id=(int)($_GET['id']??$_POST['id']??0);$err='';$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$action=$_POST['action']??'';
 try{
  if(!$id||!can_access_online_order($id)) throw new Exception('Order outside your location scope.');
  db()->beginTransaction();
  $st=db()->prepare('SELECT * FROM online_orders WHERE id=? FOR UPDATE');$st->execute([$id]);$o=$st->fetch();if(!$o)throw new Exception('Order not found.');
  if($action==='delivered'){
   if($o['status']!=='DISPATCHED')throw new Exception('Only DISPATCHED orders can be marked delivered.');
   db()->prepare("UPDATE online_orders SET status='DELIVERED',updated_at=NOW() WHERE id=?")->execute([$id]);
   $msg='Order marked DELIVERED.';
  }elseif($action==='return_in_transit'){
   if(!in_array($o['status'],['DISPATCHED','DELIVERED'],true))throw new Exception('Return can start only after dispatch/delivery.');
   db()->prepare("UPDATE online_orders SET status='RETURN_IN_TRANSIT',updated_at=NOW() WHERE id=?")->execute([$id]);
   $msg='Return/RTO marked in transit. Godown can now receive it by barcode.';
  } else throw new Exception('Invalid action.');
  db()->commit();audit('ONLINE_POST_DISPATCH_'.strtoupper($action),'online_order',$id);
  publish_event('ONLINE_POST_DISPATCH','Online order status changed',$o['order_no'].' · '.$msg,['super_admin'],'online_order',$id,null);
  publish_scoped_event('ONLINE_POST_DISPATCH','Online order status changed',$o['order_no'].' · '.$msg,['manager','godown'],'online_order',$id);
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}
$ids=is_super_admin()?[]:assigned_location_ids();$scope='';$args=[];
if(!is_super_admin()){if(!$ids)$scope=' AND 1=0';else{$ph=implode(',',array_fill(0,count($ids),'?'));$scope=" AND NOT EXISTS(SELECT 1 FROM online_order_allocations x WHERE x.online_order_id=o.id AND x.location_id NOT IN ($ph))";$args=$ids;}}
$st=db()->prepare("SELECT o.* FROM online_orders o WHERE o.status IN ('DISPATCHED','DELIVERED','RETURN_IN_TRANSIT','RETURNED')".$scope.' ORDER BY o.id DESC LIMIT 500');$st->execute($args);$rows=$st->fetchAll();
page_header('Online Delivery / RTO Control');if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
?>
<table><tr><th>Order</th><th>Customer</th><th>Courier / AWB</th><th>Status</th><th>Action</th></tr>
<?php foreach($rows as $r):?><tr><td><?=e($r['order_no'])?></td><td><?=e($r['customer_name'])?></td><td><?=e(trim(($r['courier']??'').' '.($r['awb']??'')))?></td><td><b><?=e($r['status'])?></b></td><td>
<?php if($r['status']==='DISPATCHED'):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="delivered"><button class="btn">Mark Delivered</button></form><?php endif;?>
<?php if(in_array($r['status'],['DISPATCHED','DELIVERED'],true)):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="return_in_transit"><button class="btn secondary">Start Return/RTO</button></form><?php endif;?>
<?php if($r['status']==='RETURN_IN_TRANSIT'):?><a class="btn" href="online_return_receive.php?id=<?=$r['id']?>">Receive Return</a><?php endif;?>
</td></tr><?php endforeach;?></table>
<?php page_footer();
