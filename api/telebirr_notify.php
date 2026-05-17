<?php
/**
 * Telebirr server-to-server payment notification (JSON body, signed).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Empty body']);
    exit;
}

qb_telebirr_handle_notification($raw);
exit;
