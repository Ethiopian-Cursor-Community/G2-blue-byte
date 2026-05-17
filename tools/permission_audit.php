<?php
/**
 * Static permission audit helper.
 * Scans portal pages for role guards and prints findings.
 *
 * Usage:
 *   php tools/permission_audit.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$root = dirname(__DIR__);
$portals = ['buyer', 'seller', 'organizer', 'admin', 'gatekeeper', 'api'];
$guards = [
    'requireLogin(',
    'requireBuyer(',
    'requireSeller(',
    'requireOrganizer(',
    'requireAdmin(',
    'requireGatekeeper(',
    'requireBuyerOrSeller(',
];

$rows = [];
foreach ($portals as $dir) {
    $full = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($full)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        $src = @file_get_contents($path);
        if (!is_string($src)) {
            continue;
        }
        $hasGuard = false;
        foreach ($guards as $g) {
            if (strpos($src, $g) !== false) {
                $hasGuard = true;
                break;
            }
        }
        $rows[] = ['file' => str_replace('\\', '/', $rel), 'guarded' => $hasGuard];
    }
}

usort($rows, static fn($a, $b) => strcmp($a['file'], $b['file']));
$unguarded = array_values(array_filter($rows, static fn($r) => !$r['guarded']));

echo "Permission audit summary\n";
echo "Checked files: " . count($rows) . "\n";
echo "Guarded files: " . (count($rows) - count($unguarded)) . "\n";
echo "Unguarded files: " . count($unguarded) . "\n\n";

if ($unguarded === []) {
    echo "No unguarded files found.\n";
    exit(0);
}

echo "Potentially unguarded pages/endpoints:\n";
foreach ($unguarded as $r) {
    echo "- " . $r['file'] . "\n";
}
echo "\nReview each item manually: some may be intentionally public (e.g., landing pages).\n";

