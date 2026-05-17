<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireBuyer();

$intentId = (string) ($_GET['intent'] ?? '');
if ($intentId === '') {
    header('Location: home.php?ticket_err=' . rawurlencode('Missing payment intent.'), true, 302);
    exit;
}

$verify = qb_chapa_verify_intent($intentId);
if (!$verify['ok']) {
    header('Location: home.php?ticket_err=' . rawurlencode((string) ($verify['error'] ?? 'Payment not completed.')), true, 302);
    exit;
}

$intent = (array) ($verify['intent'] ?? []);
$consume = qb_payment_intent_consume($intent);
if (!$consume['ok']) {
    header('Location: home.php?ticket_err=' . rawurlencode((string) ($consume['error'] ?? 'Could not finalize payment.')), true, 302);
    exit;
}

$to = (string) ($consume['redirect'] ?? 'home.php?pay_ok=1');
if (strpos($to, 'http://') === 0 || strpos($to, 'https://') === 0) {
    header('Location: ' . $to, true, 302);
} else {
    header('Location: ' . $to, true, 302);
}
exit;
