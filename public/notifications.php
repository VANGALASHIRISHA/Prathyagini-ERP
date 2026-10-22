<?php
require __DIR__.'/../app/bootstrap.php'; require __DIR__.'/../app/layout.php'; login_required();
$u=current_user();
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    if(isset($_POST['mark_all'])){
        $events=recent_role_events(100);
        $st=db()->prepare('INSERT IGNORE INTO workflow_event_reads(event_id,user_id,read_at) VALUES(?,?,NOW())');
        foreach($events as $ev)$st->execute([(int)$ev['id'],(int)$u['id']]);
        redirect('notifications.php');
    }
    $id=(int)($_POST['event_id']??0);
    if($id>0){
        $visible=false; foreach(recent_role_events(100) as $ev){if((int)$ev['id']===$id){$visible=true;break;}}
        if($visible)db()->prepare('INSERT IGNORE INTO workflow_event_reads(event_id,user_id,read_at) VALUES(?,?,NOW())')->execute([$id,(int)$u['id']]);
        redirect('notifications.php');
    }
}
$events=recent_role_events(100); page_header('Notifications / Panel Communication');
?>
<div class="panel"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="btn" name="mark_all" value="1">Mark all as read</button></form></div>
<div class="panel" style="margin-top:14px">
<table><thead><tr><th>Time</th><th>Event</th><th>Message</th><th>Location</th><th>Status</th></tr></thead><tbody>
<?php foreach($events as $ev): ?>
<tr><td><?=e($ev['created_at'])?></td><td><b><?=e($ev['title'])?></b><br><span class="muted"><?=e($ev['event_key'])?></span></td><td><?=e($ev['message'])?></td><td><?=e($ev['location_code']?:'ALL')?></td><td><?php if($ev['is_read']): ?>Read<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=e($ev['id'])?>"><button class="btn">Mark read</button></form><?php endif;?></td></tr>
<?php endforeach; if(!$events): ?><tr><td colspan="5">No workflow notifications yet.</td></tr><?php endif;?></tbody></table></div>
<?php page_footer();
