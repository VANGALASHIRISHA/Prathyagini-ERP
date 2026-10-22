<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_role(['godown']);
[$scope,$params]=location_scope_sql('ib.location_id');
$st=db()->prepare('SELECT COALESCE(SUM(ib.qty),0),COALESCE(SUM(ib.reserved_qty),0),COALESCE(SUM(GREATEST(ib.qty-ib.reserved_qty,0)),0) FROM inventory_balances ib WHERE ib.qty>0'.$scope);$st->execute($params);[$stockQty,$reservedQty,$sellableQty]=array_values($st->fetch());
$ids=assigned_location_ids();$locations=[];
if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$st=db()->prepare("SELECT code,name,rack,shelf_bin FROM locations WHERE id IN ($ph) ORDER BY code");$st->execute($ids);$locations=$st->fetchAll();}
$events=recent_role_events(8);
page_header('Godown Dashboard');
?>
<p class="muted">Stock identification, pick and pack, return and RTO receive, and physical count. No purchase cost and no profit.</p>
<div class="grid">
<div class="card"><div class="muted">Physical Stock</div><div class="metric"><?=e(number_format((float)$stockQty,0))?></div></div>
<div class="card"><div class="muted">Reserved Online</div><div class="metric"><?=e(number_format((float)$reservedQty,0))?></div></div>
<div class="card"><div class="muted">Sellable Stock</div><div class="metric"><?=e(number_format((float)$sellableQty,0))?></div></div>
<div class="card"><div class="muted">Pick Alerts</div><div class="metric"><?=e(unread_event_count())?></div></div>
</div>
<div class="tiles" style="margin-top:16px">
<a class="tile" href="scan_find.php"><b>Scan &amp; Find</b><span class="muted">Scan a garment or rack label to see where it is</span></a>
<a class="tile" href="stock.php"><b>Stock Identification</b><span class="muted">Scan barcode, rack and bin</span></a>
<a class="tile" href="stock_transfer.php"><b>Move Stock</b><span class="muted">Start/view branch transfers (Draft → Approved → Dispatched → Received)</span></a>
<a class="tile" href="stock_transfer_approve.php"><b>Approve Transfers</b><span class="muted">Drafts waiting for approval</span></a>
<a class="tile" href="stock_transfer_dispatch.php"><b>Dispatch Transfers</b><span class="muted">Approved transfers ready to send</span></a>
<a class="tile" href="stock_transfer_receive.php"><b>Receive Transfers</b><span class="muted">Incoming stock awaiting confirmation</span></a>
<a class="tile" href="online_pick.php"><b>Pick / Pack</b><span class="muted">Online orders ready in your racks</span></a>
<a class="tile" href="online_return_receive.php"><b>Return / RTO Receive</b><span class="muted">Put returned pieces back</span></a>
<a class="tile" href="stock_count.php"><b>Physical Count</b><span class="muted">Count a rack and submit it</span></a>
<a class="tile" href="location_labels.php"><b>Rack Labels</b><span class="muted">Print location barcodes</span></a>
</div>
<div class="panel" style="margin-top:16px"><h3>Assigned Godown Locations</h3><table><tr><th>Code</th><th>Name</th><th>Rack</th><th>Bin</th></tr><?php foreach($locations as $l):?><tr><td><?=e($l['code'])?></td><td><?=e($l['name'])?></td><td><?=e($l['rack'])?></td><td><?=e($l['shelf_bin'])?></td></tr><?php endforeach; if(!$locations):?><tr><td colspan="4" class="muted">No locations assigned.</td></tr><?php endif;?></table></div>
<div class="panel" style="margin-top:16px"><h3>Recent Stock Events</h3><table><tr><th>Time</th><th>Event</th><th>Message</th></tr><?php foreach($events as $ev):?><tr><td><?=e($ev['created_at'])?></td><td><?=e($ev['title'])?></td><td><?=e($ev['message'])?></td></tr><?php endforeach; if(!$events):?><tr><td colspan="3" class="muted">No events yet.</td></tr><?php endif;?></table></div>
<?php page_footer();
