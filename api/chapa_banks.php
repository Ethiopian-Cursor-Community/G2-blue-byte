<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

startSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$currency = isset($_GET['currency']) ? (string) $_GET['currency'] : '';
$result = qb_chapa_list_banks($currency !== '' ? $currency : null);
if (!($result['ok'] ?? false)) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => (string) ($result['error'] ?? 'Unable to fetch Chapa banks'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'mode' => qb_chapa_mode(),
    'count' => count((array) ($result['banks'] ?? [])),
    'banks' => array_values((array) ($result['banks'] ?? [])),
], JSON_UNESCAPED_UNICODE);
