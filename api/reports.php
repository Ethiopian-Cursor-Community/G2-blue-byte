<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('qb_reports_table_exists')) {
    function qb_reports_table_exists(): bool {
        static $c = null;
        if ($c !== null) {
            return $c;
        }
        $r = db()->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reports'"
        );
        $c = (bool)$r;
        return $c;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    startSession();
    if (empty($_SESSION['app_user_id'])) {
        jsonError('Sign in to submit a report', 401);
    }
    if (!qb_reports_table_exists()) {
        jsonError('Reporting not enabled — run sql/bazar_platform_core.sql', 503);
    }

    $data = getJson();
    $targetType = sanitize($data['target_type'] ?? '');
    $targetId = sanitize($data['target_id'] ?? '');
    $body = trim((string)($data['body'] ?? ''));
    $sellerId = (int)($data['seller_id'] ?? 0);
    $eventId = (int)($data['event_id'] ?? 0);

    if (!in_array($targetType, ['seller', 'product', 'user', 'behavior', 'event', 'promo'], true)) {
        jsonError('Invalid target type');
    }
    if ($body === '' || strlen($body) > 4000) {
        jsonError('Report text required (max 4000 chars)');
    }

    $rid = (int)$_SESSION['app_user_id'];
    $sid = $sellerId > 0 ? $sellerId : null;
    $eid = $eventId > 0 ? $eventId : null;

    db()->execute(
        'INSERT INTO reports (reporter_app_user_id, target_type, target_id, seller_id, event_id, body, priority) VALUES (?,?,?,?,?,?,2)',
        [$rid, $targetType, $targetId, $sid, $eid, $body]
    );

    if (function_exists('qb_audit_log')) {
        qb_audit_log('report.created', 'report', $targetId, ['type' => $targetType]);
    }

    jsonSuccess([], 'Report received');
}

if ($method === 'GET') {
    startSession();
    $role = $_SESSION['app_role'] ?? '';
    if ($role !== 'super_admin') {
        jsonError('Forbidden', 403);
    }
    if (!qb_reports_table_exists()) {
        jsonSuccess(['reports' => []]);
    }

    $reports = db()->fetchAll(
        'SELECT r.*, u.login_uid AS reporter_login FROM reports r
         LEFT JOIN app_users u ON u.id = r.reporter_app_user_id
         ORDER BY r.priority ASC, r.created_at DESC LIMIT 100'
    );
    jsonSuccess(['reports' => $reports]);
}

jsonError('Invalid method', 405);
