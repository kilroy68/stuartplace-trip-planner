<?php
require_once __DIR__ . '/../../auth/bootstrap.php';
require_once __DIR__ . '/../../auth/db.php';

function mobile_trip_json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mobile_trip_get_authorization_bearer(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($header) || $header === '') {
        return '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }
    return '';
}

function mobile_trip_configured_tokens(): array {
    $c = auth_config();
    $tokens = $c['mobile_api_tokens'] ?? [];
    return is_array($tokens) ? $tokens : [];
}

function mobile_trip_token_matches(string $provided, string $configured): bool {
    $configured = trim($configured);
    if ($configured === '' || $provided === '') {
        return false;
    }
    if (strpos($configured, 'sha256:') === 0) {
        return hash_equals(substr($configured, 7), hash('sha256', $provided));
    }
    // Plaintext tokens are supported for convenience, but sha256: hashes are preferred.
    return hash_equals($configured, $provided);
}

function mobile_trip_authenticated_client(): string {
    $client = trim((string)($_SERVER['HTTP_X_STUARTPLACE_CLIENT'] ?? ''));
    $token = mobile_trip_get_authorization_bearer();
    $tokens = mobile_trip_configured_tokens();
    if ($client !== '' && isset($tokens[$client]) && mobile_trip_token_matches($token, (string)$tokens[$client])) {
        return $client;
    }
    // Optional backwards-compatible single-token mode.
    $fallbackHash = trim((string)(auth_config()['mobile_api_token_hash'] ?? ''));
    if ($fallbackHash !== '' && $token !== '' && hash_equals($fallbackHash, hash('sha256', $token))) {
        return $client !== '' ? $client : 'unknown-mobile-client';
    }
    mobile_trip_json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

function mobile_trip_stop_id_for_index(int $index): int {
    return $index >= 5 ? $index + 1 : $index;
}

function mobile_trip_float_or_null($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function mobile_trip_apply_lodging_stop_locations(array $trip, array $reservations): array {
    if (!isset($trip['stops']) || !is_array($trip['stops'])) {
        return $trip;
    }

    $originalStopLocations = [];
    foreach ($trip['stops'] as $index => $stop) {
        if (is_array($stop) && isset($stop['latitude'], $stop['longitude'])) {
            $originalStopLocations[$index] = [(float)$stop['latitude'], (float)$stop['longitude']];
        }
    }

    $lodgingByStop = [];
    foreach ($reservations as $reservation) {
        $stopId = $reservation['stop_id'] ?? null;
        $lat = mobile_trip_float_or_null($reservation['latitude'] ?? null);
        $lng = mobile_trip_float_or_null($reservation['longitude'] ?? null);
        if ($stopId === null || $stopId === '' || $lat === null || $lng === null) {
            continue;
        }
        if (strcasecmp((string)($reservation['type'] ?? ''), 'Lodging') !== 0) {
            continue;
        }
        if (strcasecmp((string)($reservation['status'] ?? ''), 'cancelled') === 0) {
            continue;
        }
        $key = (int)$stopId;
        $reservationText = implode(' ', array_filter([
            (string)($reservation['title'] ?? ''),
            (string)($reservation['address'] ?? ''),
            (string)($reservation['notes'] ?? ''),
        ]));
        if ($key === 6 && preg_match('/yosemite|el\s*portal|wawona|fish\s*camp|oakhurst|mariposa|tenaya|rush\s*creek|evergreen|curry\s*village|ahwahnee/i', $reservationText)) {
            // Current Yosemite itinerary stop uses preserved DB stop id 7; older rows/forms may have used 6.
            $key = 7;
        }
        if ($key === 12 && preg_match('/santa\s*monica|\bpier\b|\blax\b/i', $reservationText)) {
            // Current visible itinerary index for Santa Monica is 12, but preserved DB stop id is 13.
            $key = 13;
        }
        if (!isset($lodgingByStop[$key])) {
            $lodgingByStop[$key] = $reservation;
        }
    }

    foreach ($trip['stops'] as $index => $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $stopId = isset($stop['id']) ? mobile_trip_stop_id_for_index((int)$stop['id']) : mobile_trip_stop_id_for_index((int)$index);
        if (!isset($lodgingByStop[$stopId])) {
            continue;
        }
        $lodging = $lodgingByStop[$stopId];
        $lat = mobile_trip_float_or_null($lodging['latitude'] ?? null);
        $lng = mobile_trip_float_or_null($lodging['longitude'] ?? null);
        if ($lat === null || $lng === null) {
            continue;
        }
        $trip['stops'][$index]['latitude'] = $lat;
        $trip['stops'][$index]['longitude'] = $lng;
        $trip['stops'][$index]['lodgingLocation'] = [
            'reservation_id' => (int)($lodging['id'] ?? 0),
            'title' => (string)($lodging['title'] ?? 'Lodging'),
            'address' => $lodging['address'] ?? null,
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    $segmentEndpointStopIndexes = [
        [0, 1], [1, 3], [3, 4], [4, 5], [5, 6], [6, 7],
        [7, 8], [8, 8], [8, 9], [9, 10], [10, 11], [11, 12],
    ];

    if (isset($trip['segments']) && is_array($trip['segments'])) {
        foreach ($trip['segments'] as $segmentIndex => $segment) {
            if (!isset($segment['points']) || !is_array($segment['points'])) {
                continue;
            }
            $lastPointIndex = count($segment['points']) - 1;
            $mappedEndPoint = null;
            foreach ($segment['points'] as $pointIndex => $point) {
                $latKey = array_key_exists('lat', $point) ? 'lat' : (array_key_exists('latitude', $point) ? 'latitude' : null);
                $lngKey = array_key_exists('lng', $point) ? 'lng' : (array_key_exists('longitude', $point) ? 'longitude' : null);
                if ($latKey === null || $lngKey === null) {
                    continue;
                }
                $endpointStops = $segmentEndpointStopIndexes[$segmentIndex] ?? null;
                if ($endpointStops !== null && ($pointIndex === 0 || $pointIndex === $lastPointIndex)) {
                    $mappedStopIndex = $pointIndex === 0 ? $endpointStops[0] : $endpointStops[1];
                    if (isset($trip['stops'][$mappedStopIndex]['latitude'], $trip['stops'][$mappedStopIndex]['longitude'])) {
                        $mappedLat = (float)$trip['stops'][$mappedStopIndex]['latitude'];
                        $mappedLng = (float)$trip['stops'][$mappedStopIndex]['longitude'];
                        $isDenseRoadGeometry = count($segment['points']) > 10;
                        $alreadyNearMappedStop = hypot((float)$point[$latKey] - $mappedLat, (float)$point[$lngKey] - $mappedLng) < 0.03;
                        if ($pointIndex === $lastPointIndex && $isDenseRoadGeometry && $alreadyNearMappedStop) {
                            continue;
                        }
                        $trip['segments'][$segmentIndex]['points'][$pointIndex][$latKey] = $mappedLat;
                        $trip['segments'][$segmentIndex]['points'][$pointIndex][$lngKey] = $mappedLng;
                        if ($pointIndex === $lastPointIndex) {
                            $mappedEndPoint = [$mappedLat, $mappedLng, $latKey, $lngKey];
                        }
                        continue;
                    }
                }
                foreach ($originalStopLocations as $stopIndex => $original) {
                    $isEndpoint = $pointIndex === 0 || $pointIndex === $lastPointIndex;
                    $tolerance = $isEndpoint ? 0.20 : 0.00001;
                    if (abs((float)$point[$latKey] - $original[0]) < $tolerance && abs((float)$point[$lngKey] - $original[1]) < $tolerance) {
                        $trip['segments'][$segmentIndex]['points'][$pointIndex][$latKey] = (float)$trip['stops'][$stopIndex]['latitude'];
                        $trip['segments'][$segmentIndex]['points'][$pointIndex][$lngKey] = (float)$trip['stops'][$stopIndex]['longitude'];
                        break;
                    }
                }
            }
            // If a lodging coordinate moved an endpoint, embedded road geometry can still contain
            // stale points beyond the new endpoint (e.g. old downtown LA after Santa Monica hotel).
            // Trim any tail after the closest point to the new endpoint, then end exactly at lodging.
            if ($mappedEndPoint !== null && count($trip['segments'][$segmentIndex]['points']) > 2) {
                [$endLat, $endLng, $latKey, $lngKey] = $mappedEndPoint;
                $closestIndex = null;
                $closestDistance = null;
                $points = $trip['segments'][$segmentIndex]['points'];
                $pointCount = count($points);
                for ($i = 1; $i < $pointCount - 1; $i++) {
                    if (!isset($points[$i][$latKey], $points[$i][$lngKey])) continue;
                    $distance = hypot((float)$points[$i][$latKey] - $endLat, (float)$points[$i][$lngKey] - $endLng);
                    if ($closestDistance === null || $distance < $closestDistance) {
                        $closestDistance = $distance;
                        $closestIndex = $i;
                    }
                }
                if ($closestIndex !== null && $closestDistance !== null && $closestDistance < 0.03 && $closestIndex < $pointCount - 2) {
                    $trip['segments'][$segmentIndex]['points'] = array_slice($points, 0, $closestIndex + 1);
                }
            }
        }
    }

    return $trip;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobile_trip_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$caller = mobile_trip_authenticated_client();
$path = __DIR__ . '/../trip-data.json';
if (!is_file($path) || !is_readable($path)) {
    mobile_trip_json_response(['ok' => false, 'error' => 'Trip data is not available.'], 500);
}

$raw = file_get_contents($path);
$trip = json_decode($raw ?: '', true);
if (!is_array($trip)) {
    mobile_trip_json_response(['ok' => false, 'error' => 'Trip data is invalid.'], 500);
}

$mobileExtras = [
    'items' => [],
    'reservations' => [],
    'photos' => [],
];
try {
    $pdo = auth_db();
    $mobileExtras['items'] = $pdo->query('SELECT id, stop_id, item_text, created_at, created_by FROM stop_items ORDER BY created_at ASC')->fetchAll();
    $mobileExtras['reservations'] = $pdo->query('SELECT id, stop_id, title, type, status, reservation_date, reservation_time, confirmation, address, latitude, longitude, phone, url, cancellation_deadline, cost, notes FROM reservations ORDER BY COALESCE(stop_id, 999999), COALESCE(reservation_date, "9999-12-31"), COALESCE(reservation_time, "23:59:59"), title')->fetchAll();
    $mobileExtras['photos'] = $pdo->query('SELECT id, stop_id, title, caption, thumb_url, photo_url, latitude, longitude, taken_at FROM trip_photos WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY COALESCE(taken_at, created_at) ASC')->fetchAll();
} catch (Throwable $e) {
    // The itinerary itself should remain available even if optional planning tables are temporarily unavailable.
    $mobileExtras['extrasError'] = $e->getMessage();
}

$trip = mobile_trip_apply_lodging_stop_locations($trip, $mobileExtras['reservations']);
$trip = array_merge($trip, $mobileExtras);

mobile_trip_json_response([
    'ok' => true,
    'caller' => $caller,
    'generatedAt' => gmdate('c'),
    'trip' => $trip,
]);
