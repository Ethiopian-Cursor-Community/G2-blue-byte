<?php
/**
 * Append-only audit log (RBAC-sensitive actions, security events).
 */

function qb_audit_table_exists(): bool {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $r = db()->fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_logs'"
    );
    $c = (bool)$r;
    return $c;
}

function qb_audit_log(string $action, ?string $entityType = null, ?string $entityId = null, array $meta = []): void {
    if (!qb_audit_table_exists()) {
        return;
    }
    startSession();
    $actor = !empty($_SESSION['app_user_id']) ? (int)$_SESSION['app_user_id'] : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
    $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

    if ($actor === null) {
        db()->execute(
            'INSERT INTO audit_logs (actor_app_user_id, action, entity_type, entity_id, ip, user_agent, meta) VALUES (NULL,?,?,?,?,?,?)',
            [$action, $entityType, $entityId, $ip, $ua, $metaJson]
        );
    } else {
        db()->execute(
            'INSERT INTO audit_logs (actor_app_user_id, action, entity_type, entity_id, ip, user_agent, meta) VALUES (?,?,?,?,?,?,?)',
            [(int)$actor, $action, $entityType, $entityId, $ip, $ua, $metaJson]
        );
    }
}
