<?php
/**
 * User-generated promo posts (sellers & organizers) + likes + admin approval.
 * Run: php install/migrate_promo_posts.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute([DB_NAME, $table]);

    return (int) $st->fetchColumn() > 0;
}

function run(PDO $pdo, string $sql): void {
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
}

echo "--- migrate_promo_posts ---\n";

if (!tableExists($pdo, 'promo_posts')) {
    run($pdo, <<<SQL
CREATE TABLE promo_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  content_type ENUM('text','image','video') NOT NULL DEFAULT 'text',
  media_url VARCHAR(512) DEFAULT NULL,
  thumbnail_url VARCHAR(512) DEFAULT NULL,
  owner_type ENUM('seller','organization') NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  target ENUM('homepage','store','category') NOT NULL DEFAULT 'homepage',
  status ENUM('active','pending','rejected') NOT NULL DEFAULT 'pending',
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  like_count INT UNSIGNED NOT NULL DEFAULT 0,
  moderation_tags VARCHAR(500) NULL DEFAULT NULL,
  video_duration_seconds SMALLINT UNSIGNED NULL DEFAULT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  is_sponsored TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_by INT UNSIGNED NULL DEFAULT NULL,
  reviewed_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_home (target, status, created_at),
  KEY idx_owner (owner_type, owner_id),
  KEY idx_expires (expires_at),
  KEY idx_reviewer (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

if (!tableExists($pdo, 'promo_post_likes')) {
    run($pdo, <<<SQL
CREATE TABLE promo_post_likes (
  post_id INT UNSIGNED NOT NULL,
  app_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, app_user_id),
  KEY idx_like_user (app_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

echo "Done.\n";
