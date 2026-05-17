<?php
/**
 * CLI Migration Runner for QR Bazar
 * Run from terminal: php bin/migrate.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line.\n");
}

echo "--- QR Bazar Migration Runner ---\n";

$migrations = [
    'System Audit' => 'qb_apply_audit_schema',
    'Fraud Detection' => 'qb_apply_fraud_schema',
    'Seller Slots' => 'qb_apply_seller_product_slot_schema',
    'Seller Gate' => 'qb_apply_seller_gate_schema',
    'Event Ticket Pricing' => 'qb_apply_event_ticket_pricing_schema',
    'Seller Compliance' => 'qb_apply_seller_compliance_schema',
    'Seller Downgrade' => 'qb_apply_seller_downgrade_schema',
    'Login Rate Limits' => 'qb_rate_limit_ip_ensure_table',
    'Password Resets' => 'qb_apply_password_reset_schema',
];

foreach ($migrations as $name => $func) {
    echo "Applying $name... ";
    if (function_exists($func)) {
        try {
            $func();
            echo "DONE\n";
        } catch (Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    } else {
        echo "SKIPPED (function not found)\n";
    }
}

function qb_apply_password_reset_schema(): void {
    db()->execute(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            token VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pr_token (token),
            KEY idx_pr_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

echo "Migrations complete.\n";
