<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_permission('stock_transfer');
$isAdmin=is_super_admin();$assigned=assigned_location_ids();$id=(int)($_GET['id']??$_POST['id']??0);$err='';$msg='';

function transfer_scope_ok(array $t,bool $isAdmin,array $assigned): bool {
    return $isAdmin || in_array((int)$t['from_location_id'],$assigned,true) || in_array((int)$t['to_location_id'],$assigned,true);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=$_POST['action']??'';
    try{
        db()->beginTransaction();
        $st=db()->prepare('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE');$st->execute([$id]);$t=$st->fetch();
        if(!$t)throw new Exception('Transfer not found.');
        if(!transfer_scope_ok($t,$isAdmin,$assigned))throw new Exception('Transfer is outside your assigned locations.');
        if($t['status']!=='DRAFT')throw new Exception('Only a draft transfer can be approved or cancelled here. Current status: '.$t['status']);
        $st=db()->prepare('SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id=?');$st->execute([$id]);$itemCount=(int)$st->fetchColumn();
        if($action==='approve'){
            if($itemCount<1)throw new Exception('Add at least one line item before approving.');
            db()->prepare("UPDATE stock_transfers SET status='APPROVED',approved_by=?,approved_at=NOW() WHERE id=?")->execute([current_user()['id'],$id]);
            db()->commit();
            audit('STOCK_TRANSFER_APPROVE','transfer',$id);
            publish_event('STOCK_TRANSFER_APPROVED','Transfer approved',$t['transfer_no'].' ready to dispatch',['super_admin'],'transfer',$id,null);
            publish_scoped_event('STOCK_TRANSFER_APPROVED','Transfer approved',$t['transfer_no'].' ready to dispatch',['manager','godown'],'transfer',$id);
            redirect('stock_transfer_approve.php');
        } elseif($action==='cancel'){
            db()->prepare("UPDATE stock_transfers SET status='CANCELLED' WHERE id=?")->execute([$id]);
            db()->commit();
            audit('STOCK_TRANSFER_CANCEL','transfer',$id);
            redirect('stock_transfer_approve.php');
        } else throw new Exception('Invalid action.');
    } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$err=$e->getMessage();}
}

if(!$id){
    $scopeSql='';$scopeArgs=[];
    if(!$isAdmin){if(!$assigned){$scopeSql=' AND 1=0';}else{$ph=implode(',',array_fill(0,count($assigned),'?'));$scopeSql=" AND (t.from_location_id IN ($ph) OR t.to_location_id IN ($ph))";$scopeArgs=array_merge($assigned,$assigned);}}
    $st=db()->prepare("SELECT t.*,lf.code from_code,lt.code to_code,u.name created_by_name,(SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id=t.id) line_count FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id JOIN users u ON u.id=t.created_by WHERE t.status='DRAFT'".$scopeSql.' ORDER BY t.id');
    $st->execute($scopeArgs);$queue=$st->fetchAll();
    page_header('Transfer Approval Queue');
    if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
    echo '<table><tr><th>Transfer</th><th>From → To</th><th>Lines</th><th>Requested by</th><th>Created</th><th></th></tr>';
    foreach($queue as $q){
        echo '<tr><td>'.e($q['transfer_no']).'</td><td>'.e($q['from_code']).' → '.e($q['to_code']).'</td><td>'.e($q['line_count']).'</td><td>'.e($q['created_by_name']).'</td><td>'.e($q['created_at']).'</td><td class="actions">'
            .'<a class="btn secondary" href="stock_transfer_approve.php?id='.(int)$q['id'].'">Review</a></td></tr>';
    }
    if(!$queue)echo '<tr><td colspan="6">No drafts waiting for approval.</td></tr>';
    echo '</table>';
    page_footer();exit;
}

$st=db()->prepare('SELECT t.*,lf.code from_code,lf.name from_name,lt.code to_code,lt.name to_name,u.name created_by_name FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id JOIN users u ON u.id=t.created_by WHERE t.id=?');
$st->execute([$id]);$t=$st->fetch();
if(!$t){http_response_code(404);exit('Transfer not found.');}
if(!transfer_scope_ok($t,$isAdmin,$assigned)){http_response_code(403);exit('Transfer outside your assigned locations.');}
$st=db()->prepare('SELECT i.*,p.sku,p.product_name,b.batch_barcode FROM stock_transfer_items i JOIN products p ON p.id=i.product_id JOIN batches b ON b.id=i.batch_id WHERE i.transfer_id=? ORDER BY i.id');$st->execute([$id]);$items=$st->fetchAll();

page_header('Review Transfer Draft');
if($err)echo '<div class="notice error">'.e($err).'</div>';if($msg)echo '<div class="notice success">'.e($msg).'</div>';
?>
<div class="panel"><b><?=e($t['transfer_no'])?></b> · <?=e($t['from_code'])?> → <?=e($t['to_code'])?> · requested by <?=e($t['created_by_name'])?> · <span class="badge"><?=e($t['status'])?></span></div><br>
<table><tr><th>SKU</th><th>Product</th><th>Batch</th><th>Qty</th></tr><?php foreach($items as $it):?><tr><td><?=e($it['sku'])?></td><td><?=e($it['product_name'])?></td><td><?=e($it['batch_barcode'])?></td><td><?=e(number_format((float)$it['quantity'],3))?></td></tr><?php endforeach; if(!$items):?><tr><td colspan="4">No lines on this draft.</td></tr><?php endif;?></table><br>
<?php if($t['status']==='DRAFT'):?>
<div class="actions">
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$id?>"><button class="btn">Approve</button></form>
<form method="post" onsubmit="return confirm('Cancel this transfer draft?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?=$id?>"><button class="btn danger">Cancel</button></form>
</div>
<?php else: ?><div class="notice">This transfer is already <?=e($t['status'])?>.</div><?php endif; ?>
<p><a href="stock_transfer_approve.php">← Back to approval queue</a></p>
<?php page_footer();
