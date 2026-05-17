<?php
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

echo "--- role_request columns ---\n";
if (!colExists($pdo, 'app_users', 'role_request_status')) {
    $pdo->exec("ALTER TABLE app_users ADD COLUMN role_request_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER is_active");
    echo "OK role_request_status\n";
}
if (!colExists($pdo, 'app_users', 'role_requested')) {
    $pdo->exec('ALTER TABLE app_users ADD COLUMN role_requested ENUM(\'seller\',\'organizer\') DEFAULT NULL AFTER role_request_status');
    echo "OK role_requested\n";
}
echo "Done.\n";
