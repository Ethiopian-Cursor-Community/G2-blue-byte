<?php
/**
 * Create flash_sales if missing (limited-time product discounts).
 * Run: php install/migrate_2026_flash_sales.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function run(PDO $pdo, string $sql): void {
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

echo "--- flash_sales migration ---\n";

$t = $pdo->query("SHOW TABLES LIKE 'flash_sales'")->fetch();
if (!$t) {
    run($pdo, <<<SQL
CREATE TABLE flash_sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT NOT NULL,
    seller_id       INT NOT NULL,
    event_id        INT DEFAULT NULL,
    discount_pct    TINYINT NOT NULL DEFAULT 10,
    original_price  DECIMAL(10,2) NOT NULL,
    sale_price      DECIMAL(10,2) NOT NULL,
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fs_seller (seller_id),
    KEY idx_fs_product (product_id),
    KEY idx_fs_window (starts_at, ends_at),
    KEY idx_fs_event (event_id),
    CONSTRAINT fk_fs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_fs_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    CONSTRAINT fk_fs_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );
} else {
    echo "flash_sales already exists — skip create\n";
}

echo "Done.\n";
