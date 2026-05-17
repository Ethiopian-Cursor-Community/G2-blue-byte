<?php
/**
 * event_announcements audit log for organizer broadcasts.
 * Run: php install/migrate_event_announcements.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$t = $pdo->query("SHOW TABLES LIKE 'event_announcements'")->fetch();
if ($t) {
    echo "event_announcements exists — skip\n";
    exit(0);
}

$pdo->exec(<<<SQL
CREATE TABLE event_announcements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    organizer_id    INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body            TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event (event_id),
    KEY idx_org (organizer_id),
    CONSTRAINT fk_ea_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_org FOREIGN KEY (organizer_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
);
echo "OK: event_announcements created\n";
