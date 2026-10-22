<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require __DIR__.'/../app/online_fulfillment.php';
require_permission('process_online_returns');
$id=(int)($_GET['id']??$_POST['id']??0);$err='';$msg='';
if(!$id){
 $ids=is_super_admin()?[]:assigned_location_ids();$args=[];$scope='';
 if(!is_super_admin()){if(!$ids)$scope=' AND 1=0';else{$ph=implode(',',array_fill(0,count($ids),'?'));$scope=" AND NOT EXISTS(SELECT 1 FROM online_order_allocations x WHERE x.online_order_id=o.id AND x.location_id NOT IN ($ph))";$args=$ids;}}
 $st=db()->prepare("SELECT o.* FROM online_orders o WHERE o.status='RETURN_IN_TRANSIT'".$scope.' ORDER BY o.id');$st->execute($args);$queue=$st->fetchAll();
 page_header('Return / RTO Receive Queue');echo '<div class="panel"><b>Flow:</b> Courier return arrives → open order → scan every returned garment → stock restores only after barcode verification.</div><br><table><tr><th>Order</th><th>Customer</th><th>Courier/AWB</th><th></th></tr>';foreach($queue as $q)echo '<tr><td>'.e($q['order_no']).'</td><td>'.e($q['customer_name']).'</td><td>'.e(trim(($q['courier']??'').' '.($q['awb']??''))).'</td><td><a class="btn" href="online_return_receive.php?id='.(int)$q['id'].'">Receive</a></td></tr>';echo '</table>';page_footer();exit;
}
if(!can_access_online_order($id)){http_response_code(403);exit('Order outside your assigned locations.');}
$st=db()->prepare('SELECT * FROM online_orders WHERE id=?');$st->execute([$id]);$o=$st->fetch();if(!$o)exit('Order not found.');
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();$barcode=trim($_POST['barcode']??'');$condition=($_POST['condition']??'')==='DAMAGED'?'DAMAGED':'GOOD';$reason=mb_substr(trim((string)($_POST['reason']??'')),0,150);
 try{
  if($barcode==='')throw new Exception('Scan returned item barcode.');
  if($condition==='DAMAGED'&&$reason==='')throw new Exception('Enter what is wrong with the damaged item (for example: torn, stained, missing tag).');
  db()->beginTransaction();
  $st=db()->prepare('SELECT status,order_no FROM online_orders WHERE id=? FOR UPDATE');$st->execute([$id]);$ord=$st->fetch();if(!$ord||$ord['status']!=='RETURN_IN_TRANSIT')throw new Exception('Order is not ready for return receiving.');
  $sql="SELECT a.*,ps.serial_barcode,ps.status serial_status,b.batch_barcode,p.product_barcode,p.serial_tracking,p.sku,p.product_name FROM online_order_allocations a LEFT JOIN product_serials ps ON ps.id=a.serial_id JOIN batches b ON b.id=a.batch_id JOIN products p ON p.id=a.product_id WHERE a.online_order_id=? AND a.status='DISPATCHED' AND a.returned_qty<a.quantity AND (ps.serial_barcode=? OR (a.serial_id IS NULL AND (b.batch_barcode=? OR p.product_barcode=?))) ORDER BY a.id LIMIT 1 FOR UPDATE";
  $st=db()->prepare($sql);$st->execute([$id,$barcode,$barcode,$barcode]);$a=$st->fetch();if(!$a){$st=db()->prepare("SELECT 1 FROM online_order_allocations a LEFT JOIN product_serials ps ON ps.id=a.serial_id JOIN batches b ON b.id=a.batch_id JOIN products p ON p.id=a.product_id WHERE a.online_order_id=? AND a.status IN ('DISPATCHED','RETURNED') AND (ps.serial_barcode=? OR (a.serial_id IS NULL AND (b.batch_barcode=? OR p.product_barcode=?))) LIMIT 1");$st->execute([$id,$barcode,$barcode,$barcode]);throw new Exception($st->fetchColumn()?'ALREADY RECEIVED — this item was already received for this return.':'WRONG ITEM — this barcode was not sent in this order.');}
  $q=1.0;if((int)$a['serial_tracking']===1){if($a['serial_status']!=='SOLD')throw new Exception('Serialized item is not in SOLD state. Stop and audit.');}
  $st=db()->prepare('SELECT qty FROM inventory_balances WHERE product_id=? AND batch_id=? AND location_id=? FOR UPDATE');$st->execute([$a['product_id'],$a['batch_id'],$a['location_id']]);if(!$st->fetch())throw new Exception('Inventory row missing for return location.');
  $remark=$ord['order_no'].' · Return/RTO received · '.$condition.($reason!==''?' · '.$reason:'');
  db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_in,reference_type,reference_id,remarks,created_by) VALUES('ONLINE_RETURN',?,?,?,?, 'ONLINE_ORDER',?,?,?)")->execute([$a['product_id'],$a['batch_id'],$a['location_id'],$q,$id,$remark,current_user()['id']]);
  if($condition==='GOOD'){
   db()->prepare('UPDATE inventory_balances SET qty=qty+? WHERE product_id=? AND batch_id=? AND location_id=?')->execute([$q,$a['product_id'],$a['batch_id'],$a['location_id']]);
   db()->prepare('UPDATE batches SET qty_available=qty_available+? WHERE id=?')->execute([$q,$a['batch_id']]);
   if($a['serial_id'])db()->prepare("UPDATE product_serials SET status='AVAILABLE',location_id=? WHERE id=? AND status='SOLD'")->execute([$a['location_id'],$a['serial_id']]);
  }else{
   // Damaged return: received (ledger in) and written off as DAMAGE in the same step, so sellable stock does not change.
   db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_out,reference_type,reference_id,remarks,created_by) VALUES('DAMAGE',?,?,?,?, 'ONLINE_ORDER',?,?,?)")->execute([$a['product_id'],$a['batch_id'],$a['location_id'],$q,$id,$remark,current_user()['id']]);
   if($a['serial_id'])db()->prepare("UPDATE product_serials SET status='DAMAGED',location_id=? WHERE id=? AND status='SOLD'")->execute([$a['location_id'],$a['serial_id']]);
  }
  $new=(float)$a['returned_qty']+$q;$status=$new+0.0001>=(float)$a['quantity']?'RETURNED':'DISPATCHED';
  db()->prepare('UPDATE online_order_allocations SET returned_qty=?,status=? WHERE id=?')->execute([$new,$status,$a['id']]);
  $st=db()->prepare("SELECT COALESCE(SUM(quantity),0),COALESCE(SUM(returned_qty),0) FROM online_order_allocations WHERE online_order_id=? AND status<>'RELEASED'");$st->execute([$id]);[$total,$returned]=array_values($st->fetch());$complete=(float)$returned+0.0001>=(float)$total;
  if($complete)db()->prepare("UPDATE online_orders SET status='RETURNED',updated_at=NOW() WHERE id=?")->execute([$id]);
  db()->commit();audit('ONLINE_RETURN_RECEIVE','online_order',$id,['barcode'=>$barcode,'condition'=>$condition,'reason'=>$reason]);$msg=($condition==='GOOD'?'RETURN RECEIVED · back in stock · ':'DAMAGED RETURN RECORDED · not added to sellable stock · ').$a['sku'].' · '.$a['product_name'];
  if($complete){publish_event('ONLINE_RETURN_COMPLETE','Online return completed',$ord['order_no'].' · return fully received',['super_admin'],'online_order',$id,null);publish_scoped_event('ONLINE_RETURN_COMPLETE','Online return completed',$ord['order_no'].' · return fully received',['manager','godown'],'online_order',$id);}
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}
$st=db()->prepare("SELECT a.*,p.sku,p.product_name,p.color,p.size,p.product_image,ps.serial_barcode,b.batch_barcode,l.code location_code,l.rack,l.shelf_bin FROM online_order_allocations a JOIN products p ON p.id=a.product_id LEFT JOIN product_serials ps ON ps.id=a.serial_id JOIN batches b ON b.id=a.batch_id JOIN locations l ON l.id=a.location_id WHERE a.online_order_id=? AND a.status<>'RELEASED' ORDER BY a.id");$st->execute([$id]);$rows=$st->fetchAll();
page_header('Receive Return / RTO · '.e($o['order_no']));if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
?>
<form method="post" class="panel"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><div class="row"><div><label>Condition of this item</label><select name="condition"><option value="GOOD">GOOD · resellable, put back in stock</option><option value="DAMAGED">DAMAGED · keep aside, not sellable</option></select></div><div><label>Return reason / damage note</label><input name="reason" maxlength="150" placeholder="Customer refused, size issue, torn, stained…"></div></div><br><label>Scan each returned item before putting it back in stock</label><input name="barcode" class="scanbox" data-scanner autofocus required><br><br><button class="btn">RECEIVE RETURN</button></form><br>
<table><tr><th>Photo</th><th>Product</th><th>Barcode</th><th>Return Rack/Bin</th><th>Qty</th><th>Received</th></tr><?php foreach($rows as $r):?><tr><td><?php if($r['product_image']):?><img src="<?=e($r['product_image'])?>" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:8px"><?php endif;?></td><td><?=e($r['sku'].' · '.$r['product_name'].' '.$r['color'].' '.$r['size'])?></td><td><?=e($r['serial_barcode']?:$r['batch_barcode'])?></td><td><?=e($r['location_code'].' · '.$r['rack'].'/'.$r['shelf_bin'])?></td><td><?=e($r['quantity'])?></td><td><?=e($r['returned_qty'])?></td></tr><?php endforeach;?></table>
<div class="notice"><b>Safety:</b> return stock is restored only after barcode verification. This page does not move/refund money; refund settlement remains an external payment process.</div>
<?php page_footer();
