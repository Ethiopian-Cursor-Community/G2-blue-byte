<?php
/**
 * Role-based permissions (portal features).
 */

function qb_can(string $permission): bool {
    startSession();
    $role = $_SESSION['app_role'] ?? '';

    if ($role === 'super_admin') {
        return true;
    }

    if (qb_event_staff_active()) {
        // Gatekeepers: event_staff row but limited app — no map/stall delegation.
        if ($role === 'gatekeeper') {
            return in_array($permission, ['events.read'], true);
        }
        return in_array($permission, ['events.read', 'map.live', 'reports.read_event', 'stalls.assign'], true);
    }

    $matrix = [
        'organizer' => [
            'events.read', 'events.write', 'stalls.assign', 'reports.read_event', 'map.live', 'staff.delegate',
        ],
        'seller' => [
            'products.write', 'qr.generate', 'analytics.read', 'reports.read_own', 'presence.toggle',
        ],
        'buyer' => [
            'scan', 'purchase', 'reports.create', 'map.view', 'feed.view',
        ],
    ];

    if (isset($matrix[$role]) && in_array($permission, $matrix[$role], true)) {
        return true;
    }

    return false;
}

/** Current user has a valid event_staff row */
function qb_event_staff_active(): bool {
    if (empty($_SESSION['app_user_id']) || !qb_event_staff_table_exists()) {
        return false;
    }
    $uid = (int)$_SESSION['app_user_id'];
    $r = db()->fetchOne(
        'SELECT 1 AS o FROM event_staff WHERE app_user_id = ? AND valid_until > NOW() LIMIT 1',
        [$uid],
        'i'
    );
    return (bool)$r;
}

function qb_event_staff_table_exists(): bool {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $r = db()->fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'event_staff'"
    );
    $c = (bool)$r;
    return $c;
}

/** Events this user may manage as delegated staff */
function qb_event_staff_event_ids(int $appUserId): array {
    if (!qb_event_staff_table_exists() || $appUserId <= 0) {
        return [];
    }
    $rows = db()->fetchAll(
        'SELECT event_id FROM event_staff WHERE app_user_id = ? AND valid_until > NOW()',
        [$appUserId]
    );
    return array_map('intval', array_column($rows, 'event_id'));
}
