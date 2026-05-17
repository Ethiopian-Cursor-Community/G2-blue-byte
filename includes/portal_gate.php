<?php
/**
 * Portal + device enforcement: each area is only for the right role.
 */

function qb_is_desktop_client(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') {
        return false;
    }
    if (preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
        return false;
    }
    if (preg_match('/Windows NT|Macintosh|X11|Linux x86_64|Win64|Linux x86/i', $ua)) {
        return true;
    }
    return false;
}

function qb_exit_mobile_only_page(): void {
    header('Location: ' . APP_URL . '/buyer/device_not_available.php', true, 403);
    exit;
}

/** Logged-in role for portal checks */
function qb_session_effective_role(): ?string {
    startSession();
    if (!empty($_SESSION['app_user_id'])) {
        return $_SESSION['app_role'] ?? null;
    }
    if (!empty($_SESSION['seller_id'])) {
        return 'seller';
    }
    return null;
}

function qb_portal_access_denied(string $neededPortal): void {
    header('Location: ' . APP_URL . '/portal_access_denied.php?portal=' . rawurlencode($neededPortal), true, 403);
    exit;
}

function qb_require_buyer_portal(): void {
    startSession();
    if (empty($_SESSION['app_user_id'])) {
        header('Location: ' . APP_URL . '/login.php?portal=buyer&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    $role = $_SESSION['app_role'] ?? '';
    if ($role !== 'buyer') {
        qb_portal_access_denied('buyer');
    }
}

function qb_require_buyer_portal_mobile(): void {
    qb_require_buyer_portal();
    if (qb_is_desktop_client()) {
        qb_exit_mobile_only_page();
    }
}

function qb_require_seller_portal(): void {
    startSession();
    if (empty($_SESSION['seller_id'])) {
        header('Location: ' . APP_URL . '/login.php?portal=seller&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    if (!empty($_SESSION['app_user_id']) && ($_SESSION['app_role'] ?? '') !== 'seller') {
        qb_portal_access_denied('seller');
    }
}

function qb_require_organizer_portal(): void {
    startSession();
    if (empty($_SESSION['app_user_id']) || !in_array(($_SESSION['app_role'] ?? ''), ['organizer', 'co_organizer'], true)) {
        if (!empty($_SESSION['app_user_id'])) {
            qb_portal_access_denied('organizer');
        }
        header('Location: ' . APP_URL . '/login.php?portal=organizer&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

function qb_require_admin_portal(): void {
    startSession();
    if (empty($_SESSION['app_user_id']) || ($_SESSION['app_role'] ?? '') !== 'super_admin') {
        if (!empty($_SESSION['app_user_id'])) {
            qb_portal_access_denied('admin');
        }
        header('Location: ' . APP_URL . '/login.php?portal=admin&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

/**
 * Buyer mobile-only routes (scan, etc.) — no login required.
 */
function qb_require_mobile_device_only(): void {
    if (qb_is_desktop_client()) {
        qb_exit_mobile_only_page();
    }
}

/** Safe redirect target after login — must match role. */
function qb_login_redirect_allowed(string $relativePath, ?string $role): bool {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, '..') !== false) {
        return false;
    }
    if (strpos($relativePath, 'http') === 0 || strpos($relativePath, '//') === 0) {
        return false;
    }

    $r = $role ?? '';
    if (strpos($relativePath, 'admin/') === 0) {
        return $r === 'super_admin';
    }
    if (strpos($relativePath, 'organizer/') === 0) {
        return in_array($r, ['organizer', 'co_organizer'], true);
    }
    if (strpos($relativePath, 'seller/') === 0) {
        return $r === 'seller';
    }
    if (strpos($relativePath, 'buyer/') === 0) {
        return $r === 'buyer';
    }
    if (strpos($relativePath, 'gatekeeper/') === 0) {
        return $r === 'gatekeeper';
    }
    return true;
}

function requireBuyer(): void {
    qb_require_buyer_portal();
}

function requireOrganizer(): void {
    qb_require_organizer_portal();
}

function requireAdmin(): void {
    qb_require_admin_portal();
}
