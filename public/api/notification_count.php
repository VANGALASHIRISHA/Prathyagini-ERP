<?php
require __DIR__.'/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
login_required();
echo json_encode(['ok'=>true,'unread'=>unread_event_count(),'server_time'=>date('c')]);
