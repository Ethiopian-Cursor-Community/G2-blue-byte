<?php
/**
 * Buyer dashboard: match transactions by phone on completed orders.
 */

function qb_normalize_phone(?string $phone): string {
    return preg_replace('/\D+/', '', (string)$phone);
}

/**
 * @return array{tx_list: array, spend_by_day: array<string,float>, sellers_recent: array, total_spend: float, tx_count: int}
 */
function qb_buyer_dashboard_data(string $normalizedPhone): array {
    $empty = ['tx_list' => [], 'spend_by_day' => [], 'sellers_recent' => [], 'total_spend' => 0.0, 'tx_count' => 0];
    if ($normalizedPhone === '') {
        return $empty;
    }

    $suffix = strlen($normalizedPhone) >= 9 ? substr($normalizedPhone, -9) : $normalizedPhone;
    $like = '%' . $suffix;

    $tx = db()->fetchAll(
        "SELECT t.id, t.tx_id, t.total_amount, t.created_at, t.payment_method,
                s.id AS seller_id, s.uid, s.market_name, s.full_name
         FROM transactions t
         JOIN sellers s ON s.id = t.seller_id
         WHERE t.payment_status = 'completed'
           AND t.buyer_phone IS NOT NULL AND t.buyer_phone != ''
           AND (
             REPLACE(REPLACE(REPLACE(t.buyer_phone,' ',''),'-',''),'+','') = ?
             OR REPLACE(REPLACE(REPLACE(t.buyer_phone,' ',''),'-',''),'+','') LIKE ?
           )
         ORDER BY t.created_at DESC
         LIMIT 80",
        [$normalizedPhone, $like],
        'ss'
    );

    if (!$tx) {
        return $empty;
    }

    $total = 0.0;
    foreach ($tx as $row) {
        $total += (float)$row['total_amount'];
    }

    $spendByDay = [];
    foreach (array_reverse($tx) as $row) {
        $d = substr($row['created_at'], 0, 10);
        if (!isset($spendByDay[$d])) {
            $spendByDay[$d] = 0.0;
        }
        $spendByDay[$d] += (float)$row['total_amount'];
    }
    $last14 = array_slice($spendByDay, -14, 14, true);

    $seenSeller = [];
    $sellersRecent = [];
    foreach ($tx as $row) {
        $sid = (int)$row['seller_id'];
        if (isset($seenSeller[$sid])) {
            continue;
        }
        $seenSeller[$sid] = true;
        $sellersRecent[] = [
            'seller_id'   => $sid,
            'uid'         => $row['uid'],
            'market_name' => $row['market_name'],
            'full_name'   => $row['full_name'],
            'last_at'     => $row['created_at'],
            'last_amount' => (float)$row['total_amount'],
        ];
        if (count($sellersRecent) >= 8) {
            break;
        }
    }

    return [
        'tx_list'       => $tx,
        'spend_by_day'  => $last14,
        'sellers_recent'=> $sellersRecent,
        'total_spend'   => $total,
        'tx_count'      => count($tx),
    ];
}

function qb_seller_suggestions_table_exists(): bool {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $r = db()->fetchOne(
        "SELECT 1 AS o FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'seller_suggestions'"
    );
    $c = (bool)$r;
    return $c;
}

function qb_favorites_table_exists(): bool {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $r = db()->fetchOne(
        "SELECT 1 AS o FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'buyer_favorites'"
    );
    $c = (bool)$r;
    return $c;
}

/**
 * @return list<array<string,mixed>>
 */
function qb_buyer_saved_shops(int $appUserId): array {
    if (!qb_favorites_table_exists() || $appUserId <= 0) {
        return [];
    }
    return db()->fetchAll(
        'SELECT s.id, s.uid, s.market_name, s.category, s.location, s.profile_image
         FROM buyer_favorites f
         JOIN sellers s ON s.id = f.seller_id AND s.is_active = 1
         WHERE f.app_user_id = ?
         ORDER BY f.created_at DESC
         LIMIT 50',
        [$appUserId],
        'i'
    );
}

/** Ethiopia-style mobile → wa.me link */
function qb_whatsapp_link_for_phone(?string $phone): ?string {
    $d = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($d) < 9) {
        return null;
    }
    if (isset($d[0]) && $d[0] === '0') {
        $d = '251' . substr($d, 1);
    } elseif (strpos($d, '251') !== 0) {
        $d = '251' . $d;
    }
    return 'https://wa.me/' . $d;
}
