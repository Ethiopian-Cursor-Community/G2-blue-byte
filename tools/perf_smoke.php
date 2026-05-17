<?php
/**
 * Lightweight performance smoke runner for critical pages.
 *
 * Usage:
 *   php tools/perf_smoke.php http://localhost/QR%20BAZAR
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$base = rtrim((string) ($argv[1] ?? 'http://localhost/QR%20BAZAR'), '/');
$loops = max(1, (int) ($argv[2] ?? 10));

$targets = [
    '/',
    '/public_home.php',
    '/login.php',
    '/register.php',
    '/buyer/discover.php',
    '/buyer/home.php',
    '/seller/products.php',
    '/organizer/dashboard.php',
    '/admin/reconciliation.php',
    '/admin/observability.php',
];

function hit(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['User-Agent: QB-Perf-Smoke/1.0'],
    ]);
    $start = microtime(true);
    $body = curl_exec($ch);
    $ms = (microtime(true) - $start) * 1000;
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'ms' => $ms,
        'code' => $code,
        'ok' => $err === '' && $code > 0,
        'error' => $err,
        'bytes' => is_string($body) ? strlen($body) : 0,
    ];
}

echo "QB performance smoke\n";
echo "Base URL: {$base}\n";
echo "Loops: {$loops}\n\n";

$rows = [];
foreach ($targets as $path) {
    $times = [];
    $fail = 0;
    $codeRef = 0;
    for ($i = 0; $i < $loops; $i++) {
        $r = hit($base . $path);
        if (!$r['ok']) {
            $fail++;
        }
        $times[] = $r['ms'];
        $codeRef = $r['code'];
    }
    sort($times);
    $avg = array_sum($times) / max(1, count($times));
    $p95Idx = (int) floor((count($times) - 1) * 0.95);
    $p95 = $times[$p95Idx] ?? $avg;
    $rows[] = [
        'path' => $path,
        'avg_ms' => round($avg, 1),
        'p95_ms' => round($p95, 1),
        'fails' => $fail,
        'status' => $codeRef,
    ];
}

printf("%-30s %-10s %-10s %-8s %-8s\n", 'Path', 'AVG(ms)', 'P95(ms)', 'Fails', 'HTTP');
echo str_repeat('-', 72) . "\n";
foreach ($rows as $r) {
    printf(
        "%-30s %-10s %-10s %-8d %-8d\n",
        $r['path'],
        (string) $r['avg_ms'],
        (string) $r['p95_ms'],
        (int) $r['fails'],
        (int) $r['status']
    );
}

echo "\nGuideline: keep p95 under ~1200ms for anonymous pages and under ~1800ms for protected heavy pages.\n";

