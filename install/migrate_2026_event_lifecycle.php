<?php
/**
 * Event lifecycle: postponed / canceled status + admin reason note.
 * Run from project root: php install/migrate_2026_event_lifecycle.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/event_lifecycle_migrate.php';

echo "--- Event lifecycle migration ---\n";
$r = qb_apply_event_lifecycle_schema();
foreach ($r['messages'] as $m) {
    echo $m . "\n";
}
if (!$r['ok']) {
    echo 'ERR: ' . ($r['error'] ?? 'unknown') . "\n";
    exit(1);
}
echo "Done.\n";
