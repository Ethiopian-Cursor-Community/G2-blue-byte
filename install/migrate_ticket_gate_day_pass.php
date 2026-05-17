<?php
/**
 * Gate scan counter + day_pass tier. Run: php install/migrate_ticket_gate_day_pass.php
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

echo "--- migrate_ticket_gate_day_pass ---\n";

if (!colExists($pdo, 'tickets', 'gate_scan_count')) {
    run($pdo, 'ALTER TABLE tickets ADD COLUMN gate_scan_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER used_at');
}

// Extend tier enums (ignore if already includes day_pass)
try {
    $pdo->exec("ALTER TABLE tickets MODIFY COLUMN ticket_tier ENUM('standard','premium','vip','day_pass') NOT NULL DEFAULT 'standard'");
    echo "OK tickets.ticket_tier enum\n";
} catch (Throwable $e) {
    if (stripos($e->getMessage(), 'Unknown column') !== false) {
        echo "SKIP tickets.ticket_tier (column missing — run migrate_ticket_tier_pricing first)\n";
    } else {
        echo 'ERR tickets enum: ' . $e->getMessage() . "\n";
    }
}

if (colExists($pdo, 'bazar_events', 'default_ticket_tier')) {
    try {
        $pdo->exec("ALTER TABLE bazar_events MODIFY COLUMN default_ticket_tier ENUM('standard','premium','vip','day_pass') NOT NULL DEFAULT 'standard'");
        echo "OK bazar_events.default_ticket_tier enum\n";
    } catch (Throwable $e) {
        echo 'ERR events enum: ' . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
