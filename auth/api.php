<?php
require_once __DIR__ . '/auth.php';
auth_require_login();
$pdo = auth_db();
$user = auth_current_user();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function api_require_admin(): void {
    if (!auth_is_admin()) {
        auth_json_response(['ok' => false, 'error' => 'Admin access required.'], 403);
    }
}

function api_input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

try {
    if ($action === 'bootstrap') {
        $items = $pdo->query('SELECT id, stop_id, item_text, created_at, created_by FROM stop_items ORDER BY created_at ASC')->fetchAll();
        $reservations = $pdo->query('SELECT * FROM reservations ORDER BY COALESCE(reservation_date, "9999-12-31"), COALESCE(reservation_time, "23:59:59"), title')->fetchAll();
        $photos = $pdo->query('SELECT * FROM trip_photos WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY COALESCE(taken_at, created_at) ASC')->fetchAll();
        $settingsRows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
        $settings = [];
        foreach ($settingsRows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        auth_json_response([
            'ok' => true,
            'user' => $user,
            'isAdmin' => auth_is_admin(),
            'csrf' => auth_csrf_token(),
            'items' => $items,
            'reservations' => $reservations,
            'photos' => $photos,
            'settings' => $settings,
        ]);
    }

    if ($action === 'add_item') {
        api_require_admin();
        $in = api_input();
        $stopId = (int)($in['stop_id'] ?? -1);
        $text = trim((string)($in['item_text'] ?? ''));
        if ($stopId < 0 || $text === '') {
            auth_json_response(['ok' => false, 'error' => 'Stop and item text are required.'], 400);
        }
        $stmt = $pdo->prepare('INSERT INTO stop_items (stop_id, item_text, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$stopId, $text, $user['email']]);
        auth_json_response(['ok' => true, 'id' => $pdo->lastInsertId()]);
    }

    if ($action === 'delete_item') {
        api_require_admin();
        $in = api_input();
        $stmt = $pdo->prepare('DELETE FROM stop_items WHERE id = ?');
        $stmt->execute([(int)($in['id'] ?? 0)]);
        auth_json_response(['ok' => true]);
    }

    if ($action === 'save_reservation') {
        api_require_admin();
        $in = api_input();
        $id = (int)($in['id'] ?? 0);
        $fields = [
            'stop_id' => ($in['stop_id'] ?? '') === '' ? null : (int)$in['stop_id'],
            'title' => trim((string)($in['title'] ?? '')),
            'type' => trim((string)($in['type'] ?? 'Other')) ?: 'Other',
            'status' => trim((string)($in['status'] ?? 'planned')) ?: 'planned',
            'reservation_date' => trim((string)($in['reservation_date'] ?? '')) ?: null,
            'reservation_time' => trim((string)($in['reservation_time'] ?? '')) ?: null,
            'confirmation' => trim((string)($in['confirmation'] ?? '')) ?: null,
            'address' => trim((string)($in['address'] ?? '')) ?: null,
            'phone' => trim((string)($in['phone'] ?? '')) ?: null,
            'url' => trim((string)($in['url'] ?? '')) ?: null,
            'cancellation_deadline' => trim((string)($in['cancellation_deadline'] ?? '')) ?: null,
            'cost' => ($in['cost'] ?? '') === '' ? null : (float)$in['cost'],
            'notes' => trim((string)($in['notes'] ?? '')) ?: null,
        ];
        if ($fields['title'] === '') {
            auth_json_response(['ok' => false, 'error' => 'Reservation title is required.'], 400);
        }
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE reservations SET stop_id=?, title=?, type=?, status=?, reservation_date=?, reservation_time=?, confirmation=?, address=?, phone=?, url=?, cancellation_deadline=?, cost=?, notes=?, updated_at=NOW(), updated_by=? WHERE id=?');
            $stmt->execute([$fields['stop_id'],$fields['title'],$fields['type'],$fields['status'],$fields['reservation_date'],$fields['reservation_time'],$fields['confirmation'],$fields['address'],$fields['phone'],$fields['url'],$fields['cancellation_deadline'],$fields['cost'],$fields['notes'],$user['email'],$id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO reservations (stop_id,title,type,status,reservation_date,reservation_time,confirmation,address,phone,url,cancellation_deadline,cost,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$fields['stop_id'],$fields['title'],$fields['type'],$fields['status'],$fields['reservation_date'],$fields['reservation_time'],$fields['confirmation'],$fields['address'],$fields['phone'],$fields['url'],$fields['cancellation_deadline'],$fields['cost'],$fields['notes'],$user['email']]);
            $id = (int)$pdo->lastInsertId();
        }
        auth_json_response(['ok' => true, 'id' => $id]);
    }

    if ($action === 'delete_reservation') {
        api_require_admin();
        $in = api_input();
        $stmt = $pdo->prepare('DELETE FROM reservations WHERE id = ?');
        $stmt->execute([(int)($in['id'] ?? 0)]);
        auth_json_response(['ok' => true]);
    }

    if ($action === 'save_gallery') {
        api_require_admin();
        $in = api_input();
        $gallery = trim((string)($in['gallery'] ?? ''));
        $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES ("smugmug_gallery", ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)');
        $stmt->execute([$gallery, $user['email']]);
        auth_json_response(['ok' => true]);
    }

    if ($action === 'add_photo') {
        api_require_admin();
        $in = api_input();
        $thumb = trim((string)($in['thumb_url'] ?? ''));
        $url = trim((string)($in['photo_url'] ?? ''));
        $lat = ($in['latitude'] ?? '') === '' ? null : (float)$in['latitude'];
        $lng = ($in['longitude'] ?? '') === '' ? null : (float)$in['longitude'];
        if ($thumb === '' || $url === '' || $lat === null || $lng === null) {
            auth_json_response(['ok' => false, 'error' => 'Photo URL, thumbnail URL, latitude, and longitude are required.'], 400);
        }
        $stmt = $pdo->prepare('INSERT INTO trip_photos (smugmug_key,title,caption,thumb_url,photo_url,latitude,longitude,taken_at,stop_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), caption=VALUES(caption), thumb_url=VALUES(thumb_url), photo_url=VALUES(photo_url), latitude=VALUES(latitude), longitude=VALUES(longitude), taken_at=VALUES(taken_at), stop_id=VALUES(stop_id)');
        $stmt->execute([
            trim((string)($in['smugmug_key'] ?? '')) ?: md5($url),
            trim((string)($in['title'] ?? '')) ?: null,
            trim((string)($in['caption'] ?? '')) ?: null,
            $thumb,
            $url,
            $lat,
            $lng,
            trim((string)($in['taken_at'] ?? '')) ?: null,
            ($in['stop_id'] ?? '') === '' ? null : (int)$in['stop_id'],
            $user['email'],
        ]);
        auth_json_response(['ok' => true]);
    }

    if ($action === 'sync_smugmug') {
        api_require_admin();
        $c = auth_config();
        $gallery = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = "smugmug_gallery"')->fetchColumn();
        $apiKey = trim((string)($c['smugmug_api_key'] ?? ''));
        if (!$gallery || $apiKey === '') {
            auth_json_response(['ok' => false, 'error' => 'Save a SmugMug gallery URL and add smugmug_api_key to auth/config.php first.'], 400);
        }
        if (preg_match('~/album/([A-Za-z0-9]+)~', $gallery, $m)) {
            $albumKey = $m[1];
        } elseif (preg_match('~/([A-Za-z0-9]+)(?:/)?$~', parse_url($gallery, PHP_URL_PATH) ?: '', $m)) {
            $albumKey = $m[1];
        } else {
            auth_json_response(['ok' => false, 'error' => 'Could not determine SmugMug album key from the gallery URL.'], 400);
        }
        $endpoint = 'https://api.smugmug.com/api/v2/album/' . rawurlencode($albumKey) . '!images?_accept=application%2Fjson';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-SmugMug-APIKey: ' . $apiKey]]);
        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $http < 200 || $http >= 300) {
            auth_json_response(['ok' => false, 'error' => $err ?: 'SmugMug API request failed with HTTP ' . $http], 502);
        }
        $json = json_decode($raw, true);
        $images = $json['Response']['AlbumImage'] ?? $json['Response']['AlbumImages'] ?? [];
        $count = 0;
        $stmt = $pdo->prepare('INSERT INTO trip_photos (smugmug_key,title,caption,thumb_url,photo_url,latitude,longitude,taken_at,created_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), caption=VALUES(caption), thumb_url=VALUES(thumb_url), photo_url=VALUES(photo_url), latitude=VALUES(latitude), longitude=VALUES(longitude), taken_at=VALUES(taken_at)');
        foreach ($images as $img) {
            $lat = $img['Latitude'] ?? $img['Lat'] ?? null;
            $lng = $img['Longitude'] ?? $img['Lon'] ?? null;
            if ($lat === null || $lng === null || $lat === '' || $lng === '') { continue; }
            $key = $img['ImageKey'] ?? $img['Key'] ?? md5(json_encode($img));
            $thumb = $img['ThumbnailUrl'] ?? $img['ThumbUrl'] ?? $img['Uris']['ImageSizes']['Uri'] ?? '';
            $photoUrl = $img['WebUri'] ?? $img['ArchivedUri'] ?? $img['Uri'] ?? $gallery;
            if ($thumb === '') { $thumb = $photoUrl; }
            $stmt->execute([$key, $img['Title'] ?? $img['FileName'] ?? 'Trip photo', $img['Caption'] ?? null, $thumb, $photoUrl, (float)$lat, (float)$lng, $img['DateTimeOriginal'] ?? $img['Date'] ?? null, $user['email']]);
            $count++;
        }
        auth_json_response(['ok' => true, 'imported' => $count]);
    }

    auth_json_response(['ok' => false, 'error' => 'Unknown action.'], 404);
} catch (Throwable $e) {
    auth_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
