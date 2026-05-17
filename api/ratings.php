<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$method = $_SERVER['REQUEST_METHOD'];
$data   = getJson();

// ── POST: Submit rating ─────────────────────
if ($method === 'POST') {
    $sellerId  = intval($data['seller_id'] ?? 0);
    $sellerUid = sanitize($data['seller_uid'] ?? '');
    $stars     = intval($data['stars'] ?? 0);
    $comment   = sanitize($data['comment'] ?? '');
    $buyerName = sanitize($data['buyer_name'] ?? 'Anonymous');
    $txId      = sanitize($data['tx_id'] ?? '');

    if (!$sellerId && $sellerUid) {
        $s = db()->fetchOne("SELECT id FROM sellers WHERE uid = ?", [$sellerUid]);
        $sellerId = $s ? (int)$s['id'] : 0;
    }

    if (!$sellerId) jsonError('Seller not found', 404);
    if ($stars < 1 || $stars > 5) jsonError('Rating must be between 1 and 5 stars');

    // Find transaction ID if provided
    $txDbId = null;
    if ($txId) {
        $tx = db()->fetchOne("SELECT id FROM transactions WHERE tx_id = ?", [$txId]);
        $txDbId = $tx ? $tx['id'] : null;
    }

    db()->insert(
        "INSERT INTO ratings (seller_id, transaction_id, buyer_name, stars, comment) VALUES (?,?,?,?,?)",
        [$sellerId, $txDbId, $buyerName, $stars, $comment],
        'iisis'
    );

    // Log analytics
    db()->insert(
        "INSERT INTO analytics_events (seller_id, event_type, event_hour, event_date) VALUES (?, 'rating', ?, CURDATE())",
        [$sellerId, (int)date('G')], 'ii'
    );

    // Recompute and cache trust score
    $newScore = computeTrustScore($sellerId);

    // Auto-flag if needed
    $ratingData = db()->fetchOne(
        "SELECT AVG(stars) as avg, COUNT(*) as cnt FROM ratings WHERE seller_id = ?",
        [$sellerId], 'i'
    );
    if (floatval($ratingData['avg']) < 2.0 && intval($ratingData['cnt']) >= 5) {
        db()->execute("UPDATE sellers SET is_flagged = 1 WHERE id = ?", [$sellerId], 'i');
    }

    jsonSuccess(['new_trust_score' => $newScore], 'Rating submitted — thank you!');
}

// ── GET: Seller's ratings ────────────────────
if ($method === 'GET') {
    $sellerUid = sanitize($_GET['uid'] ?? '');
    $sellerId  = intval($_GET['seller_id'] ?? 0);

    if (!$sellerId && $sellerUid) {
        $s = db()->fetchOne("SELECT id FROM sellers WHERE uid = ?", [$sellerUid]);
        $sellerId = $s ? (int)$s['id'] : 0;
    }

    if (!$sellerId) jsonError('Seller required');

    $ratings = db()->fetchAll(
        "SELECT buyer_name, stars, comment, created_at FROM ratings WHERE seller_id = ? ORDER BY created_at DESC LIMIT 20",
        [$sellerId], 'i'
    );
    $summary = db()->fetchOne(
        "SELECT AVG(stars) as avg, COUNT(*) as cnt FROM ratings WHERE seller_id = ?",
        [$sellerId], 'i'
    );

    jsonSuccess([
        'ratings'      => $ratings,
        'avg_rating'   => round(floatval($summary['avg']), 1),
        'total_ratings'=> intval($summary['cnt']),
    ]);
}

jsonError('Invalid method', 405);
