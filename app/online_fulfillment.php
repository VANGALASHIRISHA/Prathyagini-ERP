<?php
function online_allowed_locations(?int $specific=null): array {
    if(is_super_admin()){
        if($specific){$st=db()->prepare('SELECT id FROM locations WHERE id=? AND active=1 AND online_fulfillment_enabled=1');$st->execute([$specific]);return $st->fetchColumn()?[$specific]:[];}
        return array_map('intval',array_column(db()->query('SELECT id FROM locations WHERE active=1 AND online_fulfillment_enabled=1 ORDER BY id')->fetchAll(),'id'));
    }
    $ids=assigned_location_ids(); if(!$ids) return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=db()->prepare("SELECT id FROM locations WHERE active=1 AND online_fulfillment_enabled=1 AND id IN ($ph) ORDER BY id");$st->execute($ids);$enabled=array_map('intval',array_column($st->fetchAll(),'id'));
    if($specific) return in_array($specific,$enabled,true)?[$specific]:[];
    return $enabled;
}
function online_create_and_reserve(array $order,array $items,array $locationIds,?int $createdBy): int {
    if(!$locationIds) throw new Exception('No fulfilment stock locations are available.');
    $locationIds=array_values(array_unique(array_map('intval',$locationIds)));
    $ph=implode(',',array_fill(0,count($locationIds),'?'));
    $orderNo=trim((string)($order['order_no']??''));
    if($orderNo==='')$orderNo='ONL-'.date('YmdHis').'-'.random_int(100,999);
    $source=strtoupper(trim((string)($order['source']??'MANUAL')));if(!in_array($source,['WEBSITE','MARKETPLACE','MANUAL','API'],true))$source='MANUAL';
    $pay=strtoupper(trim((string)($order['payment_status']??'PENDING')));if(!in_array($pay,['PAID','COD','PENDING','REFUNDED'],true))$pay='PENDING';
    $st=db()->prepare("INSERT INTO online_orders(order_no,source,external_order_id,customer_name,customer_mobile,shipping_address,order_amount,payment_status,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,'NEW',?,?)");
    $st->execute([$orderNo,$source,trim((string)($order['external_order_id']??''))?:null,trim((string)($order['customer_name']??'')),trim((string)($order['customer_mobile']??'')),trim((string)($order['shipping_address']??'')),max(0,(float)($order['order_amount']??0)),$pay,trim((string)($order['notes']??'')),$createdBy]);
    $orderId=(int)db()->lastInsertId();
    $insItem=db()->prepare('INSERT INTO online_order_items(online_order_id,product_id,quantity) VALUES(?,?,?)');
    $insAlloc=db()->prepare("INSERT INTO online_order_allocations(online_order_id,order_item_id,product_id,batch_id,location_id,serial_id,quantity,status) VALUES(?,?,?,?,?,?,?,'RESERVED')");
    foreach($items as $it){
        $pid=(int)($it['product_id']??0);$qty=(float)($it['quantity']??0);
        if($pid<=0||$qty<=0||abs($qty-round($qty))>0.0001)throw new Exception('Online fulfilment quantities must be positive whole pieces.');
        $st=db()->prepare('SELECT id,sku,product_name,serial_tracking,active FROM products WHERE id=? FOR UPDATE');$st->execute([$pid]);$prod=$st->fetch();if(!$prod||(int)$prod['active']!==1)throw new Exception('Product is missing/inactive.');
        $insItem->execute([$orderId,$pid,$qty]);$itemId=(int)db()->lastInsertId();$need=(int)round($qty);
        $sql="SELECT ib.product_id,ib.batch_id,ib.location_id,ib.qty,ib.reserved_qty,b.purchased_at,b.batch_barcode FROM inventory_balances ib JOIN batches b ON b.id=ib.batch_id WHERE ib.product_id=? AND ib.location_id IN ($ph) AND (ib.qty-ib.reserved_qty)>0 ORDER BY b.purchased_at,ib.location_id,ib.batch_id FOR UPDATE";
        $st=db()->prepare($sql);$st->execute([$pid,...$locationIds]);$rows=$st->fetchAll();
        foreach($rows as $r){if($need<=0)break;$avail=(int)floor(max(0,(float)$r['qty']-(float)$r['reserved_qty'])+1e-9);if($avail<=0)continue;
            if((int)$prod['serial_tracking']===1){
                $ss=db()->prepare("SELECT id FROM product_serials WHERE product_id=? AND batch_id=? AND location_id=? AND status='AVAILABLE' ORDER BY id FOR UPDATE");$ss->execute([$pid,$r['batch_id'],$r['location_id']]);$serials=array_column($ss->fetchAll(),'id');$take=min($need,$avail,count($serials));
                for($i=0;$i<$take;$i++){$sid=(int)$serials[$i];$up=db()->prepare("UPDATE product_serials SET status='RESERVED' WHERE id=? AND status='AVAILABLE'");$up->execute([$sid]);if($up->rowCount()!==1)throw new Exception('A garment was reserved by another order. Please retry.');$insAlloc->execute([$orderId,$itemId,$pid,$r['batch_id'],$r['location_id'],$sid,1]);}
                if($take>0)db()->prepare('UPDATE inventory_balances SET reserved_qty=reserved_qty+? WHERE product_id=? AND batch_id=? AND location_id=?')->execute([$take,$pid,$r['batch_id'],$r['location_id']]);$need-=$take;
            }else{
                $take=min($need,$avail);if($take>0){$insAlloc->execute([$orderId,$itemId,$pid,$r['batch_id'],$r['location_id'],null,$take]);db()->prepare('UPDATE inventory_balances SET reserved_qty=reserved_qty+? WHERE product_id=? AND batch_id=? AND location_id=?')->execute([$take,$pid,$r['batch_id'],$r['location_id']]);$need-=$take;}
            }
        }
        if($need>0)throw new Exception('Insufficient sellable stock for '.$prod['sku'].' · '.$prod['product_name'].'. Required '.$qty.' piece(s).');
    }
    db()->prepare("UPDATE online_orders SET status='ALLOCATED',updated_at=NOW() WHERE id=?")->execute([$orderId]);
    return $orderId;
}
function online_release_reservations(int $orderId): void {
    $st=db()->prepare("SELECT * FROM online_order_allocations WHERE online_order_id=? AND status IN ('RESERVED','PICKED','PACKED') FOR UPDATE");$st->execute([$orderId]);$rows=$st->fetchAll();
    foreach($rows as $a){db()->prepare('UPDATE inventory_balances SET reserved_qty=GREATEST(reserved_qty-?,0) WHERE product_id=? AND batch_id=? AND location_id=?')->execute([$a['quantity'],$a['product_id'],$a['batch_id'],$a['location_id']]);if($a['serial_id'])db()->prepare("UPDATE product_serials SET status='AVAILABLE' WHERE id=? AND status='RESERVED'")->execute([$a['serial_id']]);}
    db()->prepare("UPDATE online_order_allocations SET status='RELEASED' WHERE online_order_id=? AND status IN ('RESERVED','PICKED','PACKED')")->execute([$orderId]);
}
function online_order_totals(int $orderId): array {
    $st=db()->prepare('SELECT COALESCE(SUM(quantity),0) total,COALESCE(SUM(picked_qty),0) picked,COALESCE(SUM(packed_qty),0) packed FROM online_order_allocations WHERE online_order_id=? AND status<>\'RELEASED\'');$st->execute([$orderId]);return $st->fetch()?:['total'=>0,'picked'=>0,'packed'=>0];
}
function online_order_number(int $orderId): string {$st=db()->prepare('SELECT order_no FROM online_orders WHERE id=?');$st->execute([$orderId]);return (string)$st->fetchColumn();}
