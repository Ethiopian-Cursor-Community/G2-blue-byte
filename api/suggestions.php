<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/buyer_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!qb_seller_suggestions_table_exists()) {
    echo json_encode(['success' => false, 'error' => 'Suggestions not enabled — run sql/buyer_seller_feedback.sql']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$sellerId = (int)($data['seller_id'] ?? 0);
$message = trim((string)($data['message'] ?? ''));
$buyerName = trim((string)($data['buyer_name'] ?? 'Anonymous'));
if ($buyerName === '') {
    $buyerName = 'Anonymous';
}
if ($sellerId <= 0 || $message === '') {
    echo json_encode(['success' => false, 'error' => 'Seller and message are required']);
    exit;
}
$msgLen = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
if ($msgLen > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message is too long']);
    exit;
}

$s = db()->fetchOne('SELECT id FROM sellers WHERE id = ? AND is_active = 1', [$sellerId], 'i');
if (!$s) {
    echo json_encode(['success' => false, 'error' => 'Seller not found']);
    exit;
}

db()->insert(
    'INSERT INTO seller_suggestions (seller_id, buyer_name, message) VALUES (?,?,?)',
    [$sellerId, $buyerName, $message],
    'iss'
);

echo json_encode(['success' => true, 'message' => 'Thanks — your suggestion was sent to the seller.']);
