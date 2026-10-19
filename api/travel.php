<?php
require_once __DIR__ . '/../auth/auth.php';
header('Cache-Control: no-store');
if (!auth_is_configured() || !auth_is_logged_in()) auth_json_response(['ok'=>false,'error'=>'Sign in to continue.'],401);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) auth_json_response(['ok'=>false,'error'=>'Method not allowed.'],405);
try {
    $pdo = auth_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS travel_visits (person VARCHAR(10) NOT NULL, state VARCHAR(30) NOT NULL, visited TINYINT NOT NULL DEFAULT 0, updated_by VARCHAR(190), PRIMARY KEY(person,state)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS travel_plans (stop_id VARCHAR(30) PRIMARY KEY, details TEXT NOT NULL, updated_by VARCHAR(190)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($method === 'POST') {
        if (!auth_is_admin()) auth_json_response(['ok'=>false,'error'=>'Admin access required.'],403);
        $in = json_decode(file_get_contents('php://input'),true);
        if (!is_array($in)) auth_json_response(['ok'=>false,'error'=>'Invalid request.'],400);
        if (!is_string($in['csrf'] ?? null) || !hash_equals(auth_csrf_token(),$in['csrf'])) auth_json_response(['ok'=>false,'error'=>'Please reload this page and try again.'],400);
        $email = auth_current_user()['email'];
        if (($in['action'] ?? '') === 'visit') {
            $states = json_decode(file_get_contents(__DIR__.'/../assets/states.json'),true);
            if (!in_array($in['person'] ?? '', ['David','Angela'],true) || !in_array($in['state'] ?? '',$states,true) || !is_bool($in['visited'] ?? null)) auth_json_response(['ok'=>false,'error'=>'Invalid state selection.'],400);
            $pdo->prepare('INSERT INTO travel_visits (person,state,visited,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE visited=VALUES(visited),updated_by=VALUES(updated_by)')->execute([$in['person'],$in['state'],(int)$in['visited'],$email]);
        } elseif (($in['action'] ?? '') === 'plan') {
            if (!in_array($in['stop_id'] ?? '', ['boston','woodstock','stowe','north-conway','bar-harbor','portland','return-boston'],true)) auth_json_response(['ok'=>false,'error'=>'Invalid stop.'],400);
            $details=[];
            foreach (['notes'=>5000,'lodging'=>200,'confirmation'=>200,'date'=>10,'cost'=>30] as $key=>$limit) {
                $v=$in[$key] ?? '';
                if (!is_string($v) || strlen($v)>$limit) auth_json_response(['ok'=>false,'error'=>'Planning field is too long or invalid.'],400);
                $details[$key]=trim($v);
            }
            if ($details['date'] !== '' && (!preg_match('/^2027-\d{2}-\d{2}$/',$details['date']) || !checkdate((int)substr($details['date'],5,2),(int)substr($details['date'],8,2),2027))) auth_json_response(['ok'=>false,'error'=>'Choose a valid date in 2027.'],400);
            $pdo->prepare('INSERT INTO travel_plans (stop_id,details,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE details=VALUES(details),updated_by=VALUES(updated_by)')->execute([$in['stop_id'],json_encode($details),$email]);
        } else auth_json_response(['ok'=>false,'error'=>'Unknown action.'],400);
        auth_json_response(['ok'=>true]);
    }
    $visits=['David'=>[],'Angela'=>[]];
    foreach ($pdo->query('SELECT person,state FROM travel_visits WHERE visited=1')->fetchAll() as $row) if (isset($visits[$row['person']])) $visits[$row['person']][]=$row['state'];
    $plans=[];
    foreach ($pdo->query('SELECT stop_id,details FROM travel_plans')->fetchAll() as $row) $plans[$row['stop_id']]=json_decode($row['details'],true);
    auth_json_response(['ok'=>true,'visits'=>$visits,'plans'=>$plans,'canEdit'=>auth_is_admin(),'csrf'=>auth_csrf_token()]);
} catch (Throwable $e) {
    error_log('Travel API: '.$e->getMessage());
    auth_json_response(['ok'=>false,'error'=>'Travel data could not be saved or loaded. Please try again.'],500);
}
