<?php
/**
 * Session-based rate limiting for login and sensitive forms (soft protection).
 * Also includes IP-based rate limiting using the database for brute-force prevention.
 */

declare(strict_types=1);

// ── Session-based (per-browser) ──────────────────────────────────────────────

function qb_rate_limit_login_allow(): bool {
    startSession();
    $now = time();
    $st = $_SESSION['qb_login_rl'] ?? null;
    if (!is_array($st)) {
        return true;
    }
    $n = (int) ($st['n'] ?? 0);
    $t = (int) ($st['t'] ?? 0);
    if ($now - $t > 900) {
        return true;
    }

    return $n < 12;
}

function qb_rate_limit_login_fail(): void {
    startSession();
    $now = time();
    $st = $_SESSION['qb_login_rl'] ?? ['n' => 0, 't' => $now];
    if ($now - (int) ($st['t'] ?? 0) > 900) {
        $st = ['n' => 0, 't' => $now];
    }
    $st['n'] = (int) ($st['n'] ?? 0) + 1;
    $st['t'] = (int) ($st['t'] ?? $now);
    $_SESSION['qb_login_rl'] = $st;
    // Also record at IP level.
    qb_rate_limit_ip_record_fail();
}

function qb_rate_limit_login_ok(): void {
    startSession();
    unset($_SESSION['qb_login_rl']);
    qb_rate_limit_ip_clear();
}

// ── IP-based (database-backed) ───────────────────────────────────────────────

function qb_rate_limit_ip_ensure_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->execute(
            "CREATE TABLE IF NOT EXISTS login_rate_limits (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                ip_hash     VARCHAR(64) NOT NULL,
                attempts    INT NOT NULL DEFAULT 0,
                blocked_at  DATETIME NULL,
                last_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_lrl_ip (ip_hash),
                KEY idx_lrl_last (last_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) { /* non-fatal */ }
}

function qb_rate_limit_ip_hash(): string {
    // Hash the IP so raw addresses are never stored in the DB.
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
    // Take only the first IP if there is a comma-separated list.
    $ip = trim(explode(',', $ip)[0]);
    return hash('sha256', $ip . (defined('QR_SECRET') ? QR_SECRET : 'qrbazaar'));
}

function qb_rate_limit_ip_allow(): bool {
    qb_rate_limit_ip_ensure_table();
    try {
        $hash = qb_rate_limit_ip_hash();
        $row  = db()->fetchOne(
            "SELECT attempts, last_at FROM login_rate_limits WHERE ip_hash = ?",
            [$hash]
        );
        if (!$row) return true;
        // Auto-expire after 15 minutes of inactivity.
        $lastSec = strtotime((string) $row['last_at']);
        if ($lastSec && (time() - $lastSec) > 900) return true;
        return (int) $row['attempts'] < 15; // 15 attempts per 15-minute window per IP.
    } catch (Throwable $e) {
        return true; // Fail open — never block users due to a DB error.
    }
}

function qb_rate_limit_ip_record_fail(): void {
    qb_rate_limit_ip_ensure_table();
    try {
        $hash = qb_rate_limit_ip_hash();
        db()->execute(
            "INSERT INTO login_rate_limits (ip_hash, attempts, last_at)
             VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               attempts = IF(TIMESTAMPDIFF(SECOND, last_at, NOW()) > 900, 1, attempts + 1),
               last_at  = NOW()",
            [$hash]
        );
    } catch (Throwable $e) { /* non-fatal */ }
}

function qb_rate_limit_ip_clear(): void {
    qb_rate_limit_ip_ensure_table();
    try {
        db()->execute(
            "DELETE FROM login_rate_limits WHERE ip_hash = ?",
            [qb_rate_limit_ip_hash()]
        );
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── Combined check (session + IP) ────────────────────────────────────────────

function qb_rate_limit_login_allow_all(): bool {
    return qb_rate_limit_login_allow() && qb_rate_limit_ip_allow();
}

// ── Generic per-session rate limit ───────────────────────────────────────────

function qb_rate_limit_allow(string $key, int $maxAttempts, int $windowSeconds): bool {
    startSession();
    if ($maxAttempts < 1 || $windowSeconds < 1) {
        return true;
    }
    $now = time();
    $bucketKey = 'qb_rl_' . preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    $st = $_SESSION[$bucketKey] ?? null;
    if (!is_array($st)) {
        return true;
    }
    $count = (int) ($st['n'] ?? 0);
    $started = (int) ($st['t'] ?? 0);
    if ($now - $started >= $windowSeconds) {
        return true;
    }
    return $count < $maxAttempts;
}

function qb_rate_limit_hit(string $key, int $windowSeconds): void {
    startSession();
    if ($windowSeconds < 1) {
        return;
    }
    $now = time();
    $bucketKey = 'qb_rl_' . preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    $st = $_SESSION[$bucketKey] ?? ['n' => 0, 't' => $now];
    $started = (int) ($st['t'] ?? 0);
    if ($now - $started >= $windowSeconds) {
        $st = ['n' => 0, 't' => $now];
    }
    $st['n'] = (int) ($st['n'] ?? 0) + 1;
    $_SESSION[$bucketKey] = $st;
}

function qb_rate_limit_retry_after(string $key, int $windowSeconds): int {
    startSession();
    if ($windowSeconds < 1) {
        return 1;
    }
    $bucketKey = 'qb_rl_' . preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    $st = $_SESSION[$bucketKey] ?? null;
    if (!is_array($st)) {
        return 1;
    }
    $left = ((int) ($st['t'] ?? 0) + $windowSeconds) - time();
    return max(1, $left);
}
