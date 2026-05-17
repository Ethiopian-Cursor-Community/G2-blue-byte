<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$query    = sanitize($_GET['q'] ?? '');
$all      = isset($_GET['all']);
$category = sanitize($_GET['category'] ?? '');

// ── Full vendor+product dump (for AI search cache) ──
if ($all) {
    $sellers = db()->fetchAll(
        "SELECT id, uid, full_name, market_name, location, category, is_flagged FROM sellers WHERE is_active = 1 ORDER BY id"
    );

    $result = [];
    foreach ($sellers as $s) {
        $ap = qb_sql_product_approved_plain();
        $products = db()->fetchAll(
            "SELECT id, name, description, price, unit, stock, category, is_available FROM products WHERE seller_id = ? AND is_available = 1 AND ($ap)",
            [$s['id']], 'i'
        );
        $trustScore = computeTrustScore($s['id']);
        $ratingData = db()->fetchOne("SELECT AVG(stars) as avg FROM ratings WHERE seller_id = ?", [$s['id']], 'i');
        $result[] = [
            ...$s,
            'trust_score' => $trustScore,
            'avg_rating'  => round(floatval($ratingData['avg']), 1),
            'products'    => $products,
            'distance'    => rand(50, 500), // simulated proximity in meters
        ];
    }
    jsonSuccess(['vendors' => $result]);
}

// ── Keyword search ───────────────────────────
if ($query) {
    $like = '%' . $query . '%';
    $ap = qb_sql_product_approved();
    $products = db()->fetchAll(
        "SELECT p.*, s.uid AS seller_uid, s.market_name, s.location, s.category AS seller_category
         FROM products p
         JOIN sellers s ON s.id = p.seller_id
         WHERE p.is_available = 1 AND s.is_active = 1 AND ($ap)
           AND (p.name LIKE ? OR p.description LIKE ? OR p.category LIKE ? OR s.market_name LIKE ?)
         ORDER BY p.price ASC LIMIT 30",
        [$like, $like, $like, $like], 'ssss'
    );
    jsonSuccess(['query' => $query, 'results' => $products]);
}

// ── Category search ──────────────────────────
if ($category) {
    $sellers = db()->fetchAll(
        "SELECT id, uid, market_name, location, category FROM sellers WHERE category = ? AND is_active = 1",
        [$category]
    );
    jsonSuccess(['category' => $category, 'sellers' => $sellers]);
}

// ── Nearby sellers (simulated) ───────────────
$nearby = db()->fetchAll(
    "SELECT id, uid, full_name, market_name, location, category FROM sellers WHERE is_active = 1 AND is_flagged = 0 LIMIT 8"
);
$nearby = array_map(fn($s) => [...$s, 'distance' => rand(30, 600), 'angle' => rand(0, 359)], $nearby);
jsonSuccess(['nearby' => $nearby]);
