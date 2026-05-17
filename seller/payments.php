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

$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 20;
$off = ($page - 1) * $per;

$total = (int) (db()->fetchOne(
    "SELECT COUNT(*) AS c FROM transactions WHERE seller_id = ? AND payment_method = 'chapa'",
    [$sid]
)['c'] ?? 0);

$rows = db()->fetchAll(
    "SELECT t.id, t.tx_id, t.buyer_name, t.total_amount, t.payment_status, t.created_at, e.name AS event_name
     FROM transactions t
     LEFT JOIN bazar_events e ON e.id = t.event_id
     WHERE t.seller_id = ? AND t.payment_method = 'chapa'
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT $per OFFSET $off",
    [$sid]
);

$pages = max(1, (int) ceil($total / $per));

qb_page_start('seller', 'Payment history', 'payments.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Payment history</h1>
    <p class="page-subtitle">All sales statuses — open a receipt to match what the buyer shows you.</p>
  </div>
</div>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Order ID</th>
          <th>Buyer</th>
          <th>Status</th>
          <th class="text-right">Amount</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No payments yet.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
          <?php $stMeta = qb_payment_status_meta((string) ($r['payment_status'] ?? '')); ?>
          <tr>
            <td class="text-sm"><?= htmlspecialchars(date('j M Y H:i', strtotime((string) $r['created_at']))) ?></td>
            <td><code class="text-xs"><?= htmlspecialchars($r['tx_id']) ?></code></td>
            <td><?= htmlspecialchars((string) $r['buyer_name']) ?></td>
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
  <?php if ($pages > 1): ?>
  <div class="flex align-center justify-center gap-2 py-3" style="border-top:1px solid var(--border)">
    <?php if ($page > 1): ?>
    <a class="btn btn-ghost btn-sm" href="payments.php?p=<?= $page - 1 ?>">Previous</a>
    <?php endif; ?>
    <span class="text-sm text-muted">Page <?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?>
    <a class="btn btn-ghost btn-sm" href="payments.php?p=<?= $page + 1 ?>">Next</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php qb_page_end(); ?>
