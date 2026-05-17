<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || currentRole() !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if (!qb_table_exists('payment_intents')) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rows = db()->fetchAll(
    "SELECT intent_id, target_type, provider_tx_ref, provider_status, amount, currency, paid_at, consumed_at, created_at
     FROM payment_intents
     ORDER BY id DESC
     LIMIT 60"
);

echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
