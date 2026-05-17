<?php
/**
 * Regular product discount (percentage off list price).
 * Run: php install/migrate_2026_product_discount.php
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

echo "--- products.discount_pct ---\n";
if (!colExists($pdo, 'products', 'discount_pct')) {
    run($pdo, 'ALTER TABLE products ADD COLUMN discount_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER price');
} else {
    echo "discount_pct exists — skip\n";
}
echo "Done.\n";
