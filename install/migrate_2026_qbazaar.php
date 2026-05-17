<?php
/**
 * One-time migration: event theming, promos, product approval, organizer window columns.
 * Run: php install/migrate_2026_qbazaar.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function colExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([DB_NAME, $table, $col]);
    return (int) $st->fetchColumn() > 0;
}

function run(PDO $pdo, string $sql): void {
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

echo "--- QR BAZAR migration 2026 ---\n";

if (!colExists($pdo, 'bazar_events', 'theme_color')) {
    run($pdo, "ALTER TABLE bazar_events ADD COLUMN theme_color VARCHAR(16) NOT NULL DEFAULT '#C48A32' AFTER notes");
}
if (!colExists($pdo, 'bazar_events', 'cover_image')) {
    run($pdo, 'ALTER TABLE bazar_events ADD COLUMN cover_image VARCHAR(255) DEFAULT NULL AFTER theme_color');
}
if (!colExists($pdo, 'bazar_events', 'marquee_text')) {
    run($pdo, 'ALTER TABLE bazar_events ADD COLUMN marquee_text VARCHAR(500) DEFAULT NULL AFTER cover_image');
}
if (!colExists($pdo, 'bazar_events', 'organizer_active_start')) {
    run($pdo, 'ALTER TABLE bazar_events ADD COLUMN organizer_active_start DATETIME DEFAULT NULL AFTER marquee_text');
}
if (!colExists($pdo, 'bazar_events', 'organizer_active_end')) {
    run($pdo, 'ALTER TABLE bazar_events ADD COLUMN organizer_active_end DATETIME DEFAULT NULL AFTER organizer_active_start');
}

if (!colExists($pdo, 'products', 'approval_status')) {
    run($pdo, "ALTER TABLE products ADD COLUMN approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_available");
}

$t = $pdo->query("SHOW TABLES LIKE 'event_promotions'")->fetch();
if (!$t) {
    run($pdo, <<<SQL
CREATE TABLE event_promotions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  media_url VARCHAR(500) NOT NULL,
  media_type ENUM('image','video') NOT NULL DEFAULT 'image',
  marquee_text VARCHAR(500) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event (event_id),
  KEY idx_active (is_active),
  CONSTRAINT fk_promo_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );
}

if (!colExists($pdo, 'app_users', 'residence_city')) {
    run($pdo, 'ALTER TABLE app_users ADD COLUMN residence_city VARCHAR(120) DEFAULT NULL AFTER email');
}

$t2 = $pdo->query("SHOW TABLES LIKE 'bazar_event_organizers'")->fetch();
if (!$t2) {
    run($pdo, <<<SQL
CREATE TABLE bazar_event_organizers (
  event_id INT NOT NULL,
  app_user_id INT NOT NULL,
  assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, app_user_id),
  KEY idx_eo_user (app_user_id),
  CONSTRAINT fk_eo_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_eo_user FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );
}

require_once $root . '/includes/functions.php';
require_once $root . '/includes/event_lifecycle_migrate.php';
$r = qb_apply_event_lifecycle_schema();
foreach ($r['messages'] as $m) {
    echo $m . "\n";
}
if (!$r['ok']) {
    echo 'ERR lifecycle: ' . ($r['error'] ?? '') . "\n";
}

echo "Done.\n";
