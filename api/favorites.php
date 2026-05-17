<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

startSession();

if (empty($_SESSION['app_user_id']) || ($_SESSION['app_role'] ?? '') !== 'buyer') {
    echo json_encode(['success' => false, 'error' => 'Sign in as a buyer to save shops.']);
    exit;
}

if (!qb_favorites_table_exists()) {
    echo json_encode(['success' => false, 'error' => 'Run sql/marketplace_features.sql to enable saved shops.']);
    exit;
}

$appId = (int)$_SESSION['app_user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $list = qb_buyer_saved_shops($appId);
    echo json_encode(['success' => true, 'favorites' => $list]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $sellerId = (int)($data['seller_id'] ?? 0);
    if ($sellerId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid seller']);
        exit;
    }

    $s = db()->fetchOne('SELECT id FROM sellers WHERE id = ? AND is_active = 1', [$sellerId], 'i');
    if (!$s) {
        echo json_encode(['success' => false, 'error' => 'Seller not found']);
        exit;
    }

    $exists = db()->fetchOne(
        'SELECT id FROM buyer_favorites WHERE app_user_id = ? AND seller_id = ?',
        [$appId, $sellerId],
        'ii'
    );

    if ($exists) {
        db()->execute(
            'DELETE FROM buyer_favorites WHERE app_user_id = ? AND seller_id = ?',
            [$appId, $sellerId],
            'ii'
        );
        echo json_encode(['success' => true, 'favorited' => false]);
        exit;
    }

    db()->insert(
        'INSERT INTO buyer_favorites (app_user_id, seller_id) VALUES (?,?)',
        [$appId, $sellerId],
        'ii'
    );
    echo json_encode(['success' => true, 'favorited' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
