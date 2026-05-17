<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cursor_assist.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); exit; }
startSession();
if (!currentUser()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Sign in required']); exit; }
if (!qb_cursor_enabled()) { http_response_code(503); echo json_encode(['success' => false, 'error' => 'AI assist disabled']); exit; }
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim((string) ($data['message'] ?? ''));
if ($message === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Message required']); exit; }
$result = qb_cursor_ask($message, qb_cursor_build_context(currentUser(), trim((string) ($data['page'] ?? ''))));
if (empty($result['ok'])) { http_response_code(502); echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed']); exit; }
echo json_encode(['success' => true, 'reply' => $result['reply']]);
