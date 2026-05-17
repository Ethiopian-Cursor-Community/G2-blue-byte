<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

startSession();
if (empty($_SESSION['app_user_id'])) {
    jsonError('Unauthorized', 401);
}
$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$rlKey = 'api_location_ping_' . $uid;
if (!qb_rate_limit_allow($rlKey, 30, 60)) {
    jsonError('Too many location updates. Please retry shortly.', 429);
}
qb_rate_limit_hit($rlKey, 60);

$data = getJson();
$lat = isset($data['lat']) ? (float)$data['lat'] : null;
$lng = isset($data['lng']) ? (float)$data['lng'] : null;
$eventId = (int)($data['event_id'] ?? 0);
$acc = isset($data['accuracy_m']) ? (int)$data['accuracy_m'] : null;

if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
    jsonError('Invalid coordinates');
}

if (!function_exists('qb_location_pings_table_exists')) {
    function qb_location_pings_table_exists(): bool {
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
}

if (!qb_location_pings_table_exists()) {
    jsonError('Location service not enabled — run sql/bazar_platform_core.sql', 503);
}

$role = $_SESSION['app_role'] ?? 'buyer';
$ctx = in_array($role, ['seller', 'organizer', 'super_admin'], true) ? 'seller' : 'buyer';
if ($role === 'super_admin' || $role === 'organizer') {
    $ctx = 'staff';
}

if ($eventId > 0) {
    db()->insert(
        'INSERT INTO location_pings (app_user_id, event_id, lat, lng, accuracy_m, role_context) VALUES (?,?,?,?,?,?)',
        [(int)$_SESSION['app_user_id'], $eventId, $lat, $lng, $acc, $ctx],
        'iiddis'
    );
    if (function_exists('isInsideGeofence') && function_exists('qb_event_mode_set')) {
        $geo = isInsideGeofence($lat, $lng, $eventId);
        if (!empty($geo['inside'])) {
            qb_event_mode_set((int) $_SESSION['app_user_id'], $eventId, 'geofence');
        }
    }
    if (function_exists('qb_track_event')) {
        qb_track_event('location.ping.saved', ['event_id' => $eventId, 'context' => $ctx], $uid, 'event', (string) $eventId);
    }
} else {
    db()->insert(
        'INSERT INTO location_pings (app_user_id, lat, lng, accuracy_m, role_context) VALUES (?,?,?,?,?)',
        [(int)$_SESSION['app_user_id'], $lat, $lng, $acc, $ctx],
        'iddis'
    );
    if (function_exists('qb_track_event')) {
        qb_track_event('location.ping.saved', ['event_id' => 0, 'context' => $ctx], $uid, 'location_ping', 'global');
    }
}

jsonSuccess([], 'OK');
