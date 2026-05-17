<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
startSession();
requireLogin();

$intentId = (string) ($_GET['intent'] ?? '');
if ($intentId === '') {
    header('Location: ' . APP_URL . '/?ticket_err=' . rawurlencode('Missing payment intent.'), true, 302);
    exit;
}

// Fast return path:
// do not block the user on remote verify/fulfill here.
// Payment status page will verify asynchronously and redirect on success.
header('Location: ' . APP_URL . '/buyer/payment_result.php?intent=' . rawurlencode($intentId) . '&status=pending', true, 302);
exit;
