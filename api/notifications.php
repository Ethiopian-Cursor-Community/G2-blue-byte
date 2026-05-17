<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn() || currentRole() !== 'buyer') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$uid = (int)$_SESSION['app_user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($action === 'mark_read') {
    markAllRead($uid);
    echo json_encode(['success' => true]);
    exit;
}

// Action GET
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$unreadOnly = isset($_GET['unread_only']) ? (bool)$_GET['unread_only'] : false;

$sql = "SELECT id, type, title, body, link, is_read, created_at FROM notifications WHERE user_id = ?";
if ($unreadOnly) $sql .= " AND is_read = 0";
$sql .= " ORDER BY created_at DESC LIMIT ?";

$notifs = db()->fetchAll($sql, [$uid, $limit]);
foreach ($notifs as &$row) {
    $row['title'] = html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $row['body'] = html_entity_decode((string) ($row['body'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $row['link'] = html_entity_decode((string) ($row['link'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
unset($row);
$unreadCount = getUnreadCount($uid);

echo json_encode([
    'success' => true,
    'unread_count' => $unreadCount,
    'notifications' => $notifs
]);
