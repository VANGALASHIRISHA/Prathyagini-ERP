<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_role(['super_admin']);
$stockQty=(float)db()->query('SELECT COALESCE(SUM(qty),0) FROM inventory_balances WHERE qty>0')->fetchColumn();
$sales30=(float)db()->query("SELECT COALESCE(SUM(CASE WHEN status='CANCELLED' THEN 0 ELSE GREATEST(grand_total-refund_total,0) END),0) FROM sales WHERE created_at>=DATE_SUB(CURDATE(), INTERVAL 29 DAY)")->fetchColumn();
$salesToday=(float)db()->query("SELECT COALESCE(SUM(CASE WHEN status='CANCELLED' THEN 0 ELSE GREATEST(grand_total-refund_total,0) END),0) FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$billsToday=(int)db()->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at)=CURDATE() AND status<>'CANCELLED'")->fetchColumn();
$low=(int)db()->query('SELECT COUNT(*) FROM (SELECT p.id,p.min_stock,COALESCE(SUM(GREATEST(ib.qty-ib.reserved_qty,0)),0) q FROM products p LEFT JOIN inventory_balances ib ON ib.product_id=p.id WHERE p.active=1 GROUP BY p.id,p.min_stock HAVING p.min_stock>0 AND q<=p.min_stock) x')->fetchColumn();
$productCount=(int)db()->query('SELECT COUNT(*) FROM products WHERE active=1')->fetchColumn();
$orderCounts=db()->query("SELECT COALESCE(SUM(status IN ('NEW','ALLOCATED','PICKING','PICKED','PACKING')),0) open_orders,COALESCE(SUM(status='PACKED'),0) to_dispatch,COALESCE(SUM(status='RETURN_IN_TRANSIT'),0) returns_due FROM online_orders")->fetch();
$chart=[];
$rows=db()->query("SELECT DATE(created_at) d, COALESCE(SUM(CASE WHEN status='CANCELLED' THEN 0 ELSE GREATEST(grand_total-refund_total,0) END),0) net FROM sales WHERE created_at>=DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)")->fetchAll();
foreach($rows as $row) $chart[$row['d']]=(float)$row['net'];
$days=[];
for($i=29;$i>=0;$i--){ $day=date('Y-m-d', strtotime("-$i day")); $days[$day]=$chart[$day]??0.0; }
$max=max(1, max($days));
$events=recent_role_events(8);
page_header('Super Admin Dashboard');
?>
<p class="muted">All branches and locations. Users, settings, reports and audit. Purchase cost stays on this panel only.</p>
<div class="grid">
<div class="card"><div class="muted">Total Stock</div><div class="metric"><?=e(number_format($stockQty,0))?></div></div>
<div class="card"><div class="muted">Sales (Last 30 Days)</div><div class="metric">₹<?=e(number_format($sales30,2))?></div></div>
<div class="card"><div class="muted">Today Net Sales</div><div class="metric">₹<?=e(number_format($salesToday,2))?></div><div class="muted"><?=e($billsToday)?> bills</div></div>
<div class="card"><div class="muted">Alerts</div><div class="metric"><?=e($low)?></div><div class="muted">Low sellable stock</div></div>
<div class="card"><div class="muted">Products</div><div class="metric"><?=e(number_format($productCount))?></div><div class="muted">Active</div></div>
<div class="card"><div class="muted">Online Orders Open</div><div class="metric"><?=e((int)$orderCounts['open_orders'])?></div><div class="muted">To pick / pack</div></div>
<div class="card"><div class="muted">Ready to Dispatch</div><div class="metric"><?=e((int)$orderCounts['to_dispatch'])?></div><div class="muted">Packed</div></div>
<div class="card"><div class="muted">Returns Due</div><div class="metric"><?=e((int)$orderCounts['returns_due'])?></div><div class="muted">Return / RTO in transit</div></div>
</div>
<div class="panel" style="margin-top:16px"><h3>Sales (Last 30 Days)</h3><div class="bars"><?php foreach($days as $day=>$amount): $h=max(2, (int)round(($amount/$max)*140)); ?><i style="height:<?=$h?>px" title="<?=e($day)?> ₹<?=e(number_format($amount,0))?>"></i><?php endforeach; ?></div></div>
<div class="tiles" style="margin-top:16px">
<a class="tile" href="settings.php"><b>Company Settings</b><span class="muted">Name, GSTIN, invoice prefix, discount limits</span></a>
<a class="tile" href="locations.php"><b>Locations</b><span class="muted">All branches, racks and bins</span></a>
<a class="tile" href="users.php"><b>Users & Roles</b><span class="muted">Manager, godown, cashier, auditor</span></a>
<a class="tile" href="products.php"><b>Products</b><span class="muted">Rates and private purchase cost</span></a>
<a class="tile" href="suppliers.php"><b>Suppliers</b><span class="muted">Purchase parties</span></a>
<a class="tile" href="reports.php"><b>Reports & Audit</b><span class="muted">Sales, margin, and the audit log</span></a>
<a class="tile" href="purchase_new.php"><b>Purchase Inward</b><span class="muted">Receive stock into a location</span></a>
<a class="tile" href="pos.php"><b>POS Billing</b><span class="muted">Bill from any location</span></a>
</div>
<div class="panel" style="margin-top:16px"><h3>Recent Inter-Panel Events</h3><table><tr><th>Time</th><th>Event</th><th>Message</th></tr><?php foreach($events as $ev):?><tr><td><?=e($ev['created_at'])?></td><td><?=e($ev['title'])?></td><td><?=e($ev['message'])?></td></tr><?php endforeach; if(!$events):?><tr><td colspan="3" class="muted">No events yet.</td></tr><?php endif;?></table></div>
<?php page_footer();
