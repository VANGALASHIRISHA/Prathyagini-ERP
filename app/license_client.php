<?php
/*
 PRATYANGI ERP V1.5 - Commercial Annual License Client V2
 Load AFTER db(), cfg(), current_user(), is_super_admin(), e() are defined.
*/

function license_cfg(): array { return cfg()['license'] ?? []; }

function license_log(string $event, string $message=''): void {
    try {
        db()->prepare('INSERT INTO license_client_log(event_type,message) VALUES(?,?)')
            ->execute([$event, mb_substr($message,0,500)]);
    } catch(Throwable $e) {}
}

function license_state_row(): array {
    $row=db()->query('SELECT * FROM license_client_state WHERE id=1')->fetch();
    if(!$row){
        $installation='INS-'.strtoupper(substr(bin2hex(random_bytes(16)),0,24));
        db()->prepare('INSERT INTO license_client_state(id,installation_id) VALUES(1,?)')->execute([$installation]);
        $row=db()->query('SELECT * FROM license_client_state WHERE id=1')->fetch();
    }
    return $row ?: [];
}

function license_installation_id(): string { return (string)(license_state_row()['installation_id'] ?? ''); }

function license_normalize_domain(string $domain): string {
    $domain=strtolower(trim($domain));
    $domain=preg_replace('#^https?://#','',$domain);
    $domain=preg_replace('#/.*$#','',$domain);
    $domain=preg_replace('/:\\d+$/','',$domain);
    return rtrim((string)$domain,'.');
}

function license_server_base(): string {
    $host=function_exists('panel_http_host') ? panel_http_host() : '';
    if($host==='pratyangi.com' || str_ends_with($host,'.pratyangi.com')) return 'https://centrallicense.pratyangi.com';
    return rtrim((string)(license_cfg()['server_url']??''),'/');
}

function license_domain(): string {
    $configured=trim((string)(license_cfg()['installation_domain']??''));
    if($configured!=='') return license_normalize_domain($configured);
    $host=function_exists('panel_http_host') ? panel_http_host() : license_normalize_domain((string)($_SERVER['HTTP_HOST']??''));
    if($host==='pratyangi.com' || str_ends_with($host,'.pratyangi.com')) return 'pratyangi.com';
    return license_normalize_domain($host);
}

function license_app_version(): string {
    $path=dirname(__DIR__).'/VERSION.txt';
    if(is_file($path)){
        $v=trim((string)file_get_contents($path));
        $line=strtok($v,"\r\n");
        if(is_string($line) && $line!=='' && strlen($line)<=60) return $line;
    }
    return 'PRATYANGI-ERP-V1.5';
}

function license_b64u_decode(string $data): string|false {
    $pad=strlen($data)%4;
    if($pad)$data.=str_repeat('=',4-$pad);
    return base64_decode(strtr($data,'-_','+/'),true);
}

function license_public_key_pem(): string {
    $path=(string)(license_cfg()['public_key_path']??'');
    if(!$path || !is_file($path)) return '';
    return (string)file_get_contents($path);
}

function license_verify_token(string $token): ?array {
    $parts=explode('.',$token,2);
    if(count($parts)!==2)return null;
    [$payloadB64,$sigB64]=$parts;
    $json=license_b64u_decode($payloadB64);
    $sig=license_b64u_decode($sigB64);
    $public=license_public_key_pem();
    if($json===false||$sig===false||$public==='')return null;
    $ok=openssl_verify($payloadB64,$sig,$public,OPENSSL_ALGO_SHA256);
    if($ok!==1)return null;
    $p=json_decode($json,true);
    if(!is_array($p))return null;
    if((int)($p['ver']??0)!==1)return null;
    if(!hash_equals((string)($p['installation_id']??''),license_installation_id()))return null;

    $boundDomain=license_normalize_domain((string)($p['domain']??''));
    $currentDomain=license_domain();
    if($boundDomain!=='' && $currentDomain!=='' && !hash_equals($boundDomain,$currentDomain))return null;
    return $p;
}

