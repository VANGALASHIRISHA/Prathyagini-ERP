<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require __DIR__.'/../app/online_fulfillment.php'; require_permission('manage_online_orders');
$err='';$allowed=online_allowed_locations();$locs=[];
if($allowed){$ph=implode(',',array_fill(0,count($allowed),'?'));$st=db()->prepare("SELECT id,code,name,warehouse,rack,shelf_bin FROM locations WHERE active=1 AND id IN ($ph) ORDER BY code");$st->execute($allowed);$locs=$st->fetchAll();}
$warehouses=array_values(array_unique(array_filter(array_map(fn($l)=>trim((string)$l['warehouse']),$locs))));
$products=db()->query("SELECT id,sku,product_barcode,product_name,color,size,serial_tracking FROM products WHERE active=1 ORDER BY product_name,sku LIMIT 3000")->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();try{
 if(!form_once_claim('online_order_new'))throw new Exception('This order form was already submitted. Check Online Orders before creating it again.');
 $source=$_POST['source']??'MANUAL';$scopeChoice=(string)($_POST['fulfill_scope']??'AUTO');if($scopeChoice==='AUTO'&&count($warehouses)>1)throw new Exception('Multiple fulfilment warehouses are enabled. Choose one warehouse to avoid cross-branch allocation.');$useLocs=$allowed;if(str_starts_with($scopeChoice,'LOC|')){$specific=(int)substr($scopeChoice,4);$useLocs=online_allowed_locations($specific);}elseif(str_starts_with($scopeChoice,'WH|')){$wh=substr($scopeChoice,3);$useLocs=array_values(array_map('intval',array_column(array_filter($locs,fn($x)=>$x['warehouse']===$wh),'id')));}if(!$useLocs)throw new Exception('Selected fulfilment warehouse/location is not allowed or not enabled.');
 $items=[];$pids=$_POST['product_id']??[];$qtys=$_POST['quantity']??[];foreach($pids as $i=>$pid){$pid=(int)$pid;$q=(float)($qtys[$i]??0);if($pid>0&&$q>0)$items[]=['product_id'=>$pid,'quantity'=>$q];}if(!$items)throw new Exception('Add at least one order item.');
 db()->beginTransaction();$oid=online_create_and_reserve(['order_no'=>trim($_POST['order_no']??''),'source'=>$source,'external_order_id'=>trim($_POST['external_order_id']??''),'customer_name'=>trim($_POST['customer_name']??''),'customer_mobile'=>trim($_POST['customer_mobile']??''),'shipping_address'=>trim($_POST['shipping_address']??''),'order_amount'=>(float)($_POST['order_amount']??0),'payment_status'=>$_POST['payment_status']??'PENDING','notes'=>trim($_POST['notes']??'')],$items,$useLocs,(int)current_user()['id']);db()->commit();
 audit('ONLINE_ORDER_CREATE','online_order',$oid,['source'=>$source]);publish_event('ONLINE_ORDER_NEW','Online order allocated',online_order_number($oid).' · stock reserved',['super_admin'],'online_order',$oid,null);publish_scoped_event('ONLINE_ORDER_PICK','Online order ready to pick',online_order_number($oid).' · scan rack then item',['manager','godown'],'online_order',$oid);redirect('online_orders.php?created='.$oid);
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=user_error_message($e,'This order number / external order ID already exists. Open it from Online Orders instead of creating it again.');}}
page_header('New Online Order · Reserve Stock');if($err)echo '<div class="notice error">'.e($err).'</div>';
$scanMap=[];foreach($products as $p){$scanMap[strtoupper($p['sku'])]=(int)$p['id'];if($p['product_barcode']!=='')$scanMap[strtoupper($p['product_barcode'])]=(int)$p['id'];} ?>
<form class="panel" id="orderScanForm" autocomplete="off"><label for="orderScan">Scan product barcode / SKU to add a line</label><input id="orderScan" data-scanner placeholder="Scan or type barcode / SKU and press Enter"><div class="muted" id="orderScanMsg"></div></form>
<form method="post" class="panel" id="orderForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="form_once" value="<?=e(form_once_token('online_order_new'))?>"><div class="row3"><div><label>Order No.</label><input name="order_no" placeholder="Auto if blank"></div><div><label>Source</label><select name="source"><option>MANUAL</option><option>WEBSITE</option><option>MARKETPLACE</option></select></div><div><label>External Order ID</label><input name="external_order_id"></div></div><br><div class="row3"><div><label>Customer</label><input name="customer_name"></div><div><label>Mobile</label><input name="customer_mobile"></div><div><label>Payment</label><select name="payment_status"><option>PAID</option><option>COD</option><option>PENDING</option></select></div></div><br><div class="row"><div><label>Shipping Address</label><input name="shipping_address"></div><div><label>Order Amount</label><input type="number" step="0.01" min="0" name="order_amount" value="0"></div></div><br><div class="row"><div><label>Fulfil From</label><select name="fulfill_scope"><option value="AUTO">AUTO · All my enabled fulfilment locations</option><?php foreach($warehouses as $wh):?><option value="WH|<?=e($wh)?>">WAREHOUSE · <?=e($wh)?></option><?php endforeach;?><?php foreach($locs as $l):?><option value="LOC|<?=$l['id']?>"><?=e($l['code'].' · '.$l['name'].' · '.$l['rack'].'/'.$l['shelf_bin'])?></option><?php endforeach;?></select></div><div><label>Notes</label><input name="notes"></div></div><br>
<table id="orderLines"><thead><tr><th>Product</th><th>Qty</th><th></th></tr></thead><tbody></tbody></table><br><div class="actions"><button type="button" class="btn secondary" onclick="addLine()">+ Add Item</button><button class="btn">CREATE ORDER & RESERVE STOCK</button></div></form>
<template id="lineTpl"><tr><td><select name="product_id[]" required><option value="">-- Select --</option><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=e($p['sku'].' · '.$p['product_name'].' · '.$p['color'].' '.$p['size'].((int)$p['serial_tracking']?' · UNIQUE':' · BATCH'))?></option><?php endforeach;?></select></td><td><input type="number" name="quantity[]" min="1" step="1" value="1" required></td><td><button type="button" class="btn danger" onclick="this.closest('tr').remove()">×</button></td></tr></template>
<script>function addLine(){document.querySelector('#orderLines tbody').appendChild(document.getElementById('lineTpl').content.cloneNode(true));}addLine();
(function(){
  const map=<?=json_encode($scanMap,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const form=document.getElementById('orderScanForm'),input=document.getElementById('orderScan'),msg=document.getElementById('orderScanMsg');
  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    const code=input.value.trim().toUpperCase();input.value='';
    if(!code)return;
    const pid=map[code];
    if(!pid){msg.textContent='Not found: '+code;return;}
    const selects=[...document.querySelectorAll('#orderLines select[name="product_id[]"]')];
    let sel=selects.find(s=>s.value===String(pid));
    if(sel){const q=sel.closest('tr').querySelector('input[name="quantity[]"]');q.value=(parseInt(q.value,10)||0)+1;}
    else{sel=selects.find(s=>s.value==='');if(!sel){addLine();const all=document.querySelectorAll('#orderLines select[name="product_id[]"]');sel=all[all.length-1];}sel.value=String(pid);}
    msg.textContent='Added: '+sel.options[sel.selectedIndex].text;
    input.focus();
  });
  document.getElementById('orderForm').addEventListener('submit',function(){const b=this.querySelector('button:not([type=button])');if(b){b.disabled=true;b.textContent='Creating…';}});
})();</script>
<div class="notice"><b>Reservation control:</b> once this order is allocated, reserved pieces are not available to POS billing or normal transfer until the order is dispatched or cancelled.</div><?php page_footer();
