<?php
require_once __DIR__ . '/db.php';

function auth_session_lifetime_seconds(): int {
    $days = (int)(auth_config()['session_lifetime_days'] ?? 30);
    if ($days < 1) {
        $days = 30;
    }
    if ($days > 365) {
        $days = 365;
    }
    return $days * 86400;
}

function auth_session_cookie_base(): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return [
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function auth_session_cookie_params(): array {
    return ['lifetime' => auth_session_lifetime_seconds()] + auth_session_cookie_base();
}

function auth_session_cookie_options(?int $expires = null): array {
    return ['expires' => $expires ?? (time() + auth_session_lifetime_seconds())] + auth_session_cookie_base();
}

function auth_session_save_path(): ?string {
    $configured = auth_config()['session_save_path'] ?? '';
    $path = is_string($configured) && trim($configured) !== ''
        ? trim($configured)
        : dirname(__DIR__, 2) . '/stuartplace-sessions';
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    return is_dir($path) && is_writable($path) ? $path : null;
}

function auth_refresh_session_cookie(): void {
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        return;
    }
    setcookie(session_name(), session_id(), auth_session_cookie_options());
}

function auth_clear_session_cookie(): void {
    setcookie(session_name(), '', auth_session_cookie_options(time() - 42000));
}

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        auth_refresh_session_cookie();
        return;
    }
    $lifetime = auth_session_lifetime_seconds();
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    $savePath = auth_session_save_path();
    if ($savePath !== null) {
        session_save_path($savePath);
    }
    session_set_cookie_params(auth_session_cookie_params());
    session_start();
    auth_refresh_session_cookie();
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