function license_http_post(string $url,array $data,int $timeout=8): array {
    if(stripos($url,'https://')!==0 && empty(license_cfg()['allow_insecure_http_for_local_testing'])){
        return [false,null,'License server must use HTTPS'];
    }
    $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)return [false,null,'JSON encode failed'];

    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
            CURLOPT_POSTFIELDS=>$json,
            CURLOPT_CONNECTTIMEOUT=>$timeout,
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        $body=curl_exec($ch);
        $err=curl_error($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if($body===false)return [false,null,$err?:'Network error'];
        $decoded=json_decode($body,true);
        if(!is_array($decoded))return [false,null,'Invalid JSON from license server (HTTP '.$code.' from '.preg_replace('#^(https?://[^/]+)(/[^?]*)?.*#','$1$2',$url).'). Reply starts: '.mb_substr(trim(preg_replace('/\s+/',' ',strip_tags((string)$body))),0,140)];
        return [$code>=200&&$code<300&&!empty($decoded['ok']),$decoded,$decoded['error']??('HTTP '.$code)];
    }

    if(!ini_get('allow_url_fopen'))return [false,null,'Enable PHP cURL or allow_url_fopen'];
    $ctx=stream_context_create([
        'http'=>[
            'method'=>'POST',
            'header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'=>$json,
            'timeout'=>$timeout,
            'ignore_errors'=>true,
        ],
        'ssl'=>[
            'verify_peer'=>true,
            'verify_peer_name'=>true,
        ],
    ]);
    $body=@file_get_contents($url,false,$ctx);
    if($body===false)return [false,null,'License server unreachable'];
    $decoded=json_decode($body,true);
    if(!is_array($decoded))return [false,null,'Invalid JSON from license server. Reply starts: '.mb_substr(trim(preg_replace('/\s+/',' ',strip_tags((string)$body))),0,140)];
    return [!empty($decoded['ok']),$decoded,$decoded['error']??'Request failed'];
}

function license_save_token(string $token,?array $payload=null,?string $error=null): void {
    $payload=$payload?:license_verify_token($token);
    db()->prepare('UPDATE license_client_state SET signed_token=?,payload_json=?,last_check_at=NOW(),last_ok_at=IF(? IS NULL,NOW(),last_ok_at),last_error=? WHERE id=1')
        ->execute([
            $token,
            $payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,
            $error,
            $error
        ]);
}

function license_save_error(string $error): void {
    db()->prepare('UPDATE license_client_state SET last_check_at=NOW(),last_error=? WHERE id=1')->execute([mb_substr($error,0,500)]);
    license_log('CHECK_ERROR',$error);
}

function license_activate_key(string $licenseKey): array {
    $base=license_server_base();
    if($base==='')return [false,'License server_url missing in config.php'];
    [$ok,$res,$err]=license_http_post($base.'/api/v1/activate.php',[
        'license_key'=>$licenseKey,
        'installation_id'=>license_installation_id(),
        'domain'=>license_domain(),
        'app_version'=>license_app_version(),
    ]);
    if(!$ok || !$res || empty($res['token'])){
        license_save_error($err);
        return [false,$err];
    }
    $payload=license_verify_token((string)$res['token']);
    if(!$payload){
        license_save_error('Server returned token but local RSA verification failed');
        return [false,'License signature/domain verification failed'];
    }
    license_save_token((string)$res['token'],$payload,null);
    license_log('ACTIVATE_OK','License activated · '.$payload['status']);
    return [true,'License activated successfully'];
}

function license_refresh_now(): array {
    $row=license_state_row();
    $token=trim((string)($row['signed_token']??''));
    if($token==='')return [false,'License is not activated'];
    $base=license_server_base();
    if($base==='')return [false,'License server_url missing in config.php'];

    [$ok,$res,$err]=license_http_post($base.'/api/v1/validate.php',[
        'token'=>$token,
        'installation_id'=>license_installation_id(),
        'domain'=>license_domain(),
        'app_version'=>license_app_version(),
    ]);
    if(!$ok || !$res || empty($res['token'])){
        license_save_error($err);
        return [false,$err];
    }
    $payload=license_verify_token((string)$res['token']);
    if(!$payload){
        license_save_error('Server token signature/domain verification failed');
        return [false,'License signature/domain verification failed'];
    }
    license_save_token((string)$res['token'],$payload,null);
    license_log('VALIDATE_OK','Status '.$payload['status']);
    return [true,'License refreshed'];
}

