<?php
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.example.php to config.php and update DB credentials.');
}
$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');
ini_set('display_errors', !empty($config['app']['debug']) ? '1' : '0');
ini_set('log_errors','1');
ini_set('session.use_strict_mode','1');
session_name($config['app']['session_name'] ?? 'PRATYANGI_ERP');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'], $config['db']['port'], $config['db']['name'], $config['db']['charset']
);
try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Check config.php');
}

function db(): PDO { global $pdo; return $pdo; }
function cfg(): array { global $config; return $config; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419); exit('Invalid CSRF token');
    }
}
// One-time token per rendered form: a double click or browser resubmit is detected and not processed twice.
// Session locking serialises requests of the same user, so the second request always sees the first claim.
function form_once_token(string $form): string {
    $t = bin2hex(random_bytes(16));
    $list = $_SESSION['form_once'][$form] ?? [];
    $list[$t] = time();
    $_SESSION['form_once'][$form] = array_slice($list, -20, null, true);
    return $t;
}
function form_once_claim(string $form): bool {
    $t = (string)($_POST['form_once'] ?? '');
    if ($t === '' || !isset($_SESSION['form_once'][$form][$t])) return false;
    unset($_SESSION['form_once'][$form][$t]);
    return true;
}
function user_error_message(Throwable $e, string $duplicateMessage = 'This record already exists.'): string {
    if ($e instanceof PDOException) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return $duplicateMessage;
        error_log('DB error: ' . $e->getMessage());
        return 'Database error. Please try again or contact the administrator.';
    }
    return $e->getMessage();
}
function setting(string $key, ?string $default=null): ?string {
    static $cache=[];
    if (array_key_exists($key,$cache)) return $cache[$key];
    try {
        $st=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $st->execute([$key]);
        $v=$st->fetchColumn();
        return $cache[$key]=($v===false?$default:(string)$v);
    } catch (Throwable $e) { return $default; }
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function logout_session(): void {
    $_SESSION=[];
    if (ini_get('session.use_cookies')) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
    }
    session_destroy();
}
function login_required(): void {
    $sessionUser=current_user();
    if (!$sessionUser) redirect('login.php');
    $st=db()->prepare('SELECT id,name,username,role,active,auth_version FROM users WHERE id=? LIMIT 1');
    $st->execute([(int)$sessionUser['id']]);
    $dbUser=$st->fetch();
    if(!$dbUser || !(int)$dbUser['active'] || (int)($sessionUser['auth_version']??0)!==(int)$dbUser['auth_version']){
        logout_session();
        redirect('login.php?expired=1');
    }
    $_SESSION['user']=['id'=>(int)$dbUser['id'],'name'=>$dbUser['name'],'username'=>$dbUser['username'],'role'=>$dbUser['role'],'auth_version'=>(int)$dbUser['auth_version']];
    $timeout=max(5,(int)(setting('session_timeout_minutes','30')??30))*60;
    if (!empty($_SESSION['last_activity']) && time()-$_SESSION['last_activity']>$timeout) {
        logout_session();
        redirect('login.php?expired=1');
    }
    $_SESSION['last_activity']=time();
}
function has_permission(string $perm): bool {
    $u = current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'super_admin') return true;
    $st = db()->prepare('SELECT 1 FROM role_permissions WHERE role=? AND permission=? LIMIT 1');
    $st->execute([$u['role'], $perm]);
    return (bool)$st->fetchColumn();
}
function require_permission(string $perm): void {
    login_required();
    if (!has_permission($perm)) { http_response_code(403); exit('Access denied'); }
}
function audit(string $action, ?string $entity=null, ?int $entityId=null, array $meta=[]): void {
    $u=current_user();
    $st=db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,meta_json,ip_address,created_at) VALUES(?,?,?,?,?,?,NOW())');
    $st->execute([$u['id']??null,$action,$entity,$entityId,json_encode($meta,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??null]);
}
function assigned_location_ids(): array {
    $u=current_user(); if(!$u) return [];
    if(($u['role']??'')==='super_admin') return [];
    $st=db()->prepare('SELECT location_id FROM user_locations WHERE user_id=?');
    $st->execute([$u['id']]);
    return array_map('intval', array_column($st->fetchAll(),'location_id'));
}
function location_scope_sql(string $column='ib.location_id'): array {
    $u=current_user();
    if (!$u) return [' AND 1=0',[]];
    if (($u['role']??'')==='super_admin') return ['',[]];
    $ids=assigned_location_ids();
    if (!$ids) return [' AND 1=0',[]];
    return [' AND '.$column.' IN ('.implode(',', array_fill(0,count($ids),'?')).')',$ids];
}
function can_access_location(int $locationId): bool {
    if (is_super_admin()) return true;
    return in_array($locationId, assigned_location_ids(), true);
}
function is_super_admin(): bool { return (current_user()['role'] ?? '') === 'super_admin'; }
function require_super_admin(): void { login_required(); if(!is_super_admin()){ http_response_code(403); exit('Super Admin only'); } }
function sale_fully_in_user_scope(int $saleId): bool {
    if (is_super_admin()) return true;
    $ids=assigned_location_ids();
    if (!$ids) return false;
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=db()->prepare("SELECT COUNT(*) FROM sale_items WHERE sale_id=? AND location_id NOT IN ($ph)");
    $st->execute([$saleId,...$ids]);
    return (int)$st->fetchColumn()===0;
}
function can_view_sale(array $sale): bool {
    $u=current_user(); if(!$u) return false;
    if(is_super_admin()) return true;
    if(has_permission('pos') && (int)$sale['cashier_id']===(int)$u['id']) return true;
    return has_permission('view_reports') && sale_fully_in_user_scope((int)$sale['id']);
}
function user_discount_cap_pct(): float {
    $role=current_user()['role']??'';
    if($role==='super_admin') return 100.0;
    if($role==='manager') return max(0,(float)(setting('manager_max_discount_pct','0')??0));
    return max(0,(float)(setting('cashier_max_discount_pct','0')??0));
}


