<?php
/**
 * app_users: public opaque id (short URL-safe id, ~16 chars / 96-bit) + moderation flags.
 * Column remains `public_uuid` for backwards compatibility; values are not UUIDs anymore.
 */

function qb_user_account_schema_ready(): bool {
    return qb_has_column('app_users', 'public_uuid')
        && qb_has_column('app_users', 'is_locked')
        && qb_has_column('app_users', 'is_banned');
}

/** @return array{ok:bool,error?:string} */
function qb_apply_user_account_schema(): array {
    try {
        if (!qb_has_column('app_users', 'public_uuid')) {
            db()->execute(
                'ALTER TABLE app_users ADD COLUMN public_uuid VARCHAR(36) NULL DEFAULT NULL AFTER id'
            );
        }
        $idx = db()->fetchOne(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_users' AND INDEX_NAME = 'uq_app_users_public_uuid'"
        );
        if ((int) ($idx['n'] ?? 0) === 0) {
            try {
                db()->execute('CREATE UNIQUE INDEX uq_app_users_public_uuid ON app_users (public_uuid)');
            } catch (Throwable $e) {
                /* index may exist under another name */
            }
        }
        if (!qb_has_column('app_users', 'is_locked')) {
            db()->execute(
                'ALTER TABLE app_users ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
            );
        }
        if (!qb_has_column('app_users', 'is_banned')) {
            db()->execute(
                'ALTER TABLE app_users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_locked'
            );
        }
        if (!qb_has_column('app_users', 'moderation_note')) {
            db()->execute(
                'ALTER TABLE app_users ADD COLUMN moderation_note VARCHAR(500) NULL DEFAULT NULL AFTER is_banned'
            );
        }

        $rows = db()->fetchAll('SELECT id FROM app_users WHERE public_uuid IS NULL OR public_uuid = \'\'');
        foreach ($rows as $r) {
            $pid = qb_generate_public_id();
            db()->execute('UPDATE app_users SET public_uuid = ? WHERE id = ?', [$pid, (int) $r['id']]);
        }

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Short public identifier: 12 random bytes → base64url without padding (~16 chars, 96-bit entropy).
 * URL-safe (A–Z a–z 0–9 - _) — shorter than UUID, still infeasible to guess.
 */
function qb_generate_public_id(): string {
    return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
}

/** @see qb_generate_public_id() Legacy name for older call sites. */
function qb_generate_public_uuid(): string {
    return qb_generate_public_id();
}
