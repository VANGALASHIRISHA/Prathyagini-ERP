<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
login_required();
if (!in_array(current_user()['role'] ?? '', ['super_admin', 'auditor'], true)) { http_response_code(403); exit('Super Admin or Auditor only'); }

$G = fn(string $k): string => trim((string)($_GET[$k] ?? ''));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $G('from')) ? $G('from') : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $G('to')) ? $G('to') : '';
$userId = (int)($_GET['user_id'] ?? 0);
$action = $G('action');
$entity = $G('entity');
$q = $G('q');
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$args = [];
if ($from !== '') { $where[] = 'a.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'a.created_at <= ?'; $args[] = $to . ' 23:59:59'; }
if ($userId > 0) { $where[] = 'a.user_id = ?'; $args[] = $userId; }
if ($action !== '') { $where[] = 'a.action = ?'; $args[] = $action; }
if ($entity !== '') { $where[] = 'a.entity_type = ?'; $args[] = $entity; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(a.action LIKE ? OR a.entity_type LIKE ? OR CAST(a.entity_id AS CHAR) LIKE ? OR a.ip_address LIKE ? OR a.meta_json LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
    array_push($args, $like, $like, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$base = 'FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id' . $whereSql;

// CSV export of the current filter (capped at 20,000 rows).
if ($G('export') === 'csv') {
    $st = db()->prepare("SELECT a.id,a.created_at,COALESCE(u.name,'System') uname,u.username,a.action,a.entity_type,a.entity_id,a.ip_address,a.meta_json $base ORDER BY a.id DESC LIMIT 20000");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Date', 'User', 'Username', 'Action', 'Entity', 'Entity ID', 'IP', 'Details']);
    $safe = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") ? "'" . $v : $v; // block CSV formula injection
    while ($r = $st->fetch()) fputcsv($out, array_map($safe, array_values($r)));
    exit;
}

$cnt = db()->prepare("SELECT COUNT(*) $base");
$cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$st = db()->prepare("SELECT a.*,u.name,u.username,u.role $base ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$rows = $st->fetchAll();

$users = db()->query('SELECT id,name,username FROM users ORDER BY name')->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$entities = db()->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type<>'' ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
$today = (int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE created_at >= CURDATE()")->fetchColumn();
$todayUsers = (int)db()->query("SELECT COUNT(DISTINCT user_id) FROM audit_logs WHERE created_at >= CURDATE() AND user_id IS NOT NULL")->fetchColumn();

function audit_tone(string $a): string {
    if (preg_match('/DELETE|VOID|CANCEL|REJECT|FAIL|DENIED|LOCK|RESET|DAMAGE/i', $a)) return 'danger';
    if (preg_match('/LOGIN|LOGOUT|PASSWORD|ROLE|PERMISSION|USER/i', $a)) return 'info';
    if (preg_match('/CREATE|APPROVE|RECEIVE|INWARD|FINALIZE|DISPATCH/i', $a)) return 'ok';
    return 'neutral';
}
function audit_meta_html(?string $json): string {
    if ($json === null || $json === '' || $json === '[]' || $json === '{}' || $json === 'null') return '<span class="muted">—</span>';
    $d = json_decode($json, true);
    if (!is_array($d)) return '<small>' . e($json) . '</small>';
    $parts = [];
    foreach ($d as $k => $v) {
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        elseif (is_bool($v)) $v = $v ? 'yes' : 'no';
        $parts[] = '<span class="au-kv"><b>' . e(str_replace('_', ' ', (string)$k)) . ':</b> ' . e((string)$v) . '</span>';
    }
    return implode(' ', $parts);
}
$qs = fn(array $o = []) => http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'user_id' => $userId ?: '', 'action' => $action, 'entity' => $entity, 'q' => $q], $o), fn($v) => $v !== '' && $v !== null));

page_header('Security Audit Log');
?>
<style>
.au-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.au-stats .panel{margin:0;padding:10px 16px;min-width:140px}.au-stats b{font-size:1.4em;display:block}
.au-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end}
.au-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.78em;font-weight:700;white-space:nowrap}
.au-badge.ok{background:#dcfce7;color:#166534}.au-badge.danger{background:#fee2e2;color:#991b1b}
.au-badge.info{background:#dbeafe;color:#1e40af}.au-badge.neutral{background:#e5e7eb;color:#374151}
.au-kv{display:inline-block;margin:0 8px 2px 0;font-size:.85em}
.au-pager{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}
@media(max-width:600px){.au-stats .panel{flex:1 1 40%}}
</style>
<div class="au-stats">
 <div class="panel"><span class="muted">Matching events</span><b><?= number_format($total) ?></b></div>
 <div class="panel"><span class="muted">Events today</span><b><?= number_format($today) ?></b></div>
 <div class="panel"><span class="muted">Active users today</span><b><?= $todayUsers ?></b></div>
</div>
<form method="get" class="panel">
 <div class="au-filter">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div><label>User</label><select name="user_id"><option value="">All users</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $userId === (int)$u['id'] ? ' selected' : '' ?>><?= e($u['name'] . ' (' . $u['username'] . ')') ?></option><?php endforeach; ?></select></div>
  <div><label>Action</label><select name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option<?= $action === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div><label>Entity</label><select name="entity"><option value="">All entities</option><?php foreach ($entities as $en): ?><option<?= $entity === $en ? ' selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select></div>
  <div><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="IP, ID, details…"></div>
 </div>
 <div class="actions" style="margin-top:10px">
  <button class="btn">Apply Filters</button>
  <a class="btn secondary" href="audit.php">Clear</a>
  <a class="btn secondary" href="audit.php?<?= e($qs(['export' => 'csv'])) ?>">Export CSV</a>
 </div>
</form>
<div class="au-pager muted">Showing <?= $total ? number_format(($page - 1) * $perPage + 1) . '–' . number_format(($page - 1) * $perPage + count($rows)) : '0' ?> of <?= number_format($total) ?> · Page <?= $page ?> of <?= $pages ?></div>
<table>
<tr><th>Date / Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
 <td><?= e(date('d-m-Y H:i:s', strtotime($r['created_at']))) ?></td>
 <td><?= $r['name'] ? e($r['name']) . '<br><small class="muted">' . e($r['username'] . ' · ' . role_label((string)$r['role'])) . '</small>' : 'System' ?></td>
 <td><span class="au-badge <?= audit_tone($r['action']) ?>"><?= e($r['action']) ?></span></td>
 <td><?= $r['entity_type'] ? e($r['entity_type']) . ($r['entity_id'] !== null ? ' #' . (int)$r['entity_id'] : '') : '<span class="muted">—</span>' ?></td>
 <td><?= e($r['ip_address'] ?: '—') ?></td>
 <td><?= audit_meta_html($r['meta_json']) ?></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="muted">No audit events match these filters.</td></tr><?php endif; ?>
</table>
<div class="au-pager">
 <?php if ($page > 1): ?><a class="btn secondary" href="audit.php?<?= e($qs(['page' => 1])) ?>">« First</a><a class="btn secondary" href="audit.php?<?= e($qs(['page' => $page - 1])) ?>">‹ Prev</a><?php endif; ?>
 <?php if ($page < $pages): ?><a class="btn secondary" href="audit.php?<?= e($qs(['page' => $page + 1])) ?>">Next ›</a><a class="btn secondary" href="audit.php?<?= e($qs(['page' => $pages])) ?>">Last »</a><?php endif; ?>
</div>
<?php page_footer();
