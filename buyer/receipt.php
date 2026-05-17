<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyerOrSeller();

$txId = sanitize($_GET['tx'] ?? '');
$paidOk = (string) ($_GET['paid_ok'] ?? '') === '1';
$paidItems = max(0, (int) ($_GET['items'] ?? 0));

$tx = db()->fetchOne("
    SELECT t.*, s.market_name, s.full_name as seller_name, e.name as event_name
    FROM transactions t
    JOIN sellers s ON t.seller_id = s.id
    LEFT JOIN bazar_events e ON t.event_id = e.id
    WHERE t.tx_id = ? AND t.buyer_id = ? AND t.payment_status = 'completed'
", [$txId, $_SESSION['app_user_id']]);

if (!$tx) {
    header('Location: ' . qb_shopper_home_url());
    exit;
}

$items = db()->fetchAll('SELECT * FROM transaction_items WHERE transaction_id = ?', [$tx['id']]);

qb_page_start('buyer', 'Receipt', 'receipt.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main qb-buyer-receipt-wrap">

  <?php if ($paidOk): ?>
  <div class="alert alert-success mb-2"><?= qb_icon('check', 'qb-icon', 16) ?> Order saved in purchase history. Reference: <strong><?= htmlspecialchars($txId) ?></strong><?= $paidItems > 0 ? ' · Items: ' . (int) $paidItems : '' ?></div>
  <?php endif; ?>

  <?php
  $role = 'buyer';
  require __DIR__ . '/../includes/partials/payment_receipt.php';
  ?>

  <?php
  $rated = db()->fetchOne('SELECT id FROM ratings WHERE transaction_id = ?', [$tx['id']]);
  if (!$rated):
  ?>
  <div class="card" style="padding:1.25rem;margin-top:0.5rem">
    <p class="text-sm font-bold mb-2 text-center">Rate this seller</p>
    <div style="display:flex;justify-content:center;gap:0.25rem;color:var(--text-muted)">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <form method="post" action="../api/rate.php" style="display:inline">
          <input type="hidden" name="tx_id" value="<?= (int) $tx['id'] ?>">
          <input type="hidden" name="seller_id" value="<?= (int) $tx['seller_id'] ?>">
          <input type="hidden" name="stars" value="<?= $i ?>">
          <button type="submit" class="btn btn-ghost" style="padding:0.25rem" aria-label="<?= $i ?> stars">
            <?= qb_icon('star', 'qb-icon', 28) ?>
          </button>
        </form>
      <?php endfor; ?>
    </div>
  </div>
  <?php else: ?>
  <p class="text-center text-sm font-bold" style="margin-top:1rem;color:var(--emerald)">Thanks for rating!</p>
  <?php endif; ?>

  <div style="text-align:center;margin-top:1.5rem">
    <a href="<?= htmlspecialchars(qb_shopper_home_url()) ?>" class="btn btn-primary btn-full font-bold"><?= currentRole() === 'seller' ? 'Back to seller dashboard' : 'Back to Home' ?></a>
  </div>
</div>
</div>

<?php qb_page_end(); ?>
