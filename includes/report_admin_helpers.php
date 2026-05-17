<?php
/**
 * Admin reports queue — supports legacy (reporter_id, reason, details) and
 * API schema (reporter_app_user_id, body, priority).
 */
function qb_reports_table_columns(): array {
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $rows = db()->fetchAll('SHOW COLUMNS FROM reports');
        $cols = array_column($rows, 'Field');
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

function qb_reports_table_exists(): bool {
    return qb_reports_table_columns() !== [];
}

/**
 * @param array{status?:string,type?:string,q?:string} $filters
 * @return list<array<string,mixed>>
 */
function qb_admin_reports_fetch(array $filters): array {
    $cols = qb_reports_table_columns();
    if ($cols === []) {
        return [];
    }

    $hasReporterNew = in_array('reporter_app_user_id', $cols, true);
    $hasReporterOld = in_array('reporter_id', $cols, true);
    if ($hasReporterNew && $hasReporterOld) {
        $joinOn = 'u.id = COALESCE(r.reporter_app_user_id, r.reporter_id)';
    } elseif ($hasReporterNew) {
        $joinOn = 'u.id = r.reporter_app_user_id';
    } elseif ($hasReporterOld) {
        $joinOn = 'u.id = r.reporter_id';
    } else {
        $joinOn = '1=0';
    }

    $select = [
        'r.id',
        'r.target_type',
        'r.target_id',
        'r.created_at',
        'u.display_name AS reporter_name',
        'u.login_uid AS reporter_login',
    ];

    if (in_array('status', $cols, true)) {
        $select[] = 'r.status';
    } else {
        $select[] = "'open' AS status";
    }

    if (in_array('body', $cols, true)) {
        $select[] = 'r.body AS report_text';
        $select[] = "'' AS reason";
        $select[] = "'' AS details";
    } else {
        $select[] = 'COALESCE(r.reason, \'\') AS reason';
        $select[] = 'COALESCE(r.details, \'\') AS details';
        $select[] = 'TRIM(CONCAT(COALESCE(r.reason,\'\'), \' \', COALESCE(r.details,\'\'))) AS report_text';
    }

    if (in_array('priority', $cols, true)) {
        $select[] = 'r.priority';
    } else {
        $select[] = '0 AS priority';
    }

    $where = ['1=1'];
    $params = [];

    $st = $filters['status'] ?? '';
    if ($st !== '' && $st !== 'all' && in_array('status', $cols, true)) {
        $where[] = 'r.status = ?';
        $params[] = $st;
    }

    $ty = $filters['type'] ?? '';
    if ($ty !== '' && $ty !== 'all') {
        $where[] = 'r.target_type = ?';
        $params[] = $ty;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        if (in_array('body', $cols, true)) {
            $where[] = 'r.body LIKE ?';
            $params[] = $like;
        } elseif (in_array('reason', $cols, true)) {
            $where[] = '(r.reason LIKE ? OR r.details LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
    }

    $orderParts = [];
    if (in_array('priority', $cols, true)) {
        $orderParts[] = 'r.priority ASC';
    }
    $orderParts[] = 'r.created_at DESC';

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM reports r'
        . ' LEFT JOIN app_users u ON ' . $joinOn
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . implode(', ', $orderParts)
        . ' LIMIT 200';

    return db()->fetchAll($sql, $params);
}

/**
 * Flags users who have many moderation reports (total ≥10, or ≥10 in a single event when event_id exists).
 *
 * @param list<int> $userIds
 * @return array<int, array{warn: bool, label: string}>
 */
function qb_admin_user_report_warnings(array $userIds): array {
    $out = [];
    foreach ($userIds as $id) {
        $out[(int) $id] = ['warn' => false, 'label' => ''];
    }
    if ($userIds === [] || !qb_reports_table_exists()) {
        return $out;
    }
    $cols = qb_reports_table_columns();
    if (!in_array('target_type', $cols, true) || !in_array('target_id', $cols, true)) {
        return $out;
    }

    $ids = array_values(array_unique(array_map('intval', $userIds)));
    $hasEvent = in_array('event_id', $cols, true);
    $sellerMap = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $srows = db()->fetchAll("SELECT id, app_user_id FROM sellers WHERE app_user_id IN ($in)", $ids);
        foreach ($srows as $s) {
            $sellerMap[(int) $s['app_user_id']] = (int) $s['id'];
        }
    } catch (Throwable $e) {
        $sellerMap = [];
    }

    $threshold = 10;

    foreach ($ids as $uid) {
        $sid = $sellerMap[$uid] ?? 0;
        $parts = [];
        $params = [];
        $parts[] = '(target_type = ? AND target_id = ?)';
        array_push($params, 'user', $uid);
        if ($sid > 0) {
            $parts[] = '(target_type = ? AND target_id = ?)';
            array_push($params, 'seller', $sid);
        }
        $where = '(' . implode(' OR ', $parts) . ')';

        $total = 0;
        try {
            $total = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM reports WHERE $where", $params)['c'] ?? 0);
        } catch (Throwable $e) {
            if ($sid > 0) {
                try {
                    $total = (int) (db()->fetchOne(
                        'SELECT COUNT(*) AS c FROM reports WHERE target_type = ? AND target_id = ?',
                        ['seller', $sid]
                    )['c'] ?? 0);
                } catch (Throwable $e2) {
                    $total = 0;
                }
            }
        }

        $maxInEvent = 0;
        if ($hasEvent) {
            try {
                $evRows = db()->fetchAll(
                    "SELECT event_id, COUNT(*) AS c FROM reports WHERE $where AND event_id IS NOT NULL AND event_id > 0 GROUP BY event_id",
                    $params
                );
                foreach ($evRows as $er) {
                    $maxInEvent = max($maxInEvent, (int) $er['c']);
                }
            } catch (Throwable $e) {
                /* ignore */
            }
        }

        if ($total < $threshold && $maxInEvent < $threshold) {
            continue;
        }

        if ($maxInEvent >= $threshold) {
            $out[$uid] = ['warn' => true, 'label' => $maxInEvent . ' reports (one event)'];
        } else {
            $out[$uid] = ['warn' => true, 'label' => $total . ' reports'];
        }
    }

    return $out;
}
