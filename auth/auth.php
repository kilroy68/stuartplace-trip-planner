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

function auth_remember_cookie_name(): string {
    return 'stuartplace_remember';
}

function auth_remember_cookie_options(?int $expires = null): array {
    return ['expires' => $expires ?? (time() + auth_session_lifetime_seconds())] + auth_session_cookie_base();
}

function auth_clear_remember_cookie(): void {
    setcookie(auth_remember_cookie_name(), '', auth_remember_cookie_options(time() - 42000));
}

function auth_hash_remember_validator(string $validator): string {
    return hash('sha256', $validator);
}

function auth_issue_remember_token(string $email): void {
    if (!auth_is_configured()) {
        return;
    }
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + auth_session_lifetime_seconds());
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    try {
        $pdo = auth_db();
        $pdo->prepare('DELETE FROM auth_tokens WHERE expires_at < NOW()')->execute();
        $stmt = $pdo->prepare('INSERT INTO auth_tokens (selector, token_hash, email, expires_at, user_agent, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$selector, auth_hash_remember_validator($validator), strtolower(trim($email)), $expiresAt, $userAgent ?: null, $ip ?: null]);
        setcookie(auth_remember_cookie_name(), $selector . ':' . $validator, auth_remember_cookie_options());
    } catch (Throwable $e) {
        // If persistent login storage is unavailable, keep the normal PHP session working.
    }
}

function auth_revoke_remember_token(): void {
    $raw = $_COOKIE[auth_remember_cookie_name()] ?? '';
    if (is_string($raw) && preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        try {
            auth_db()->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$m[1]]);
        } catch (Throwable $e) {
            // Ignore cleanup failures during logout.
        }
    }
    auth_clear_remember_cookie();
}

function auth_restore_remembered_user(): bool {
    if (!auth_is_configured()) {
        return false;
    }
    $raw = $_COOKIE[auth_remember_cookie_name()] ?? '';
    if (!is_string($raw) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        return false;
    }
    [$unused, $selector, $validator] = $m;
    try {
        $stmt = auth_db()->prepare('SELECT * FROM auth_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$selector]);
        $token = $stmt->fetch();
        if (!$token || !hash_equals((string)$token['token_hash'], auth_hash_remember_validator($validator))) {
            auth_revoke_remember_token();
            return false;
        }
        $user = auth_find_allowed_user((string)$token['email']);
        if (!$user) {
            auth_revoke_remember_token();
            return false;
        }
        $_SESSION['user'] = [
            'email' => $user['email'],
            'name' => $user['name'] ?: $user['email'],
            'picture' => $user['picture'] ?? null,
            'role' => $user['role'],
        ];
        auth_refresh_session_cookie();
        setcookie(auth_remember_cookie_name(), $selector . ':' . $validator, auth_remember_cookie_options());
        auth_db()->prepare('UPDATE auth_tokens SET last_used_at = NOW(), expires_at = ? WHERE selector = ?')
            ->execute([date('Y-m-d H:i:s', time() + auth_session_lifetime_seconds()), $selector]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
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
    if (is_array(auth_current_user()) && !empty($_SESSION['user']['email'])) {
        return true;
    }
    return auth_restore_remembered_user();
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
