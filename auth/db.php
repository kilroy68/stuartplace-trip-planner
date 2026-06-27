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

    $pdo->exec("CREATE TABLE IF NOT EXISTS stop_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        stop_id INT NOT NULL,
        item_text TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(190) NULL,
        updated_at DATETIME NULL,
        updated_by VARCHAR(190) NULL,
        INDEX idx_stop_items_stop (stop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        stop_id INT NULL,
        title VARCHAR(255) NOT NULL,
        type VARCHAR(80) NOT NULL DEFAULT 'Other',
        status VARCHAR(40) NOT NULL DEFAULT 'planned',
        reservation_date DATE NULL,
        reservation_time TIME NULL,
        confirmation VARCHAR(190) NULL,
        address VARCHAR(255) NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        phone VARCHAR(80) NULL,
        url VARCHAR(500) NULL,
        cancellation_deadline DATETIME NULL,
        cost DECIMAL(10,2) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(190) NULL,
        updated_at DATETIME NULL,
        updated_by VARCHAR(190) NULL,
        INDEX idx_reservations_stop (stop_id),
        INDEX idx_reservations_date (reservation_date),
        INDEX idx_reservations_location (latitude, longitude)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE reservations ADD INDEX idx_reservations_location (latitude, longitude)"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS trip_photos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        smugmug_key VARCHAR(190) NULL UNIQUE,
        title VARCHAR(255) NULL,
        caption TEXT NULL,
        thumb_url VARCHAR(500) NOT NULL,
        photo_url VARCHAR(500) NOT NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        taken_at DATETIME NULL,
        stop_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(190) NULL,
        INDEX idx_trip_photos_location (latitude, longitude),
        INDEX idx_trip_photos_stop (stop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(190) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        selector CHAR(24) NOT NULL UNIQUE,
        token_hash CHAR(64) NOT NULL,
        email VARCHAR(190) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        user_agent VARCHAR(255) NULL,
        ip_address VARCHAR(64) NULL,
        INDEX idx_auth_tokens_email (email),
        INDEX idx_auth_tokens_expires (expires_at)
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
