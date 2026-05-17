<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$sellers = qb_lb_sellers_global(30);
$buyers = qb_lb_buyers_global(30);
$sellerStats = [];
$buyerEventStats = [];
if (!empty($sellers)) {
    $sellerIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['seller_id'] ?? 0), $sellers), static fn($v) => $v > 0));
    if (!empty($sellerIds)) {
        $ph = implode(',', array_fill(0, count($sellerIds), '?'));
        $rows = db()->fetchAll(
            "SELECT seller_id, AVG(stars) AS avg_stars
             FROM ratings
             WHERE seller_id IN ($ph)
             GROUP BY seller_id",
            $sellerIds
        );
        foreach ($rows as $row) {
            $sellerStats[(int) ($row['seller_id'] ?? 0)] = (float) ($row['avg_stars'] ?? 0);
        }
    }
}
if (!empty($buyers)) {
    $buyerIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['buyer_id'] ?? 0), $buyers), static fn($v) => $v > 0));
    if (!empty($buyerIds)) {
        $ph = implode(',', array_fill(0, count($buyerIds), '?'));
        $rows = db()->fetchAll(
            "SELECT buyer_id, COUNT(DISTINCT event_id) AS event_count
             FROM transactions
             WHERE payment_status = 'completed' AND buyer_id IN ($ph) AND event_id IS NOT NULL
             GROUP BY buyer_id",
            $buyerIds
        );
        foreach ($rows as $row) {
            $buyerEventStats[(int) ($row['buyer_id'] ?? 0)] = (int) ($row['event_count'] ?? 0);
        }
    }
}
$rankClass = static function (int $rank): string {
    return match ($rank) {
        1 => 'qb-lb-rank qb-lb-rank--gold',
        2 => 'qb-lb-rank qb-lb-rank--silver',
        3 => 'qb-lb-rank qb-lb-rank--bronze',
        default => 'qb-lb-rank qb-lb-rank--plain',
    };
};

qb_page_start('admin', 'Leaderboards', 'leaderboards.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= qb_icon('star', 'qb-icon', 22) ?> Leaderboards</h1>
    <p class="page-subtitle">Platform-wide privacy-safe activity rankings (completed payments only).</p>
  </div>
</div>

<div class="grid grid-2 gap-2" style="align-items:start">
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1.1rem">Top sellers</h2>
    <p class="text-sm text-muted mb-2">By completed orders and seller trust score.</p>
    <?php if (empty($sellers)): ?>
      <p class="text-muted text-sm mb-0">No seller sales yet.</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="qb-lb-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Stall / market</th>
            <th class="text-right">Orders</th>
            <th class="text-right">Trust</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sellers as $row): ?>
          <?php
            $sidRow = (int) ($row['seller_id'] ?? 0);
            $trust = (float) ($sellerStats[$sidRow] ?? 0);
            $trustLabel = $trust > 0 ? number_format($trust, 1) . '/5' : 'New';
          ?>
          <tr>
            <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span></td>
            <td class="font-bold"><?= htmlspecialchars($row['market_name']) ?></td>
            <td class="text-right"><?= (int) $row['orders'] ?></td>
            <td class="text-right text-sm"><?= htmlspecialchars($trustLabel) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1.1rem">Top buyers</h2>
    <p class="text-sm text-muted mb-2">By completed orders and event participation.</p>
    <?php if (empty($buyers)): ?>
      <p class="text-muted text-sm mb-0">No buyer purchases with linked accounts yet.</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="qb-lb-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Buyer</th>
            <th class="text-right">Orders</th>
            <th class="text-right">Events</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($buyers as $row): ?>
          <?php $bid = (int) ($row['buyer_id'] ?? 0); ?>
          <tr>
            <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span></td>
            <td class="font-bold"><?= htmlspecialchars($row['label']) ?></td>
            <td class="text-right"><?= (int) $row['orders'] ?></td>
            <td class="text-right text-sm"><?= (int) ($buyerEventStats[$bid] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php qb_page_end(); ?>
