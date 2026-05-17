<?php
/**
 * Bazar events, ticket windows, and event-scoped seller participation.
 */

function qb_tickets_live_for_event(int $eventId): bool {
    if ($eventId <= 0) {
        return false;
    }
    $e = db()->fetchOne(
        'SELECT ticket_sales_start, ticket_sales_end FROM bazar_events WHERE id = ?',
        [$eventId],
        'i'
    );
    if (!$e || empty($e['ticket_sales_start']) || empty($e['ticket_sales_end'])) {
        return false;
    }
    $now = time();
    $a = strtotime($e['ticket_sales_start']);
    $b = strtotime($e['ticket_sales_end']);
    return $a <= $now && $now <= $b;
}

/**
 * Direct storefront sales allowed (legacy / admin override). Otherwise sales need a live bazar + seller participant row.
 */
function qb_seller_may_complete_sale(int $sellerId, ?int $eventId): bool {
    $seller = db()->fetchOne(
        'SELECT allow_direct_sales, app_user_id FROM sellers WHERE id = ? AND is_active = 1',
        [$sellerId],
        'i'
    );
    if (!$seller) {
        return false;
    }
    if (!empty($seller['allow_direct_sales'])) {
        return true;
    }
    $eid = (int)($eventId ?? 0);
    if ($eid <= 0) {
        return false;
    }
    $aid = (int)($seller['app_user_id'] ?? 0);
    if ($aid <= 0) {
        return false;
    }
    if (!qb_tickets_live_for_event($eid)) {
        return false;
    }
    $p = db()->fetchOne(
        'SELECT 1 AS ok FROM event_participants WHERE event_id = ? AND app_user_id = ? AND role_in_event = ?',
        [$eid, $aid, 'seller'],
        'iis'
    );
    return (bool)$p;
}

function qb_app_user_participant_in_event(int $appUserId, int $eventId): ?string {
    $r = db()->fetchOne(
        'SELECT role_in_event FROM event_participants WHERE event_id = ? AND app_user_id = ?',
        [$eventId, $appUserId],
        'ii'
    );
    return $r ? (string)$r['role_in_event'] : null;
}

/**
 * @return array{participants:int,buyers:int,sellers:int,revenue:float,tx_count:int}
 */
function qb_event_stats(int $eventId): array {
    $part = db()->fetchOne(
        'SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ?',
        [$eventId],
        'i'
    );
    $buyers = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ? AND role_in_event = 'buyer'",
        [$eventId],
        'i'
    );
    $sellers = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ? AND role_in_event = 'seller'",
        [$eventId],
        'i'
    );
    $txCol = db()->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'event_id'"
    );
    $revenue = ['total' => 0.0, 'count' => 0];
    if ($txCol) {
        $r = db()->fetchOne(
            'SELECT COALESCE(SUM(total_amount),0) AS t, COUNT(*) AS c FROM transactions WHERE event_id = ? AND payment_status = ?',
            [$eventId, 'completed'],
            'is'
        );
        $revenue = ['total' => (float)($r['t'] ?? 0), 'count' => (int)($r['c'] ?? 0)];
    }
    return [
        'participants' => (int)($part['c'] ?? 0),
        'buyers'       => (int)($buyers['c'] ?? 0),
        'sellers'      => (int)($sellers['c'] ?? 0),
        'revenue'      => $revenue['total'],
        'tx_count'     => $revenue['count'],
    ];
}
