<?php
/**
 * Baseline HTTP security headers (safe defaults for typical PHP+XAMPP deploys).
 */
declare(strict_types=1);

function qb_send_security_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://fonts.googleapis.com; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com https://cdn.jsdelivr.net; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data: blob: https:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none';"
    );
}
