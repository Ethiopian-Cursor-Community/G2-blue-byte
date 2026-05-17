<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/fraud.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/upload_helpers.php';
require_once __DIR__ . '/qb_features.php';
require_once __DIR__ . '/qb_promo_posts.php';
require_once __DIR__ . '/qb_flash_sales.php';
require_once __DIR__ . '/qb_product_pricing.php';
require_once __DIR__ . '/qb_leaderboards.php';
require_once __DIR__ . '/qb_catalog.php';
require_once __DIR__ . '/qb_rate_limit.php';
require_once __DIR__ . '/role_request_helpers.php';
require_once __DIR__ . '/moderation_history.php';
require_once __DIR__ . '/event_approval.php';
require_once __DIR__ . '/chapa_service.php';

/** Public URL for app_users.avatar, or null if none. */
function qb_avatar_url(?array $user): ?string {
    if (!$user || empty($user['avatar'])) {
        return null;
    }
    return qb_public_upload_url($user['avatar']);
}

/**
 * Seller compliance fields on app_users + sellers + role request metadata.
 */
function qb_apply_seller_compliance_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('qb_has_column')) {
        return;
    }
    try {
        if (qb_table_exists('app_users')) {
            $adds = [
                'seller_tin_no VARCHAR(64) NULL',
                'seller_license_no VARCHAR(128) NULL',
                'seller_national_id_fan_no VARCHAR(128) NULL',
                'seller_legal_confirmed TINYINT(1) NOT NULL DEFAULT 0',
                'role_request_tin_no VARCHAR(64) NULL',
                'role_request_license_no VARCHAR(128) NULL',
                'role_request_national_id_fan_no VARCHAR(128) NULL',
                'role_request_legal_confirmed TINYINT(1) NOT NULL DEFAULT 0',
                'role_request_stall_image VARCHAR(255) NULL',
            ];
            foreach ($adds as $colDef) {
                $parts = preg_split('/\s+/', trim($colDef));
                $col = (string) ($parts[0] ?? '');
                if ($col !== '' && !qb_has_column('app_users', $col)) {
                    db()->execute('ALTER TABLE app_users ADD COLUMN ' . $colDef);
                }
            }
        }
        if (qb_table_exists('sellers')) {
            $sellerAdds = [
                'tin_no VARCHAR(64) NULL',
                'license_no VARCHAR(128) NULL',
                'national_id_fan_no VARCHAR(128) NULL',
                'legal_confirmed TINYINT(1) NOT NULL DEFAULT 0',
                "approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'",
                'approval_submitted_at DATETIME NULL',
                'approval_reviewed_at DATETIME NULL',
                'approval_reviewed_by INT NULL',
                'approval_note VARCHAR(255) NULL',
                'stall_image VARCHAR(255) NULL',
            ];
            foreach ($sellerAdds as $colDef) {
                $parts = preg_split('/\s+/', trim($colDef));
                $col = (string) ($parts[0] ?? '');
                if ($col !== '' && !qb_has_column('sellers', $col)) {
                    db()->execute('ALTER TABLE sellers ADD COLUMN ' . $colDef);
                }
            }
            if (qb_has_column('sellers', 'approval_status') && qb_has_column('sellers', 'approval_submitted_at') && qb_has_column('sellers', 'approval_reviewed_at')) {
                // Legacy sellers should stay active; only newly submitted sellers remain pending.
                db()->execute(
                    "UPDATE sellers
                     SET approval_status = 'approved'
                     WHERE approval_status = 'pending'
                       AND approval_submitted_at IS NULL
                       AND approval_reviewed_at IS NULL"
                );
            }
        }
    } catch (Throwable $e) {
        // Non-fatal; pages continue even if migration cannot run here.
    }
}

/**
 * Event exception controls + free product support.
 */
function qb_apply_event_special_access_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (qb_table_exists('event_participants')) {
            $epAdds = [
                "participant_type ENUM('standard_seller','guest_seller','service_booth','sponsor_booth') NOT NULL DEFAULT 'standard_seller'",
                "category_enforcement ENUM('strict','bypass') NOT NULL DEFAULT 'strict'",
                "price_policy ENUM('normal','free_only','mixed') NOT NULL DEFAULT 'normal'",
                "checkout_policy ENUM('allow_checkout','display_only') NOT NULL DEFAULT 'allow_checkout'",
                "visibility_badge VARCHAR(64) NULL",
                "application_visibility_mode ENUM('selected','all') NOT NULL DEFAULT 'selected'",
            ];
            foreach ($epAdds as $colDef) {
                $parts = preg_split('/\s+/', trim($colDef));
                $col = (string) ($parts[0] ?? '');
                if ($col !== '' && !qb_has_column('event_participants', $col)) {
                    db()->execute('ALTER TABLE event_participants ADD COLUMN ' . $colDef);
                }
            }
        }
        if (qb_table_exists('products')) {
            $pAdds = [
                "is_free_item TINYINT(1) NOT NULL DEFAULT 0",
                "free_label VARCHAR(80) NULL",
            ];
            foreach ($pAdds as $colDef) {
                $parts = preg_split('/\s+/', trim($colDef));
                $col = (string) ($parts[0] ?? '');
                if ($col !== '' && !qb_has_column('products', $col)) {
                    db()->execute('ALTER TABLE products ADD COLUMN ' . $colDef);
                }
            }
        }
    } catch (Throwable $e) {
        // Non-fatal on mixed schemas.
    }
}

/**
 * Lightweight key-value system settings storage.
 */
function qb_apply_system_settings_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->execute(
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // non-fatal
    }
}

function qb_setting_get(string $key, ?string $default = null): ?string {
    qb_apply_system_settings_schema();
    try {
        $row = db()->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$key]);
        if ($row && array_key_exists('setting_value', $row)) {
            return $row['setting_value'] !== null ? (string) $row['setting_value'] : null;
        }
    } catch (Throwable $e) {
    }
    return $default;
}

