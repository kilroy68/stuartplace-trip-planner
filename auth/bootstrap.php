<?php
function auth_config_paths(): array {
    $paths = [];
    $envPath = getenv('STUARTPLACE_CONFIG');
    if (is_string($envPath) && trim($envPath) !== '') {
        $paths[] = trim($envPath);
    }

    // Preferred Hostinger location: one level above public_html, so Git deploys cannot delete it.
    // If this file is /home/user/domains/stuartplace.net/public_html/auth/bootstrap.php,
    // this checks /home/user/domains/stuartplace.net/stuartplace-config.php.
    $paths[] = dirname(__DIR__, 2) . '/stuartplace-config.php';

    // Alternate Hostinger/common shared-host locations.
    $paths[] = dirname(__DIR__, 3) . '/stuartplace-config.php';
    $paths[] = dirname(__DIR__) . '/../stuartplace-config.php';

    // Legacy fallback inside the web root. This keeps the site working until the private
    // config is moved, but this file can be deleted by Git deployments.
    $paths[] = __DIR__ . '/config.php';

    return array_values(array_unique($paths));
}

function auth_config_path(): ?string {
    foreach (auth_config_paths() as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function auth_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = auth_config_path();
    if ($path === null) {
        $config = ['configured' => false, 'config_paths_checked' => auth_config_paths()];
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
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Authentication setup needed</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff8ec;color:#102024;margin:0;display:grid;place-items:center;min-height:100vh}.card{max-width:820px;background:white;border:1px solid #eadfcd;border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(16,32,36,.14)}code{background:#f6f3ed;padding:2px 6px;border-radius:6px}li{margin:.35rem 0}</style></head><body><main class="card"><h1>Authentication setup needed</h1><p>The site authentication code is installed, but the private config file was not found.</p><p>Preferred permanent fix: move the private config outside <code>public_html</code> so Git deploys cannot delete it:</p><ol><li>Copy <code>public_html/auth/config.php</code></li><li>Paste it one level above <code>public_html</code> as <code>stuartplace-config.php</code></li><li>Keep <code>public_html/auth/config.php</code> only as a temporary fallback, or delete it after the outside copy works.</li></ol><p>The code also still checks legacy <code>auth/config.php</code> as a fallback.</p></main></body></html><?php
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
