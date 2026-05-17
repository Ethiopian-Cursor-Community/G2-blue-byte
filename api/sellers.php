<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

startSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$uid    = sanitize($_GET['uid'] ?? '');

// ── GET: Seller + Products (for QR scan) ───
if ($method === 'GET' && $uid) {
    $seller = db()->fetchOne(
        "SELECT id, uid, full_name, market_name, phone, location, category, profile_image, is_flagged FROM sellers WHERE uid = ? AND is_active = 1",
        [$uid]
    );
    if (!$seller) jsonError('Seller not found', 404);

    $ap = qb_sql_product_approved_plain();
    $products = db()->fetchAll(
        "SELECT id, name, description, price, unit, stock, image_url, category, is_available, view_count FROM products WHERE seller_id = ? AND is_available = 1 AND ($ap) ORDER BY view_count DESC",
        [$seller['id']], 'i'
    );

    $trustScore = computeTrustScore($seller['id']);
    $badge      = getTrustBadge($trustScore);
    $ratingData = db()->fetchOne("SELECT AVG(stars) as avg, COUNT(*) as cnt FROM ratings WHERE seller_id = ?", [$seller['id']], 'i');

    jsonSuccess([
        'seller'      => $seller,
        'products'    => $products,
        'trust_score' => $trustScore,
        'trust_badge' => $badge,
        'avg_rating'  => round(floatval($ratingData['avg']), 1),
        'rating_count'=> intval($ratingData['cnt']),
    ]);
}

// ── POST: Refresh QR secret ─────────────────
if ($method === 'POST' && $action === 'refresh_qr') {
    if (!isLoggedIn()) jsonError('Unauthorized', 401);
    $sellerId  = $_SESSION['seller_id'];
    $newSecret = bin2hex(random_bytes(16));
    db()->execute("UPDATE sellers SET qr_secret = ? WHERE id = ?", [$newSecret, $sellerId], 'si');
    jsonSuccess([], 'QR refreshed successfully');
}

// ── GET: All sellers (for AI search / discover) ─
if ($method === 'GET' && !$uid) {
    $sellers = db()->fetchAll(
        "SELECT id, uid, full_name, market_name, location, category, profile_image FROM sellers WHERE is_active = 1 AND is_flagged = 0 ORDER BY id"
    );
    jsonSuccess(['sellers' => $sellers]);
}

jsonError('Invalid request', 400);