function license_effective_state(bool $tryRefresh=true): array {
    $row=license_state_row();
    $token=trim((string)($row['signed_token']??''));
    if($token==='')return ['status'=>'UNLICENSED','message'=>'License activation required','payload'=>null,'offline'=>false];

    $payload=license_verify_token($token);
    if(!$payload)return ['status'=>'UNLICENSED','message'=>'Invalid, copied or domain-mismatched license token','payload'=>null,'offline'=>false];

    $now=time();
    $refreshAt=strtotime((string)($payload['refresh_after']??''))?:0;
    $lastCheckAt=strtotime((string)($row['last_check_at']??''))?:0;
    $retryAfterSeconds=max(300,(int)(license_cfg()['retry_after_seconds']??3600));
    $canRetry=($lastCheckAt===0 || ($now-$lastCheckAt)>=$retryAfterSeconds);
    if($tryRefresh && $refreshAt>0 && $now>=$refreshAt && $canRetry){
        [$ok,] = license_refresh_now();
        if($ok){
            $row=license_state_row();
            $payload=license_verify_token((string)($row['signed_token']??'')) ?: $payload;
        }
    }

    $signedStatus=strtoupper((string)($payload['status']??'ACTIVE'));
    if(in_array($signedStatus,['SUSPENDED','REVOKED','CUSTOMER_DISABLED'],true)){
        return ['status'=>$signedStatus,'message'=>'License '.$signedStatus,'payload'=>$payload,'offline'=>false];
    }

    $offlineUntil=strtotime((string)($payload['offline_valid_until']??''))?:0;
    $lastErr=trim((string)(license_state_row()['last_error']??''));
    if($offlineUntil>0 && $now>$offlineUntil){
        return ['status'=>'VALIDATION_REQUIRED','message'=>'License server validation required before write operations','payload'=>$payload,'offline'=>true];
    }

    $today=strtotime(date('Y-m-d'));
    $start=strtotime((string)($payload['starts_at']??''))?:0;
    $exp=strtotime((string)($payload['expires_at']??''))?:0;
    $grace=strtotime((string)($payload['grace_until']??''))?:0;

    if($start && $today<$start){
        $status='NOT_STARTED';
    }elseif($exp && $today<=$exp){
        $days=(int)floor(($exp-$today)/86400);
        $status=$days<=30?'EXPIRING':'ACTIVE';
    }elseif($grace && $today<=$grace){
        $status='GRACE';
    }else{
        $status='EXPIRED';
    }

    return [
        'status'=>$status,
        'message'=>match($status){
            'NOT_STARTED'=>'License start date has not arrived',
            'EXPIRING'=>'License renewal due soon',
            'GRACE'=>'License expired; grace period active',
            'EXPIRED'=>'License expired; ERP is read-only until renewal',
            default=>'License active'
        },
        'payload'=>$payload,
        'offline'=>$lastErr!=='' && $refreshAt>0 && $now>=$refreshAt
    ];
}

function license_can_write(): bool {
    return in_array(license_effective_state(false)['status'],['ACTIVE','EXPIRING','GRACE'],true);
}

function license_has_feature(string $feature): bool {
    $p=license_effective_state(false)['payload']??null;
    if(!$p)return false;
    $features=array_values(array_filter(array_map('strval',$p['features']??[])));
    if(!$features)return true; // backward compatibility for older license tokens
    return in_array($feature,$features,true) || in_array('all',$features,true);
}

function license_max_users(): int { return max(0,(int)(license_effective_state(false)['payload']['max_users']??0)); }
function license_max_branches(): int { return max(0,(int)(license_effective_state(false)['payload']['max_branches']??0)); }

function license_require_user_capacity(?int $excludeUserId=null): void {
    $max=license_max_users();
    if($max<=0)return;
    if($excludeUserId){
        $st=db()->prepare('SELECT COUNT(*) FROM users WHERE active=1 AND id<>?');
        $st->execute([$excludeUserId]);
        $count=(int)$st->fetchColumn();
    }else{
        $count=(int)db()->query('SELECT COUNT(*) FROM users WHERE active=1')->fetchColumn();
    }
    if($count >= $max){
        http_response_code(409);
        exit('License user limit reached. Upgrade the license to activate another user.');
    }
}

function license_require_reactivation_capacity(int $userId, bool $requestedActive): void {
    if(!$requestedActive)return;
    $st=db()->prepare('SELECT active FROM users WHERE id=? LIMIT 1');
    $st->execute([$userId]);
    $wasActive=(int)$st->fetchColumn()===1;
    if(!$wasActive) license_require_user_capacity($userId);
}

