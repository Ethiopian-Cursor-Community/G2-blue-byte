<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$eventId = (int)($_GET['event_id'] ?? 0);
$limit = min(30, max(5, (int)($_GET['limit'] ?? 15)));

$hasEventCol = db()->fetchOne(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'event_id'"
);

$sql = "SELECT t.total_amount, t.created_at, s.market_name, s.location
        FROM transactions t
        JOIN sellers s ON s.id = t.seller_id
        WHERE t.payment_status = 'completed'";
$params = [];
$types = '';

if ($hasEventCol && $eventId > 0) {
    $sql .= ' AND t.event_id = ?';
    $params[] = $eventId;
    $types .= 'i';
}

$sql .= ' ORDER BY t.created_at DESC LIMIT ?';
$params[] = $limit;
$types .= 'i';

$rows = db()->fetchAll($sql, $params, $types);

$feed = [];
foreach ($rows as $r) {
    $loc = $r['location'] ? trim(explode(',', (string)$r['location'])[0]) : 'nearby';
    $feed[] = [
        'text'       => 'Purchase · ' . $r['market_name'] . ' · ' . $loc,
        'amount_etb' => (float)$r['total_amount'],
        'at'         => $r['created_at'],
    ];
}

jsonSuccess(['feed' => $feed]);
