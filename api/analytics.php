<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ── POST: Log analytics event ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data      = getJson();
    $sellerId  = intval($data['seller_id'] ?? 0);
    $eventType = sanitize($data['event_type'] ?? 'qr_scan');

    if ($sellerId && in_array($eventType, ['qr_scan','product_view','purchase','rating'])) {
        db()->insert(
            "INSERT INTO analytics_events (seller_id, event_type, event_hour, event_date) VALUES (?,?,?,CURDATE())",
            [$sellerId, $eventType, (int)date('G')], 'isi'
        );
    }
    jsonSuccess([], 'Event logged');
}

// ── GET: Dashboard analytics ─────────────────
startSession();
if (!isLoggedIn()) jsonError('Unauthorized', 401);
$sellerId = (int)$_SESSION['seller_id'];

// 7-day revenue
$revenue7 = db()->fetchAll(
    "SELECT DATE(created_at) as date, SUM(total_amount) as total
     FROM transactions
     WHERE seller_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at) ORDER BY date ASC",
    [$sellerId], 'i'
);

// Fill missing days
$revenueByDate = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $revenueByDate[$d] = 0;
}
foreach ($revenue7 as $row) {
    $revenueByDate[$row['date']] = floatval($row['total']);
}

// Top 5 products by sales count
$topProducts = db()->fetchAll(
    "SELECT ti.product_name, SUM(ti.quantity) as total_sold
     FROM transaction_items ti
     JOIN transactions t ON t.id = ti.transaction_id
     WHERE t.seller_id = ?
     GROUP BY ti.product_name ORDER BY total_sold DESC LIMIT 5",
    [$sellerId], 'i'
);

// Payment method breakdown
$paymentBreakdown = db()->fetchAll(
    "SELECT payment_method, COUNT(*) as count FROM transactions WHERE seller_id = ? GROUP BY payment_method",
    [$sellerId], 'i'
);

// Peak hours (0-23)
$peakHours = array_fill(0, 24, 0);
$hourData  = db()->fetchAll(
    "SELECT event_hour, COUNT(*) as cnt FROM analytics_events WHERE seller_id = ? AND event_type IN ('qr_scan','purchase') AND event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY event_hour",
    [$sellerId], 'i'
);
foreach ($hourData as $h) {
    $peakHours[(int)$h['event_hour']] = (int)$h['cnt'];
}

// Summary stats
$summary = db()->fetchOne(
    "SELECT COUNT(*) as total_tx, SUM(total_amount) as total_revenue FROM transactions WHERE seller_id = ?",
    [$sellerId], 'i'
);
$todayRevenue = db()->fetchOne(
    "SELECT SUM(total_amount) as today FROM transactions WHERE seller_id = ? AND DATE(created_at) = CURDATE()",
    [$sellerId], 'i'
);

// QR scans count
$totalScans = db()->fetchOne(
    "SELECT COUNT(*) as cnt FROM analytics_events WHERE seller_id = ? AND event_type = 'qr_scan'",
    [$sellerId], 'i'
);

// Trust + credit score
$trustScore  = computeTrustScore($sellerId);
$creditScore = computeCreditScore($sellerId);
$badge       = getTrustBadge($trustScore);
$credit      = getCreditStatus($creditScore);

// Rating trend (last 7 days)
$ratingTrend = db()->fetchAll(
    "SELECT DATE(created_at) as date, AVG(stars) as avg FROM ratings WHERE seller_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY date",
    [$sellerId], 'i'
);

$ratingByDate = [];
foreach ($revenueByDate as $d => $_) {
    $ratingByDate[$d] = null;
}
foreach ($ratingTrend as $row) {
    $ratingByDate[$row['date']] = round(floatval($row['avg']), 1);
}

jsonSuccess([
    'revenue_chart' => [
        'labels' => array_keys($revenueByDate),
        'values' => array_values($revenueByDate)
    ],
    'top_products' => $topProducts,
    'payment_methods' => $paymentBreakdown,
    'peak_hours'  => $peakHours,
    'rating_trend'=> [
        'labels' => array_keys($ratingByDate),
        'values' => array_values($ratingByDate)
    ],
    'summary' => [
        'total_transactions' => intval($summary['total_tx']),
        'total_revenue'      => floatval($summary['total_revenue']),
        'today_revenue'      => floatval($todayRevenue['today']),
        'total_scans'        => intval($totalScans['cnt']),
        'trust_score'        => $trustScore,
        'credit_score'       => $creditScore,
        'trust_badge'        => $badge,
        'credit_status'      => $credit,
    ]
]);
