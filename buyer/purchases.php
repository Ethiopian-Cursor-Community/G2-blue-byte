<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyerOrSeller();

$uid = (int) $_SESSION['app_user_id'];

$rows = db()->fetchAll(
    "SELECT t.tx_id, t.total_amount, t.payment_status, t.created_at, s.market_name, e.name AS event_name, t.seller_id, t.event_id
     FROM transactions t
     LEFT JOIN sellers s ON s.id = t.seller_id
     LEFT JOIN bazar_events e ON e.id = t.event_id
     WHERE t.buyer_id = ? AND t.payment_method = 'chapa'
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 100",
    [$uid]
);

qb_page_start('buyer', 'Purchases', 'purchases.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main">
<div class="page-header qb-dash-header">
  <div>
    <h1 class="page-title qb-dash-title">Payment history</h1>
      <p class="page-subtitle qb-dash-subtitle">All your payment history — purchases, tickets, and other QR Bazar payments.</p>
  </div>
</div>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Reference</th>
          <th>Status</th>
          <th class="text-right">Amount</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No purchases yet.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
          <?php $stMeta = qb_payment_status_meta((string) ($r['payment_status'] ?? '')); ?>
          <tr>
            <td class="text-sm"><?= htmlspecialchars(date('j M Y H:i', strtotime((string) $r['created_at']))) ?></td>
            <td>
              <?php
                $refTitle = (string) ($r['market_name'] ?? '');
                if ($refTitle === '' && !empty($r['event_name'])) {
                    $refTitle = 'Event ticket';
                }
                if ($refTitle === '') {
                    $refTitle = 'Platform payment';
                }
              ?>
              <div class="font-bold"><?= htmlspecialchars($refTitle) ?></div>
              <?php if (!empty($r['event_name'])): ?><div class="text-xs text-muted"><?= htmlspecialchars((string) $r['event_name']) ?></div><?php endif; ?>
              <div class="text-xs text-muted">Ref: <?= htmlspecialchars((string) $r['tx_id']) ?></div>
            </td>
            <td><span class="badge <?= htmlspecialchars($stMeta['class']) ?>"><?= htmlspecialchars($stMeta['label']) ?></span></td>
            <td class="text-right font-bold"><?= number_format((float) $r['total_amount'], 2) ?> ETB</td>
            <td>
              <a class="btn btn-ghost btn-sm" href="receipt.php?tx=<?= urlencode($r['tx_id']) ?>">Receipt</a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
</div>

<?php qb_page_end(); ?>
