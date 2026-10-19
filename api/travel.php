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
    $pdo->exec("CREATE TABLE IF NOT EXISTS travel_future_trips (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, sort_order INT UNSIGNED NOT NULL, updated_by VARCHAR(190), created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $legacyTogether = $pdo->query("SELECT d.state FROM travel_visits d INNER JOIN travel_visits a ON a.state=d.state WHERE d.person='David' AND a.person='Angela' AND d.visited=1 AND a.visited=1")->fetchAll(PDO::FETCH_COLUMN);
    if ($legacyTogether) {
        $migrate = $pdo->prepare("INSERT IGNORE INTO travel_visits (person,state,visited,updated_by) VALUES ('Together',?,1,NULL)");
        foreach ($legacyTogether as $state) $migrate->execute([$state]);
    }
    if ($method === 'POST') {
        if (!auth_is_admin()) auth_json_response(['ok'=>false,'error'=>'Admin access required.'],403);
        $in = json_decode(file_get_contents('php://input'),true);
        if (!is_array($in)) auth_json_response(['ok'=>false,'error'=>'Invalid request.'],400);
        if (!is_string($in['csrf'] ?? null) || !hash_equals(auth_csrf_token(),$in['csrf'])) auth_json_response(['ok'=>false,'error'=>'Please reload this page and try again.'],400);
        $email = auth_current_user()['email'];
        if (($in['action'] ?? '') === 'visit') {
            $states = json_decode(file_get_contents(__DIR__.'/../assets/states.json'),true);
            if (!in_array($in['state'] ?? '',$states,true) || !is_bool($in['visited'] ?? null)) auth_json_response(['ok'=>false,'error'=>'Invalid state selection.'],400);
            $pdo->prepare("INSERT INTO travel_visits (person,state,visited,updated_by) VALUES ('Together',?,?,?) ON DUPLICATE KEY UPDATE visited=VALUES(visited),updated_by=VALUES(updated_by)")->execute([$in['state'],(int)$in['visited'],$email]);
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
        } elseif (($in['action'] ?? '') === 'future_trip_add') {
            $title=$in['title'] ?? null;
            $description=$in['description'] ?? null;
            if (!is_string($title) || !is_string($description)) auth_json_response(['ok'=>false,'error'=>'Enter a title and description.'],400);
            $title=trim($title);
            $description=trim($description);
            if ($title==='' || $description==='' || strlen($title)>200 || strlen($description)>5000) auth_json_response(['ok'=>false,'error'=>'Enter a title and description within the allowed length.'],400);
            $sortOrder=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM travel_future_trips')->fetchColumn();
            $pdo->prepare('INSERT INTO travel_future_trips (title,description,sort_order,updated_by) VALUES (?,?,?,?)')->execute([$title,$description,$sortOrder,$email]);
            auth_json_response(['ok'=>true,'trip'=>['id'=>(int)$pdo->lastInsertId(),'title'=>$title,'description'=>$description,'sortOrder'=>$sortOrder]]);
        } elseif (($in['action'] ?? '') === 'future_trip_delete') {
            if (!is_int($in['id'] ?? null) || $in['id']<1) auth_json_response(['ok'=>false,'error'=>'Invalid trip.'],400);
            $delete=$pdo->prepare('DELETE FROM travel_future_trips WHERE id=?');
            $delete->execute([$in['id']]);
            if ($delete->rowCount()!==1) auth_json_response(['ok'=>false,'error'=>'Trip not found.'],404);
        } elseif (($in['action'] ?? '') === 'future_trip_reorder') {
            $ids=$in['ids'] ?? null;
            if (!is_array($ids) || count($ids)>100 || count($ids)!==count(array_unique($ids,SORT_REGULAR))) auth_json_response(['ok'=>false,'error'=>'Invalid priority order.'],400);
            foreach ($ids as $id) if (!is_int($id) || $id<1) auth_json_response(['ok'=>false,'error'=>'Invalid priority order.'],400);
            $pdo->beginTransaction();
            try {
                $current=array_map('intval',$pdo->query('SELECT id FROM travel_future_trips FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
                $expected=$current;
                $submitted=$ids;
                sort($expected,SORT_NUMERIC);
                sort($submitted,SORT_NUMERIC);
                if ($expected!==$submitted) {
                    $pdo->rollBack();
                    auth_json_response(['ok'=>false,'error'=>'The trip list changed. Reload and try again.'],409);
                }
                $reorder=$pdo->prepare('UPDATE travel_future_trips SET sort_order=?,updated_by=? WHERE id=?');
                foreach ($ids as $position=>$id) $reorder->execute([$position+1,$email,$id]);
                $pdo->commit();
            } catch (Throwable $reorderError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $reorderError;
            }
        } else auth_json_response(['ok'=>false,'error'=>'Unknown action.'],400);
        auth_json_response(['ok'=>true]);
    }
    $visits=$pdo->query("SELECT state FROM travel_visits WHERE person='Together' AND visited=1 ORDER BY state")->fetchAll(PDO::FETCH_COLUMN);
    $plans=[];
    foreach ($pdo->query('SELECT stop_id,details FROM travel_plans')->fetchAll() as $row) $plans[$row['stop_id']]=json_decode($row['details'],true);
    $futureTrips=[];
    foreach ($pdo->query('SELECT id,title,description,sort_order FROM travel_future_trips ORDER BY sort_order,id')->fetchAll() as $row) $futureTrips[]=['id'=>(int)$row['id'],'title'=>$row['title'],'description'=>$row['description'],'sortOrder'=>(int)$row['sort_order']];
    auth_json_response(['ok'=>true,'visits'=>$visits,'plans'=>$plans,'futureTrips'=>$futureTrips,'canEdit'=>auth_is_admin(),'csrf'=>auth_csrf_token()]);
} catch (Throwable $e) {
    error_log('Travel API: '.$e->getMessage());
    auth_json_response(['ok'=>false,'error'=>'Travel data could not be saved or loaded. Please try again.'],500);
}
