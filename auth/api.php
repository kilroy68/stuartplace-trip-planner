<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/smugmug.php';
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

function api_reservation_geocode_query(array $reservation): string {
    $address = trim((string)($reservation['address'] ?? ''));
    if ($address !== '') {
        return $address;
    }
    $title = trim((string)($reservation['title'] ?? ''));
    $text = $title . ' ' . trim((string)($reservation['notes'] ?? ''));
    if (preg_match('/humb.*bay inn/i', $text)) return '232 W 5th St, Eureka, CA 95501';
    if (preg_match('/beachcomber motel/i', $text)) return '1111 N Main St, Fort Bragg, CA 95437';
    if (preg_match('/hotel zephyr/i', $text)) return '250 Beach St, San Francisco, CA 94133';
    if (preg_match('/santa\s*monica|\bpier\b|\blax\b/i', $text)) return trim($title . ' Santa Monica CA');
    return trim($title . ' California');
}

function api_geocode_reservation_with_nominatim(array $reservation): array {
    $query = api_reservation_geocode_query($reservation);
    if ($query === '') {
        throw new RuntimeException('No address or title available to look up.');
    }
    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: stuartplace-trip-planner/1.0 (https://www.stuartplace.net/)'
        ],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http < 200 || $http >= 300) {
        throw new RuntimeException($err ?: 'Nominatim lookup failed with HTTP ' . $http);
    }
    $results = json_decode($raw, true);
    if (!is_array($results) || empty($results[0]['lat']) || empty($results[0]['lon'])) {
        throw new RuntimeException('No coordinate found for query: ' . $query);
    }
    return [
        'query' => $query,
        'latitude' => (float)$results[0]['lat'],
        'longitude' => (float)$results[0]['lon'],
        'display_name' => (string)($results[0]['display_name'] ?? ''),
    ];
}

