<?php
/**
 * Leaderboard queries — scope by role in calling pages (admin = global, organizer = event, etc.).
 */

require_once __DIR__ . '/qb_features.php';

function qb_lb_rank_history_table_exists(): bool {
    return qb_table_exists('leaderboard_rank_history');
}

/**
 * @param list<array<string,mixed>> $rows
 */
function qb_lb_save_rank_snapshot(array $rows, string $subjectType, string $scopeType = 'global', ?int $scopeId = null): void {
    if (!qb_lb_rank_history_table_exists() || empty($rows)) {
        return;
    }
    $d = date('Y-m-d');
    foreach ($rows as $row) {
        $subjectId = $subjectType === 'seller' ? (int) ($row['seller_id'] ?? 0) : (int) ($row['buyer_id'] ?? 0);
        if ($subjectId <= 0) {
            continue;
        }
        $amount = $subjectType === 'seller' ? (float) ($row['revenue'] ?? 0) : (float) ($row['spend'] ?? 0);
        $orders = (int) ($row['orders'] ?? 0);
        db()->execute(
            "INSERT INTO leaderboard_rank_history
             (subject_type, subject_id, scope_type, scope_id, rank_position, metric_amount, metric_orders, snapshot_date, created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE rank_position = VALUES(rank_position), metric_amount = VALUES(metric_amount), metric_orders = VALUES(metric_orders), created_at = NOW()",
            [$subjectType, $subjectId, $scopeType, $scopeId, (int) ($row['rank'] ?? 0), $amount, $orders, $d]
        );
    }
}

/** @return list<array{rank:int,seller_id:int,market_name:string,revenue:float,orders:int}> */
function qb_lb_sellers_global(int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    $rows = db()->fetchAll(
        "SELECT s.id AS seller_id, s.market_name, s.full_name,
                COALESCE(SUM(CASE WHEN t.payment_status = 'completed' THEN t.total_amount ELSE 0 END), 0) AS revenue,
                SUM(CASE WHEN t.payment_status = 'completed' THEN 1 ELSE 0 END) AS orders
         FROM sellers s
         LEFT JOIN transactions t ON t.seller_id = s.id
         WHERE s.is_active = 1
         GROUP BY s.id, s.market_name, s.full_name
         ORDER BY revenue DESC, orders DESC
         LIMIT " . (int) $limit
    );
    return qb_lb_rank_seller_rows($rows);
}

/** @return list<array{rank:int,seller_id:int,market_name:string,revenue:float,orders:int}> */
function qb_lb_sellers_event(int $eventId, int $limit = 25): array {
    if ($eventId <= 0) {
        return [];
    }
    if (!qb_table_exists('stalls')) {
        return qb_lb_sellers_event_from_transactions_only($eventId, $limit);
    }
    $limit = max(1, min(100, $limit));
    $rows = db()->fetchAll(
        "SELECT s.id AS seller_id, s.market_name, s.full_name,
                COALESCE(SUM(CASE WHEN t.payment_status = 'completed' AND t.event_id = ? THEN t.total_amount ELSE 0 END), 0) AS revenue,
                SUM(CASE WHEN t.payment_status = 'completed' AND t.event_id = ? THEN 1 ELSE 0 END) AS orders
         FROM stalls st
         INNER JOIN sellers s ON s.id = st.seller_id AND s.is_active = 1
         LEFT JOIN transactions t ON t.seller_id = s.id
         WHERE st.event_id = ?
         GROUP BY s.id, s.market_name, s.full_name
         ORDER BY revenue DESC, orders DESC
         LIMIT " . (int) $limit,
        [$eventId, $eventId, $eventId]
    );
    return qb_lb_rank_seller_rows($rows);
}

/** When stalls table is absent: rank sellers who have completed sales in the event only. */
function qb_lb_sellers_event_from_transactions_only(int $eventId, int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    $rows = db()->fetchAll(
        "SELECT s.id AS seller_id, s.market_name, s.full_name,
                COALESCE(SUM(t.total_amount), 0) AS revenue,
                COUNT(t.id) AS orders
         FROM transactions t
         INNER JOIN sellers s ON s.id = t.seller_id AND s.is_active = 1
         WHERE t.event_id = ? AND t.payment_status = 'completed'
         GROUP BY s.id, s.market_name, s.full_name
         ORDER BY revenue DESC, orders DESC
         LIMIT " . (int) $limit,
        [$eventId]
    );
    return qb_lb_rank_seller_rows($rows);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{rank:int,seller_id:int,market_name:string,revenue:float,orders:int}>
 */
function qb_lb_rank_seller_rows(array $rows): array {
    $out = [];
    $r = 0;
    foreach ($rows as $row) {
        $r++;
        $out[] = [
            'rank' => $r,
            'seller_id' => (int) ($row['seller_id'] ?? 0),
            'market_name' => (string) ($row['market_name'] ?? ''),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'orders' => (int) ($row['orders'] ?? 0),
        ];
    }
    return $out;
}

/** @return list<array{rank:int,buyer_id:int,label:string,spend:float,orders:int}> */
function qb_lb_buyers_global(int $limit = 25): array {
    $limit = max(1, min(100, $limit));
    $rows = db()->fetchAll(
        "SELECT u.id AS buyer_id, u.display_name,
                COALESCE(SUM(t.total_amount), 0) AS spend,
                COUNT(t.id) AS orders
         FROM app_users u
         INNER JOIN transactions t ON t.buyer_id = u.id AND t.payment_status = 'completed'
         WHERE u.role = 'buyer'
         GROUP BY u.id, u.display_name
         ORDER BY spend DESC, orders DESC
         LIMIT " . (int) $limit
    );
    return qb_lb_rank_buyer_rows($rows);
}

/** @return list<array{rank:int,buyer_id:int,label:string,spend:float,orders:int}> */
function qb_lb_buyers_event(int $eventId, int $limit = 25): array {
    if ($eventId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $rows = db()->fetchAll(
        "SELECT u.id AS buyer_id, u.display_name,
                COALESCE(SUM(t.total_amount), 0) AS spend,
                COUNT(t.id) AS orders
         FROM app_users u
         INNER JOIN transactions t ON t.buyer_id = u.id AND t.payment_status = 'completed' AND t.event_id = ?
         WHERE u.role = 'buyer'
         GROUP BY u.id, u.display_name
         ORDER BY spend DESC, orders DESC
         LIMIT " . (int) $limit,
        [$eventId]
    );
    return qb_lb_rank_buyer_rows($rows);
}

/**
 * Top buyers by spend at one seller's stall (completed transactions).
 *
 * @return list<array{rank:int,buyer_id:int|null,label:string,spend:float,orders:int}>
 */
function qb_lb_buyers_for_seller(int $sellerId, int $limit = 25): array {
    if ($sellerId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $rows = db()->fetchAll(
        "SELECT t.buyer_id,
                MAX(COALESCE(u.display_name, t.buyer_name, 'Guest')) AS label,
                COALESCE(SUM(t.total_amount), 0) AS spend,
                COUNT(t.id) AS orders
         FROM transactions t
         LEFT JOIN app_users u ON u.id = t.buyer_id
         WHERE t.seller_id = ? AND t.payment_status = 'completed'
         GROUP BY t.buyer_id
         ORDER BY spend DESC, orders DESC
         LIMIT " . (int) $limit,
        [$sellerId]
    );
    $out = [];
    $r = 0;
    foreach ($rows as $row) {
        $r++;
        $bid = $row['buyer_id'];
        $out[] = [
            'rank' => $r,
            'buyer_id' => $bid !== null ? (int) $bid : null,
            'label' => (string) ($row['label'] ?? 'Guest'),
            'spend' => (float) ($row['spend'] ?? 0),
            'orders' => (int) ($row['orders'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Buyers see peers with light privacy: first name + last initial when possible.
 *
 * @return list<array{rank:int,buyer_id:int,label:string,spend:float,orders:int,is_viewer:bool}>
 */
function qb_lb_buyers_buyer_portal(int $limit = 25, ?int $viewerAppUserId = null): array {
    $raw = qb_lb_buyers_global($limit);
    $out = [];
    foreach ($raw as $row) {
        $bid = (int) ($row['buyer_id'] ?? 0);
        $name = (string) ($row['label'] ?? '');
        $out[] = [
            'rank' => $row['rank'],
            'buyer_id' => $bid,
            'label' => qb_lb_privacy_buyer_name($name),
            'spend' => $row['spend'],
            'orders' => $row['orders'],
            'is_viewer' => $viewerAppUserId !== null && $viewerAppUserId === $bid,
        ];
    }
    return $out;
}

function qb_lb_privacy_buyer_name(string $displayName): string {
    $displayName = trim($displayName);
    if ($displayName === '') {
        return 'Buyer';
    }
    $parts = preg_split('/\s+/', $displayName, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts || count($parts) === 1) {
        return $parts[0];
    }
    $first = $parts[0];
    $rest = array_slice($parts, 1);
    $last = $rest[count($rest) - 1];
    $ini = mb_strtoupper(mb_substr($last, 0, 1));
    return $first . ' ' . $ini . '.';
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{rank:int,buyer_id:int,label:string,spend:float,orders:int}>
 */
function qb_lb_rank_buyer_rows(array $rows): array {
    $out = [];
    $r = 0;
    foreach ($rows as $row) {
        $r++;
        $out[] = [
            'rank' => $r,
            'buyer_id' => (int) ($row['buyer_id'] ?? 0),
            'label' => (string) ($row['display_name'] ?? ''),
            'spend' => (float) ($row['spend'] ?? 0),
            'orders' => (int) ($row['orders'] ?? 0),
        ];
    }
    return $out;
}

/** Organizer may access this event (primary or co-organizer). */
function qb_lb_organizer_event_allowed(int $organizerAppUserId, int $eventId): bool {
    if ($eventId <= 0 || $organizerAppUserId <= 0) {
        return false;
    }
    $ew = qb_organizer_event_alias_access_sql('e');
    $eb = qb_organizer_event_access_bind($organizerAppUserId);
    $row = db()->fetchOne(
        "SELECT e.id FROM bazar_events e WHERE e.id = ? AND $ew",
        array_merge([$eventId], $eb)
    );
    return $row !== null;
}

/** Gatekeeper may view leaderboards only for bazars they are assigned to (valid event_staff). */
function qb_lb_gatekeeper_event_allowed(int $appUserId, int $eventId): bool {
    if ($eventId <= 0 || $appUserId <= 0 || !function_exists('qb_event_staff_table_exists') || !qb_event_staff_table_exists()) {
        return false;
    }
    $r = db()->fetchOne(
        'SELECT 1 AS o FROM event_staff WHERE app_user_id = ? AND event_id = ? AND valid_until > NOW() LIMIT 1',
        [$appUserId, $eventId]
    );
    return (bool) $r;
}

/** Seller has a stall at event. */
function qb_lb_seller_in_event(int $sellerTableId, int $eventId): bool {
    if ($sellerTableId <= 0 || $eventId <= 0 || !qb_table_exists('stalls')) {
        return false;
    }
    $r = db()->fetchOne('SELECT 1 FROM stalls WHERE seller_id = ? AND event_id = ? LIMIT 1', [$sellerTableId, $eventId]);
    return $r !== null;
}
