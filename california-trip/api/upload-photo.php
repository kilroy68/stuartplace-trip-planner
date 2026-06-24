<?php
require_once __DIR__ . '/../../auth/bootstrap.php';
require_once __DIR__ . '/../../auth/db.php';

function mobile_json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mobile_get_authorization_bearer(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || $header === '') { return ''; }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) { return trim($m[1]); }
    return '';
}

function mobile_token_matches(string $provided, string $configured): bool {
    $configured = trim($configured);
    if ($configured === '' || $provided === '') { return false; }
    if (strpos($configured, 'sha256:') === 0) {
        return hash_equals(substr($configured, 7), hash('sha256', $provided));
    }
    return hash_equals($configured, $provided);
}

function mobile_authenticated_client(): string {
    $client = trim((string)($_SERVER['HTTP_X_STUARTPLACE_CLIENT'] ?? ''));
    $token = mobile_get_authorization_bearer();
    $tokens = auth_config()['mobile_api_tokens'] ?? [];
    if (is_array($tokens) && $client !== '' && isset($tokens[$client]) && mobile_token_matches($token, (string)$tokens[$client])) {
        return $client;
    }
    mobile_json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

function smug_json_get(string $url, string $apiKey): array {
    $sep = strpos($url, '?') === false ? '?' : '&';
    $url .= $sep . 'APIKey=' . rawurlencode($apiKey) . '&_accept=application%2Fjson';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http < 200 || $http >= 300) {
        throw new RuntimeException($err ?: 'SmugMug API request failed with HTTP ' . $http);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) { throw new RuntimeException('SmugMug returned an unreadable API response.'); }
    return $json;
}

function smug_find_album_uri($value): ?string {
    if (is_string($value) && preg_match('~^/api/v2/album/[A-Za-z0-9]+~', $value, $m)) { return $m[0]; }
    if (is_array($value)) {
        foreach ($value as $child) {
            $found = smug_find_album_uri($child);
            if ($found !== null) { return $found; }
        }
    }
    return null;
}

function smug_album_uri_from_gallery(string $gallery, string $apiKey): string {
    $gallery = trim($gallery);
    if ($gallery === '') { throw new RuntimeException('Save the SmugMug gallery URL first in the website photo settings.'); }
    if (preg_match('~/api/v2/album/([A-Za-z0-9]+)~', $gallery, $m) || preg_match('~/album/([A-Za-z0-9]+)~', $gallery, $m)) {
        return '/api/v2/album/' . $m[1];
    }
    if (preg_match('~/(?:n-|node/)([A-Za-z0-9]+)~', $gallery, $m)) {
        $node = smug_json_get('https://api.smugmug.com/api/v2/node/' . rawurlencode($m[1]), $apiKey);
        $uri = smug_find_album_uri($node);
        if ($uri !== null) { return $uri; }
    }
    $parts = parse_url($gallery);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    $nickname = '';
    if (preg_match('~^([a-z0-9-]+)\.smugmug\.com$~i', $host, $m)) { $nickname = $m[1]; }
    elseif (preg_match('~^www\.([a-z0-9-]+)\.smugmug\.com$~i', $host, $m)) { $nickname = $m[1]; }
    if ($nickname === '' || $path === '') { throw new RuntimeException('Please save the normal SmugMug gallery URL first.'); }
    $path = preg_replace('~/i-[A-Za-z0-9]+.*$~', '', $path) ?: $path;
    $path = '/' . trim($path, '/');
    $lookupBase = 'https://api.smugmug.com/api/v2/user/' . rawurlencode($nickname) . '!urlpathlookup';
    foreach ([$lookupBase . '?urlpath=' . rawurlencode($path), $lookupBase . '?UrlPath=' . rawurlencode($path)] as $url) {
        try {
            $json = smug_json_get($url, $apiKey);
            $uri = smug_find_album_uri($json);
            if ($uri !== null) { return $uri; }
        } catch (Throwable $e) {}
    }
    throw new RuntimeException('Could not resolve the configured SmugMug gallery URL.');
}