function role_home_url(?string $role=null): string {
    $role = $role ?? (current_user()['role'] ?? '');
    return match($role) {
        'super_admin' => 'super_admin_dashboard.php',
        'manager' => 'manager_dashboard.php',
        'godown' => 'godown_dashboard.php',
        'cashier' => 'cashier_dashboard.php',
        'auditor' => 'auditor_dashboard.php',
        default => 'dashboard.php',
    };
}
function require_role(array $roles): void {
    login_required();
    $role=current_user()['role']??'';
    if(!in_array($role,$roles,true)){http_response_code(403);exit('Access denied');}
}
function publish_event(string $eventKey,string $title,string $message,array $roles,?string $entityType=null,?int $entityId=null,?int $locationId=null): int {
    try {
        $roles=array_values(array_unique(array_filter($roles)));
        if(!$roles) return 0;
        $st=db()->prepare('INSERT INTO workflow_events(event_key,title,message,entity_type,entity_id,location_id,source_user_id,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
        $st->execute([$eventKey,$title,$message,$entityType,$entityId,$locationId,current_user()['id']??null]);
        $id=(int)db()->lastInsertId();
        $tg=db()->prepare('INSERT IGNORE INTO workflow_event_roles(event_id,role) VALUES(?,?)');
        foreach($roles as $role)$tg->execute([$id,$role]);
        return $id;
    } catch (Throwable $e) { return 0; }
}
function entity_location_ids(string $entityType,int $entityId): array {
    try {
        if($entityType==='sale'){$st=db()->prepare('SELECT DISTINCT location_id FROM sale_items WHERE sale_id=?');$st->execute([$entityId]);}
        elseif($entityType==='purchase'){$st=db()->prepare('SELECT DISTINCT location_id FROM purchase_items WHERE purchase_id=?');$st->execute([$entityId]);}
        elseif($entityType==='transfer'){$st=db()->prepare('SELECT from_location_id location_id FROM stock_transfers WHERE id=? UNION SELECT to_location_id FROM stock_transfers WHERE id=?');$st->execute([$entityId,$entityId]);}
        elseif($entityType==='online_order'){$st=db()->prepare("SELECT DISTINCT location_id FROM online_order_allocations WHERE online_order_id=?");$st->execute([$entityId]);}
        else return [];
        return array_values(array_unique(array_map('intval',array_column($st->fetchAll(),'location_id'))));
    } catch(Throwable $e){return [];}
}
function publish_scoped_event(string $eventKey,string $title,string $message,array $roles,string $entityType,int $entityId): void {
    $ids=entity_location_ids($entityType,$entityId);
    if(!$ids){publish_event($eventKey,$title,$message,$roles,$entityType,$entityId,null);return;}
    foreach($ids as $loc)publish_event($eventKey,$title,$message,$roles,$entityType,$entityId,$loc);
}
function event_scope_sql(string $eventAlias='we'): array {
    $u=current_user();
    if(!$u) return [' AND 1=0',[]];
    if(($u['role']??'')==='super_admin') return ['',[]];
    $ids=assigned_location_ids();
    if(!$ids) return [" AND {$eventAlias}.location_id IS NULL",[]];
    return [" AND ({$eventAlias}.location_id IS NULL OR {$eventAlias}.location_id IN (".implode(',',array_fill(0,count($ids),'?'))."))",$ids];
}
function unread_event_count(): int {
    $u=current_user(); if(!$u)return 0;
    try {
    [$scope,$params]=event_scope_sql('we');
    $sql='SELECT COUNT(*) FROM workflow_events we JOIN workflow_event_roles wr ON wr.event_id=we.id AND wr.role=? LEFT JOIN workflow_event_reads rr ON rr.event_id=we.id AND rr.user_id=? WHERE rr.event_id IS NULL'.$scope;
    $st=db()->prepare($sql);$st->execute([($u['role']??''),(int)$u['id'],...$params]);return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
function recent_role_events(int $limit=8): array {
    $u=current_user(); if(!$u)return [];
    try {
    [$scope,$params]=event_scope_sql('we');
    $limit=max(1,min(50,$limit));
    $sql='SELECT we.*,l.code location_code,l.name location_name,(rr.event_id IS NOT NULL) is_read FROM workflow_events we JOIN workflow_event_roles wr ON wr.event_id=we.id AND wr.role=? LEFT JOIN workflow_event_reads rr ON rr.event_id=we.id AND rr.user_id=? LEFT JOIN locations l ON l.id=we.location_id WHERE 1=1'.$scope.' ORDER BY we.id DESC LIMIT '.$limit;
    $st=db()->prepare($sql);$st->execute([($u['role']??''),(int)$u['id'],...$params]);return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}


function online_order_location_ids(int $orderId): array {
    $st=db()->prepare('SELECT DISTINCT location_id FROM online_order_allocations WHERE online_order_id=?');
    $st->execute([$orderId]);
    return array_values(array_unique(array_map('intval',array_column($st->fetchAll(),'location_id'))));
}
function can_access_online_order(int $orderId): bool {
    if(is_super_admin()) return true;
    $assigned=assigned_location_ids(); if(!$assigned) return false;
    $locs=online_order_location_ids($orderId); if(!$locs) return false;
    foreach($locs as $id) if(!in_array($id,$assigned,true)) return false;
    return true;
}
function online_order_api_key(): string { return trim((string)(cfg()['api']['order_ingest_key'] ?? '')); }

function maybe_publish_low_stock(int $productId,int $locationId): void {
    try {
        $st=db()->prepare('SELECT sku,product_name,min_stock FROM products WHERE id=?');$st->execute([$productId]);$p=$st->fetch();if(!$p||(float)$p['min_stock']<=0)return;
        $st=db()->prepare('SELECT COALESCE(SUM(GREATEST(qty-reserved_qty,0)),0) FROM inventory_balances WHERE product_id=? AND location_id=?');$st->execute([$productId,$locationId]);$sellable=(float)$st->fetchColumn();if($sellable>(float)$p['min_stock'])return;
        $st=db()->prepare("SELECT 1 FROM workflow_events WHERE event_key='LOW_STOCK' AND entity_type='product' AND entity_id=? AND location_id=? AND created_at>=NOW()-INTERVAL 24 HOUR LIMIT 1");$st->execute([$productId,$locationId]);if($st->fetchColumn())return;
        publish_event('LOW_STOCK','Low sellable stock',$p['sku'].' · '.$p['product_name'].' · Sellable '.number_format($sellable,0).' / Min '.number_format((float)$p['min_stock'],0),['super_admin','manager','godown'],'product',$productId,$locationId);
    } catch(Throwable $e) {}
}
// Physical place of a location row: Godown · Rack · Shelf · Bin. Accepts DB column names.
function location_parts(array $l): array {
    return [
        'godown' => trim((string)($l['warehouse'] ?? '')),
        'rack' => trim((string)($l['rack'] ?? '')),
        'shelf' => trim((string)($l['shelf'] ?? '')),
        'bin' => trim((string)($l['shelf_bin'] ?? '')),
    ];
}
function location_assigned(array $l): bool {
    $p = location_parts($l);
    return $p['rack'] !== '' || $p['shelf'] !== '' || $p['bin'] !== '';
}
function location_place(array $l): string {
    $p = location_parts($l);
    $out = [];
    if ($p['godown'] !== '') $out[] = $p['godown'];
    if ($p['rack'] !== '') $out[] = 'Rack ' . $p['rack'];
    if ($p['shelf'] !== '') $out[] = 'Shelf ' . $p['shelf'];
    if ($p['bin'] !== '') $out[] = 'Bin ' . $p['bin'];
    if (!location_assigned($l)) $out[] = 'Location not assigned';
    return implode(' · ', $out);
}
require_once __DIR__ . '/panel_hosts.php';
require_once __DIR__ . '/license_client.php';
panel_host_enforce();
license_enforce_request();
