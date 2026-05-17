<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!qb_chapa_ready()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Chapa is not configured']);
    exit;
}

$intentId = (string) ($_POST['intent_id'] ?? $_GET['intent_id'] ?? '');
if ($intentId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing intent_id']);
    exit;
}

$intent = qb_payment_intent_get($intentId);
if (!$intent) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Intent not found']);
    exit;
}
if ((int) ($intent['app_user_id'] ?? 0) !== (int) ($_SESSION['app_user_id'] ?? 0) && currentRole() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$u = currentUser() ?? [];
$targetType = (string) ($intent['target_type'] ?? '');
if ($targetType === 'product_purchase' && currentRole() === 'buyer') {
    $meta = json_decode((string) ($intent['metadata_json'] ?? '{}'), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $sellerId = (int) ($meta['seller_id'] ?? 0);
    $mode = function_exists('qb_event_mode_get') ? qb_event_mode_get((int) ($_SESSION['app_user_id'] ?? 0)) : null;
    $eventId = ((string) ($mode['mode_source'] ?? '') === 'ticket_scan') ? (int) ($mode['event_id'] ?? 0) : 0;
    if ($eventId <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Gate scan required before checkout']);
        exit;
    }
    if ($sellerId > 0 && function_exists('qb_seller_gate_is_unlocked') && !qb_seller_gate_is_unlocked($sellerId, $eventId)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Seller gate validation required before checkout']);
        exit;
    }
}
$start = qb_chapa_checkout_start($intent, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? 'User'), (string) ($u['phone'] ?? ''));
if (!$start['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => (string) ($start['error'] ?? 'Could not initialize checkout')]);
    exit;
}

echo json_encode(['ok' => true, 'checkout_url' => (string) ($start['checkout_url'] ?? '')]);
