<?php
/**
 * Adds application category snapshot + seller category edit lock (admin can re-open).
 *
 * Run: php install/migrate_seller_event_application_enhancements.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/functions.php';

if (!function_exists('qb_has_column')) {
    fwrite(STDERR, "qb_has_column missing.\n");
    exit(1);
}

try {
    if (!qb_has_column('event_participants', 'application_categories_json')) {
        db()->execute('ALTER TABLE event_participants ADD COLUMN application_categories_json TEXT NULL');
        echo "Added event_participants.application_categories_json\n";
    } else {
        echo "event_participants.application_categories_json already exists\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

try {
    if (!qb_has_column('sellers', 'allow_categories_edit')) {
        db()->execute('ALTER TABLE sellers ADD COLUMN allow_categories_edit TINYINT(1) NOT NULL DEFAULT 1');
        db()->execute(
            "UPDATE sellers SET allow_categories_edit = 0 WHERE categories_json IS NOT NULL AND TRIM(categories_json) NOT IN ('', '[]', 'null')"
        );
        echo "Added sellers.allow_categories_edit and locked existing categorized sellers\n";
    } else {
        echo "sellers.allow_categories_edit already exists\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Done.\n";
