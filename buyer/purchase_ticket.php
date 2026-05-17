<?php
/**
 * POST: issue admission ticket for a bazar (buyer).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireBuyer();
qb_apply_event_ticket_pricing_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: home.php', true, 302);
    exit;
}

$uid = (int) (currentUser()['id'] ?? 0);
$user = currentUser() ?? [];
$eventId = (int) ($_POST['event_id'] ?? 0);
$ret = isset($_POST['redirect']) ? (string) $_POST['redirect'] : 'home.php';
$method = (string) ($_POST['payment_method'] ?? 'chapa');
$ticketType = (string) ($_POST['ticket_type'] ?? 'standard');
$agreedRules = !empty($_POST['agree_primary_rules']);
$rlKey = 'ticket_purchase_submit_' . $uid;
if (!qb_rate_limit_allow($rlKey, 6, 60)) {
    $retry = qb_rate_limit_retry_after($rlKey, 60);
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Too many ticket requests. Retry in ' . $retry . 's.'), true, 302);
    exit;
}
qb_rate_limit_hit($rlKey, 60);

if ($eventId <= 0) {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Invalid bazar.'), true, 302);
    exit;
}
if (!in_array($ticketType, ['standard', 'premium'], true)) {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Select a valid ticket type.'), true, 302);
    exit;
}
if (!$agreedRules) {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('You must accept the event primary rules.'), true, 302);
    exit;
}

if ($method !== 'chapa') {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Only Chapa payment is enabled.'), true, 302);
    exit;
}

if (!qb_chapa_ready()) {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Chapa is not configured yet.'), true, 302);
    exit;
}

$ev = db()->fetchOne('SELECT standard_ticket_price_etb, premium_ticket_price_etb FROM bazar_events WHERE id = ? LIMIT 1', [$eventId]);
$amount = $ticketType === 'premium'
    ? (float) ($ev['premium_ticket_price_etb'] ?? 0)
    : (float) ($ev['standard_ticket_price_etb'] ?? 0);
if ($amount < 0) {
    $amount = 0.0;
}
if ($amount <= 0) {
    $amount = qb_setting_get_float('ticket_fee_etb', (float) CHAPA_TICKET_FEE_ETB);
}
if ($amount <= 0) {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Ticket price is not configured. Ask organizer/admin to set ticket prices.'), true, 302);
    exit;
}

$intent = qb_payment_intent_create(
    $uid,
    'ticket_purchase',
    'event:' . $eventId,
    $amount,
    ['event_id' => $eventId, 'redirect' => $ret, 'ticket_type' => $ticketType, 'agree_primary_rules' => 1]
);
$intentId = (string) ($intent['intent_id'] ?? '');
qb_track_event('ticket.purchase.intent_created', [
    'event_id' => $eventId,
    'ticket_type' => $ticketType,
    'amount' => $amount,
    'payment_method' => 'chapa',
], $uid, 'event', (string) $eventId);
$intentRow = qb_payment_intent_get((string) $intent['intent_id']);
if (!$intentRow) {
    header('Location: ' . $ret . '?ticket_err=' . rawurlencode('Could not create payment intent.'), true, 302);
    exit;
}
$start = qb_chapa_checkout_start(
    $intentRow,
    (string) ($user['email'] ?? ''),
    (string) ($user['display_name'] ?? 'Buyer'),
    (string) ($user['phone'] ?? '')
);
if (!$start['ok']) {
    qb_track_event('ticket.purchase.checkout_failed', [
        'event_id' => $eventId,
        'ticket_type' => $ticketType,
        'intent_id' => $intentId,
        'error' => (string) ($start['error'] ?? 'unknown'),
    ], $uid, 'payment_intent', $intentId);
    qb_audit_log('payment.chapa.init_failed', 'payment_intents', (int) ($intentRow['id'] ?? 0), [
        'event_id' => $eventId,
        'ticket_type' => $ticketType,
        'error' => (string) ($start['error'] ?? 'unknown'),
    ]);
    $failUrl = APP_URL . '/buyer/payment_result.php?intent=' . rawurlencode((string) ($intentRow['intent_id'] ?? '')) . '&status=failed&error=' . rawurlencode((string) ($start['error'] ?? 'Could not start Chapa checkout.')) . '&next=' . rawurlencode(APP_URL . '/buyer/' . ltrim($ret, '/'));
    header('Location: ' . $failUrl, true, 302);
    exit;
}
qb_track_event('ticket.purchase.checkout_started', [
    'event_id' => $eventId,
    'ticket_type' => $ticketType,
    'intent_id' => $intentId,
], $uid, 'payment_intent', $intentId);
header('Location: ' . (string) $start['checkout_url'], true, 302);
exit;
