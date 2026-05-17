<?php
/**
 * Adds app_users.role value gatekeeper for dedicated gate portal.
 * Run: php install/migrate_gatekeeper_role.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$row = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
$type = strtolower((string) ($row['Type'] ?? ''));
if (str_contains($type, 'gatekeeper')) {
    echo "app_users.role already includes gatekeeper — skip\n";
    exit(0);
}

try {
    $pdo->exec(
        "ALTER TABLE app_users MODIFY COLUMN role ENUM('super_admin','organizer','seller','buyer','gatekeeper') NOT NULL"
    );
    echo "OK: gatekeeper added to app_users.role\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
