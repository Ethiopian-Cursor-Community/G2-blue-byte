<?php
/**
 * Telebirr H5 C2B checkout via melaku/telebirr (Ethio Telecom Fabric API).
 */

declare(strict_types=1);

use Melaku\Telebirr\Config;
use Melaku\Telebirr\Telebirr;
use Melaku\Telebirr\NotificationHandler;
use Melaku\Telebirr\ReturnUrlHandler;

function qb_app_public_base_url(): string {
    return str_replace(' ', '%20', rtrim((string) APP_URL, '/'));
}

function qb_telebirr_class_loaded(): bool {
    return class_exists(Telebirr::class, false) || class_exists(Telebirr::class);
}

/** True when Composer package is present and config flags + credentials are set. */
function qb_telebirr_ready(): bool {
    if (!qb_telebirr_class_loaded()) {
        return false;
    }
    if (!defined('TELEBIRR_ENABLED') || !TELEBIRR_ENABLED) {
        return false;
    }
    try {
        $c = qb_telebirr_config();
        return $c !== null && $c->isComplete();
    } catch (Throwable $e) {
        return false;
    }
}

function qb_telebirr_load_private_key_pem(): string {
    $raw = (string) TELEBIRR_PRIVATE_KEY_PEM;
    if ($raw === '') {
        return '';
    }
    if (str_contains($raw, 'BEGIN PRIVATE KEY') || str_contains($raw, 'BEGIN RSA PRIVATE KEY')) {
        return $raw;
    }
    if (is_readable($raw)) {
        $pem = @file_get_contents($raw);
        return is_string($pem) ? $pem : '';
    }
    return '';
}

function qb_telebirr_config(): ?Config {
    if (!qb_telebirr_class_loaded()) {
        return null;
    }
    $pem = qb_telebirr_load_private_key_pem();
    if ($pem === '' || TELEBIRR_FABRIC_APP_ID === '' || TELEBIRR_APP_SECRET === ''
        || TELEBIRR_MERCHANT_APP_ID === '' || TELEBIRR_MERCHANT_CODE === '') {
        return null;
    }
    $env = strtolower((string) TELEBIRR_ENV);
    $opts = [
        'fabricAppId' => TELEBIRR_FABRIC_APP_ID,
        'appSecret' => TELEBIRR_APP_SECRET,
        'merchantAppId' => TELEBIRR_MERCHANT_APP_ID,
        'merchantCode' => TELEBIRR_MERCHANT_CODE,
        'privateKey' => $pem,
        'notifyUrl' => qb_app_public_base_url() . '/api/telebirr_notify.php',
        'redirectUrl' => qb_app_public_base_url() . '/buyer/telebirr_return.php',
    ];
    if (TELEBIRR_PUBLIC_KEY_PEM !== '') {
        $opts['telebirrPublicKey'] = TELEBIRR_PUBLIC_KEY_PEM;
    }
    if (in_array($env, ['production', 'prod', 'live'], true)) {
        return Config::forProduction($opts);
    }
    return Config::forTest($opts);
}

/**
 * Start Telebirr web checkout — returns redirect URL or throws.
 */
function qb_telebirr_create_checkout_url(string $orderTitle, float $amount, string $merchOrderId): string {
    $config = qb_telebirr_config();
    if ($config === null) {
        throw new RuntimeException('Telebirr is not configured.');
    }
    $config->validate();
    $client = new Telebirr($config);
    return $client->createCheckoutUrl($orderTitle, $amount, $merchOrderId);
}

function qb_telebirr_digits(?string $phone): string {
    return preg_replace('/\D+/', '', (string) ($phone ?? ''));
}

/**
 * Try common Telebirr callback keys for payer phone/MSISDN.
 */
function qb_telebirr_extract_payer_phone(array $info): string {
    $keys = [
        'payerMsisdn',
        'msisdn',
        'payerPhone',
        'phoneNumber',
        'payer_mobile',
        'customerMsisdn',
    ];
    foreach ($keys as $k) {
        if (!empty($info[$k])) {
            return (string) $info[$k];
        }
    }
    return '';
}

function qb_telebirr_diag(string $message, array $ctx = []): void {
    $line = '[telebirr] ' . $message;
    if ($ctx !== []) {
        $json = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }
    error_log($line);
}

/**
 * Mark pending Telebirr transaction completed, deduct stock, notify buyer (idempotent).
 */
