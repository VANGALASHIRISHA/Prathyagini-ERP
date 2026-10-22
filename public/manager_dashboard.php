<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_role(['manager']);
[$scope,$params]=location_scope_sql('ib.location_id');
$st=db()->prepare('SELECT COALESCE(SUM(ib.qty),0),COALESCE(SUM(ib.reserved_qty),0),COALESCE(SUM(GREATEST(ib.qty-ib.reserved_qty,0)),0) FROM inventory_balances ib WHERE ib.qty>0'.$scope);$st->execute($params);[$stockQty,$reservedQty,$sellableQty]=array_values($st->fetch());
$ids=assigned_location_ids();$salesToday=0;$billsToday=0;$locations=[];
if($ids){
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $sql="SELECT COALESCE(SUM(GREATEST(s.grand_total-s.refund_total,0)),0),COUNT(*) FROM sales s WHERE DATE(s.created_at)=CURDATE() AND s.status<>'CANCELLED' AND EXISTS(SELECT 1 FROM sale_items a WHERE a.sale_id=s.id) AND NOT EXISTS(SELECT 1 FROM sale_items b WHERE b.sale_id=s.id AND b.location_id NOT IN ($ph))";
  $st=db()->prepare($sql);$st->execute($ids);[$salesToday,$billsToday]=array_values($st->fetch());
  $st=db()->prepare("SELECT code,name FROM locations WHERE id IN ($ph) ORDER BY code");$st->execute($ids);$locations=$st->fetchAll();
}
$events=recent_role_events(8);
page_header('Manager Dashboard');
?>
<p class="muted">Assigned locations only. Listings, online fulfillment and reports. Purchase cost and profit are not on this panel.</p>
<div class="grid">
<div class="card"><div class="muted">Assigned Stock</div><div class="metric"><?=e(number_format((float)$stockQty,0))?></div></div>
<div class="card"><div class="muted">Reserved Online</div><div class="metric"><?=e(number_format((float)$reservedQty,0))?></div></div>
<div class="card"><div class="muted">Sellable</div><div class="metric"><?=e(number_format((float)$sellableQty,0))?></div></div>
<div class="card"><div class="muted">Today Net Sales</div><div class="metric">₹<?=e(number_format((float)$salesToday,2))?></div><div class="muted"><?=e((int)$billsToday)?> bills</div></div>
</div>
<div class="tiles" style="margin-top:16px">
<a class="tile" href="stock.php"><b>Assigned Locations</b><span class="muted"><?php if($locations): foreach($locations as $l) echo e($l['code']).' '; else: ?>No locations assigned<?php endif; ?></span></a>
<a class="tile" href="staff_listing.php"><b>Users & Listings</b><span class="muted">Staff who share your locations</span></a>
<a class="tile" href="products.php"><b>Product Listings</b><span class="muted">Selling rates only. No purchase cost</span></a>
<a class="tile" href="online_orders.php"><b>Online Fulfillment</b><span class="muted">Orders, pick, pack and dispatch</span></a>
<a class="tile" href="stock_transfer.php"><b>Branch Transfers</b><span class="muted">Draft → Approve → Dispatch → Receive</span></a>
<a class="tile" href="reports.php"><b>Reports</b><span class="muted">Sales for your locations. No cost or margin</span></a>
<a class="tile" href="pos.php"><b>POS Billing</b><span class="muted">Bill stock from assigned locations</span></a>
</div>
<div class="panel" style="margin-top:16px"><h3>Recent Events</h3><table><tr><th>Time</th><th>Event</th><th>Message</th></tr><?php foreach($events as $ev):?><tr><td><?=e($ev['created_at'])?></td><td><?=e($ev['title'])?></td><td><?=e($ev['message'])?></td></tr><?php endforeach; if(!$events):?><tr><td colspan="3" class="muted">No events yet.</td></tr><?php endif;?></table></div>
<?php page_footer();
