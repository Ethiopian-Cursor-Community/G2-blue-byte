<?php
/**
 * Promo v3: drafts, withdrawal, appeals, reports, rejection reasons, fairness sort fields,
 * media hash (duplicate detection), transcode placeholder, submitted_at.
 *
 * Run: php install/migrate_promo_posts_v3.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function colExists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([DB_NAME, $table, $column]);

    return (int) $st->fetchColumn() > 0;
}

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

echo "--- migrate_promo_posts_v3 ---\n";

if (!tableExists($pdo, 'promo_posts')) {
    echo "Run migrate_promo_posts.php first.\n";
    exit(1);
}

run($pdo, "ALTER TABLE promo_posts MODIFY COLUMN status ENUM('active','pending','rejected','draft','withdrawn','flagged') NOT NULL DEFAULT 'pending'");

if (!colExists($pdo, 'promo_posts', 'rejection_code')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN rejection_code VARCHAR(64) NULL DEFAULT NULL AFTER reviewed_at');
}
if (!colExists($pdo, 'promo_posts', 'rejection_note')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN rejection_note VARCHAR(2000) NULL DEFAULT NULL AFTER rejection_code');
}
if (!colExists($pdo, 'promo_posts', 'appeal_message')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN appeal_message VARCHAR(2000) NULL DEFAULT NULL AFTER rejection_note');
}
if (!colExists($pdo, 'promo_posts', 'appealed_at')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN appealed_at DATETIME NULL DEFAULT NULL AFTER appeal_message');
}
if (!colExists($pdo, 'promo_posts', 'submitted_at')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN submitted_at DATETIME NULL DEFAULT NULL AFTER created_at');
}
if (!colExists($pdo, 'promo_posts', 'report_count')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN report_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER like_count');
}
if (!colExists($pdo, 'promo_posts', 'media_sha256')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN media_sha256 CHAR(64) NULL DEFAULT NULL AFTER thumbnail_url');
}
if (!colExists($pdo, 'promo_posts', 'video_transcode_status')) {
    run($pdo, "ALTER TABLE promo_posts ADD COLUMN video_transcode_status VARCHAR(32) NULL DEFAULT 'skipped' AFTER video_duration_seconds");
}

if (!tableExists($pdo, 'promo_post_reports')) {
    run($pdo, <<<SQL
CREATE TABLE promo_post_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  reporter_app_user_id INT UNSIGNED NULL DEFAULT NULL,
  reporter_ip_hash CHAR(64) NULL DEFAULT NULL,
  reason ENUM('spam','inappropriate','misleading','copyright','other') NOT NULL DEFAULT 'other',
  body VARCHAR(2000) NULL DEFAULT NULL,
  status ENUM('open','reviewed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_post (post_id),
  KEY idx_open (status),
  KEY idx_reporter (reporter_app_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

if (tableExists($pdo, 'promo_posts') && colExists($pdo, 'promo_posts', 'submitted_at')) {
    run($pdo, "UPDATE promo_posts SET submitted_at = created_at WHERE submitted_at IS NULL AND status IN ('pending','active','rejected','flagged')");
}

echo "Done.\n";
