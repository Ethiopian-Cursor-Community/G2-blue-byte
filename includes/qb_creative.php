<?php
/**
 * “Creative” UX helpers: streaks, pulse, leaderboards, social proof, organizer pings.
 */
declare(strict_types=1);

/** Buyer has an active ticket for an event happening on today’s calendar date (inclusive window). */
function qb_buyer_ticket_pulses_today(int $buyerId): bool {
    try {
        $r = db()->fetchOne(
            "SELECT t.id FROM tickets t
             INNER JOIN bazar_events e ON e.id = t.event_id
             WHERE t.buyer_id = ? AND t.status = 'active'
               AND CURDATE() >= DATE(e.event_start)
               AND CURDATE() <= DATE(COALESCE(e.event_end, e.event_start))
             LIMIT 1",
            [$buyerId]
        );

        return (bool) $r;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Distinct bazars (events) the buyer has engaged with — tickets or purchases.
 *
 * @return array{count:int,label:string}
 */
function qb_buyer_bazar_explorer(int $buyerId): array {
    $ids = [];
    try {
        $rows = db()->fetchAll('SELECT DISTINCT event_id FROM tickets WHERE buyer_id = ? AND event_id IS NOT NULL', [$buyerId]);
        foreach ($rows as $r) {
            $ids[(int) $r['event_id']] = true;
        }
    } catch (Throwable $e) {
    }
    try {
        $rows = db()->fetchAll(
            "SELECT DISTINCT event_id FROM transactions WHERE buyer_id = ? AND payment_status = 'completed' AND event_id IS NOT NULL",
            [$buyerId]
        );
        foreach ($rows as $r) {
            $ids[(int) $r['event_id']] = true;
        }
    } catch (Throwable $e) {
    }
    $n = count($ids);
    if ($n <= 0) {
        return ['count' => 0, 'label' => ''];
    }
    if ($n === 1) {
        return ['count' => 1, 'label' => 'First bazar'];
    }
    if ($n === 2) {
        return ['count' => 2, 'label' => '2 bazars & counting'];
    }
    if ($n === 3) {
        return ['count' => 3, 'label' => '3rd bazar — regular!'];
    }

    return ['count' => $n, 'label' => $n . ' bazars explored'];
}

/** Top stalls by QR scans in the last hour. */
function qb_leaderboard_stall_scans_last_hour(int $limit = 3): array {
    try {
        return db()->fetchAll(
            "SELECT s.id, s.market_name, COUNT(*) AS scan_n
             FROM analytics_events ae
             INNER JOIN sellers s ON s.id = ae.seller_id
             WHERE ae.event_type = 'qr_scan' AND ae.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             GROUP BY s.id, s.market_name
             ORDER BY scan_n DESC
             LIMIT " . (int) max(1, min(10, $limit))
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** Units sold today for one product (completed transactions). */
function qb_product_sold_units_today(int $productId): int {
    try {
        $r = db()->fetchOne(
            "SELECT COALESCE(SUM(ti.quantity), 0) AS u
             FROM transaction_items ti
             INNER JOIN transactions t ON t.id = ti.transaction_id
             WHERE ti.product_id = ? AND t.payment_status = 'completed' AND DATE(t.created_at) = CURDATE()",
            [$productId]
        );

        return (int) ($r['u'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Map product_id => units sold today. */
function qb_products_sold_today_map(array $productIds): array {
    $productIds = array_values(array_filter(array_map('intval', $productIds)));
    if ($productIds === []) {
        return [];
    }
    $in = implode(',', $productIds);
    try {
        $rows = db()->fetchAll(
            "SELECT ti.product_id, SUM(ti.quantity) AS u
             FROM transaction_items ti
             INNER JOIN transactions t ON t.id = ti.transaction_id
             WHERE ti.product_id IN ($in) AND t.payment_status = 'completed' AND DATE(t.created_at) = CURDATE()
             GROUP BY ti.product_id"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']] = (int) $r['u'];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Notify primary event organizer that a seller asked for restock help.
 */
function qb_notify_organizer_stock_ping(int $sellerAppUserId, string $marketName, int $productId, string $productName): bool {
    if (!function_exists('createNotification')) {
        return false;
    }
    try {
        $ep = db()->fetchOne(
            "SELECT event_id FROM event_participants WHERE app_user_id = ? AND role_in_event = 'seller' ORDER BY event_id DESC LIMIT 1",
            [$sellerAppUserId]
        );
        if (!$ep || empty($ep['event_id'])) {
            return false;
        }
        $eid = (int) $ep['event_id'];
        $ev = db()->fetchOne('SELECT name, organizer_app_user_id FROM bazar_events WHERE id = ?', [$eid]);
        if (!$ev || empty($ev['organizer_app_user_id'])) {
            return false;
        }
        $oid = (int) $ev['organizer_app_user_id'];
        $en = (string) ($ev['name'] ?? 'Bazar');
        $title = 'Restock help — ' . $marketName;
        $body = 'A seller requested organizer attention for low stock: ' . $productName . ' (product #' . $productId . ') at ' . $en . '.';
        createNotification($oid, 'system', $title, $body, APP_URL . '/organizer/event.php?id=' . $eid);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Public line for map/discover: custom tagline or category + first word of market. */
function qb_seller_story_line(array $sellerRow): string {
    $tag = trim((string) ($sellerRow['stall_tagline'] ?? ''));
    if ($tag !== '') {
        return $tag;
    }
    $cat = trim((string) ($sellerRow['category'] ?? ''));
    $m = trim((string) ($sellerRow['market_name'] ?? ''));
    if ($cat !== '' && $m !== '') {
        return $cat . ' · ' . $m;
    }

    return $m !== '' ? $m : 'Open stall';
}
