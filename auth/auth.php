<?php
require_once __DIR__ . '/db.php';

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

auth_start_session();

function auth_current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function auth_is_logged_in(): bool {
    return is_array(auth_current_user()) && !empty($_SESSION['user']['email']);
}

function auth_is_admin(): bool {
    $u = auth_current_user();
    return is_array($u) && (($u['role'] ?? '') === 'admin');
}

function auth_require_login(): void {
    if (!auth_is_configured()) {
        auth_setup_message();
    }
    if (!auth_is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/california-trip/';
        auth_redirect(auth_url('/auth/login.php') . '?next=' . rawurlencode($next));
    }
}

function auth_require_admin(): void {
    auth_require_login();
    if (!auth_is_admin()) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title><p>Admin access required.</p>';
        exit;
    }
}

function auth_csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function auth_verify_csrf(): void {
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || !hash_equals($known, $sent)) {
        http_response_code(400);
        echo 'Invalid CSRF token.';
        exit;
    }
}

function auth_json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
