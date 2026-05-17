<?php
/**
 * Telebirr H5 redirect after payment — verifies signed params, fulfills pending order.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

use Melaku\Telebirr\ReturnUrlHandler;

startSession();
requireBuyerOrSeller();

$uid = (int) $_SESSION['app_user_id'];
$config = qb_telebirr_config();
if ($config === null) {
    qb_flash_set('telebirr_notice', 'Telebirr is not configured on this server. Please try another payment method.');
    header('Location: ' . qb_shopper_home_url() . '?error=telebirr_config');
    exit;
}

try {
    $data = ReturnUrlHandler::handle($_GET, $config);
} catch (Throwable $e) {
    qb_flash_set('telebirr_notice', 'We could not verify the Telebirr callback. Please check Purchases for latest status.');
    header('Location: ' . qb_shopper_home_url() . '?error=telebirr_verify');
    exit;
}

$merch = (string) ($data['merchantOrderId'] ?? '');
if ($merch === '') {
    qb_flash_set('telebirr_notice', 'Telebirr returned without an order reference. Contact support if you were charged.');
    header('Location: ' . APP_URL . '/buyer/purchases.php?telebirr=missing_order');
    exit;
}

$txRow = db()->fetchOne(
    "SELECT buyer_id FROM transactions WHERE tx_id = ? AND payment_method = 'telebirr'",
    [$merch]
);
if (!$txRow || (int) ($txRow['buyer_id'] ?? 0) !== $uid) {
    qb_flash_set('telebirr_notice', 'Telebirr returned for an order that is not linked to your account.');
    header('Location: ' . qb_shopper_home_url() . '?error=telebirr_access');
    exit;
}

if (!empty($data['isSuccess'])) {
    $ok = qb_fulfill_pending_transaction($merch);
    if ($ok) {
        qb_flash_set('telebirr_notice', 'Telebirr payment confirmed successfully.');
    } else {
        qb_flash_set('telebirr_notice', 'Telebirr callback arrived, but payment is still pending. Refresh Purchases in a moment.');
    }
    header('Location: ' . APP_URL . '/buyer/receipt.php?tx=' . rawurlencode($merch));
    exit;
}

qb_flash_set('telebirr_notice', 'Telebirr payment was cancelled or not completed.');
header('Location: ' . APP_URL . '/buyer/purchases.php?telebirr=cancelled');
exit;
