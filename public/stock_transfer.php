<?php require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_permission('stock_transfer');
// Draft creation + item scanning for the transfer approval workflow (Draft -> Approved -> Dispatched -> Received).
// See stock_transfer_approve.php, stock_transfer_dispatch.php, stock_transfer_receive.php for the later stages.
$isAdmin=is_super_admin();$assigned=assigned_location_ids();
$allLoc=db()->query('SELECT id,code,name FROM locations WHERE active=1 ORDER BY code')->fetchAll();
$myLoc=$isAdmin?$allLoc:array_values(array_filter($allLoc,fn($l)=>in_array((int)$l['id'],$assigned,true)));
$err='';$msg='';$id=(int)($_GET['id']??$_POST['id']??0);

function transfer_can_touch(array $t,bool $isAdmin,array $assigned): bool {
    return $isAdmin || in_array((int)$t['from_location_id'],$assigned,true) || in_array((int)$t['to_location_id'],$assigned,true);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$action=$_POST['action']??'';
    try{
        if($action==='create_header'){
            $from=(int)($_POST['from_location_id']??0);$to=(int)($_POST['to_location_id']??0);
            if($from<=0||$to<=0||$from===$to)throw new Exception('Choose two different locations.');
            if(!$isAdmin&&!in_array($from,$assigned,true)&&!in_array($to,$assigned,true))throw new Exception('You must be assigned to the source or destination location.');
            $transferNo='TRF-'.date('YmdHis').'-'.random_int(100,999);
            $st=db()->prepare("INSERT INTO stock_transfers(transfer_no,from_location_id,to_location_id,status,created_by) VALUES(?,?,?,'DRAFT',?)");
            $st->execute([$transferNo,$from,$to,current_user()['id']]);
            $newId=(int)db()->lastInsertId();
            audit('STOCK_TRANSFER_DRAFT_CREATE','transfer',$newId);
            redirect('stock_transfer.php?id='.$newId);
        } elseif($action==='add_item'){
            if(!$id)throw new Exception('Open a draft first.');
            $st=db()->prepare('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE');$st->execute([$id]);$t=$st->fetch();
            if(!$t)throw new Exception('Transfer not found.');
            if($t['status']!=='DRAFT')throw new Exception('Items can only be added while the transfer is still a draft.');
            if(!transfer_can_touch($t,$isAdmin,$assigned))throw new Exception('Transfer is outside your assigned locations.');
            $barcode=trim($_POST['barcode']??'');$qty=(float)($_POST['quantity']??1);$from=(int)$t['from_location_id'];
            if($barcode===''||$qty<=0)throw new Exception('Scan a barcode and enter a positive quantity.');
            $st=db()->prepare("SELECT ps.*,p.product_name,p.sku FROM product_serials ps JOIN products p ON p.id=ps.product_id WHERE ps.serial_barcode=? AND ps.location_id=?");$st->execute([$barcode,$from]);$serial=$st->fetch();
            $productId=$batchId=0;$serialId=null;$moveQty=$qty;
            if($serial){
                if($serial['status']!=='AVAILABLE')throw new Exception('Unique piece is not available for transfer.');
                if(abs($qty-1)>0.0001)throw new Exception('Unique piece barcode must be quantity 1.');
                $st=db()->prepare('SELECT 1 FROM stock_transfer_items WHERE transfer_id=? AND serial_id=?');$st->execute([$id,$serial['id']]);
                if($st->fetchColumn())throw new Exception('This piece is already on this transfer draft.');
                $productId=(int)$serial['product_id'];$batchId=(int)$serial['batch_id'];$serialId=(int)$serial['id'];$moveQty=1;
            } else {
                $st=db()->prepare('SELECT ib.product_id,ib.batch_id,ib.qty,ib.reserved_qty,b.batch_barcode,p.product_name,p.serial_tracking FROM inventory_balances ib JOIN batches b ON b.id=ib.batch_id JOIN products p ON p.id=ib.product_id WHERE ib.location_id=? AND (ib.qty-ib.reserved_qty)>0 AND (b.batch_barcode=? OR p.product_barcode=?) ORDER BY b.purchased_at LIMIT 1');
                $st->execute([$from,$barcode,$barcode]);$r=$st->fetch();
                if(!$r)throw new Exception('Barcode not found with sellable stock at the source location.');
                if((int)$r['serial_tracking']===1)throw new Exception('Serialized garment: scan the individual unique piece barcode.');
                if(((float)$r['qty']-(float)$r['reserved_qty'])<$qty)throw new Exception('Insufficient sellable stock at source (some may be reserved for online orders).');
                $productId=(int)$r['product_id'];$batchId=(int)$r['batch_id'];
            }
            db()->prepare('INSERT INTO stock_transfer_items(transfer_id,product_id,batch_id,serial_id,quantity) VALUES(?,?,?,?,?)')->execute([$id,$productId,$batchId,$serialId,$moveQty]);
            $msg='Added to draft: '.$moveQty.' piece(s).';
        } elseif($action==='remove_item'){
            $itemId=(int)($_POST['item_id']??0);
            $st=db()->prepare('SELECT t.* FROM stock_transfer_items i JOIN stock_transfers t ON t.id=i.transfer_id WHERE i.id=?');$st->execute([$itemId]);$t=$st->fetch();
            if(!$t)throw new Exception('Line not found.');
            if($t['status']!=='DRAFT')throw new Exception('Only draft transfers can be edited.');
            if(!transfer_can_touch($t,$isAdmin,$assigned))throw new Exception('Transfer is outside your assigned locations.');
            db()->prepare('DELETE FROM stock_transfer_items WHERE id=?')->execute([$itemId]);
            $id=(int)$t['id'];$msg='Line removed.';
        } elseif($action==='cancel_draft'){
            $st=db()->prepare('SELECT * FROM stock_transfers WHERE id=? FOR UPDATE');$st->execute([$id]);$t=$st->fetch();
            if(!$t)throw new Exception('Transfer not found.');
            if($t['status']!=='DRAFT')throw new Exception('Only a draft can be cancelled here.');
            if(!transfer_can_touch($t,$isAdmin,$assigned))throw new Exception('Transfer is outside your assigned locations.');
            db()->prepare("UPDATE stock_transfers SET status='CANCELLED' WHERE id=?")->execute([$id]);
            audit('STOCK_TRANSFER_CANCEL','transfer',$id);
            redirect('stock_transfer.php');
        } else throw new Exception('Invalid action.');
    } catch(Throwable $e){$err=$e->getMessage();}
}