function smug_rebuild_trip_photos(PDO $pdo, string $albumUri, string $apiKey, string $createdBy): int {
    $endpoint = 'https://api.smugmug.com' . $albumUri . '!images?count=500';
    $json = smug_json_get($endpoint, $apiKey);
    $images = $json['Response']['AlbumImage'] ?? $json['Response']['AlbumImages'] ?? [];
    $count = 0;
    $stmt = $pdo->prepare('INSERT INTO trip_photos (smugmug_key,title,caption,thumb_url,photo_url,latitude,longitude,taken_at,created_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), caption=VALUES(caption), thumb_url=VALUES(thumb_url), photo_url=VALUES(photo_url), latitude=VALUES(latitude), longitude=VALUES(longitude), taken_at=VALUES(taken_at)');
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM trip_photos');
    foreach ($images as $img) {
        $lat = $img['Latitude'] ?? $img['Lat'] ?? null;
        $lng = $img['Longitude'] ?? $img['Lon'] ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '') { continue; }
        $key = $img['ImageKey'] ?? $img['Key'] ?? md5(json_encode($img));
        $thumb = trim((string)($img['ThumbnailUrl'] ?? $img['ThumbUrl'] ?? ''));
        $photoUrl = trim((string)($img['WebUri'] ?? $img['ArchivedUri'] ?? ''));
        if ($thumb === '') { $thumb = $photoUrl; }
        if ($photoUrl === '' || $thumb === '') { continue; }
        $stmt->execute([$key, $img['Title'] ?? $img['FileName'] ?? 'Trip photo', $img['Caption'] ?? null, $thumb, $photoUrl, (float)$lat, (float)$lng, $img['DateTimeOriginal'] ?? $img['Date'] ?? null, $createdBy]);
        $count++;
    }
    $pdo->commit();
    return $count;
}

function oauth_percent_encode(string $value): string {
    return str_replace('%7E', '~', rawurlencode($value));
}

function oauth_header(string $method, string $url, array $params, string $consumerSecret, string $tokenSecret): string {
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) { $pairs[] = oauth_percent_encode((string)$k) . '=' . oauth_percent_encode((string)$v); }
    $base = strtoupper($method) . '&' . oauth_percent_encode($url) . '&' . oauth_percent_encode(implode('&', $pairs));
    $key = oauth_percent_encode($consumerSecret) . '&' . oauth_percent_encode($tokenSecret);
    $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));
    $headerParts = [];
    foreach ($params as $k => $v) {
        if (strpos($k, 'oauth_') === 0) { $headerParts[] = oauth_percent_encode((string)$k) . '="' . oauth_percent_encode((string)$v) . '"'; }
    }
    return 'OAuth ' . implode(', ', $headerParts);
}

function gps_fraction_to_float($value): ?float {
    if (!is_string($value) || $value === '') { return null; }
    if (strpos($value, '/') !== false) {
        [$num, $den] = array_map('floatval', explode('/', $value, 2));
        return $den == 0.0 ? null : $num / $den;
    }
    return (float)$value;
}

function gps_coord_to_float($coord, $ref): ?float {
    if (!is_array($coord) || count($coord) < 3) { return null; }
    $deg = gps_fraction_to_float((string)$coord[0]);
    $min = gps_fraction_to_float((string)$coord[1]);
    $sec = gps_fraction_to_float((string)$coord[2]);
    if ($deg === null || $min === null || $sec === null) { return null; }
    $value = $deg + ($min / 60.0) + ($sec / 3600.0);
    $ref = strtoupper((string)$ref);
    if ($ref === 'S' || $ref === 'W') { $value *= -1; }
    return $value;
}