function qb_setting_get_float(string $key, float $default): float {
    $raw = qb_setting_get($key, null);
    if ($raw === null || $raw === '') {
        return $default;
    }
    return (float) $raw;
}

function qb_setting_get_int(string $key, int $default): int {
    $raw = qb_setting_get($key, null);
    if ($raw === null || $raw === '') {
        return $default;
    }
    return (int) $raw;
}

function qb_setting_get_bool(string $key, bool $default): bool {
    $raw = qb_setting_get($key, null);
    if ($raw === null || $raw === '') {
        return $default;
    }
    $v = strtolower(trim($raw));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function qb_setting_set(string $key, ?string $value): void {
    qb_apply_system_settings_schema();
    db()->execute(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $value]
    );
}

function qb_apply_observability_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->execute(
            "CREATE TABLE IF NOT EXISTS qb_event_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_key VARCHAR(120) NOT NULL,
                app_user_id INT NULL,
                actor_role VARCHAR(40) NULL,
                target_type VARCHAR(50) NULL,
                target_id VARCHAR(80) NULL,
                meta_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_qb_event_logs_key_time (event_key, created_at),
                KEY idx_qb_event_logs_user_time (app_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // non-fatal
    }
}

function qb_track_event(string $eventKey, array $meta = [], ?int $appUserId = null, ?string $targetType = null, ?string $targetId = null): void {
    if ($eventKey === '') {
        return;
    }
    qb_apply_observability_schema();
    $uid = $appUserId;
    if ($uid === null) {
        startSession();
        $uid = isset($_SESSION['app_user_id']) ? (int) $_SESSION['app_user_id'] : null;
    }
    $role = function_exists('currentRole') ? (string) currentRole() : '';
    try {
        db()->execute(
            "INSERT INTO qb_event_logs (event_key, app_user_id, actor_role, target_type, target_id, meta_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $eventKey,
                $uid,
                $role !== '' ? $role : null,
                $targetType,
                $targetId,
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    } catch (Throwable $e) {
    }
}

function qb_apply_seller_product_slot_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    qb_apply_system_settings_schema();
    try {
        db()->execute(
            "CREATE TABLE IF NOT EXISTS seller_product_slot_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                intent_id VARCHAR(64) NOT NULL UNIQUE,
                app_user_id INT NOT NULL,
                seller_id INT NOT NULL,
                slots_qty INT NOT NULL DEFAULT 0,
                unit_fee_etb DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                paid_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_spsp_seller_status (seller_id, status),
                KEY idx_spsp_user_status (app_user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

function qb_seller_product_slot_free_limit(): int {
    qb_apply_seller_product_slot_schema();
    return max(0, qb_setting_get_int('seller_product_free_limit', 7));
}

function qb_seller_product_slot_fee_etb(): float {
    qb_apply_seller_product_slot_schema();
    return max(0.0, qb_setting_get_float('seller_product_slot_fee_etb', 25.0));
}

function qb_apply_transaction_fee_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!qb_table_exists('transactions') || !function_exists('qb_has_column')) {
        return;
    }
    try {
        if (!qb_has_column('transactions', 'admin_fee_pct')) {
            db()->execute("ALTER TABLE transactions ADD COLUMN admin_fee_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER total_amount");
        }
        if (!qb_has_column('transactions', 'admin_fee_amount')) {
            db()->execute("ALTER TABLE transactions ADD COLUMN admin_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER admin_fee_pct");
        }
        if (!qb_has_column('transactions', 'seller_net_amount')) {
            db()->execute("ALTER TABLE transactions ADD COLUMN seller_net_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER admin_fee_amount");
        }
    } catch (Throwable $e) {
        // Non-fatal migration safety.
    }
}

function qb_admin_tx_fee_pct(): float {
    qb_apply_system_settings_schema();
    $pct = qb_setting_get_float('tx_admin_fee_pct', 5.0);
    if ($pct < 0) {
        return 0.0;
    }
    if ($pct > 100) {
        return 100.0;
    }
    return round($pct, 2);
}

/**
 * @return array{fee_pct:float,fee_amount:float,seller_net:float}
 */
function qb_tx_fee_breakdown(float $grossAmount): array {
    $gross = max(0.0, round($grossAmount, 2));
    $pct = qb_admin_tx_fee_pct();
    $fee = round($gross * ($pct / 100), 2);
    $net = round(max(0.0, $gross - $fee), 2);
    return [
        'fee_pct' => $pct,
        'fee_amount' => $fee,
        'seller_net' => $net,
    ];
}

function qb_seller_product_slot_paid_total(int $sellerId): int {
    qb_apply_seller_product_slot_schema();
    if ($sellerId <= 0) {
        return 0;
    }
    $row = db()->fetchOne(
        "SELECT COALESCE(SUM(slots_qty),0) AS c
         FROM seller_product_slot_payments
         WHERE seller_id = ? AND status = 'paid'",
        [$sellerId]
    );
    return max(0, (int) ($row['c'] ?? 0));
}

function qb_seller_product_slot_usage(int $sellerId): int {
    if ($sellerId <= 0) {
        return 0;
    }
    $row = db()->fetchOne('SELECT COUNT(*) AS c FROM products WHERE seller_id = ?', [$sellerId]);
    return max(0, (int) ($row['c'] ?? 0));
}

function qb_seller_product_slot_remaining(int $sellerId): int {
    $cap = qb_seller_product_slot_free_limit() + qb_seller_product_slot_paid_total($sellerId);
    return max(0, $cap - qb_seller_product_slot_usage($sellerId));
}

// ── Session ──────────────────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('QRBAZAAR_SESS');
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return !empty($_SESSION['app_user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return db()->fetchOne('SELECT * FROM app_users WHERE id = ?', [(int)$_SESSION['app_user_id']]);
}

function currentRole(): string {
    return $_SESSION['app_role'] ?? '';
}

function requireLogin(string $redirect = ''): void {
    if (!isLoggedIn()) {
        $uri = $redirect ?: $_SERVER['REQUEST_URI'];
        header('Location: ' . APP_URL . '/login.php?redirect=' . urlencode($uri));
        exit;
    }
    qb_sync_disabled_session_state();
    qb_block_disabled_write_actions();
}

function qb_user_is_disabled(?array $user = null): bool {
    $u = $user ?? currentUser();
    if (!$u) return false;
    return ((int)($u['is_active'] ?? 1) === 0);
}

function qb_sync_disabled_session_state(): void {
    $u = currentUser();
    $_SESSION['qb_user_disabled'] = qb_user_is_disabled($u) ? 1 : 0;
}

function qb_is_disabled_session(): bool {
    return !empty($_SESSION['qb_user_disabled']);
}

function qb_block_disabled_write_actions(): void {
    if (!qb_is_disabled_session()) return;
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;

    $isApi = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;
    if ($isApi) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Your account is disabled. Read-only access only.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $back = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/login.php?error=readonly');
    $sep = strpos($back, '?') === false ? '?' : '&';
    header('Location: ' . $back . $sep . 'error=readonly');
    exit;
}

function requireAdmin(): void {
    requireLogin();
    if (currentRole() !== 'super_admin') {
        header('Location: ' . APP_URL . '/login.php?portal=admin&error=access');
        exit;
    }
}

function requireOrganizer(): void {
    requireLogin();
    if (!in_array(currentRole(), ['super_admin', 'organizer', 'co_organizer'], true)) {
        header('Location: ' . APP_URL . '/login.php?portal=organizer&error=access');
        exit;
    }
}

function requireSeller(): void {
    requireLogin();
    if (!in_array(currentRole(), ['super_admin', 'seller'])) {
        header('Location: ' . APP_URL . '/login.php?portal=seller&error=access');
        exit;
    }
    if (currentRole() === 'seller' && qb_has_column('sellers', 'approval_status')) {
        $seller = getCurrentSeller();
        $status = strtolower((string) ($seller['approval_status'] ?? 'approved'));
        if ($status !== 'approved') {
            header('Location: ' . APP_URL . '/buyer/home.php?seller_approval=' . urlencode($status));
            exit;
        }
    }
}

function requireBuyer(): void {
    requireLogin();
    if (!in_array(currentRole(), ['super_admin', 'buyer'])) {
        header('Location: ' . APP_URL . '/login.php?portal=buyer&error=access');
        exit;
    }
}

/** Gate portal: gatekeeper role only (assignment checked per page). */
function requireGatekeeper(): void {
    requireLogin();
    if (currentRole() !== 'gatekeeper') {
        header('Location: ' . APP_URL . '/login.php?portal=gatekeeper&error=access');
        exit;
    }
}

/** Buyer routes that are also used when a seller shops another stall (scan → vendor → receipt). */
function requireBuyerOrSeller(): void {
    requireLogin();
    if (!in_array(currentRole(), ['super_admin', 'buyer', 'seller'])) {
        header('Location: ' . APP_URL . '/login.php?portal=buyer&error=access');
        exit;
    }
}

/** After purchase / scan: seller returns to seller dashboard, buyer to buyer home. */
function qb_shopper_home_url(): string {
    if (function_exists('currentRole') && currentRole() === 'seller') {
        return APP_URL . '/seller/dashboard.php';
    }

    return APP_URL . '/buyer/home.php';
}

// Legacy gate aliases
function qb_require_seller_portal(): void { requireSeller(); }

// ── Device Detection ─────────────────────────────────────────
function isMobileDevice(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool)preg_match('/Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i', $ua);
}

// ── Current Seller (from sessions) ───────────────────────────
function getCurrentSeller(): ?array {
    if (!isLoggedIn()) return null;
    $uid = (int)$_SESSION['app_user_id'];
    return db()->fetchOne('SELECT * FROM sellers WHERE app_user_id = ?', [$uid]);
}

// ── Password ─────────────────────────────────────────────────
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

// ── UID Generator ─────────────────────────────────────────────
function generateUID(string $prefix = 'SEL'): string {
    return strtoupper($prefix . bin2hex(random_bytes(4)));
}

function generateTxId(): string {
    return 'TXN' . date('Ymd') . strtoupper(bin2hex(random_bytes(3)));
}

function generateTicketCode(): string {
    return 'TKT' . strtoupper(bin2hex(random_bytes(8)));
}

// ── QR Signing ────────────────────────────────────────────────
function signQRPayload(int $sellerId, string $uid, string $secret): string {
    $payload = $sellerId . '|' . $uid . '|' . $secret;
    return hash_hmac('sha256', $payload, QR_SECRET);
}

function signQRPayloadTimed(int $sellerId, string $uid, string $secret, int $unixTs): string {
    $payload = $sellerId . '|' . $uid . '|' . $unixTs . '|' . $secret;
    return hash_hmac('sha256', $payload, QR_SECRET);
}

function verifyQRTimed(int $sellerId, string $uid, string $sig, int $unixTs): bool {
    if ($unixTs <= 0 || abs(time() - $unixTs) > QR_TTL_SECONDS) return false;
    $seller = db()->fetchOne('SELECT qr_secret FROM sellers WHERE id = ? AND uid = ?', [$sellerId, $uid]);
    if (!$seller) return false;
    $expected = signQRPayloadTimed($sellerId, $uid, $seller['qr_secret'], $unixTs);
    return hash_equals($expected, $sig);
}

// ── Trust Score ───────────────────────────────────────────────
function computeTrustScore(int $sellerId): int {
    $seller = db()->fetchOne('SELECT created_at FROM sellers WHERE id = ?', [$sellerId]);
    if (!$seller) return 0;

    $ratingData = db()->fetchOne('SELECT AVG(stars) as avg_stars, COUNT(*) as total FROM ratings WHERE seller_id = ?', [$sellerId]);
    $txCount    = db()->fetchOne("SELECT COUNT(*) as cnt FROM transactions WHERE seller_id = ? AND payment_status = 'completed'", [$sellerId]);

    $avgRating    = floatval($ratingData['avg_stars'] ?? 0);
    $totalRatings = intval($ratingData['total'] ?? 0);
    $completedTx  = intval($txCount['cnt'] ?? 0);
    $ageInDays    = max(1, (time() - strtotime($seller['created_at'])) / 86400);
    $ageFactor    = min(1.0, $ageInDays / 90);

    $score = intval(($avgRating / 5.0) * 50 + min(30, $completedTx * 2) + $ageFactor * 20);

    if ($avgRating < 2.0 && $totalRatings >= 5) {
        db()->execute('UPDATE sellers SET is_flagged = 1 WHERE id = ?', [$sellerId]);
    }

    return min(100, $score);
}

function getTrustBadge(int $score): array {
    if ($score >= 85) return ['label' => 'Top Rated',      'class' => 'badge-gold',  'icon_key' => 'star'];
    if ($score >= 70) return ['label' => 'Verified Seller','class' => 'badge-green', 'icon_key' => 'check'];
    if ($score >= 40) return ['label' => 'Trusted',        'class' => 'badge-blue',  'icon_key' => 'shield'];
    return             ['label' => 'New Seller',            'class' => 'badge-gray',  'icon_key' => 'spark'];
}

function computeCreditScore(int $sellerId): int {
    $txData     = db()->fetchOne("SELECT COUNT(*) as cnt, SUM(total_amount) as total FROM transactions WHERE seller_id = ? AND payment_status='completed'", [$sellerId]);
    $ratingData = db()->fetchOne('SELECT AVG(stars) as avg FROM ratings WHERE seller_id = ?', [$sellerId]);
    $recentTx   = db()->fetchOne("SELECT COUNT(*) as cnt FROM transactions WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$sellerId]);

    $totalTx   = intval($txData['cnt'] ?? 0);
    $avgRating = floatval($ratingData['avg'] ?? 0);
    $recent    = intval($recentTx['cnt'] ?? 0);

    return min(100, intval(min(40, $recent * 5) + ($avgRating / 5.0) * 35 + min(25, $totalTx * 2)));
}

function getCreditStatus(int $score): array {
    if ($score >= 75) return ['label' => 'Eligible for Micro-Loan', 'class' => 'credit-eligible', 'icon_key' => 'building', 'amount' => 'Up to 5,000 ETB'];
    if ($score >= 45) return ['label' => 'Building Credit',         'class' => 'credit-building', 'icon_key' => 'chart',    'amount' => null];
    return             ['label' => 'Not Yet Eligible',              'class' => 'credit-none',     'icon_key' => 'calendar', 'amount' => null];
}

// ── JSON Helpers ──────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $code = 400): never {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function jsonSuccess(array $data = [], string $message = 'OK'): never {
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

// ── Input Sanitization ────────────────────────────────────────
function sanitize(mixed $val): string {
    return strip_tags(trim((string)$val));
}

/**
 * Plain text for database storage (titles, messages, marquee). Strips HTML tags; does not
 * HTML-escape — escape once at output with htmlspecialchars() or qb_esc_html().
 */
function qb_sanitize_plain_text(string $val, int $maxLen = 0): string {
    $s = strip_tags(trim($val));
    if ($maxLen > 0) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s) > $maxLen) {
                $s = mb_substr($s, 0, $maxLen);
            }
        } elseif (strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
        }
    }

    return $s;
}