try {
    if ($action === 'bootstrap') {
        $items = $pdo->query('SELECT id, stop_id, item_text, created_at, created_by FROM stop_items ORDER BY created_at ASC')->fetchAll();
        $reservations = $pdo->query('SELECT * FROM reservations ORDER BY COALESCE(stop_id, 999999), COALESCE(reservation_date, "9999-12-31"), COALESCE(reservation_time, "23:59:59"), title')->fetchAll();
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
            'latitude' => ($in['latitude'] ?? '') === '' ? null : (float)$in['latitude'],
            'longitude' => ($in['longitude'] ?? '') === '' ? null : (float)$in['longitude'],
            'phone' => trim((string)($in['phone'] ?? '')) ?: null,
            'url' => trim((string)($in['url'] ?? '')) ?: null,
            'cancellation_deadline' => trim((string)($in['cancellation_deadline'] ?? '')) ?: null,
            'cost' => ($in['cost'] ?? '') === '' ? null : (float)$in['cost'],
            'notes' => trim((string)($in['notes'] ?? '')) ?: null,
        ];
        $reservationText = implode(' ', array_filter([$fields['title'], $fields['address'], $fields['notes']]));
        if ($fields['stop_id'] === 6 && (
            preg_match('/yosemite|el\s*portal|wawona|fish\s*camp|oakhurst|mariposa|tenaya|rush\s*creek|evergreen|curry\s*village|ahwahnee|cedar\s*lodge|yosemite\s*view|autocamp/i', $reservationText)
            || (strcasecmp($fields['type'], 'Lodging') === 0 && ($fields['reservation_date'] ?? '') >= '2026-09-26' && ($fields['reservation_date'] ?? '') <= '2026-09-29')
        )) {
            // Current Yosemite itinerary stop uses preserved DB stop id 7; older rows/forms may have used 6.
            $fields['stop_id'] = 7;
        }
        if ($fields['stop_id'] === 12 && preg_match('/santa\s*monica|\bpier\b|\blax\b/i', $reservationText)) {
            // Current visible itinerary index for Santa Monica is 12, but preserved DB stop id is 13.
            $fields['stop_id'] = 13;
        }
        if ($fields['title'] === '') {
            auth_json_response(['ok' => false, 'error' => 'Reservation title is required.'], 400);
        }
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE reservations SET stop_id=?, title=?, type=?, status=?, reservation_date=?, reservation_time=?, confirmation=?, address=?, latitude=?, longitude=?, phone=?, url=?, cancellation_deadline=?, cost=?, notes=?, updated_at=NOW(), updated_by=? WHERE id=?');
            $stmt->execute([$fields['stop_id'],$fields['title'],$fields['type'],$fields['status'],$fields['reservation_date'],$fields['reservation_time'],$fields['confirmation'],$fields['address'],$fields['latitude'],$fields['longitude'],$fields['phone'],$fields['url'],$fields['cancellation_deadline'],$fields['cost'],$fields['notes'],$user['email'],$id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO reservations (stop_id,title,type,status,reservation_date,reservation_time,confirmation,address,latitude,longitude,phone,url,cancellation_deadline,cost,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$fields['stop_id'],$fields['title'],$fields['type'],$fields['status'],$fields['reservation_date'],$fields['reservation_time'],$fields['confirmation'],$fields['address'],$fields['latitude'],$fields['longitude'],$fields['phone'],$fields['url'],$fields['cancellation_deadline'],$fields['cost'],$fields['notes'],$user['email']]);
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

    if ($action === 'geocode_reservation') {
        api_require_admin();
        $in = api_input();
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            auth_json_response(['ok' => false, 'error' => 'Reservation id is required.'], 400);
        }
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
        $stmt->execute([$id]);
        $reservation = $stmt->fetch();
        if (!$reservation) {
            auth_json_response(['ok' => false, 'error' => 'Reservation not found.'], 404);
        }
        if (strcasecmp((string)($reservation['type'] ?? ''), 'Lodging') !== 0) {
            auth_json_response(['ok' => false, 'error' => 'Only lodging reservations can be geocoded here.'], 400);
        }
        $geo = api_geocode_reservation_with_nominatim($reservation);
        $stopId = ($reservation['stop_id'] ?? null) === null ? null : (int)$reservation['stop_id'];
        $reservationText = implode(' ', array_filter([(string)($reservation['title'] ?? ''), (string)($reservation['address'] ?? ''), (string)($reservation['notes'] ?? '')]));
        if ($stopId === 6 && (
            preg_match('/yosemite|el\s*portal|wawona|fish\s*camp|oakhurst|mariposa|tenaya|rush\s*creek|evergreen|curry\s*village|ahwahnee|cedar\s*lodge|yosemite\s*view|autocamp/i', $reservationText)
            || (strcasecmp((string)($reservation['type'] ?? ''), 'Lodging') === 0 && (string)($reservation['reservation_date'] ?? '') >= '2026-09-26' && (string)($reservation['reservation_date'] ?? '') <= '2026-09-29')
        )) {
            $stopId = 7;
        }
        if ($stopId === 12 && preg_match('/santa\s*monica|\bpier\b|\blax\b/i', $reservationText)) {
            $stopId = 13;
        }
        $address = trim((string)($reservation['address'] ?? '')) ?: ($geo['display_name'] ?: null);
        $stmt = $pdo->prepare('UPDATE reservations SET stop_id=?, address=?, latitude=?, longitude=?, updated_at=NOW(), updated_by=? WHERE id=?');
        $stmt->execute([$stopId, $address, $geo['latitude'], $geo['longitude'], $user['email'], $id]);
        auth_json_response(['ok' => true, 'id' => $id, 'stop_id' => $stopId, 'address' => $address, 'query' => $geo['query'], 'latitude' => $geo['latitude'], 'longitude' => $geo['longitude'], 'display_name' => $geo['display_name']]);
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
            auth_json_response(['ok' => false, 'error' => 'Save a SmugMug gallery URL and add smugmug_api_key to stuartplace-config.php first.'], 400);
        }
        $sync = smugmug_rebuild_trip_photos($pdo, (string)$gallery, $apiKey, $user['email']);
        auth_json_response([
            'ok' => true,
            'imported' => $sync['imported'],
            'albums' => $sync['albums'],
            'albumNames' => $sync['albumNames'],
            'rebuilt' => true,
        ]);
    }

    auth_json_response(['ok' => false, 'error' => 'Unknown action.'], 404);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    auth_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
