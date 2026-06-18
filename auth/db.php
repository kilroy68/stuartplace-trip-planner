<?php
require_once __DIR__ . '/bootstrap.php';

function auth_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!auth_is_configured()) {
        auth_setup_message();
    }
    $c = auth_config();
    $charset = $c['db_charset'] ?? 'utf8mb4';
    $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=' . $charset;
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    auth_ensure_schema($pdo);
    return $pdo;
}

function auth_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS allowed_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        role ENUM('admin','user') NOT NULL DEFAULT 'user',
        status ENUM('active','disabled') NOT NULL DEFAULT 'active',
        name VARCHAR(190) NULL,
        picture VARCHAR(500) NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(190) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $c = auth_config();
    foreach (($c['initial_users'] ?? []) as $email => $role) {
        $email = strtolower(trim((string)$email));
        $role = $role === 'admin' ? 'admin' : 'user';
        if ($email === '') {
            continue;
        }
        $stmt = $pdo->prepare("INSERT INTO allowed_users (email, role, status, created_by)
            VALUES (?, ?, 'active', 'config')
            ON DUPLICATE KEY UPDATE role = VALUES(role), status = 'active'");
        $stmt->execute([$email, $role]);
    }
    $done = true;
}

function auth_find_allowed_user(string $email): ?array {
    $stmt = auth_db()->prepare("SELECT * FROM allowed_users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function auth_touch_login(string $email, ?string $name, ?string $picture): void {
    $stmt = auth_db()->prepare("UPDATE allowed_users SET name = ?, picture = ?, last_login_at = NOW() WHERE email = ?");
    $stmt->execute([$name, $picture, strtolower(trim($email))]);
}