$transfer=null;$items=[];
if($id){
    $st=db()->prepare('SELECT t.*,lf.code from_code,lf.name from_name,lt.code to_code,lt.name to_name FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id WHERE t.id=?');$st->execute([$id]);$transfer=$st->fetch();
    if($transfer&&!transfer_can_touch($transfer,$isAdmin,$assigned)){http_response_code(403);exit('Transfer outside your assigned locations.');}
    if($transfer){$st=db()->prepare('SELECT i.*,p.sku,p.product_name,b.batch_barcode FROM stock_transfer_items i JOIN products p ON p.id=i.product_id JOIN batches b ON b.id=i.batch_id WHERE i.transfer_id=? ORDER BY i.id');$st->execute([$id]);$items=$st->fetchAll();}
}

$scopeSql='';$scopeArgs=[];
if(!$isAdmin){if(!$assigned){$scopeSql=' AND 1=0';}else{$ph=implode(',',array_fill(0,count($assigned),'?'));$scopeSql=" AND (t.from_location_id IN ($ph) OR t.to_location_id IN ($ph))";$scopeArgs=array_merge($assigned,$assigned);}}
$st=db()->prepare("SELECT t.*,lf.code from_code,lt.code to_code,(SELECT COUNT(*) FROM stock_transfer_items i WHERE i.transfer_id=t.id) line_count FROM stock_transfers t JOIN locations lf ON lf.id=t.from_location_id JOIN locations lt ON lt.id=t.to_location_id WHERE 1=1".$scopeSql.' ORDER BY t.id DESC LIMIT 200');
$st->execute($scopeArgs);$history=$st->fetchAll();

page_header('Stock Transfer');
if($err)echo '<div class="notice error">'.e($err).'</div>';
if($msg)echo '<div class="notice success">'.e($msg).'</div>';

