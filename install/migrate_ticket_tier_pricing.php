<?php
/**
 * Ticket tier (standard / premium / vip), face value, public ticket number.
 * Run: php install/migrate_ticket_tier_pricing.php
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

echo "--- migrate_ticket_tier_pricing ---\n";

if (!colExists($pdo, 'tickets', 'ticket_tier')) {
    run($pdo, "ALTER TABLE tickets ADD COLUMN ticket_tier ENUM('standard','premium','vip') NOT NULL DEFAULT 'standard' AFTER status");
}
if (!colExists($pdo, 'tickets', 'face_value_etb')) {
    run($pdo, 'ALTER TABLE tickets ADD COLUMN face_value_etb DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ticket_tier');
}
if (!colExists($pdo, 'tickets', 'display_no')) {
    run($pdo, 'ALTER TABLE tickets ADD COLUMN display_no VARCHAR(32) DEFAULT NULL AFTER face_value_etb');
}

try {
    $pdo->exec("UPDATE tickets SET display_no = CONCAT('QB-', LPAD(id, 6, '0')) WHERE display_no IS NULL OR display_no = ''");
    echo "OK display_no backfill\n";
} catch (Throwable $e) {
    echo "ERR backfill: " . $e->getMessage() . "\n";
}

echo "Done.\n";
