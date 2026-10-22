<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; require_role(['manager']);
$ids=assigned_location_ids();
$rows=[];
if($ids){
  $ph=implode(',',array_fill(0,count($ids),'?'));
  $sql="SELECT u.name,u.username,u.role,u.active,GROUP_CONCAT(DISTINCT l.code ORDER BY l.code SEPARATOR ', ') locations
        FROM users u
        JOIN user_locations ul ON ul.user_id=u.id
        JOIN locations l ON l.id=ul.location_id
        WHERE u.role<>'super_admin' AND ul.location_id IN ($ph)
        GROUP BY u.id
        ORDER BY u.role,u.name";
  $st=db()->prepare($sql);$st->execute($ids);$rows=$st->fetchAll();
}
page_header('Users & Listings');
?>
<p class="muted">Staff assigned to your locations. This list is read-only. Purchase cost is not shown.</p>
<table><tr><th>Name</th><th>Username</th><th>Role</th><th>Locations</th><th>Status</th></tr>
<?php foreach($rows as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['username'])?></td><td><?=e(role_label($r['role']))?></td><td><?=e($r['locations'])?></td><td><?=$r['active']?'Active':'Inactive'?></td></tr><?php endforeach; if(!$rows):?><tr><td colspan="5" class="muted">No staff share your locations yet.</td></tr><?php endif;?>
</table>
<?php page_footer();
