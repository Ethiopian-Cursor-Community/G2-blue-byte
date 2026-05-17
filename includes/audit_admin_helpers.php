<?php
/**
 * Admin audit log listing: supports `audit_logs` (current) or legacy `audit_log` from seed SQL.
 */

function qb_audit_admin_schema(): ?array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $new = db()->fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_logs'"
    );
    if ($new) {
        $cache = ['kind' => 'audit_logs', 'table' => 'audit_logs'];
        return $cache;
    }
    $old = db()->fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_log'"
    );
    if ($old) {
        $cache = ['kind' => 'audit_log', 'table' => 'audit_log'];
        return $cache;
    }
    $cache = null;
    return null;
}

/**
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function qb_audit_admin_fetch(string $actionQ, string $entityQ, int $page, int $perPage): array {
    $schema = qb_audit_admin_schema();
    if (!$schema) {
        return ['rows' => [], 'total' => 0];
    }

    $offset = max(0, ($page - 1) * $perPage);
    $lim = max(1, min(200, $perPage));
    $off = (int)$offset;
    $params = [];
    $where = [];

    if ($schema['kind'] === 'audit_logs') {
        if ($actionQ !== '') {
            $where[] = 'a.action LIKE ?';
            $params[] = '%' . $actionQ . '%';
        }
        if ($entityQ !== '') {
            $where[] = '(a.entity_type LIKE ? OR a.entity_id LIKE ?)';
            $params[] = '%' . $entityQ . '%';
            $params[] = '%' . $entityQ . '%';
        }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countRow = db()->fetchOne("SELECT COUNT(*) AS c FROM audit_logs a $w", $params);
        $total = (int)($countRow['c'] ?? 0);

        $rows = db()->fetchAll(
            "SELECT a.*, u.display_name AS actor_name, u.login_uid AS actor_login
             FROM audit_logs a
             LEFT JOIN app_users u ON u.id = a.actor_app_user_id
             $w
             ORDER BY a.id DESC
             LIMIT " . (int)$lim . " OFFSET " . $off,
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    // Legacy singular table
    if ($actionQ !== '') {
        $where[] = 'a.action LIKE ?';
        $params[] = '%' . $actionQ . '%';
    }
    if ($entityQ !== '') {
        $where[] = '(a.target_type LIKE ? OR CAST(a.target_id AS CHAR) LIKE ?)';
        $params[] = '%' . $entityQ . '%';
        $params[] = '%' . $entityQ . '%';
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countRow = db()->fetchOne("SELECT COUNT(*) AS c FROM audit_log a $w", $params);
    $total = (int)($countRow['c'] ?? 0);

    $rows = db()->fetchAll(
        "SELECT
            a.id,
            a.user_id AS actor_app_user_id,
            a.action,
            a.target_type AS entity_type,
            CAST(a.target_id AS CHAR) AS entity_id,
            a.ip_address AS ip,
            NULL AS user_agent,
            a.metadata AS meta,
            a.created_at,
            u.display_name AS actor_name,
            u.login_uid AS actor_login
         FROM audit_log a
         LEFT JOIN app_users u ON u.id = a.user_id
         $w
         ORDER BY a.id DESC
         LIMIT " . (int)$lim . " OFFSET " . $off,
        $params
    );
    return ['rows' => $rows, 'total' => $total];
}
function qb_shorten_audit_action(string $action): string {
    $a = strtolower(trim($action));
    $map = [
        'admin.lock_user'           => 'LOCK',
        'admin.ban_user'            => 'BAN',
        'admin.unlock_user'         => 'UNLOCK',
        'admin.unban_user'          => 'UNBAN',
        'admin.downgrade_seller'    => 'DOWNGRADE',
        'admin.upgrade_seller'      => 'UPGRADE',
        'payment.telebirr.completed' => 'TELEBIRR OK',
        'payment.telebirr.failed'    => 'TELEBIRR FAIL',
        'payment.cash.completed'     => 'CASH OK',
        'moderation.ban'            => 'MOD BAN',
        'moderation.warn'           => 'MOD WARN',
    ];
    if (isset($map[$a])) return $map[$a];
    if (str_starts_with($a, 'admin.')) return strtoupper(substr($a, 6));
    if (str_starts_with($a, 'payment.')) return strtoupper(substr($a, 8));
    return strtoupper($a);
}
