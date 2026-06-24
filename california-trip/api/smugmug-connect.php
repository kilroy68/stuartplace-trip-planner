<?php
require_once __DIR__ . '/../../auth/bootstrap.php';
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../auth/db.php';

auth_require_admin();

function sm_oauth_percent_encode(string $value): string {
    return str_replace('%7E', '~', rawurlencode($value));
}

function sm_oauth_header(string $method, string $url, array $params, string $consumerSecret, string $tokenSecret = ''): string {
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = sm_oauth_percent_encode((string)$k) . '=' . sm_oauth_percent_encode((string)$v);
    }
    $base = strtoupper($method) . '&' . sm_oauth_percent_encode($url) . '&' . sm_oauth_percent_encode(implode('&', $pairs));
    $key = sm_oauth_percent_encode($consumerSecret) . '&' . sm_oauth_percent_encode($tokenSecret);
    $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));
    $headerParts = [];
    foreach ($params as $k => $v) {
        if (strpos($k, 'oauth_') === 0) {
            $headerParts[] = sm_oauth_percent_encode((string)$k) . '="' . sm_oauth_percent_encode((string)$v) . '"';
        }
    }
    return 'OAuth ' . implode(', ', $headerParts);
}

function sm_oauth_params(string $consumerKey, ?string $token = null): array {
    $params = [
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => bin2hex(random_bytes(16)),
        'oauth_timestamp' => (string)time(),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_version' => '1.0',
    ];
    if ($token !== null && $token !== '') {
        $params['oauth_token'] = $token;
    }
    return $params;
}

function sm_oauth_post(string $url, array $params, string $consumerSecret, string $tokenSecret = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: ' . sm_oauth_header('POST', $url, $params, $consumerSecret, $tokenSecret)],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http < 200 || $http >= 300) {
        throw new RuntimeException($err ?: 'SmugMug OAuth failed with HTTP ' . $http . ': ' . substr((string)$raw, 0, 300));
    }
    parse_str((string)$raw, $out);
    return is_array($out) ? $out : [];
}

function sm_setting_set(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function sm_page(string $title, string $body): never {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . auth_h($title) . '</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff8ec;color:#102024;margin:0;display:grid;place-items:center;min-height:100vh}.card{max-width:760px;background:#fff;border:1px solid #eadfcd;border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(16,32,36,.14)}a,.button{display:inline-block;background:#1f7a66;color:#fff;text-decoration:none;padding:12px 16px;border-radius:999px;margin-top:12px}.muted{color:#5b686b}code{background:#f6f3ed;padding:2px 6px;border-radius:6px}</style></head><body><main class="card"><h1>' . auth_h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

$config = auth_config();
$apiKey = trim((string)($config['smugmug_api_key'] ?? ''));
$apiSecret = trim((string)($config['smugmug_api_secret'] ?? ''));
if ($apiKey === '' || $apiSecret === '') {
    sm_page('SmugMug API key needed', '<p>Add <code>smugmug_api_key</code> and <code>smugmug_api_secret</code> to the private <code>stuartplace-config.php</code> file first.</p><p class="muted">After that, return here to connect the SmugMug account.</p><p><a class="button" href="/california-trip/">Back to trip planner</a></p>');
}

$pdo = auth_db();
$action = $_GET['action'] ?? '';

try {
    if ($action === 'reset') {
        $pdo->prepare('DELETE FROM app_settings WHERE setting_key IN ("smugmug_access_token", "smugmug_access_token_secret")')->execute();
        unset($_SESSION['smugmug_request_token'], $_SESSION['smugmug_request_token_secret']);
        sm_page('SmugMug disconnected', '<p>The stored SmugMug OAuth tokens were removed.</p><p><a class="button" href="/california-trip/api/smugmug-connect.php">Reconnect SmugMug</a></p>');
    }

    if (isset($_GET['oauth_token'], $_GET['oauth_verifier'])) {
        $requestToken = (string)$_GET['oauth_token'];
        $verifier = (string)$_GET['oauth_verifier'];
        $knownToken = (string)($_SESSION['smugmug_request_token'] ?? '');
        $requestSecret = (string)($_SESSION['smugmug_request_token_secret'] ?? '');
        if ($requestToken === '' || $requestSecret === '' || !hash_equals($knownToken, $requestToken)) {
            throw new RuntimeException('The SmugMug authorization session expired. Please start again.');
        }
        $url = 'https://api.smugmug.com/services/oauth/1.0a/getAccessToken';
        $params = sm_oauth_params($apiKey, $requestToken);
        $params['oauth_verifier'] = $verifier;
        $access = sm_oauth_post($url, $params, $apiSecret, $requestSecret);
        $token = (string)($access['oauth_token'] ?? '');
        $secret = (string)($access['oauth_token_secret'] ?? '');
        if ($token === '' || $secret === '') {
            throw new RuntimeException('SmugMug did not return an access token.');
        }
        sm_setting_set($pdo, 'smugmug_access_token', $token);
        sm_setting_set($pdo, 'smugmug_access_token_secret', $secret);
        unset($_SESSION['smugmug_request_token'], $_SESSION['smugmug_request_token_secret']);
        sm_page('SmugMug connected', '<p>SmugMug OAuth 1.0a access was authorized and saved for trip photo uploads.</p><p><a class="button" href="/california-trip/">Back to trip planner</a></p>');
    }

    $requestURL = 'https://api.smugmug.com/services/oauth/1.0a/getRequestToken';
    $params = sm_oauth_params($apiKey);
    $params['oauth_callback'] = auth_url('/california-trip/api/smugmug-connect.php');
    $request = sm_oauth_post($requestURL, $params, $apiSecret);
    $token = (string)($request['oauth_token'] ?? '');
    $secret = (string)($request['oauth_token_secret'] ?? '');
    if ($token === '' || $secret === '') {
        throw new RuntimeException('SmugMug did not return a request token.');
    }
    $_SESSION['smugmug_request_token'] = $token;
    $_SESSION['smugmug_request_token_secret'] = $secret;
    auth_refresh_session_cookie();
    $authorize = 'https://api.smugmug.com/services/oauth/1.0a/authorize?oauth_token=' . rawurlencode($token) . '&Access=Full&Permissions=Modify';
    auth_redirect($authorize);
} catch (Throwable $e) {
    sm_page('SmugMug connection failed', '<p>' . auth_h($e->getMessage()) . '</p><p><a class="button" href="/california-trip/api/smugmug-connect.php">Try again</a></p>');
}