function qb_fulfill_pending_transaction(string $txId): bool {
    $tx = db()->fetchOne("SELECT * FROM transactions WHERE tx_id = ? AND payment_status = 'pending'", [$txId]);
    if (!$tx) {
        $done = db()->fetchOne("SELECT id FROM transactions WHERE tx_id = ? AND payment_status = 'completed'", [$txId]);
        return $done !== null;
    }
    if (($tx['payment_method'] ?? '') !== 'telebirr') {
        return false;
    }
    $tid = (int) $tx['id'];
    $n = db()->execute("UPDATE transactions SET payment_status = 'completed' WHERE id = ? AND payment_status = 'pending'", [$tid]);
    if ($n === 0) {
        return true;
    }
    $items = db()->fetchAll('SELECT * FROM transaction_items WHERE transaction_id = ?', [$tid]);
    $firstPid = null;
    foreach ($items as $i) {
        $pid = (int) ($i['product_id'] ?? 0);
        $qty = (int) ($i['quantity'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            if ($firstPid === null) {
                $firstPid = $pid;
            }
            db()->execute('UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?', [$qty, $pid]);
            db()->execute('UPDATE products SET is_available = 0 WHERE id = ? AND stock <= 0', [$pid]);
            db()->execute('UPDATE products SET view_count = view_count + 1 WHERE id = ?', [$pid]);
        }
    }
    $sid = (int) $tx['seller_id'];
    db()->execute(
        "INSERT INTO analytics_events (seller_id, event_type, product_id, event_hour, event_date) VALUES (?, 'purchase', ?, HOUR(NOW()), CURDATE())",
        [$sid, $firstPid]
    );
    $buyerId = (int) ($tx['buyer_id'] ?? 0);
    if ($buyerId > 0) {
        createNotification(
            $buyerId,
            'purchase',
            'Purchase Successful',
            'Telebirr payment confirmed — ' . number_format((float) $tx['total_amount'], 2) . ' ETB.',
            'receipt.php?tx=' . rawurlencode($txId)
        );
    }
    qb_audit_log('payment.telebirr.completed', 'transaction', (string) $tid, ['tx_id' => $txId]);
    return true;
}

function qb_telebirr_handle_notification(string $rawJson): void {
    $config = qb_telebirr_config();
    if ($config === null) {
        qb_telebirr_diag('notify rejected: config missing');
        NotificationHandler::respondError('Telebirr not configured', 503);
        return;
    }
    try {
        $notification = NotificationHandler::parse($rawJson);
    } catch (Throwable $e) {
        qb_telebirr_diag('notify invalid payload', ['error' => $e->getMessage()]);
        NotificationHandler::respondError('Invalid payload', 400);
        return;
    }
    if (!NotificationHandler::verify($notification, $config)) {
        qb_telebirr_diag('notify invalid signature');
        NotificationHandler::respondError('Invalid signature', 401);
        return;
    }
    if (!NotificationHandler::isPaymentSuccessful($notification)) {
        NotificationHandler::respondSuccess('Ignored');
        return;
    }
    $info = NotificationHandler::extractPaymentInfo($notification);
    $merchId = (string) ($info['merchantOrderId'] ?? '');
    if ($merchId === '') {
        qb_telebirr_diag('notify missing order id');
        NotificationHandler::respondError('Missing order id', 400);
        return;
    }
    $tx = db()->fetchOne(
        "SELECT t.id, t.total_amount, t.payment_status, t.payment_method, t.buyer_id, t.seller_id, t.buyer_phone,
                u.phone AS buyer_registered_phone, s.phone AS seller_registered_phone
         FROM transactions t
         LEFT JOIN app_users u ON u.id = t.buyer_id
         LEFT JOIN sellers s ON s.id = t.seller_id
         WHERE t.tx_id = ? LIMIT 1",
        [$merchId]
    );
    if (!$tx || ($tx['payment_method'] ?? '') !== 'telebirr') {
        qb_telebirr_diag('notify unknown order', ['tx_id' => $merchId]);
        NotificationHandler::respondError('Unknown order', 404);
        return;
    }
    $paidRaw = $info['totalAmount'] ?? ($info['amount'] ?? ($info['transAmount'] ?? null));
    $paid = $paidRaw !== null ? (float) $paidRaw : null;
    $expected = (float) ($tx['total_amount'] ?? 0);
    if ($paid !== null && $expected > 0) {
        $delta = abs($paid - $expected);
        if ($delta > 0.01) {
            qb_telebirr_diag('notify amount mismatch', ['tx_id' => $merchId, 'paid' => $paid, 'expected' => $expected]);
            db()->execute(
                "UPDATE transactions SET payment_status = 'failed' WHERE tx_id = ? AND payment_status = 'pending'",
                [$merchId]
            );
            qb_audit_log('payment.telebirr.failed', 'transaction', (string) ($tx['id'] ?? ''), ['tx_id' => $merchId, 'reason' => 'amount_mismatch']);
            NotificationHandler::respondError('Amount mismatch', 409);
            return;
        }
    }
    // Verify buyer payer number in callback against known buyer phone.
    $payerPhone = qb_telebirr_digits(qb_telebirr_extract_payer_phone($info));
    $buyerPhoneRegistered = qb_telebirr_digits((string) ($tx['buyer_registered_phone'] ?? ''));
    $buyerPhoneAtCheckout = qb_telebirr_digits((string) ($tx['buyer_phone'] ?? ''));
    $sellerRegisteredPhone = qb_telebirr_digits((string) ($tx['seller_registered_phone'] ?? ''));
    if ($sellerRegisteredPhone === '') {
        qb_telebirr_diag('notify seller phone missing', ['tx_id' => $merchId]);
        NotificationHandler::respondError('Seller phone not registered', 409);
        return;
    }
    if ($payerPhone !== '') {
        $buyerPhoneRef = $buyerPhoneRegistered !== '' ? $buyerPhoneRegistered : $buyerPhoneAtCheckout;
        if ($buyerPhoneRef !== '' && $buyerPhoneRef !== $payerPhone) {
            qb_telebirr_diag('notify buyer phone mismatch', ['tx_id' => $merchId, 'payer' => $payerPhone, 'buyer_ref' => $buyerPhoneRef]);
            db()->execute(
                "UPDATE transactions SET payment_status = 'failed' WHERE tx_id = ? AND payment_status = 'pending'",
                [$merchId]
            );
            qb_audit_log('payment.telebirr.failed', 'transaction', (string) ($tx['id'] ?? ''), ['tx_id' => $merchId, 'reason' => 'buyer_phone_mismatch']);
            NotificationHandler::respondError('Buyer phone mismatch', 409);
            return;
        }
    }
    try {
        qb_fulfill_pending_transaction($merchId);
    } catch (Throwable $e) {
        qb_telebirr_diag('notify fulfillment failed', ['tx_id' => $merchId, 'error' => $e->getMessage()]);
        NotificationHandler::respondError('Fulfillment failed', 500);
        return;
    }
    qb_telebirr_diag('notify fulfilled', ['tx_id' => $merchId]);
    NotificationHandler::respondSuccess('OK');
}
