<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_role(['auditor', 'super_admin']);
$q = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
$events24 = $q("SELECT COUNT(*) FROM audit_logs WHERE created_at >= (NOW() - INTERVAL 1 DAY)");
$logins24 = $q("SELECT COUNT(*) FROM audit_logs WHERE action='LOGIN' AND created_at >= (NOW() - INTERVAL 1 DAY)");
$discrepancies = $q("SELECT COUNT(*) FROM stock_transfers WHERE has_discrepancy=1");
$inTransit = $q("SELECT COUNT(*) FROM stock_transfers WHERE status='DISPATCHED'");
$pendingCounts = $q("SELECT COUNT(*) FROM stock_count_sessions WHERE status IN ('OPEN','SUBMITTED')");
$voids = $q("SELECT COUNT(*) FROM sales WHERE status IN ('CANCELLED','RETURNED_PARTIAL','RETURNED_FULL') AND created_at >= (NOW() - INTERVAL 30 DAY)");
$todaySales = db()->query("SELECT COUNT(*) c, COALESCE(SUM(grand_total-refund_total),0) t FROM sales WHERE status<>'CANCELLED' AND created_at >= CURDATE()")->fetch();
$recent = db()->query("SELECT a.created_at,a.action,a.entity_type,a.entity_id,COALESCE(u.name,'System') uname FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 10")->fetchAll();
$disc = db()->query("SELECT t.transfer_no,t.received_at,fl.code from_code,tl.code to_code FROM stock_transfers t JOIN locations fl ON fl.id=t.from_location_id JOIN locations tl ON tl.id=t.to_location_id WHERE t.has_discrepancy=1 ORDER BY t.id DESC LIMIT 5")->fetchAll();
page_header('Auditor Dashboard');
?>
<p class="muted">Read-only oversight: sales, stock movement, transfer discrepancies, physical counts and the audit trail. You cannot change any data.</p>
<div class="grid">
<div class="card"><div class="muted">Audit events (24h)</div><div class="metric"><?= $events24 ?></div></div>
<div class="card"><div class="muted">Logins (24h)</div><div class="metric"><?= $logins24 ?></div></div>
<div class="card"><div class="muted">Today's net sales</div><div class="metric">₹<?= e(number_format((float)$todaySales['t'], 2)) ?></div><div class="muted"><?= (int)$todaySales['c'] ?> bills</div></div>
<div class="card"><div class="muted">Cancelled / returned bills (30d)</div><div class="metric"><?= $voids ?></div></div>
<div class="card"><div class="muted">Transfers with shortage</div><div class="metric"><?= $discrepancies ?></div></div>
<div class="card"><div class="muted">Stock in transit</div><div class="metric"><?= $inTransit ?></div></div>
<div class="card"><div class="muted">Counts not yet approved</div><div class="metric"><?= $pendingCounts ?></div></div>
</div>
<div class="tiles" style="margin-top:16px">
<a class="tile" href="audit.php"><b>Audit Log</b><span class="muted">Every action by every user, with filters and CSV export</span></a>
<a class="tile" href="stock_reports.php"><b>Stock Reports</b><span class="muted">Ledger, in-transit, transfer discrepancies, damage, ageing</span></a>
<a class="tile" href="reports.php"><b>Sales Reports</b><span class="muted">Sales, cancellations and returns</span></a>
<a class="tile" href="stock.php"><b>Stock</b><span class="muted">Stock by location</span></a>
<a class="tile" href="scan_find.php"><b>Scan &amp; Find</b><span class="muted">Verify any piece and its location</span></a>
<a class="tile" href="product_finder.php"><b>Product Finder</b><span class="muted">Search products</span></a>
</div>
<div class="panel" style="margin-top:16px"><h3>Transfers with shortage</h3><table><tr><th>Transfer</th><th>From</th><th>To</th><th>Received</th></tr>
<?php foreach ($disc as $d): ?><tr><td><?= e($d['transfer_no']) ?></td><td><?= e($d['from_code']) ?></td><td><?= e($d['to_code']) ?></td><td><?= e($d['received_at']) ?></td></tr><?php endforeach; if (!$disc): ?><tr><td colspan="4" class="muted">No discrepancies.</td></tr><?php endif; ?></table></div>
<div class="panel" style="margin-top:16px"><h3>Latest audit events</h3><table><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th></tr>
<?php foreach ($recent as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['uname']) ?></td><td><?= e($r['action']) ?></td><td><?= e(($r['entity_type'] ?? '') . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?></td></tr><?php endforeach; ?></table>
<p><a href="audit.php">Open full audit log →</a></p></div>
<?php page_footer();
