<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function qb_map_tables_ready(): bool {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $r = db()->fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'location_pings'"
    );
    $c = (bool)$r;
    return $c;
}

startSession();
$role = $_SESSION['app_role'] ?? '';
if (!in_array($role, ['super_admin', 'organizer'], true)) {
    jsonError('Forbidden', 403);
}

if (!qb_map_tables_ready()) {
    jsonError('Run sql/bazar_platform_core.sql', 503);
}

$eventId = (int)($_GET['event_id'] ?? 0);
$since = date('Y-m-d H:i:s', time() - 900);

$sql = "SELECT lp.lat, lp.lng, lp.role_context, lp.created_at, u.display_name AS label
        FROM location_pings lp
        LEFT JOIN app_users u ON u.id = lp.app_user_id
        WHERE lp.created_at >= ?";
$params = [$since];
$types = 's';
if ($eventId > 0) {
    $sql .= ' AND (lp.event_id = ? OR lp.event_id IS NULL)';
    $params[] = $eventId;
    $types .= 'i';
}
$sql .= ' ORDER BY lp.created_at DESC LIMIT 200';

$rows = db()->fetchAll($sql, $params, $types);

$event = null;
if ($eventId > 0) {
    $event = db()->fetchOne('SELECT id, name, center_lat, center_lng, radius_m FROM bazar_events WHERE id = ?', [$eventId], 'i');
}

jsonSuccess([
    'pings' => $rows,
    'event' => $event,
]);
