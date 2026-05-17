<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Chapa-Signature, X-Chapa-Signature, X-Webhook-Signature');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!qb_chapa_ready()) {
    http_response_code(503);
    echo 'disabled';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$result = qb_chapa_process_webhook($raw);
http_response_code((int) ($result['http'] ?? 200));
if (!empty($result['ok'])) {
    echo (string) ($result['message'] ?? 'ok');
} else {
    echo (string) ($result['error'] ?? 'error');
}
