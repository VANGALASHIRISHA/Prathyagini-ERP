<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_permission('stock_transfer');
$isAdmin=is_super_admin();$id=(int)($_GET['id']??$_POST['id']??0);$err='';$msg='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=$_POST['action']??'';
    try{
        $st=db()->prepare('SELECT * FROM stock_transfers WHERE id=?');$st->execute([$id]);$t=$st->fetch();
        if(!$t)throw new Exception('Transfer not found.');
        $to=(int)$t['to_location_id'];
        if(!$isAdmin&&!can_access_location($to))throw new Exception('Only staff assigned to the destination location can receive this transfer.');

        if($action==='scan_serial'){
            if($t['status']!=='DISPATCHED')throw new Exception('Transfer is not awaiting receipt.');
            $barcode=trim($_POST['barcode']??'');if($barcode==='')throw new Exception('Scan a piece barcode.');
            $st=db()->prepare("SELECT i.* FROM stock_transfer_items i JOIN product_serials ps ON ps.id=i.serial_id WHERE i.transfer_id=? AND ps.serial_barcode=? AND i.received_qty<i.quantity LIMIT 1");
            $st->execute([$id,$barcode]);$line=$st->fetch();
            if(!$line){
                $st=db()->prepare("SELECT 1 FROM stock_transfer_items i JOIN product_serials ps ON ps.id=i.serial_id WHERE i.transfer_id=? AND ps.serial_barcode=? AND i.received_qty>=i.quantity LIMIT 1");$st->execute([$id,$barcode]);
                throw new Exception($st->fetchColumn()?'ALREADY CONFIRMED — this piece was already received into this transfer.':'WRONG ITEM — this barcode is not part of this transfer.');
            }
            db()->prepare('UPDATE stock_transfer_items SET received_qty=1 WHERE id=?')->execute([$line['id']]);
            $msg='Confirmed piece received.';
        } elseif($action==='set_qty'){
            if($t['status']!=='DISPATCHED')throw new Exception('Transfer is not awaiting receipt.');
            $itemId=(int)($_POST['item_id']??0);$qty=(float)($_POST['received_qty']??0);
            $st=db()->prepare('SELECT * FROM stock_transfer_items WHERE id=? AND transfer_id=? AND serial_id IS NULL');$st->execute([$itemId,$id]);$line=$st->fetch();
            if(!$line)throw new Exception('Line not found or is a serialized piece (scan it instead).');
            if($qty<0||$qty>(float)$line['quantity']+0.0001)throw new Exception('Received quantity must be between 0 and the dispatched quantity.');
            db()->prepare('UPDATE stock_transfer_items SET received_qty=? WHERE id=?')->execute([$qty,$itemId]);
            $msg='Line updated.';
        } elseif($action==='finalize'){
            if(empty($_POST['confirm']))throw new Exception('Confirm the physical check before finalizing.');
            db()->beginTransaction();
            $st=db()->prepare('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE');$st->execute([$id]);$t=$st->fetch();
            if($t['status']!=='DISPATCHED')throw new Exception('Only a DISPATCHED transfer can be received. Current status: '.$t['status']);
            $st=db()->prepare('SELECT i.*,p.serial_tracking FROM stock_transfer_items i JOIN products p ON p.id=i.product_id WHERE i.transfer_id=? FOR UPDATE');$st->execute([$id]);$items=$st->fetchAll();
            if(!$items)throw new Exception('Transfer has no line items.');
            $hasDiscrepancy=0;
            $sl=db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_in,qty_out,reference_type,reference_id,remarks,created_by) VALUES('TRANSFER_IN',?,?,?,?,0,'TRANSFER',?,?,?)");
            foreach($items as $it){
                $pid=(int)$it['product_id'];$bid=(int)$it['batch_id'];$recv=(float)$it['received_qty'];$expect=(float)$it['quantity'];
                if($recv+0.0001<$expect)$hasDiscrepancy=1;
                if($recv<=0)continue;
                db()->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')->execute([$pid,$bid,$to,$recv]);
                db()->prepare('UPDATE batches SET qty_available=qty_available+? WHERE id=?')->execute([$recv,$bid]);
                $sl->execute([$pid,$bid,$to,$recv,$id,$t['transfer_no'],current_user()['id']]);
                if((int)$it['serial_tracking']===1&&$it['serial_id']){
                    $ss=db()->prepare("SELECT status FROM product_serials WHERE id=? FOR UPDATE");$ss->execute([$it['serial_id']]);$sstat=$ss->fetchColumn();
                    if($sstat!=='IN_TRANSIT')throw new Exception('A scanned piece is no longer IN_TRANSIT; aborting for safety.');
                    db()->prepare("UPDATE product_serials SET status='AVAILABLE',location_id=? WHERE id=?")->execute([$to,$it['serial_id']]);
                }
            }
            $notes=mb_substr(trim((string)($_POST['notes']??'')),0,255);
            db()->prepare("UPDATE stock_transfers SET status='RECEIVED',received_by=?,received_at=NOW(),has_discrepancy=?,notes=? WHERE id=? AND status='DISPATCHED'")->execute([current_user()['id'],$hasDiscrepancy,$notes?:null,$id]);
            db()->commit();
            audit('STOCK_TRANSFER_RECEIVE','transfer',$id,['discrepancy'=>$hasDiscrepancy]);
            publish_event('STOCK_TRANSFER_RECEIVED','Transfer received',$t['transfer_no'].($hasDiscrepancy?' · DISCREPANCY — review':' · complete'),['super_admin'],'transfer',$id,null);
            publish_scoped_event('STOCK_TRANSFER_RECEIVED','Transfer received',$t['transfer_no'].($hasDiscrepancy?' · DISCREPANCY — review':' · complete'),['manager','godown'],'transfer',$id);
            redirect('stock_transfer_receive.php');
        } else throw new Exception('Invalid action.');
    } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}

