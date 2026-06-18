<?php
function auth_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/config.php';
    if (!file_exists($path)) {
        $config = ['configured' => false];
        return $config;
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        $config = ['configured' => false];
        return $config;
    }
    $loaded['configured'] = true;
    $config = $loaded;
    return $config;
}

function auth_is_configured(): bool {
    $c = auth_config();
    $required = ['site_url', 'google_client_id', 'google_client_secret', 'db_host', 'db_name', 'db_user'];
    foreach ($required as $key) {
        $value = (string)($c[$key] ?? '');
        if ($value === '' || strpos($value, 'YOUR_') === 0) {
            return false;
        }
    }
    return !empty($c['configured']);
}

function auth_setup_message(): void {
    http_response_code(503);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Authentication setup needed</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff8ec;color:#102024;margin:0;display:grid;place-items:center;min-height:100vh}.card{max-width:760px;background:white;border:1px solid #eadfcd;border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(16,32,36,.14)}code{background:#f6f3ed;padding:2px 6px;border-radius:6px}</style></head><body><main class="card"><h1>Authentication setup needed</h1><p>The site authentication code is installed, but <code>auth/config.php</code> has not been configured on the server yet.</p><p>Copy <code>auth/config.example.php</code> to <code>auth/config.php</code>, then add the Google OAuth and Hostinger MySQL settings.</p></main></body></html><?php
    exit;
}

function auth_site_url(): string {
    $c = auth_config();
    return rtrim($c['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'www.stuartplace.net')), '/');
}

function auth_url(string $path): string {
    return auth_site_url() . '/' . ltrim($path, '/');
}

function auth_redirect(string $url): never {
    header('Location: ' . $url, true, 302);
    exit;
}

function auth_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
