<?php
require_once __DIR__ . '/../../auth/bootstrap.php';

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

mobile_trip_json_response([
    'ok' => true,
    'caller' => $caller,
    'generatedAt' => gmdate('c'),
    'trip' => $trip,
]);