if(!$id){
    $assigned=assigned_location_ids();$scopeSql='';$scopeArgs=[];
    if(!$isAdmin){if(!$assigned){$scopeSql=' AND 1=0';}else{$ph=implode(',',array_fill(0,count($assigned),'?'));$scopeSql=" AND t.to_location_id IN ($ph)";$scopeArgs=$assigned;}}
    $st=db()->prepare("SELECT t.*,lf.code from_code,lt.code to_code,(SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id=t.id) line_count FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id WHERE t.status='DISPATCHED'".$scopeSql.' ORDER BY t.id');
    $st->execute($scopeArgs);$queue=$st->fetchAll();
    page_header('Transfer Receive Queue');
    if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
    echo '<div class="notice"><b>Flow:</b> Open transfer → scan each unique piece / confirm each batch quantity → finalize. Shortfalls are flagged as a discrepancy, not silently written off.</div><br>';
    echo '<table><tr><th>Transfer</th><th>From → To</th><th>Lines</th><th>Dispatched</th><th></th></tr>';
    foreach($queue as $q){
        echo '<tr><td>'.e($q['transfer_no']).'</td><td>'.e($q['from_code']).' → '.e($q['to_code']).'</td><td>'.e($q['line_count']).'</td><td>'.e($q['dispatched_at']).'</td><td><a class="btn secondary" href="stock_transfer_receive.php?id='.(int)$q['id'].'">Receive</a></td></tr>';
    }
    if(!$queue)echo '<tr><td colspan="5">No transfers waiting to be received.</td></tr>';
    echo '</table>';
    page_footer();exit;
}

$st=db()->prepare('SELECT t.*,lf.code from_code,lt.code to_code FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id WHERE t.id=?');$st->execute([$id]);$t=$st->fetch();
if(!$t){http_response_code(404);exit('Transfer not found.');}
if(!$isAdmin&&!can_access_location((int)$t['to_location_id'])){http_response_code(403);exit('Only staff assigned to the destination location can receive this transfer.');}
$st=db()->prepare('SELECT i.*,p.sku,p.product_name,p.serial_tracking,ps.serial_barcode FROM stock_transfer_items i JOIN products p ON p.id=i.product_id LEFT JOIN product_serials ps ON ps.id=i.serial_id WHERE i.transfer_id=? ORDER BY i.id');$st->execute([$id]);$items=$st->fetchAll();
page_header('Receive Transfer');
if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
?>
<div class="panel"><b><?=e($t['transfer_no'])?></b> · <?=e($t['from_code'])?> → <?=e($t['to_code'])?> · <span class="badge"><?=e($t['status'])?></span><?=$t['has_discrepancy']?' <span class="badge">DISCREPANCY</span>':''?></div><br>
<?php if($t['status']==='DISPATCHED'):?>
<form method="post" class="panel"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="scan_serial"><input type="hidden" name="id" value="<?=$id?>"><label>Scan Unique Piece Barcode</label><input name="barcode" data-scanner class="scanbox" autofocus required><br><br><button class="btn">Confirm Piece</button></form><br>
<?php endif; ?>
<table><tr><th>SKU</th><th>Product</th><th>Piece / Batch</th><th>Dispatched Qty</th><th>Received Qty</th><?php if($t['status']==='DISPATCHED'):?><th></th><?php endif;?></tr>
<?php foreach($items as $it):?><tr><td><?=e($it['sku'])?></td><td><?=e($it['product_name'])?></td><td><?=e($it['serial_barcode']?:'(batch line)')?></td><td><?=e(number_format((float)$it['quantity'],3))?></td><td><?=e(number_format((float)$it['received_qty'],3))?></td>
<?php if($t['status']==='DISPATCHED'):?><td><?php if(!$it['serial_id']):?><form method="post" style="display:flex;gap:6px"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="set_qty"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="item_id" value="<?=$it['id']?>"><input type="number" name="received_qty" step="0.001" min="0" max="<?=e($it['quantity'])?>" value="<?=e($it['received_qty'])?>" style="width:90px"><button class="btn secondary">Set</button></form><?php else:?><span class="muted">Scan above</span><?php endif;?></td><?php endif;?>
</tr><?php endforeach;?></table><br>
<?php if($t['status']==='DISPATCHED'):?>
<form method="post" onsubmit="return confirm('Finalize receipt? Any shortfall below dispatched quantity will be flagged as a discrepancy, not silently adjusted.');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="finalize"><input type="hidden" name="id" value="<?=$id?>"><label>Notes (optional)</label><input name="notes" maxlength="255"><br><br><label><input type="checkbox" name="confirm" value="1" required style="width:auto"> I have physically checked every line above against what arrived.</label><br><br><button class="btn">Finalize Receipt</button></form>
<?php else: ?><div class="notice">This transfer is already <?=e($t['status'])?>.</div><?php endif; ?>
<p><a href="stock_transfer_receive.php">← Back to receive queue</a></p>
<?php page_footer();
