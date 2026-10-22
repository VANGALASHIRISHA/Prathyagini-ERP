<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_role(['cashier']);
$uid=(int)current_user()['id'];
$st=db()->prepare('SELECT id,invoice_no,customer_name,grand_total,refund_total,status,payment_mode,created_at FROM sales WHERE cashier_id=? ORDER BY id DESC LIMIT 200');
$st->execute([$uid]);
$rows=$st->fetchAll();
page_header('My Invoices');
?>
<p class="muted">Only invoices billed by you. Other cashiers' bills are not listed here.</p>
<table><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Payment</th><th>Status</th><th>Total</th></tr>
<?php foreach($rows as $r):?><tr><td><a href="invoice.php?id=<?=e($r['id'])?>"><?=e($r['invoice_no'])?></a></td><td><?=e($r['created_at'])?></td><td><?=e($r['customer_name']?:'Walk-in')?></td><td><?=e($r['payment_mode'])?></td><td><?=e($r['status'])?></td><td>₹<?=e(number_format((float)$r['grand_total'],2))?></td></tr><?php endforeach; if(!$rows):?><tr><td colspan="6" class="muted">No invoices yet.</td></tr><?php endif;?>
</table>
<?php page_footer();