/**
 * Escape for HTML text/attributes. Decodes legacy stored entities first so text saved with
 * sanitize() (e.g. &#039;) displays as a normal apostrophe.
 */
function qb_esc_html(?string $stored): string {
    $s = (string) ($stored ?? '');
    if ($s === '') {
        return '';
    }
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Normalize admin theme color to #rrggbb for CSS and inputs. */
function qb_theme_hex(?string $raw, string $fallback = '#C48A32'): string {
    $s = trim((string) ($raw ?? ''));
    if ($s === '') {
        return $fallback;
    }
    if ($s[0] !== '#') {
        $s = '#' . $s;
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $s, $m)) {
        $h = $m[1];

        return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $s, $m)) {
        return '#' . strtolower($m[1]);
    }

    return $fallback;
}

function getPost(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? sanitize($_POST[$key]) : $default;
}

function qb_csrf_token(): string {
    startSession();
    if (empty($_SESSION['qb_csrf'])) {
        $_SESSION['qb_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['qb_csrf'];
}

function qb_csrf_verify(?string $token): bool {
    startSession();
    return is_string($token) && $token !== ''
        && isset($_SESSION['qb_csrf'])
        && hash_equals($_SESSION['qb_csrf'], $token);
}

function getJson(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

// ── Bazar / Event Helpers ─────────────────────────────────────
function qb_sync_event_statuses(): void {
    static $done = false;
    if ($done || !qb_table_exists('bazar_events')) {
        return;
    }
    $done = true;
    try {
        // Start window reached: published -> live.
        db()->execute(
            "UPDATE bazar_events
             SET status = 'live'
             WHERE status = 'published'
               AND event_start IS NOT NULL
               AND event_start <= NOW()
               AND (event_end IS NULL OR event_end > NOW())"
        );
        // Event ended: published/live -> ended.
        db()->execute(
            "UPDATE bazar_events
             SET status = 'ended'
             WHERE status IN ('published','live')
               AND event_end IS NOT NULL
               AND event_end <= NOW()"
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

function qb_event_overlay_summary(int $eventId): array {
    $blank = [
        'top_seller' => '',
        'products_sold' => 0,
        'orders_completed' => 0,
        'payments_completed' => 0.0,
    ];
    if ($eventId <= 0) {
        return $blank;
    }
    try {
        $top = db()->fetchOne(
            "SELECT s.market_name, COUNT(t.id) AS n
             FROM transactions t
             LEFT JOIN sellers s ON s.id = t.seller_id
             WHERE t.event_id = ? AND t.payment_status = 'completed'
             GROUP BY t.seller_id, s.market_name
             ORDER BY n DESC, t.seller_id ASC
             LIMIT 1",
            [$eventId]
        );
        $sold = db()->fetchOne(
            "SELECT COALESCE(SUM(ti.quantity),0) AS q
             FROM transaction_items ti
             INNER JOIN transactions t ON t.id = ti.transaction_id
             WHERE t.event_id = ? AND t.payment_status = 'completed'",
            [$eventId]
        );
        $orders = db()->fetchOne(
            "SELECT COUNT(*) AS c
             FROM transactions
             WHERE event_id = ? AND payment_status = 'completed'",
            [$eventId]
        );
        $paid = db()->fetchOne(
            "SELECT COALESCE(SUM(total_amount),0) AS a
             FROM transactions
             WHERE event_id = ? AND payment_status = 'completed'",
            [$eventId]
        );
        $blank['top_seller'] = trim((string) ($top['market_name'] ?? ''));
        $blank['products_sold'] = (int) ($sold['q'] ?? 0);
        $blank['orders_completed'] = (int) ($orders['c'] ?? 0);
        $blank['payments_completed'] = (float) ($paid['a'] ?? 0);
    } catch (Throwable $e) {
    }
    return $blank;
}

/**
 * Bulk event overlay summary to avoid N+1 queries on heavy event lists.
 *
 * @param array<int, int> $eventIds
 * @return array<int, array{top_seller:string,products_sold:int,orders_completed:int,payments_completed:float}>
 */
function qb_event_overlay_summary_bulk(array $eventIds): array {
    $ids = [];
    foreach ($eventIds as $id) {
        $v = (int) $id;
        if ($v > 0) {
            $ids[$v] = true;
        }
    }
    if ($ids === []) {
        return [];
    }
    $eventIds = array_keys($ids);
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $out = [];
    foreach ($eventIds as $eid) {
        $out[$eid] = [
            'top_seller' => '',
            'products_sold' => 0,
            'orders_completed' => 0,
            'payments_completed' => 0.0,
        ];
    }
    try {
        $topRows = db()->fetchAll(
            "SELECT x.event_id, x.market_name
             FROM (
               SELECT t.event_id,
                      COALESCE(s.market_name, '') AS market_name,
                      COUNT(t.id) AS n,
                      ROW_NUMBER() OVER (PARTITION BY t.event_id ORDER BY COUNT(t.id) DESC, t.seller_id ASC) AS rn
               FROM transactions t
               LEFT JOIN sellers s ON s.id = t.seller_id
               WHERE t.payment_status = 'completed'
                 AND t.event_id IN ($placeholders)
               GROUP BY t.event_id, t.seller_id, s.market_name
             ) x
             WHERE x.rn = 1",
            $eventIds
        );
        foreach ($topRows as $r) {
            $eid = (int) ($r['event_id'] ?? 0);
            if ($eid > 0 && isset($out[$eid])) {
                $out[$eid]['top_seller'] = trim((string) ($r['market_name'] ?? ''));
            }
        }
        $soldRows = db()->fetchAll(
            "SELECT t.event_id, COALESCE(SUM(ti.quantity),0) AS q
             FROM transactions t
             INNER JOIN transaction_items ti ON ti.transaction_id = t.id
             WHERE t.payment_status = 'completed'
               AND t.event_id IN ($placeholders)
             GROUP BY t.event_id",
            $eventIds
        );
        foreach ($soldRows as $r) {
            $eid = (int) ($r['event_id'] ?? 0);
            if ($eid > 0 && isset($out[$eid])) {
                $out[$eid]['products_sold'] = (int) ($r['q'] ?? 0);
            }
        }
        $orderRows = db()->fetchAll(
            "SELECT event_id, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS a
             FROM transactions
             WHERE payment_status = 'completed'
               AND event_id IN ($placeholders)
             GROUP BY event_id",
            $eventIds
        );
        foreach ($orderRows as $r) {
            $eid = (int) ($r['event_id'] ?? 0);
            if ($eid > 0 && isset($out[$eid])) {
                $out[$eid]['orders_completed'] = (int) ($r['c'] ?? 0);
                $out[$eid]['payments_completed'] = (float) ($r['a'] ?? 0);
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function getActiveEvents(): array {
    qb_sync_event_statuses();
    try {
        if (qb_table_exists('bazar_event_organizers')) {
            return db()->fetchAll("
                SELECT e.*,
                    u.display_name AS organizer_name,
                    (SELECT GROUP_CONCAT(DISTINCT u2.display_name ORDER BY u2.display_name SEPARATOR ', ')
                     FROM bazar_event_organizers eo
                     INNER JOIN app_users u2 ON u2.id = eo.app_user_id
                     WHERE eo.event_id = e.id) AS co_organizer_names
                FROM bazar_events e
                LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
                WHERE e.status IN ('published','live','ended')
                ORDER BY CASE e.status WHEN 'live' THEN 0 WHEN 'published' THEN 1 WHEN 'ended' THEN 2 ELSE 3 END, e.event_start ASC
            ");
        }
        return db()->fetchAll("
            SELECT e.*, u.display_name AS organizer_name
            FROM bazar_events e
            LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
            WHERE e.status IN ('published','live','ended')
            ORDER BY CASE e.status WHEN 'live' THEN 0 WHEN 'published' THEN 1 WHEN 'ended' THEN 2 ELSE 3 END, e.event_start ASC
        ");
    } catch (Exception $e) { return []; }
}

function getEventById(int $id): ?array {
    try {
        return db()->fetchOne('SELECT * FROM bazar_events WHERE id = ?', [$id]);
    } catch (Exception $e) { return null; }
}

function qb_apply_event_ticket_pricing_schema(): void {
    if (!qb_table_exists('bazar_events') || !qb_table_exists('tickets')) {
        return;
    }
    if (!qb_has_column('bazar_events', 'standard_ticket_price_etb')) {
        db()->execute("ALTER TABLE bazar_events ADD COLUMN standard_ticket_price_etb DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER ticket_sales_end");
    }
    if (!qb_has_column('bazar_events', 'premium_ticket_price_etb')) {
        db()->execute("ALTER TABLE bazar_events ADD COLUMN premium_ticket_price_etb DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER standard_ticket_price_etb");
    }
    if (!qb_has_column('bazar_events', 'primary_rules')) {
        db()->execute("ALTER TABLE bazar_events ADD COLUMN primary_rules TEXT NULL AFTER notes");
    }
    if (!qb_has_column('tickets', 'ticket_type')) {
        db()->execute("ALTER TABLE tickets ADD COLUMN ticket_type VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER ticket_code");
    }
    if (!qb_has_column('tickets', 'ticket_price_etb')) {
        db()->execute("ALTER TABLE tickets ADD COLUMN ticket_price_etb DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER ticket_type");
    }
}

function qb_event_stats(int $eventId): array {
    try {
        $p   = db()->fetchOne('SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ?', [$eventId]);
        $b   = db()->fetchOne("SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ? AND role_in_event='buyer'", [$eventId]);
        $s   = db()->fetchOne("SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ? AND role_in_event='seller'", [$eventId]);
        $rev = db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM transactions WHERE event_id = ? AND payment_status='completed'", [$eventId]);
        $txc = db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE event_id = ? AND payment_status='completed'", [$eventId]);
        return [
            'participants' => (int)($p['c'] ?? 0),
            'buyers'       => (int)($b['c'] ?? 0),
            'sellers'      => (int)($s['c'] ?? 0),
            'revenue'      => (float)($rev['t'] ?? 0),
            'tx_count'     => (int)($txc['c'] ?? 0),
        ];
    } catch (Exception $e) { return ['participants'=>0,'buyers'=>0,'sellers'=>0,'revenue'=>0,'tx_count'=>0]; }
}

function qb_tickets_live_for_event(int $eventId): bool {
    try {
        $e = db()->fetchOne('SELECT ticket_sales_start, ticket_sales_end FROM bazar_events WHERE id = ?', [$eventId]);
        if (!$e) return false;
        $now = time();
        return strtotime($e['ticket_sales_start']) <= $now && strtotime($e['ticket_sales_end']) >= $now;
    } catch (Exception $e2) { return false; }
}

function qb_seller_suggestions_table_exists(): bool {
    return false; // Not in new schema
}

// ── Geo-fence check ───────────────────────────────────────────
function isInsideGeofence(float $lat, float $lng, int $eventId): array {
    $event = db()->fetchOne('SELECT lat, lng, radius_meters FROM bazar_events WHERE id = ?', [$eventId]);
    if (!$event || !$event['lat']) return ['inside' => true, 'distance' => 0]; // no geo set

    $R = 6371000; // Earth radius in meters
    $dLat = deg2rad($lat - $event['lat']);
    $dLng = deg2rad($lng - $event['lng']);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($event['lat']))*cos(deg2rad($lat))*sin($dLng/2)*sin($dLng/2);
    $distance = $R * 2 * atan2(sqrt($a), sqrt(1-$a));

    return [
        'inside'   => $distance <= (int)$event['radius_meters'],
        'distance' => (int)$distance
    ];
}

function qb_event_mode_table_exists(): bool {
    return function_exists('qb_table_exists') && qb_table_exists('user_event_mode');
}

function qb_event_mode_set(int $appUserId, int $eventId, string $modeSource = 'manual'): bool {
    if ($appUserId <= 0 || $eventId <= 0 || !qb_event_mode_table_exists()) {
        return false;
    }
    try {
        db()->execute(
            "INSERT INTO user_event_mode (app_user_id, event_id, mode_source, activated_at, updated_at)
             VALUES (?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), mode_source = VALUES(mode_source), updated_at = NOW()",
            [$appUserId, $eventId, $modeSource]
        );
        $_SESSION['qb_event_mode_event_id'] = $eventId;
        $_SESSION['qb_event_mode_source'] = $modeSource;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function qb_event_mode_get(int $appUserId): ?array {
    if ($appUserId <= 0) {
        return null;
    }
    if (!empty($_SESSION['qb_event_mode_event_id'])) {
        return [
            'event_id' => (int) $_SESSION['qb_event_mode_event_id'],
            'mode_source' => (string) ($_SESSION['qb_event_mode_source'] ?? 'session'),
        ];
    }
    if (!qb_event_mode_table_exists()) {
        return null;
    }
    try {
        $r = db()->fetchOne(
            'SELECT event_id, mode_source FROM user_event_mode WHERE app_user_id = ? LIMIT 1',
            [$appUserId]
        );
        if (!$r) {
            return null;
        }
        $_SESSION['qb_event_mode_event_id'] = (int) ($r['event_id'] ?? 0);
        $_SESSION['qb_event_mode_source'] = (string) ($r['mode_source'] ?? 'db');
        return [
            'event_id' => (int) ($r['event_id'] ?? 0),
            'mode_source' => (string) ($r['mode_source'] ?? 'db'),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function qb_apply_seller_gate_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->execute(
            "CREATE TABLE IF NOT EXISTS seller_gate_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seller_id INT NOT NULL,
                event_id INT NOT NULL,
                gatekeeper_app_user_id INT NULL,
                gate_pass_no VARCHAR(48) NULL,
                qr_unlocked TINYINT(1) NOT NULL DEFAULT 0,
                scanned_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_seller_event_gate (seller_id, event_id),
                KEY idx_seller_gate_event (event_id, qr_unlocked),
                KEY idx_seller_gate_seller (seller_id, qr_unlocked)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // non-fatal
    }
}

function qb_seller_gate_pass_no(int $sellerId, int $eventId): string {
    return 'SG-' . $eventId . '-' . str_pad((string) $sellerId, 4, '0', STR_PAD_LEFT);
}

function qb_seller_entry_qr_payload(int $sellerId, string $sellerUid, int $eventId, string $sellerQrSecret): string {
    $ts = time();
    $seed = $sellerId . '|' . $sellerUid . '|' . $eventId . '|' . $ts;
    $sig = hash_hmac('sha256', $seed, APP_KEY . '|' . $sellerQrSecret);
    return 'SE|' . $sellerId . '|' . $sellerUid . '|' . $eventId . '|' . $ts . '|' . $sig;
}

/**
 * @return array{ok:bool,seller_id:int,event_id:int,error?:string}
 */
function qb_parse_seller_entry_qr(string $raw): array {
    $parts = explode('|', trim($raw));
    if (count($parts) !== 6 || strtoupper((string) ($parts[0] ?? '')) !== 'SE') {
        return ['ok' => false, 'seller_id' => 0, 'event_id' => 0, 'error' => 'Invalid seller entry QR format'];
    }
    $sellerId = (int) ($parts[1] ?? 0);
    $sellerUid = (string) ($parts[2] ?? '');
    $eventId = (int) ($parts[3] ?? 0);
    $ts = (int) ($parts[4] ?? 0);
    $sig = (string) ($parts[5] ?? '');
    if ($sellerId <= 0 || $eventId <= 0 || $sellerUid === '' || $ts <= 0 || $sig === '') {
        return ['ok' => false, 'seller_id' => 0, 'event_id' => 0, 'error' => 'Invalid seller entry QR payload'];
    }
    if (abs(time() - $ts) > 86400) {
        return ['ok' => false, 'seller_id' => 0, 'event_id' => 0, 'error' => 'Seller entry QR expired'];
    }
    $seller = db()->fetchOne('SELECT id, uid, qr_secret FROM sellers WHERE id = ? LIMIT 1', [$sellerId]);
    if (!$seller || (string) ($seller['uid'] ?? '') !== $sellerUid) {
        return ['ok' => false, 'seller_id' => 0, 'event_id' => 0, 'error' => 'Seller not found'];
    }
    $seed = $sellerId . '|' . $sellerUid . '|' . $eventId . '|' . $ts;
    $expSig = hash_hmac('sha256', $seed, APP_KEY . '|' . (string) ($seller['qr_secret'] ?? ''));
    if (!hash_equals($expSig, $sig)) {
        return ['ok' => false, 'seller_id' => 0, 'event_id' => 0, 'error' => 'Invalid seller entry QR signature'];
    }
    return ['ok' => true, 'seller_id' => $sellerId, 'event_id' => $eventId];
}

function qb_seller_gate_unlock(int $sellerId, int $eventId, int $gatekeeperAppUserId): bool {
    qb_apply_seller_gate_schema();
    if ($sellerId <= 0 || $eventId <= 0) {
        return false;
    }
    $passNo = qb_seller_gate_pass_no($sellerId, $eventId);
    try {
        db()->execute(
            "INSERT INTO seller_gate_entries (seller_id, event_id, gatekeeper_app_user_id, gate_pass_no, qr_unlocked, scanned_at)
             VALUES (?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE gatekeeper_app_user_id = VALUES(gatekeeper_app_user_id), gate_pass_no = VALUES(gate_pass_no), qr_unlocked = 1, scanned_at = NOW()",
            [$sellerId, $eventId, $gatekeeperAppUserId > 0 ? $gatekeeperAppUserId : null, $passNo]
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function qb_seller_gate_is_unlocked(int $sellerId, int $eventId): bool {
    qb_apply_seller_gate_schema();
    if ($sellerId <= 0 || $eventId <= 0) {
        return false;
    }
    try {
        $r = db()->fetchOne(
            'SELECT qr_unlocked FROM seller_gate_entries WHERE seller_id = ? AND event_id = ? LIMIT 1',
            [$sellerId, $eventId]
        );
        return (int) ($r['qr_unlocked'] ?? 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function qb_cash_confirm_table_exists(): bool {
    return function_exists('qb_table_exists') && qb_table_exists('transaction_cash_confirms');
}

function qb_cash_confirm_status(int $transactionId): array {
    $blank = ['buyer_confirmed' => false, 'seller_confirmed' => false];
    if ($transactionId <= 0 || !qb_cash_confirm_table_exists()) {
        return $blank;
    }
    try {
        $r = db()->fetchOne(
            'SELECT buyer_confirmed_at, seller_confirmed_at FROM transaction_cash_confirms WHERE transaction_id = ?',
            [$transactionId]
        );
        if (!$r) {
            return $blank;
        }
        return [
            'buyer_confirmed' => !empty($r['buyer_confirmed_at']),
            'seller_confirmed' => !empty($r['seller_confirmed_at']),
        ];
    } catch (Throwable $e) {
        return $blank;
    }
}

function qb_cash_confirm_mark(int $transactionId, string $side): bool {
    if ($transactionId <= 0 || !qb_cash_confirm_table_exists()) {
        return false;
    }
    $col = $side === 'seller' ? 'seller_confirmed_at' : 'buyer_confirmed_at';
    try {
        db()->execute(
            "INSERT INTO transaction_cash_confirms (transaction_id, {$col}, created_at, updated_at)
             VALUES (?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE {$col} = COALESCE({$col}, NOW()), updated_at = NOW()",
            [$transactionId]
        );
        $s = qb_cash_confirm_status($transactionId);
        if (!empty($s['buyer_confirmed']) && !empty($s['seller_confirmed'])) {
            db()->execute(
                "UPDATE transactions SET payment_status = 'completed' WHERE id = ? AND payment_method = 'cash'",
                [$transactionId]
            );
            qb_audit_log('payment.cash.completed', 'transaction', (string) $transactionId, ['source' => 'cash_double_confirmation']);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * HTML attribute fragment: data-qb-event="…" for the centered event info dialog (double-click).
 *
 * @param array<string, string> $fields keys: name, status, venue, city, start, end, organizers, notes, products, attendance, leaderboard, products_sold, orders_completed, payments_completed
 */
function qb_event_dialog_data_attr(array $fields): string {
    $keys = ['name', 'status', 'venue', 'city', 'start', 'end', 'organizers', 'notes', 'products', 'attendance', 'leaderboard', 'products_sold', 'orders_completed', 'payments_completed'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = isset($fields[$k]) ? (string) $fields[$k] : '';
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return '';
    }

    return ' data-qb-event="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Event image URL with generated fallback so every event has a visual.
 *
 * @param array<string,mixed> $event
 */
function qb_event_image_url(array $event): string {
    $cover = trim((string) ($event['cover_image'] ?? ''));
    if ($cover !== '') {
        return qb_public_upload_url($cover);
    }
    $hay = strtolower(trim(
        (string) ($event['name'] ?? '') . ' ' .
        (string) ($event['notes'] ?? '') . ' ' .
        (string) ($event['venue'] ?? '') . ' ' .
        (string) ($event['city'] ?? '')
    ));
    $seed = abs(crc32((string) ($event['name'] ?? 'event') . '|' . (string) ($event['city'] ?? '')));
    $map = [
        'tech' => [
            'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=1200&q=70',
        ],
        'food' => [
            'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1200&q=70',
        ],
        'coffee' => [
            'https://images.unsplash.com/photo-1447933601403-0c6688de566e?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=70',
        ],
        'organic' => [
            'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=1200&q=70',
        ],
        'fashion' => [
            'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=1200&q=70',
        ],
        'home' => [
            'https://images.unsplash.com/photo-1484101403633-562f891dc89a?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=70',
        ],
        'family' => [
            'https://images.unsplash.com/photo-1511895426328-dc8714191300?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1511632765486-a01980e01a18?auto=format&fit=crop&w=1200&q=70',
        ],
        'market' => [
            'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=1200&q=70',
            'https://images.unsplash.com/photo-1534723452862-4c874018d66d?auto=format&fit=crop&w=1200&q=70',
        ],
    ];
    $pick = static function (array $arr, int $idx): string {
        return $arr[$idx % max(1, count($arr))];
    };

    if (str_contains($hay, 'tech') || str_contains($hay, 'digital') || str_contains($hay, 'electronics')) {
        return $pick($map['tech'], $seed);
    }
    if (str_contains($hay, 'coffee') || str_contains($hay, 'spice')) {
        return $pick($map['coffee'], $seed);
    }
    if (str_contains($hay, 'food') || str_contains($hay, 'kitchen') || str_contains($hay, 'restaurant')) {
        return $pick($map['food'], $seed);
    }
    if (str_contains($hay, 'organic') || str_contains($hay, 'farm') || str_contains($hay, 'harvest')) {
        return $pick($map['organic'], $seed);
    }
    if (str_contains($hay, 'fashion') || str_contains($hay, 'style') || str_contains($hay, 'clothing')) {
        return $pick($map['fashion'], $seed);
    }
    if (str_contains($hay, 'home') || str_contains($hay, 'furniture') || str_contains($hay, 'decor')) {
        return $pick($map['home'], $seed);
    }
    if (str_contains($hay, 'family') || str_contains($hay, 'kids')) {
        return $pick($map['family'], $seed);
    }
    return $pick($map['market'], $seed);
}

/**
 * Session flash helpers for one-time UI notices.
 */
function qb_flash_set(string $key, string $message): void {
    startSession();
    $_SESSION['qb_flash_' . $key] = $message;
}

function qb_flash_pull(string $key): string {
    startSession();
    $sk = 'qb_flash_' . $key;
    $msg = isset($_SESSION[$sk]) ? (string) $_SESSION[$sk] : '';
    unset($_SESSION[$sk]);
    return $msg;
}

/**
 * Normalized payment status presentation.
 *
 * @return array{label:string,class:string}
 */
function qb_payment_status_meta(string $statusRaw): array {
    $s = strtolower(trim($statusRaw));
    return match ($s) {
        'completed' => ['label' => 'Completed', 'class' => 'badge-green'],
        'pending' => ['label' => 'Pending payment', 'class' => 'badge-amber'],
        'pending_confirmation' => ['label' => 'Pending confirmation', 'class' => 'badge-amber'],
        'failed' => ['label' => 'Failed', 'class' => 'badge-red'],
        'refunded' => ['label' => 'Refunded', 'class' => 'badge-gray'],
        default => ['label' => ucfirst($s !== '' ? $s : 'unknown'), 'class' => 'badge-gray'],
    };
}

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/seller_inactivity.php';
require_once __DIR__ . '/auth_login.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/ticket_gate.php';
require_once __DIR__ . '/qb_creative.php';
require_once __DIR__ . '/telebirr_service.php';
