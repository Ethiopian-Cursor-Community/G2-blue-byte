<?php
declare(strict_types=1);

/**
 * Lightweight static contract checks for critical component files.
 * Run: php tools/component_contract_check.php
 */

$root = dirname(__DIR__);
$checks = [
    'admin/users.php' => [
        'js-open-moderation-modal',
        'id="qbModerationModal"',
        'name="note"',
        'Reason history',
    ],
    'admin/reconciliation.php' => [
        'Method x status matrix',
        'Needs attention',
        'payment_status',
    ],
    'admin/activity.php' => [
        'Critical action timeline',
        'payment.telebirr.completed',
        'admin.downgrade_seller',
    ],
    'assets/css/style.css' => [
        '.btn.btn-icon.btn-admin-downgrade',
        '.qb-alert-floating',
    ],
];

$failed = false;
foreach ($checks as $rel => $needles) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        echo "[FAIL] Missing file: {$rel}\n";
        $failed = true;
        continue;
    }
    $content = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        if (strpos($content, $needle) === false) {
            echo "[FAIL] {$rel} missing: {$needle}\n";
            $failed = true;
        }
    }
}

if ($failed) {
    echo "\nComponent contract checks failed.\n";
    exit(1);
}

echo "Component contract checks passed.\n";
