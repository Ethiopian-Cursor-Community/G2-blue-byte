<?php
/**
 * Assign / revoke gate staff (event_staff) — used by organizer and admin UIs.
 */
declare(strict_types=1);

/**
 * @return array{ok:bool, message:string, user_id?:int}
 */
function qb_event_staff_assign_gatekeeper(int $eventId, string $phoneOrLogin, string $validUntilDatetime, int $assignedByUserId): array {
    if ($eventId <= 0 || $assignedByUserId <= 0) {
        return ['ok' => false, 'message' => 'Invalid request.'];
    }
    if (!qb_event_staff_table_exists()) {
        return ['ok' => false, 'message' => 'Run database migration: install/migrate_event_staff.php'];
    }
    $q = trim($phoneOrLogin);
    if ($q === '') {
        return ['ok' => false, 'message' => 'Enter a phone number or login username.'];
    }
    $vu = trim($validUntilDatetime);
    if ($vu === '' || strtotime($vu) === false) {
        return ['ok' => false, 'message' => 'Choose a valid “active until” date and time.'];
    }
    if (strtotime($vu) <= time()) {
        return ['ok' => false, 'message' => '“Active until” must be in the future.'];
    }
    $u = db()->fetchOne(
        'SELECT id, role, display_name FROM app_users WHERE phone = ? OR login_uid = ? LIMIT 1',
        [$q, $q]
    );
    if (!$u) {
        return ['ok' => false, 'message' => 'No account matches that phone or username. Create a buyer account first, or ask an admin to add the user.'];
    }
    $rid = (int) ($u['id'] ?? 0);
    $role = (string) ($u['role'] ?? '');
    if (!in_array($role, ['buyer', 'gatekeeper'], true)) {
        return ['ok' => false, 'message' => 'Only buyer (or existing gatekeeper) accounts can be assigned at the gate.'];
    }
    try {
        db()->execute(
            'INSERT INTO event_staff (event_id, app_user_id, assigned_by, valid_until, role_label) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valid_until = VALUES(valid_until), assigned_by = VALUES(assigned_by), role_label = VALUES(role_label)',
            [$eventId, $rid, $assignedByUserId, date('Y-m-d H:i:s', strtotime($vu)), 'gatekeeper']
        );
        db()->execute("UPDATE app_users SET role = 'gatekeeper' WHERE id = ?", [$rid]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not save assignment. Check database migrations.'];
    }
    $nm = (string) ($u['display_name'] ?? 'user');
    return [
        'ok' => true,
        'message' => 'Gate access saved for ' . $nm . '. They sign in with the Gatekeeper portal (phone-friendly).',
        'user_id' => $rid,
    ];
}

function qb_event_staff_remove(int $staffRowId, int $actingUserId, bool $isSuperAdmin): array {
    if ($staffRowId <= 0) {
        return ['ok' => false, 'message' => 'Invalid row.'];
    }
    $row = db()->fetchOne(
        'SELECT es.id, es.event_id, es.app_user_id FROM event_staff es WHERE es.id = ?',
        [$staffRowId]
    );
    if (!$row) {
        return ['ok' => false, 'message' => 'Assignment not found.'];
    }
    $eventId = (int) ($row['event_id'] ?? 0);
    $targetUser = (int) ($row['app_user_id'] ?? 0);
    if (!$isSuperAdmin) {
        $ew = qb_organizer_event_alias_access_sql('e');
        $eb = qb_organizer_event_access_bind($actingUserId);
        $ok = db()->fetchOne("SELECT e.id FROM bazar_events e WHERE e.id = ? AND $ew", array_merge([$eventId], $eb));
        if (!$ok) {
            return ['ok' => false, 'message' => 'You cannot remove staff for this bazar.'];
        }
    }
    db()->execute('DELETE FROM event_staff WHERE id = ?', [$staffRowId]);
    $still = db()->fetchOne(
        'SELECT 1 AS o FROM event_staff WHERE app_user_id = ? AND valid_until > NOW() LIMIT 1',
        [$targetUser]
    );
    if (!$still) {
        db()->execute("UPDATE app_users SET role = 'buyer' WHERE id = ? AND role = 'gatekeeper'", [$targetUser]);
    }
    return ['ok' => true, 'message' => 'Assignment removed.'];
}