if($transfer):?>
<div class="panel"><b><?=e($transfer['transfer_no'])?></b> · <?=e($transfer['from_code'])?> → <?=e($transfer['to_code'])?> · <span class="badge"><?=e($transfer['status'])?></span></div><br>
<?php if($transfer['status']==='DRAFT'):?>
<form method="post" class="panel"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_item"><input type="hidden" name="id" value="<?=$id?>"><div class="row"><div><label>Scan Unique/Batch Barcode</label><input name="barcode" data-scanner class="scanbox" autofocus required></div><div><label>Quantity</label><input type="number" name="quantity" step="0.001" min="0.001" value="1" required></div></div><br><button class="btn">Add Line</button></form><br>
<table><tr><th>SKU</th><th>Product</th><th>Batch</th><th>Qty</th><th></th></tr><?php foreach($items as $it):?><tr><td><?=e($it['sku'])?></td><td><?=e($it['product_name'])?></td><td><?=e($it['batch_barcode'])?></td><td><?=e(number_format((float)$it['quantity'],3))?></td><td><form method="post" style="display:inline" onsubmit="return confirm('Remove this line?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove_item"><input type="hidden" name="item_id" value="<?=$it['id']?>"><button class="btn secondary">Remove</button></form></td></tr><?php endforeach; if(!$items):?><tr><td colspan="5">No lines yet. Scan an item above.</td></tr><?php endif;?></table><br>
<div class="actions"><?php if($items):?><a class="btn" href="stock_transfer_approve.php">Go to Approval Queue</a><?php endif;?><form method="post" onsubmit="return confirm('Cancel this draft?');"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel_draft"><input type="hidden" name="id" value="<?=$id?>"><button class="btn danger">Cancel Draft</button></form></div>
<?php else: ?>
<table><tr><th>SKU</th><th>Product</th><th>Batch</th><th>Qty</th><th>Received</th></tr><?php foreach($items as $it):?><tr><td><?=e($it['sku'])?></td><td><?=e($it['product_name'])?></td><td><?=e($it['batch_barcode'])?></td><td><?=e(number_format((float)$it['quantity'],3))?></td><td><?=e(number_format((float)$it['received_qty'],3))?></td></tr><?php endforeach;?></table>
<?php endif; ?>
<p><a href="stock_transfer.php">← Back to transfer list</a></p>
<?php else: ?>
<form method="post" class="panel"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_header"><div class="row"><div><label>From Location</label><select name="from_location_id" required><option value="">Select…</option><?php foreach(($isAdmin?$allLoc:$myLoc) as $l):?><option value="<?=$l['id']?>"><?=e($l['code'].' · '.$l['name'])?></option><?php endforeach;?></select></div><div><label>To Location</label><select name="to_location_id" required><option value="">Select…</option><?php foreach($allLoc as $l):?><option value="<?=$l['id']?>"><?=e($l['code'].' · '.$l['name'])?></option><?php endforeach;?></select></div></div><br><button class="btn">Start New Transfer Draft</button></form>
<div class="notice">New workflow: Draft (scan items here) → Approve → Dispatch (stock leaves source) → Receive (stock arrives at destination). Nothing moves until Dispatch; discrepancies at Receive are flagged, not silently adjusted.</div><br>
<table><tr><th>Transfer</th><th>From → To</th><th>Status</th><th>Lines</th><th>Created</th><th></th></tr><?php foreach($history as $h):?><tr><td><?=e($h['transfer_no'])?></td><td><?=e($h['from_code'])?> → <?=e($h['to_code'])?></td><td><span class="badge"><?=e($h['status'])?></span><?=$h['has_discrepancy']?' <span class="badge">DISCREPANCY</span>':''?></td><td><?=e($h['line_count'])?></td><td><?=e($h['created_at'])?></td><td class="actions">
<?php if($h['status']==='DRAFT'):?><a class="btn secondary" href="stock_transfer.php?id=<?=$h['id']?>">Open Draft</a>
<?php elseif($h['status']==='APPROVED'):?><a class="btn secondary" href="stock_transfer_dispatch.php?id=<?=$h['id']?>">Dispatch</a>
<?php elseif($h['status']==='DISPATCHED'):?><a class="btn secondary" href="stock_transfer_receive.php?id=<?=$h['id']?>">Receive</a>
<?php else:?><span class="muted">—</span><?php endif;?>
</td></tr><?php endforeach; if(!$history):?><tr><td colspan="6">No transfers yet.</td></tr><?php endif;?></table>
<?php endif; page_footer();
