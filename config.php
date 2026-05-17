<?php
// =============================================
// QR-Bazaar Configuration
// =============================================

if (!function_exists('qb_load_env_file')) {
    function qb_load_env_file(string $path): void {
        if (!is_readable($path)) return;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq + 1));
            if ($k === '') continue;
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            if (getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
        }
    }
}
if (!function_exists('qb_env')) {
    function qb_env(string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === null || $v === '') return $default;
        return (string) $v;
    }
}
qb_load_env_file(__DIR__ . '/.env');

define('DB_HOST',     (string) qb_env('DB_HOST', 'localhost'));
define('DB_PORT',     (int) (qb_env('DB_PORT', '3306')));
define('DB_USER',     (string) qb_env('DB_USER', 'root'));
define('DB_PASS',     (string) qb_env('DB_PASS', ''));
define('DB_NAME',     (string) qb_env('DB_NAME', 'qr_bazaar'));

define('APP_NAME',    'QR Bazar');
// Stable app URL root for both localhost and LAN devices.
$qbHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$qbScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$qbRootSegment = '/' . rawurlencode(basename(__DIR__));
$qbAppUrl = $qbScheme . '://' . $qbHost . $qbRootSegment;
define('APP_URL',     rtrim($qbAppUrl, '/'));
define('SITE_URL',    rtrim($qbAppUrl, '/'));
define('APP_VERSION', '2.0.0');

define('QR_SECRET',         (string) qb_env('QR_SECRET', 'qrbazaar_hmac_secret_2026'));
define('SESSION_SECRET',    (string) qb_env('SESSION_SECRET', 'qrbazaar_session_2026'));
define('APP_KEY',           (string) qb_env('APP_KEY', SESSION_SECRET));
define('QR_TTL_SECONDS',    3600);
define('SESSION_LIFETIME',  28800);

define('GEOFENCE_DEFAULT_RADIUS',      500);  // meters
define('NOTIFICATION_POLL_INTERVAL',   30);   // seconds

// Telebirr H5 C2B — register at https://developer.ethiotelecom.et/ then set TELEBIRR_ENABLED true and fill credentials.
define('TELEBIRR_ENABLED', false);
define('TELEBIRR_ENV', 'test');
define('TELEBIRR_FABRIC_APP_ID', '');
define('TELEBIRR_APP_SECRET', '');
define('TELEBIRR_MERCHANT_APP_ID', '');
define('TELEBIRR_MERCHANT_CODE', '');
/** RSA private key PEM (-----BEGIN PRIVATE KEY----- …) or absolute path to a .pem file */
define('TELEBIRR_PRIVATE_KEY_PEM', '');
/** Telebirr platform public key PEM for verifying callbacks (from developer portal) */
define('TELEBIRR_PUBLIC_KEY_PEM', '');

// Chapa sandbox/live configuration.
define('CHAPA_ENABLED', strtolower((string) qb_env('CHAPA_ENABLED', 'false')) === 'true');
// test|live : use test while integrating, switch to live only after verification.
define('CHAPA_MODE', (string) qb_env('CHAPA_MODE', 'test'));
define('CHAPA_BASE_URL', (string) qb_env('CHAPA_BASE_URL', 'https://api.chapa.co/v1'));
define('CHAPA_PUBLIC_KEY', (string) qb_env('CHAPA_PUBLIC_KEY', ''));
define('CHAPA_SECRET_KEY', (string) qb_env('CHAPA_SECRET_KEY', ''));
define('CHAPA_ENCRYPTION_KEY', (string) qb_env('CHAPA_ENCRYPTION_KEY', ''));
define('CHAPA_TICKET_FEE_ETB', (float) qb_env('CHAPA_TICKET_FEE_ETB', '25'));
define('CHAPA_ROLE_REQUEST_FEE_ETB', (float) qb_env('CHAPA_ROLE_REQUEST_FEE_ETB', '79'));
define('CHAPA_SELLER_EVENT_FEE_ETB', (float) qb_env('CHAPA_SELLER_EVENT_FEE_ETB', '99'));
define('CHAPA_PROMO_FEE_ETB', (float) qb_env('CHAPA_PROMO_FEE_ETB', '120'));

define('CURSOR_ENABLED', strtolower((string) qb_env('CURSOR_ENABLED', 'true')) !== 'false');
define('CURSOR_API_KEY', (string) qb_env('CURSOR_API_KEY', ''));
define('CURSOR_MODEL', (string) qb_env('CURSOR_MODEL', 'composer-2'));

$qbIsDev = strtolower((string) qb_env('APP_ENV', 'production')) === 'development';
ini_set('display_errors', $qbIsDev ? '1' : '0');
error_reporting($qbIsDev ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_STRICT));

date_default_timezone_set('Africa/Addis_Ababa');
require_once __DIR__ . '/includes/logger.php';

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (is_readable(__DIR__ . '/includes/security_headers.php')) {
    require_once __DIR__ . '/includes/security_headers.php';
    qb_send_security_headers();
}
