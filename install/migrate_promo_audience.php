<?php
/**
 * Who sees admin promos: buyers, sellers, organizers (checkboxes in admin/promos.php).
 * Run: php install/migrate_promo_audience.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

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
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
}

echo "--- migrate_promo_audience ---\n";

if (!colExists($pdo, 'event_promotions', 'show_buyers')) {
    run($pdo, 'ALTER TABLE event_promotions ADD COLUMN show_buyers TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active');
}
if (!colExists($pdo, 'event_promotions', 'show_sellers')) {
    run($pdo, 'ALTER TABLE event_promotions ADD COLUMN show_sellers TINYINT(1) NOT NULL DEFAULT 1 AFTER show_buyers');
}
if (!colExists($pdo, 'event_promotions', 'show_organizers')) {
    run($pdo, 'ALTER TABLE event_promotions ADD COLUMN show_organizers TINYINT(1) NOT NULL DEFAULT 1 AFTER show_sellers');
}

echo "Done.\n";
