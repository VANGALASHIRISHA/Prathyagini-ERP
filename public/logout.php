<?php require __DIR__.'/../app/bootstrap.php';
if(current_user()) { try{audit('LOGOUT','user',(int)current_user()['id']);}catch(Throwable $e){} }
logout_session();
header('Location: login.php'); exit;
