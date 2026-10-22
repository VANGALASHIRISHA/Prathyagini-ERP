<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_role(['cashier']);
$uid=(int)current_user()['id'];
$st=db()->prepare("SELECT COALESCE(SUM(GREATEST(grand_total-refund_total,0)),0),COUNT(*) FROM sales WHERE cashier_id=? AND DATE(created_at)=CURDATE() AND status<>'CANCELLED'");$st->execute([$uid]);[$salesToday,$billsToday]=array_values($st->fetch());
[$scope,$params]=location_scope_sql('ib.location_id');
$st=db()->prepare('SELECT COALESCE(SUM(GREATEST(ib.qty-ib.reserved_qty,0)),0) FROM inventory_balances ib WHERE (ib.qty-ib.reserved_qty)>0'.$scope);$st->execute($params);$stockQty=(float)$st->fetchColumn();
$st=db()->prepare('SELECT id,invoice_no,customer_name,grand_total,status,created_at FROM sales WHERE cashier_id=? ORDER BY id DESC LIMIT 8');$st->execute([$uid]);$recent=$st->fetchAll();
page_header('Cashier Dashboard');
?>
<p class="muted">POS billing, available stock only, and your own invoices. Purchase cost, users and settings are not on this panel.</p>
<div class="grid">
<div class="card"><div class="muted">My Today Sales</div><div class="metric">₹<?=e(number_format((float)$salesToday,2))?></div></div>
<div class="card"><div class="muted">My Bills Today</div><div class="metric"><?=e((int)$billsToday)?></div></div>
<div class="card"><div class="muted">Available Stock</div><div class="metric"><?=e(number_format($stockQty,0))?></div></div>
</div>
<div class="tiles" style="margin-top:16px">
<a class="tile" href="pos.php"><b>POS Billing</b><span class="muted">Scan a garment barcode and take payment</span></a>
<a class="tile" href="stock.php"><b>Available Stock Only</b><span class="muted">Sellable quantity in your locations</span></a>
<a class="tile" href="cashier_invoices.php"><b>My Invoices</b><span class="muted">Bills created by you</span></a>
</div>
<div class="panel" style="margin-top:16px"><h3>My Recent Bills</h3><table><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Status</th></tr><?php foreach($recent as $r):?><tr><td><a href="invoice.php?id=<?=e($r['id'])?>"><?=e($r['invoice_no'])?></a></td><td><?=e($r['customer_name']?:'Walk-in')?></td><td>₹<?=e(number_format((float)$r['grand_total'],2))?></td><td><?=e($r['status'])?></td></tr><?php endforeach; if(!$recent):?><tr><td colspan="4" class="muted">No bills yet.</td></tr><?php endif;?></table></div>
<?php page_footer();
