<?php
require_once __DIR__ . '/auth.php';

if (!auth_is_configured()) {
    auth_setup_message();
}

function auth_error_page(string $title, string $message, int $status = 400): never {
    http_response_code($status);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= auth_h($title) ?></title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff8ec;color:#102024;min-height:100vh;display:grid;place-items:center;margin:0}.card{max-width:620px;background:white;border:1px solid #eadfcd;border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(16,32,36,.14)}a{color:#0f6c81;font-weight:800}</style></head><body><main class="card"><h1><?= auth_h($title) ?></h1><p><?= auth_h($message) ?></p><p><a href="<?= auth_h(auth_url('/')) ?>">Return home</a></p></main></body></html><?php
    exit;
}

if (!empty($_GET['error'])) {
    auth_error_page('Google sign-in cancelled', 'Google returned an error: ' . (string)$_GET['error']);
}

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
if (!is_string($state) || !hash_equals($_SESSION['oauth_state'] ?? '', $state) || !is_string($code) || $code === '') {
    auth_error_page('Invalid sign-in response', 'The Google sign-in response could not be verified. Please try again.');
}

$c = auth_config();
$post = http_build_query([
    'code' => $code,
    'client_id' => $c['google_client_id'],
    'client_secret' => $c['google_client_secret'],
    'redirect_uri' => auth_url('/auth/callback.php'),
    'grant_type' => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
]);
$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $http < 200 || $http >= 300) {
    auth_error_page('Google token exchange failed', $curlError ?: 'Google did not accept the OAuth callback. Check the client ID, client secret, and redirect URI.', 502);
}
$token = json_decode($response, true);
$idToken = $token['id_token'] ?? '';
if (!is_string($idToken) || substr_count($idToken, '.') !== 2) {
    auth_error_page('Invalid Google token', 'Google did not return a valid identity token.', 502);
}

$parts = explode('.', $idToken);
$payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
$payload = json_decode($payloadJson ?: '', true);
if (!is_array($payload)) {
    auth_error_page('Invalid Google profile', 'The Google identity token could not be decoded.', 502);
}

$issuer = $payload['iss'] ?? '';
$aud = $payload['aud'] ?? '';
$exp = (int)($payload['exp'] ?? 0);
$email = strtolower(trim((string)($payload['email'] ?? '')));
$emailVerified = ($payload['email_verified'] ?? false) === true || ($payload['email_verified'] ?? '') === 'true';
$name = isset($payload['name']) ? (string)$payload['name'] : null;
$picture = isset($payload['picture']) ? (string)$payload['picture'] : null;

if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true) || $aud !== $c['google_client_id'] || $exp < time() || $email === '' || !$emailVerified) {
    auth_error_page('Google account not verified', 'The Google sign-in could not be validated for this site.', 403);
}

$user = auth_find_allowed_user($email);
if (!$user) {
    auth_error_page('Access not allowed', $email . ' is not currently allowed to access this site. Ask David or Angela to add this email address.', 403);
}

auth_touch_login($email, $name, $picture);
$next = $_SESSION['oauth_next'] ?? '/california-trip/';
if (!is_string($next) || $next === '' || $next[0] !== '/') {
    $next = '/california-trip/';
}
session_regenerate_id(true);
$_SESSION['user'] = [
    'email' => $email,
    'name' => $name ?: $email,
    'picture' => $picture,
    'role' => $user['role'],
];
unset($_SESSION['oauth_state'], $_SESSION['oauth_next']);
auth_redirect($next);