function image_gps_from_file(string $path): array {
    if (!function_exists('exif_read_data')) { return [null, null]; }
    $exif = @exif_read_data($path, 'GPS', true);
    if (!is_array($exif) || !isset($exif['GPS'])) { return [null, null]; }
    $gps = $exif['GPS'];
    $lat = gps_coord_to_float($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
    $lng = gps_coord_to_float($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);
    return [$lat, $lng];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobile_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$caller = mobile_authenticated_client();
$config = auth_config();
$apiKey = trim((string)($config['smugmug_api_key'] ?? ''));
$apiSecret = trim((string)($config['smugmug_api_secret'] ?? ''));
$pdo = auth_db();
$settingsStmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ("smugmug_access_token", "smugmug_access_token_secret")');
$smugSettings = [];
foreach ($settingsStmt->fetchAll() as $row) {
    $smugSettings[(string)$row['setting_key']] = (string)$row['setting_value'];
}
$accessToken = trim((string)($config['smugmug_access_token'] ?? ($smugSettings['smugmug_access_token'] ?? '')));
$accessSecret = trim((string)($config['smugmug_access_token_secret'] ?? ($smugSettings['smugmug_access_token_secret'] ?? '')));
if ($apiKey === '' || $apiSecret === '') {
    mobile_json_response(['ok' => false, 'error' => 'SmugMug upload is not configured. Add smugmug_api_key and smugmug_api_secret to the private config.'], 500);
}
if ($accessToken === '' || $accessSecret === '') {
    mobile_json_response(['ok' => false, 'error' => 'SmugMug is not connected. Sign in as an admin and open /california-trip/api/smugmug-connect.php to authorize uploads.'], 500);
}

$gallery = (string)$pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = "smugmug_gallery"')->fetchColumn();
$albumUri = smug_album_uri_from_gallery($gallery, $apiKey);
if (($_POST['action'] ?? '') === 'rebuild') {
    try {
        $imported = smug_rebuild_trip_photos($pdo, $albumUri, $apiKey, $caller);
        mobile_json_response(['ok' => true, 'caller' => $caller, 'rebuilt' => true, 'imported' => $imported]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        mobile_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
    mobile_json_response(['ok' => false, 'error' => 'No photo was uploaded.'], 400);
}
$file = $_FILES['photo'];
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    mobile_json_response(['ok' => false, 'error' => 'Upload failed with code ' . (int)$file['error']], 400);
}
$size = (int)($file['size'] ?? 0);
if ($size <= 0 || $size > 50 * 1024 * 1024) {
    mobile_json_response(['ok' => false, 'error' => 'Photo must be smaller than 50 MB.'], 400);
}
$mime = mime_content_type($file['tmp_name']) ?: 'image/jpeg';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/heic', 'image/heif'], true)) {
    mobile_json_response(['ok' => false, 'error' => 'Only JPEG, PNG, and HEIC images are supported.'], 400);
}

$filename = basename((string)($file['name'] ?? 'trip-photo.jpg')) ?: 'trip-photo.jpg';
$title = trim((string)($_POST['title'] ?? '')) ?: pathinfo($filename, PATHINFO_FILENAME);
$caption = trim((string)($_POST['caption'] ?? '')) ?: null;
$stopID = ($_POST['stop_id'] ?? '') === '' ? null : (int)$_POST['stop_id'];
[$lat, $lng] = image_gps_from_file($file['tmp_name']);
if (isset($_POST['latitude']) && $_POST['latitude'] !== '') { $lat = (float)$_POST['latitude']; }
if (isset($_POST['longitude']) && $_POST['longitude'] !== '') { $lng = (float)$_POST['longitude']; }

$body = file_get_contents($file['tmp_name']);
if ($body === false) { mobile_json_response(['ok' => false, 'error' => 'Could not read uploaded photo.'], 500); }
$uploadURL = 'https://upload.smugmug.com/';
$oauthParams = [
    'oauth_consumer_key' => $apiKey,
    'oauth_token' => $accessToken,
    'oauth_nonce' => bin2hex(random_bytes(16)),
    'oauth_timestamp' => (string)time(),
    'oauth_signature_method' => 'HMAC-SHA1',
    'oauth_version' => '1.0',
];
$headers = [
    'Authorization: ' . oauth_header('POST', $uploadURL, $oauthParams, $apiSecret, $accessSecret),
    'Content-Type: ' . $mime,
    'Content-Length: ' . strlen($body),
    'Content-MD5: ' . base64_encode(md5($body, true)),
    'X-Smug-Version: v2',
    'X-Smug-ResponseType: JSON',
    'X-Smug-AlbumUri: ' . $albumUri,
    'X-Smug-FileName: ' . $filename,
    'X-Smug-Title: ' . $title,
];
if ($caption !== null) { $headers[] = 'X-Smug-Caption: ' . $caption; }
if ($lat !== null) { $headers[] = 'X-Smug-Latitude: ' . $lat; }
if ($lng !== null) { $headers[] = 'X-Smug-Longitude: ' . $lng; }

$ch = curl_init($uploadURL);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
$raw = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
if ($raw === false || $http < 200 || $http >= 300) {
    mobile_json_response(['ok' => false, 'error' => $err ?: ('SmugMug upload failed with HTTP ' . $http), 'details' => is_string($raw) ? substr($raw, 0, 500) : null], 502);
}
$response = json_decode($raw, true);
if (!is_array($response) || ($response['stat'] ?? '') !== 'ok') {
    mobile_json_response(['ok' => false, 'error' => 'SmugMug returned an unexpected upload response.', 'details' => substr($raw, 0, 500)], 502);
}
$image = $response['Image'] ?? [];
$photoURL = (string)($image['URL'] ?? '');
$imageUri = (string)($image['ImageUri'] ?? ($image['AlbumImageUri'] ?? ''));
$smugKey = $imageUri !== '' ? $imageUri : md5($photoURL . $filename . microtime(true));
$thumbURL = $photoURL !== '' ? $photoURL : $gallery;

$imported = null;
$syncWarning = null;
try {
    $imported = smug_rebuild_trip_photos($pdo, $albumUri, $apiKey, $caller);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $syncWarning = $e->getMessage();
}

mobile_json_response([
    'ok' => true,
    'caller' => $caller,
    'imageUri' => $imageUri,
    'photoURL' => $photoURL,
    'latitude' => $lat,
    'longitude' => $lng,
    'rebuilt' => $imported !== null,
    'imported' => $imported,
    'syncWarning' => $syncWarning,
]);
