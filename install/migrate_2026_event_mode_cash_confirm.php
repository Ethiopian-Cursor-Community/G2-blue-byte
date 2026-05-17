<?php
/**
 * Event mode + cash confirmation support.
 * Run: php install/migrate_2026_event_mode_cash_confirm.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function tExists(PDO $pdo, string $table): bool {
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

echo "--- migrate_2026_event_mode_cash_confirm ---\n";

if (!tExists($pdo, 'user_event_mode')) {
    run($pdo, <<<SQL
CREATE TABLE user_event_mode (
  app_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  mode_source VARCHAR(32) NOT NULL DEFAULT 'manual',
  activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

if (!tExists($pdo, 'transaction_cash_confirms')) {
    run($pdo, <<<SQL
CREATE TABLE transaction_cash_confirms (
  transaction_id INT UNSIGNED NOT NULL PRIMARY KEY,
  buyer_confirmed_at DATETIME NULL DEFAULT NULL,
  seller_confirmed_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_buyer (buyer_confirmed_at),
  KEY idx_seller (seller_confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

if (!tExists($pdo, 'leaderboard_rank_history')) {
    run($pdo, <<<SQL
CREATE TABLE leaderboard_rank_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type ENUM('seller','buyer') NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  scope_type ENUM('global','event','seller_portal') NOT NULL DEFAULT 'global',
  scope_id INT UNSIGNED NULL DEFAULT NULL,
  rank_position INT UNSIGNED NOT NULL,
  metric_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  metric_orders INT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_daily_rank (subject_type, subject_id, scope_type, scope_id, snapshot_date),
  KEY idx_subject (subject_type, subject_id, snapshot_date),
  KEY idx_scope (scope_type, scope_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

run($pdo, "UPDATE transactions SET payment_status = 'pending_confirmation' WHERE payment_method = 'cash' AND payment_status = 'pending'");

echo "Done.\n";