function license_banner_html(): string {
    if(!current_user())return '';
    $s=license_effective_state(false);
    $p=$s['payload']??[];
    $status=$s['status'];
    if($status==='ACTIVE')return '';
    $expiry=e($p['expires_at']??'');
    $msg=e($s['message']);
    $action=is_super_admin()?' · <a href="license_status.php">License Status</a>':' · Contact Super Admin';
    return '<div style="padding:10px;background:#fff3cd;border:1px solid #e2c04c;margin:10px 20px"><b>LICENSE '.e($status).':</b> '.$msg.($expiry?' · Expiry: '.$expiry:'').$action.'</div>';
}

function license_feature_for_script(string $script): ?string {
    $script=basename($script);
    $map=[
        'pos.php'=>'pos','invoice.php'=>'pos','sale_return.php'=>'pos','sale_void.php'=>'pos','cashier_dashboard.php'=>'pos',
        'stock.php'=>'stock','stock_adjust.php'=>'stock','stock_count.php'=>'stock','stock_transfer.php'=>'stock','stock_reports.php'=>'stock','barcode_labels.php'=>'stock','location_labels.php'=>'stock','godown_dashboard.php'=>'stock','product_finder.php'=>'stock','product_search.php'=>'stock','product_stock.php'=>'stock',
        'purchase_new.php'=>'purchase','purchases.php'=>'purchase','purchase_return.php'=>'purchase','suppliers.php'=>'purchase','supplier_edit.php'=>'purchase','vendors.php'=>'purchase','vendor_form.php'=>'purchase','vendor_view.php'=>'purchase','vendor_payments.php'=>'purchase','purchase_bill.php'=>'purchase','purchase_view.php'=>'purchase','purchase_history.php'=>'purchase','purchase_file.php'=>'purchase','purchase_reports.php'=>'purchase','gst_reports.php'=>'purchase','purchase_settings.php'=>'purchase','goods_receipt.php'=>'purchase',
        'online_orders.php'=>'online_orders','online_order_new.php'=>'online_orders','online_pick.php'=>'online_orders','online_pack.php'=>'online_orders','online_dispatch.php'=>'online_orders','online_after_dispatch.php'=>'online_orders','online_return_receive.php'=>'online_orders','order_create.php'=>'online_orders','catalog_products.php'=>'online_orders','catalog_stock.php'=>'online_orders',
        'reports.php'=>'reports','audit.php'=>'reports',
    ];
    return $map[$script]??null;
}

// Returns the request's hostname with any port stripped, handling the
// bracketed IPv6 form (e.g. "[::1]:8000") as well as "host:port".
function license_request_hostname(): string {
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') return '';
    if (str_starts_with($host, '[')) {
        $close = strpos($host, ']');
        return $close !== false ? substr($host, 1, $close - 1) : $host;
    }
    return explode(':', $host, 2)[0];
}

// Development-only bypass: true only when config.php has local_development_bypass
// enabled AND the request is for localhost/127.0.0.1/::1. Never true for a public domain.
function license_local_bypass_active(): bool {
    if ((license_cfg()['local_development_bypass'] ?? false) !== true) return false;
    return in_array(license_request_hostname(), ['localhost', '127.0.0.1', '::1'], true);
}

function license_enforce_request(): void {
    $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
    $allowAlways=['login.php','logout.php','forgot_password.php','setup_admin.php','license_activate.php','license_status.php'];
    if(in_array($script,$allowAlways,true))return;
    if(license_local_bypass_active())return;

    $state=license_effective_state(true);
    $status=$state['status'];

    if($status==='UNLICENSED'){
        if((current_user()['role']??'')==='super_admin'){
            header('Location: license_activate.php');exit;
        }
        http_response_code(402);exit('ERP license activation required. Contact your Super Admin.');
    }

    if(in_array($status,['SUSPENDED','REVOKED','CUSTOMER_DISABLED'],true)){
        if((current_user()['role']??'')==='super_admin'){
            header('Location: license_status.php');exit;
        }
        http_response_code(403);exit('ERP license is '.$status.'. Contact software owner.');
    }

    if($status==='NOT_STARTED'){
        if((current_user()['role']??'')==='super_admin'){
            header('Location: license_status.php');exit;
        }
        http_response_code(403);exit('ERP license has not started yet.');
    }

    $feature=license_feature_for_script($script);
    if($feature!==null && !license_has_feature($feature)){
        http_response_code(403);
        exit('This module is not included in the current ERP license plan: '.$feature);
    }

    if(in_array($status,['EXPIRED','VALIDATION_REQUIRED'],true) && ($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
        http_response_code(402);
        exit('ERP is in READ-ONLY mode. Renew/validate the license before creating or changing data.');
    }
}
