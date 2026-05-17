<?php
/**
 * Default ticket tier & face value per event (admin Event branding).
 * Run: php install/migrate_event_ticket_defaults.php
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
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

echo "--- migrate_event_ticket_defaults ---\n";

if (!colExists($pdo, 'bazar_events', 'default_ticket_tier')) {
    run($pdo, "ALTER TABLE bazar_events ADD COLUMN default_ticket_tier ENUM('standard','premium','vip','day_pass') NOT NULL DEFAULT 'standard' AFTER marquee_text");
}
if (!colExists($pdo, 'bazar_events', 'default_ticket_face_etb')) {
    run($pdo, 'ALTER TABLE bazar_events ADD COLUMN default_ticket_face_etb DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER default_ticket_tier');
}

echo "Done.\n";
