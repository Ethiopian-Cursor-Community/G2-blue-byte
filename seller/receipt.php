<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

$seller = getCurrentSeller();
if (!$seller) {
    header('Location: ' . APP_URL . '/seller/dashboard.php');
    exit;
}
$sid = (int) $seller['id'];

$txId = sanitize($_GET['tx'] ?? '');
if ($txId === '') {
    header('Location: ' . APP_URL . '/seller/payments.php');
    exit;
}

$tx = db()->fetchOne("
    SELECT t.*, s.market_name, s.full_name as seller_name, e.name as event_name
    FROM transactions t
    JOIN sellers s ON t.seller_id = s.id
    LEFT JOIN bazar_events e ON t.event_id = e.id
    WHERE t.tx_id = ? AND t.seller_id = ? AND t.payment_status = 'completed'
", [$txId, $sid]);

if (!$tx) {
    header('Location: ' . APP_URL . '/seller/payments.php');
    exit;
}

$items = db()->fetchAll('SELECT * FROM transaction_items WHERE transaction_id = ?', [$tx['id']]);

qb_page_start('seller', 'Payment receipt', 'receipt.php', false);
?>

<div class="page-header">
  <div>
    <a href="payments.php" class="text-sm text-secondary">&larr; Payment history</a>
    <h1 class="page-title mt-1">Receipt</h1>
    <p class="page-subtitle"><?= htmlspecialchars($tx['tx_id']) ?></p>
  </div>
</div>

<?php
$role = 'seller';
require __DIR__ . '/../includes/partials/payment_receipt.php';
?>

<div style="max-width:22rem;margin:1rem auto 0;text-align:center">
  <a href="payments.php" class="btn btn-secondary">Back to payments</a>
</div>

<?php qb_page_end(); ?>
