<?php
/**
 * Event gatekeeper/delegated staff table.
 * Run: php install/migrate_event_staff.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$exists = $pdo->query("SHOW TABLES LIKE 'event_staff'")->fetch();
if ($exists) {
    echo "event_staff exists — skip\n";
    exit(0);
}

$pdo->exec(<<<SQL
CREATE TABLE event_staff (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    app_user_id     INT NOT NULL,
    assigned_by     INT NULL DEFAULT NULL,
    role_label      VARCHAR(50) NOT NULL DEFAULT 'gatekeeper',
    valid_until     DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_staff (event_id, app_user_id),
    KEY idx_staff_user (app_user_id),
    KEY idx_staff_valid (valid_until),
    CONSTRAINT fk_staff_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_user FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
);

echo "OK: event_staff created\n";

