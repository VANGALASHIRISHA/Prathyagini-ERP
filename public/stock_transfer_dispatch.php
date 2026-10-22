<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_permission('stock_transfer');
$isAdmin=is_super_admin();$assigned=assigned_location_ids();$id=(int)($_GET['id']??$_POST['id']??0);$err='';$msg='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    try{
        db()->beginTransaction();
        $st=db()->prepare('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE');$st->execute([$id]);$t=$st->fetch();
        if(!$t)throw new Exception('Transfer not found.');
        $from=(int)$t['from_location_id'];
        if(!$isAdmin&&!can_access_location($from))throw new Exception('Only staff assigned to the source location can dispatch.');
        if($t['status']!=='APPROVED')throw new Exception('Only an APPROVED transfer can be dispatched. Current status: '.$t['status']);
        $st=db()->prepare('SELECT i.*,p.sku,p.product_name,p.serial_tracking FROM stock_transfer_items i JOIN products p ON p.id=i.product_id WHERE i.transfer_id=? FOR UPDATE');$st->execute([$id]);$items=$st->fetchAll();
        // i.* already includes serial_id from stock_transfer_items.
        if(!$items)throw new Exception('Transfer has no line items.');
        $sl=db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_in,qty_out,reference_type,reference_id,remarks,created_by) VALUES('TRANSFER_OUT',?,?,?,0,?, 'TRANSFER',?,?,?)");
        foreach($items as $it){
            $pid=(int)$it['product_id'];$bid=(int)$it['batch_id'];$qty=(float)$it['quantity'];
            $st=db()->prepare('SELECT qty,reserved_qty FROM inventory_balances WHERE product_id=? AND batch_id=? AND location_id=? FOR UPDATE');$st->execute([$pid,$bid,$from]);$bal=$st->fetch();
            if(!$bal||((float)$bal['qty']-(float)$bal['reserved_qty'])<$qty-0.0001)throw new Exception('Insufficient sellable stock at source for '.$it['sku'].' · '.$it['product_name'].'. Stock may have moved since the draft was approved.');
            db()->prepare('UPDATE inventory_balances SET qty=qty-? WHERE product_id=? AND batch_id=? AND location_id=?')->execute([$qty,$pid,$bid,$from]);
            db()->prepare('UPDATE batches SET qty_available=qty_available-? WHERE id=?')->execute([$qty,$bid]);
            $sl->execute([$pid,$bid,$from,$qty,$id,$t['transfer_no'],current_user()['id']]);
            if((int)$it['serial_tracking']===1){
                $sid=(int)$it['serial_id'];
                if(!$sid)throw new Exception('Line for '.$it['sku'].' is missing its scanned piece reference.');
                $ss=db()->prepare("SELECT status,location_id FROM product_serials WHERE id=? FOR UPDATE");$ss->execute([$sid]);$srow=$ss->fetch();
                if(!$srow||$srow['status']!=='AVAILABLE'||(int)$srow['location_id']!==$from)throw new Exception('The exact scanned piece for '.$it['sku'].' is no longer AVAILABLE at the source. It may have been sold, adjusted or moved since this draft was created.');
                $up=db()->prepare("UPDATE product_serials SET status='IN_TRANSIT' WHERE id=? AND status='AVAILABLE'");$up->execute([$sid]);
                if($up->rowCount()!==1)throw new Exception('Serial piece changed status; dispatch aborted for safety.');
            }
            maybe_publish_low_stock($pid,$from);
        }
        db()->prepare("UPDATE stock_transfers SET status='DISPATCHED',dispatched_by=?,dispatched_at=NOW() WHERE id=? AND status='APPROVED'")->execute([current_user()['id'],$id]);
        db()->commit();
        audit('STOCK_TRANSFER_DISPATCH','transfer',$id);
        publish_event('STOCK_TRANSFER_DISPATCHED','Transfer dispatched',$t['transfer_no'].' in transit',['super_admin'],'transfer',$id,null);
        publish_scoped_event('STOCK_TRANSFER_DISPATCHED','Transfer dispatched',$t['transfer_no'].' in transit',['manager','godown'],'transfer',$id);
        redirect('stock_transfer_dispatch.php');
    } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}

if(!$id){
    $scopeSql='';$scopeArgs=[];
    if(!$isAdmin){if(!$assigned){$scopeSql=' AND 1=0';}else{$ph=implode(',',array_fill(0,count($assigned),'?'));$scopeSql=" AND t.from_location_id IN ($ph)";$scopeArgs=$assigned;}}
    $st=db()->prepare("SELECT t.*,lf.code from_code,lt.code to_code,(SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id=t.id) line_count FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id WHERE t.status='APPROVED'".$scopeSql.' ORDER BY t.id');
    $st->execute($scopeArgs);$queue=$st->fetchAll();
    page_header('Transfer Dispatch Queue');
    if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
    echo '<div class="notice">Dispatching deducts stock from the source location immediately and posts a stock ledger entry. Stock is "in transit" until the destination confirms receipt.</div><br>';
    echo '<table><tr><th>Transfer</th><th>From → To</th><th>Lines</th><th>Approved</th><th></th></tr>';
    foreach($queue as $q){
        echo '<tr><td>'.e($q['transfer_no']).'</td><td>'.e($q['from_code']).' → '.e($q['to_code']).'</td><td>'.e($q['line_count']).'</td><td>'.e($q['approved_at']).'</td><td class="actions">'
            .'<form method="post" onsubmit="return confirm(\'Dispatch '.e($q['transfer_no']).'? Stock leaves the source now.\');"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="id" value="'.(int)$q['id'].'"><button class="btn">Dispatch</button></form></td></tr>';
    }
    if(!$queue)echo '<tr><td colspan="5">No approved transfers waiting for dispatch.</td></tr>';
    echo '</table>';
    page_footer();exit;
}

$st=db()->prepare('SELECT t.*,lf.code from_code,lt.code to_code FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id WHERE t.id=?');$st->execute([$id]);$t=$st->fetch();
if(!$t){http_response_code(404);exit('Transfer not found.');}
$st=db()->prepare('SELECT i.*,p.sku,p.product_name,b.batch_barcode FROM stock_transfer_items i JOIN products p ON p.id=i.product_id JOIN batches b ON b.id=i.batch_id WHERE i.transfer_id=? ORDER BY i.id');$st->execute([$id]);$items=$st->fetchAll();
page_header('Dispatch Transfer');
if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
?>
<div class="panel"><b><?=e($t['transfer_no'])?></b> · <?=e($t['from_code'])?> → <?=e($t['to_code'])?> · <span class="badge"><?=e($t['status'])?></span></div><br>
<table><tr><th>SKU</th><th>Product</th><th>Batch</th><th>Qty</th></tr><?php foreach($items as $it):?><tr><td><?=e($it['sku'])?></td><td><?=e($it['product_name'])?></td><td><?=e($it['batch_barcode'])?></td><td><?=e(number_format((float)$it['quantity'],3))?></td></tr><?php endforeach;?></table><br>
<?php if($t['status']==='APPROVED'):?>
<form method="post" onsubmit="return confirm('Dispatch this transfer? Stock leaves the source location now.');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><button class="btn">Confirm Dispatch</button></form>
<?php else: ?><div class="notice">This transfer is already <?=e($t['status'])?>.</div><?php endif; ?>
<p><a href="stock_transfer_dispatch.php">← Back to dispatch queue</a></p>
<?php page_footer();
