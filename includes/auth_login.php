<?php
/**
 * Auth: Unified login for all roles
 */

function qb_auth_set_last_error(string $message): void {
    $GLOBALS['qb_auth_last_error'] = $message;
}

function qb_auth_last_error_get(): string {
    return (string) ($GLOBALS['qb_auth_last_error'] ?? '');
}

function tryUnifiedLogin(string $login, string $password): bool {
    qb_auth_set_last_error('');
    $login = trim($login);
    if ($login === '' || $password === '') return false;

    $user = db()->fetchOne(
        'SELECT * FROM app_users WHERE (login_uid = ? OR phone = ?)',
        [$login, $login]
    );

    if (!$user || !verifyPassword($password, $user['password_hash'])) return false;

    if (!empty($user['is_banned']) || !empty($user['is_locked'])) {
        return false;
    }

    if (($user['role'] ?? '') === 'seller') {
        qb_apply_seller_downgrade_schema();
        $user = qb_maybe_downgrade_inactive_seller($user);
        if (qb_has_column('sellers', 'approval_status')) {
            $seller = db()->fetchOne(
                'SELECT approval_status, approval_note FROM sellers WHERE app_user_id = ? LIMIT 1',
                [(int) ($user['id'] ?? 0)]
            );
            $approvalStatus = strtolower((string) ($seller['approval_status'] ?? 'approved'));
            if ($approvalStatus !== 'approved') {
                $msg = 'Seller account is pending admin approval.';
                if ($approvalStatus === 'rejected') {
                    $msg = 'Seller account request was rejected by admin. Update your profile details and contact support.';
                }
                $note = trim((string) ($seller['approval_note'] ?? ''));
                if ($note !== '') {
                    $msg .= ' Note: ' . $note;
                }
                qb_auth_set_last_error($msg);
                return false;
            }
        }
    }

    startSession();
    session_regenerate_id(true);
    $_SESSION['app_user_id'] = (int)$user['id'];
    $_SESSION['app_role']    = $user['role'];
    $_SESSION['app_name']    = $user['display_name'];
    $_SESSION['login_time']  = time();
    $_SESSION['qb_user_disabled'] = ((int)($user['is_active'] ?? 1) === 0) ? 1 : 0;

    return true;
}

function qb_redirect_after_login(): void {
    $role = $_SESSION['app_role'] ?? '';
    $map  = [
        'super_admin' => '/admin/dashboard.php',
        'organizer'   => '/organizer/dashboard.php',
        'co_organizer' => '/organizer/dashboard.php',
        'seller'      => '/seller/dashboard.php',
        'buyer'       => '/buyer/home.php',
        'gatekeeper'  => '/gatekeeper/dashboard.php',
    ];
    $path = $map[$role] ?? '/login.php';
    header('Location: ' . APP_URL . $path);
    exit;
}

function logoutApp(): void {
    startSession();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function appCurrentUser(): ?array {
    if (empty($_SESSION['app_user_id'])) return null;
    return db()->fetchOne('SELECT * FROM app_users WHERE id = ?', [(int)$_SESSION['app_user_id']]);
}

function appRole(): ?string {
    return $_SESSION['app_role'] ?? null;
}
