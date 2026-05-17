<?php
/**
 * Adds moderation_tags + video_duration_seconds to promo_posts (incremental).
 * Run after migrate_promo_posts.php: php install/migrate_promo_posts_epic.php
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

function run(PDO $pdo, string $sql): void {
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
}

echo "--- migrate_promo_posts_epic ---\n";

if (!colExists($pdo, 'promo_posts', 'id')) {
    echo "Table promo_posts missing — run php install/migrate_promo_posts.php first.\n";
    exit(1);
}

if (!colExists($pdo, 'promo_posts', 'moderation_tags')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN moderation_tags VARCHAR(500) NULL DEFAULT NULL AFTER like_count');
}

if (!colExists($pdo, 'promo_posts', 'video_duration_seconds')) {
    run($pdo, 'ALTER TABLE promo_posts ADD COLUMN video_duration_seconds SMALLINT UNSIGNED NULL DEFAULT NULL AFTER moderation_tags');
}

echo "Done.\n";
