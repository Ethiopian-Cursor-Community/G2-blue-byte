<?php
/**
 * Flash sales: time-boxed discounts on seller products (see flash_sales table).
 */

/** @return list<array<string,mixed>> */
function qb_flash_sales_active_rows(int $sellerId, ?int $buyerEventId): array {
    if (!qb_table_exists('flash_sales') || $sellerId <= 0) {
        return [];
    }
    if ($buyerEventId === null || $buyerEventId <= 0) {
        return db()->fetchAll(
            "SELECT * FROM flash_sales WHERE seller_id = ? AND is_active = 1
             AND NOW() >= starts_at AND NOW() <= ends_at AND event_id IS NULL",
            [$sellerId]
        );
    }
    return db()->fetchAll(
        "SELECT * FROM flash_sales WHERE seller_id = ? AND is_active = 1
         AND NOW() >= starts_at AND NOW() <= ends_at
         AND (event_id IS NULL OR event_id = ?)",
        [$sellerId, $buyerEventId]
    );
}

/**
 * Map product_id => flash row. Event-specific rows override global (event_id NULL) for same product.
 *
 * @return array<int, array<string,mixed>>
 */
function qb_flash_sales_active_map(int $sellerId, ?int $buyerEventId): array {
    $rows = qb_flash_sales_active_rows($sellerId, $buyerEventId);
    $map = [];
    foreach ($rows as $r) {
        $pid = (int) ($r['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($map[$pid])) {
            $map[$pid] = $r;
            continue;
        }
        $prev = $map[$pid];
        $prevEv = $prev['event_id'] ?? null;
        $curEv = $r['event_id'] ?? null;
        if ($curEv !== null && $prevEv === null) {
            $map[$pid] = $r;
        }
    }
    return $map;
}

