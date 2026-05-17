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

$intentId = (string) ($_GET['intent_id'] ?? $_POST['intent_id'] ?? '');
if ($intentId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing intent_id']);
    exit;
}
$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$rlKey = 'api_chapa_verify_' . $uid . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $intentId);
if (!qb_rate_limit_allow($rlKey, 10, 30)) {
    $retry = qb_rate_limit_retry_after($rlKey, 30);
    http_response_code(429);
    header('Retry-After: ' . $retry);
    echo json_encode(['ok' => false, 'error' => 'Too many verification requests. Retry in ' . $retry . 's']);
    exit;
}
qb_rate_limit_hit($rlKey, 30);

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

$currentStatus = strtolower((string) ($intent['provider_status'] ?? 'pending'));
if (in_array($currentStatus, ['paid', 'completed', 'fulfilled'], true)) {
    qb_track_event('payment.verify.fast_path', ['intent_id' => $intentId, 'status' => $currentStatus], $uid, 'payment_intent', $intentId);
    $consume = qb_payment_intent_consume($intent);
    $fresh = qb_payment_intent_get($intentId);
    echo json_encode([
        'ok' => true,
        'status' => (string) ($fresh['provider_status'] ?? $currentStatus),
        'intent_id' => (string) ($fresh['intent_id'] ?? ''),
        'target_type' => (string) ($fresh['target_type'] ?? ''),
        'fulfilled' => (bool) ($consume['ok'] ?? false),
        'redirect' => (string) ($consume['redirect'] ?? ''),
    ]);
    exit;
}

$verify = qb_chapa_verify_intent($intentId);
if (!$verify['ok']) {
    qb_track_event('payment.verify.pending_or_failed', ['intent_id' => $intentId, 'error' => (string) ($verify['error'] ?? 'pending')], $uid, 'payment_intent', $intentId);
    echo json_encode(['ok' => false, 'status' => (string) (($intent['provider_status'] ?? 'pending')), 'error' => (string) ($verify['error'] ?? 'Verification pending')]);
    exit;
}

$postVerifyIntent = (array) ($verify['intent'] ?? $intent);
$consume = qb_payment_intent_consume($postVerifyIntent);
$verifiedStatus = (string) ($postVerifyIntent['provider_status'] ?? '');
qb_track_event('payment.verify.success', ['intent_id' => $intentId, 'verified_status' => $verifiedStatus, 'fulfilled' => (bool) ($consume['ok'] ?? false)], $uid, 'payment_intent', $intentId);
$fresh = qb_payment_intent_get($intentId);
echo json_encode([
    'ok' => true,
    'status' => (string) ($fresh['provider_status'] ?? 'paid'),
    'intent_id' => (string) ($fresh['intent_id'] ?? ''),
    'target_type' => (string) ($fresh['target_type'] ?? ''),
    'fulfilled' => (bool) ($consume['ok'] ?? false),
    'redirect' => (string) ($consume['redirect'] ?? ''),
]);
