<?php
/**
 * User moderation reason history for admin actions.
 */

function qb_moderation_history_table_ready(): bool {
    return qb_table_exists('user_moderation_history');
}

function qb_apply_moderation_history_schema(): void {
    if (qb_moderation_history_table_ready()) {
        return;
    }
    try {
        db()->execute(
            "CREATE TABLE IF NOT EXISTS user_moderation_history (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                target_app_user_id INT UNSIGNED NOT NULL,
                actor_app_user_id INT UNSIGNED NULL,
                action VARCHAR(80) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_target_created (target_app_user_id, created_at),
                INDEX idx_actor_created (actor_app_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Keep moderation flow usable if migration races in another request.
    }
}

function qb_moderation_history_add(int $targetUserId, string $action, string $reason): void {
    $reason = trim($reason);
    if ($targetUserId <= 0 || $action === '' || $reason === '') {
        return;
    }
    qb_apply_moderation_history_schema();
    if (!qb_moderation_history_table_ready()) {
        return;
    }
    startSession();
    $actorId = !empty($_SESSION['app_user_id']) ? (int) $_SESSION['app_user_id'] : null;
    db()->execute(
        'INSERT INTO user_moderation_history (target_app_user_id, actor_app_user_id, action, reason) VALUES (?, ?, ?, ?)',
        [$targetUserId, $actorId, $action, mb_substr($reason, 0, 500)]
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function qb_moderation_history_recent_for_users(array $userIds, int $perUser = 6): array {
    qb_apply_moderation_history_schema();
    if (!qb_moderation_history_table_ready() || $userIds === []) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    $ids = array_filter($ids, static fn ($v) => $v > 0);
    if ($ids === []) {
        return [];
    }
    $perUser = max(1, min(20, $perUser));
    $limit = max(count($ids) * $perUser, $perUser);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return db()->fetchAll(
        "SELECT h.*, au.display_name AS actor_name, au.login_uid AS actor_login
         FROM user_moderation_history h
         LEFT JOIN app_users au ON au.id = h.actor_app_user_id
         WHERE h.target_app_user_id IN ($ph)
         ORDER BY h.created_at DESC, h.id DESC
         LIMIT " . (int) $limit,
        $ids
    );
}
