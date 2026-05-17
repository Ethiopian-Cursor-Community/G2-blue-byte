<?php
/**
 * Sellers: grace period to list at least one product; otherwise auto-downgrade to buyer + one-time notice.
 */

/** Days from seller profile creation with zero products before role becomes buyer. */
const QB_SELLER_ITEM_GRACE_DAYS = 2;

function qb_seller_downgrade_schema_ready(): bool {
    return qb_has_column('app_users', 'seller_downgrade_notice_pending');
}

function qb_apply_seller_downgrade_schema(): void {
    if (qb_seller_downgrade_schema_ready()) {
        return;
    }
    try {
        db()->execute(
            'ALTER TABLE app_users ADD COLUMN seller_downgrade_notice_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER moderation_note'
        );
    } catch (Throwable $e) {
        /* column may exist from parallel request */
    }
}

function qb_count_seller_products(int $sellerTableId): int {
    $r = db()->fetchOne('SELECT COUNT(*) AS c FROM products WHERE seller_id = ?', [$sellerTableId]);

    return (int) ($r['c'] ?? 0);
}

/**
 * If seller has no products and grace period elapsed, set role to buyer and flag first-login notice.
 *
 * @param array $user app_users row
 * @return array refreshed app_users row
 */
function qb_maybe_downgrade_inactive_seller(array $user): array {
    if (($user['role'] ?? '') !== 'seller') {
        return $user;
    }

    qb_apply_seller_downgrade_schema();

    $seller = db()->fetchOne('SELECT id, created_at FROM sellers WHERE app_user_id = ?', [(int) $user['id']]);
    if (!$seller) {
        return $user;
    }

    if (qb_count_seller_products((int) $seller['id']) > 0) {
        return $user;
    }

    $created = strtotime((string) $seller['created_at']);
    if ($created === false) {
        return $user;
    }

    $deadline = $created + (QB_SELLER_ITEM_GRACE_DAYS * 86400);
    if (time() < $deadline) {
        return $user;
    }

    $uid = (int) $user['id'];
    if (qb_seller_downgrade_schema_ready()) {
        db()->execute(
            "UPDATE app_users SET role = 'buyer', seller_downgrade_notice_pending = 1 WHERE id = ? AND role = 'seller'",
            [$uid]
        );
    } else {
        db()->execute("UPDATE app_users SET role = 'buyer' WHERE id = ? AND role = 'seller'", [$uid]);
    }

    $fresh = db()->fetchOne('SELECT * FROM app_users WHERE id = ?', [$uid]);

    return $fresh ?: $user;
}

/**
 * Clear one-time downgrade notice after user has seen it (buyer home).
 */
function qb_ack_seller_downgrade_notice(int $appUserId): void {
    if (!qb_seller_downgrade_schema_ready()) {
        return;
    }
    db()->execute(
        'UPDATE app_users SET seller_downgrade_notice_pending = 0 WHERE id = ? AND seller_downgrade_notice_pending = 1',
        [$appUserId]
    );
}

/**
 * For seller dashboard: seconds left in grace period, or null if not applicable.
 */
function qb_seller_grace_seconds_remaining(int $sellerTableId, string $sellerCreatedAt): ?int {
    if (qb_count_seller_products($sellerTableId) > 0) {
        return null;
    }
    $created = strtotime($sellerCreatedAt);
    if ($created === false) {
        return null;
    }
    $deadline = $created + (QB_SELLER_ITEM_GRACE_DAYS * 86400);
    $left = $deadline - time();

    return $left > 0 ? $left : null;
}
