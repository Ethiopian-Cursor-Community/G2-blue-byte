<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

echo "CHAPA_ENABLED=" . (CHAPA_ENABLED ? 'true' : 'false') . PHP_EOL;
echo "CHAPA_MODE=" . CHAPA_MODE . PHP_EOL;
echo "CHAPA_READY=" . (qb_chapa_ready() ? 'true' : 'false') . PHP_EOL;
if (!qb_chapa_ready()) {
    echo "ERROR: Chapa not ready from env/config." . PHP_EOL;
    exit(1);
}

$buyer = db()->fetchOne("SELECT id, display_name, email, phone FROM app_users WHERE role = 'buyer' ORDER BY id ASC LIMIT 1");
if (!$buyer) {
    echo "ERROR: No buyer account exists in DB." . PHP_EOL;
    exit(1);
}

$uid = (int) ($buyer['id'] ?? 0);
$intentMeta = ['event_id' => 1, 'redirect' => 'home.php', 'ticket_type' => 'standard', 'agree_primary_rules' => 1];
$newIntent = qb_payment_intent_create($uid, 'ticket_purchase', 'event:1', 1.00, $intentMeta);
$intentId = (string) ($newIntent['intent_id'] ?? '');
$intent = qb_payment_intent_get($intentId);
if (!$intent) {
    echo "ERROR: Failed to read created intent." . PHP_EOL;
    exit(1);
}
echo "INTENT_ID={$intentId}" . PHP_EOL;
echo "TX_REF=" . (string) ($intent['provider_tx_ref'] ?? '') . PHP_EOL;

$start = qb_chapa_checkout_start(
    $intent,
    (string) ($buyer['email'] ?? ''),
    (string) ($buyer['display_name'] ?? 'Buyer'),
    (string) ($buyer['phone'] ?? '')
);
if (!($start['ok'] ?? false)) {
    echo "ERROR: checkout start failed: " . (string) ($start['error'] ?? 'unknown') . PHP_EOL;
    exit(1);
}
echo "CHECKOUT_URL=" . (string) ($start['checkout_url'] ?? '') . PHP_EOL;

$verify = qb_chapa_verify_intent($intentId);
echo "VERIFY_OK=" . (($verify['ok'] ?? false) ? 'true' : 'false') . PHP_EOL;
if (!($verify['ok'] ?? false)) {
    echo "VERIFY_MSG=" . (string) ($verify['error'] ?? 'pending') . PHP_EOL;
}

$payload = [
    'tx_ref' => (string) ($intent['provider_tx_ref'] ?? ''),
    'status' => 'success',
];
$raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
$sig = hash_hmac('sha256', (string) $raw, (string) CHAPA_ENCRYPTION_KEY);
$_SERVER['HTTP_CHAPA_SIGNATURE'] = $sig;
$wh = qb_chapa_process_webhook((string) $raw);
echo "WEBHOOK_OK=" . (($wh['ok'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo "WEBHOOK_HTTP=" . (string) ($wh['http'] ?? 0) . PHP_EOL;
echo "WEBHOOK_MSG=" . (string) ($wh['message'] ?? $wh['error'] ?? '') . PHP_EOL;

unset($_SERVER['HTTP_CHAPA_SIGNATURE']);
echo "DONE" . PHP_EOL;
