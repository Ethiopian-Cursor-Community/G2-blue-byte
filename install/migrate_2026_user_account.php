<?php
/**
 * CLI: add app_users.public_uuid (short public IDs), moderation flags, backfill IDs.
 * Usage: php install/migrate_2026_user_account.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_account_migrate.php';

$r = qb_apply_user_account_schema();
if ($r['ok']) {
    echo "OK: user account columns applied.\n";
    exit(0);
}
echo "Error: " . ($r['error'] ?? 'unknown') . "\n";
exit(1);
