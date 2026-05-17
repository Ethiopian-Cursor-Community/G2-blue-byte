<?php
/**
 * Form POST handler for buyer rating from receipt (browser redirect).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireBuyerOrSeller();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . qb_shopper_home_url());
    exit;
}

$tid = (int) ($_POST['tx_id'] ?? 0);
$sellerId = (int) ($_POST['seller_id'] ?? 0);
$stars = (int) ($_POST['stars'] ?? 0);
$uid = (int) ($_SESSION['app_user_id'] ?? 0);

$tx = db()->fetchOne('SELECT id, tx_id, seller_id, buyer_id FROM transactions WHERE id = ? AND buyer_id = ?', [$tid, $uid]);
if (!$tx || (int) $tx['seller_id'] !== $sellerId || $stars < 1 || $stars > 5) {
    header('Location: ' . qb_shopper_home_url());
    exit;
}

$exists = db()->fetchOne('SELECT id FROM ratings WHERE transaction_id = ?', [$tid]);
if (!$exists) {
    $buyer = currentUser();
    $name = $buyer['display_name'] ?? 'Anonymous';
    db()->insert(
        'INSERT INTO ratings (seller_id, transaction_id, buyer_name, stars, comment) VALUES (?,?,?,?,?)',
        [$sellerId, $tid, $name, $stars, '']
    );
    try {
        computeTrustScore($sellerId);
    } catch (Throwable $e) {
        /* ignore */
    }
}

header('Location: ' . APP_URL . '/buyer/receipt.php?tx=' . rawurlencode((string) $tx['tx_id']));
exit;
