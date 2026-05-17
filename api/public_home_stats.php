<?php
/**
 * JSON metrics for public home live numbers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $events = getActiveEvents();
    $eventCount = 0;
    foreach ($events as $ev) {
        $st = strtolower((string) ($ev['status'] ?? ''));
        if (in_array($st, ['published', 'live'], true)) {
            $eventCount++;
        }
    }
    $cities = [];
    foreach ($events as $ev) {
        $city = trim((string) ($ev['city'] ?? ''));
        if ($city !== '') {
            $cities[$city] = true;
        }
    }
    $cityCount = count($cities);
    $promoCount = count(qb_fetch_homepage_spotlight_slides());

    echo json_encode([
        'ok' => true,
        'stats' => [
            'eventCount' => $eventCount,
            'cityCount' => $cityCount,
            'promoCount' => $promoCount,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

