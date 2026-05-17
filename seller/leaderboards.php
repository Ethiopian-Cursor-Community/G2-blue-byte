<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

$seller = getCurrentSeller();
$sid = (int) $seller['id'];

$events = [];
if (qb_table_exists('stalls')) {
    $events = db()->fetchAll(
        'SELECT DISTINCT e.id, e.name FROM stalls st INNER JOIN bazar_events e ON e.id = st.event_id WHERE st.seller_id = ? ORDER BY e.event_start DESC',
        [$sid]
    );
}

$eventId = (int) ($_GET['event'] ?? 0);
if ($eventId > 0 && !qb_lb_seller_in_event($sid, $eventId)) {
    $eventId = 0;
}
if ($eventId === 0 && !empty($events)) {
    $eventId = (int) $events[0]['id'];
}

$allowedPerPage = [10, 15, 25, 50, 100];
$perPage = max(1, (int) ($_GET['per_page'] ?? 15));
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}
$sellerPage = max(1, (int) ($_GET['seller_page'] ?? 1));
$buyerPage = max(1, (int) ($_GET['buyer_page'] ?? 1));
$sellersAll = $eventId > 0 ? qb_lb_sellers_event($eventId, 220) : [];
$sellerTotalPages = max(1, (int) ceil(count($sellersAll) / $perPage));
$sellerPage = min($sellerPage, $sellerTotalPages);
$sellersLb = array_slice($sellersAll, ($sellerPage - 1) * $perPage, $perPage);
if ($eventId > 0) {
    qb_lb_save_rank_snapshot($sellersAll, 'seller', 'event', $eventId);
}
$myRank = null;
foreach ($sellersLb as $row) {
    if ((int) $row['seller_id'] === $sid) {
        $myRank = $row;
        break;
    }
}

$topBuyersAll = qb_lb_buyers_for_seller($sid, 220);
$buyerTotalPages = max(1, (int) ceil(count($topBuyersAll) / $perPage));
$buyerPage = min($buyerPage, $buyerTotalPages);
$topBuyers = array_slice($topBuyersAll, ($buyerPage - 1) * $perPage, $perPage);
qb_lb_save_rank_snapshot($topBuyersAll, 'buyer', 'seller_portal', $sid);

$sellerStats = [];
if (!empty($sellersAll)) {
    $sellerIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['seller_id'] ?? 0), $sellersAll), static fn($v) => $v > 0));
    if (!empty($sellerIds)) {
        $ph = implode(',', array_fill(0, count($sellerIds), '?'));
        $rows = db()->fetchAll(
            "SELECT seller_id, AVG(stars) AS avg_stars, COUNT(*) AS rating_count
             FROM ratings
             WHERE seller_id IN ($ph)
             GROUP BY seller_id",
            $sellerIds
        );
        foreach ($rows as $row) {
            $sellerStats[(int) ($row['seller_id'] ?? 0)] = [
                'avg' => (float) ($row['avg_stars'] ?? 0),
                'n' => (int) ($row['rating_count'] ?? 0),
            ];
        }
    }
}
$buyerEventStats = [];
if (!empty($topBuyersAll)) {
    $buyerIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['buyer_id'] ?? 0), $topBuyersAll), static fn($v) => $v > 0));
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

$rankBadge = static function (int $rank): string {
    if ($rank === 1) return '🥇';
    if ($rank === 2) return '🥈';
    if ($rank === 3) return '🥉';
    return '';
};
$rankClass = static function (int $rank): string {
    return match ($rank) {
        1 => 'qb-lb-rank qb-lb-rank--gold',
        2 => 'qb-lb-rank qb-lb-rank--silver',
        3 => 'qb-lb-rank qb-lb-rank--bronze',
        default => 'qb-lb-rank qb-lb-rank--plain',
    };
};

$eventName = '';
foreach ($events as $ev) {
    if ((int) $ev['id'] === $eventId) {
        $eventName = (string) $ev['name'];
        break;
    }
}

qb_page_start('seller', 'Leaderboards', 'leaderboards.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= qb_icon('star', 'qb-icon', 22) ?> Leaderboards</h1>
    <p class="page-subtitle">Privacy-safe leaderboard by activity and trust (no revenue/spend shown).</p>
  </div>
</div>

<?php if (empty($events)): ?>
  <div class="alert alert-warning mb-2">Join a bazar (get a stall assigned) to see seller rankings by event.</div>
<?php else: ?>
<form method="get" class="card mb-2" style="padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
  <div class="form-group mb-0">
    <label class="form-label" for="ev">Bazar</label>
    <select name="event" id="ev" class="form-control" onchange="this.form.submit()">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $eventId ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group mb-0">
    <label class="form-label" for="pp">Show users</label>
    <select name="per_page" id="pp" class="form-control" onchange="this.form.submit()">
      <?php foreach ($allowedPerPage as $n): ?>
        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <input type="hidden" name="seller_page" value="1"/>
  <input type="hidden" name="buyer_page" value="1"/>
</form>

<?php if ($eventId > 0): ?>
<p class="text-sm text-muted mb-2">Event: <strong><?= htmlspecialchars($eventName) ?></strong>
  <?php if ($myRank): ?>
    &nbsp;·&nbsp; Your rank: <strong>#<?= (int) $myRank['rank'] ?></strong>
    (<?= (int) $myRank['orders'] ?> orders)
  <?php else: ?>
    &nbsp;·&nbsp; <span class="text-muted">No sales recorded for this event yet.</span>
  <?php endif; ?>
</p>
<?php endif; ?>

<div class="card mb-2">
  <h2 class="font-bold mb-2" style="font-size:1.1rem">Sellers in this bazar</h2>
  <?php if ($eventId <= 0 || empty($sellersLb)): ?>
    <p class="text-muted text-sm mb-0">No data for this selection.</p>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="qb-lb-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Market</th>
          <th class="text-right">Orders</th>
          <th class="text-right">Trust</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sellersLb as $row): ?>
        <?php
          $sidRow = (int) ($row['seller_id'] ?? 0);
          $sStat = $sellerStats[$sidRow] ?? ['avg' => 0.0, 'n' => 0];
          $trustLabel = ((float) $sStat['avg'] > 0) ? number_format((float) $sStat['avg'], 1) . '/5' : 'New';
        ?>
        <tr class="<?= (int) $row['seller_id'] === $sid ? 'qb-lb-row--me' : '' ?>">
          <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span><?php if ($rankBadge((int) $row['rank']) !== ''): ?> <span class="qb-lb-medal"><?= $rankBadge((int) $row['rank']) ?></span><?php endif; ?></td>
          <td class="font-bold"><?= htmlspecialchars($row['market_name']) ?><?= (int) $row['seller_id'] === $sid ? ' (you)' : '' ?></td>
          <td class="text-right"><?= (int) $row['orders'] ?></td>
          <td class="text-right text-sm"><?= htmlspecialchars($trustLabel) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($sellerTotalPages > 1): ?>
  <div class="qb-lb-pagination">
    <?php for ($p = 1; $p <= $sellerTotalPages; $p++): ?>
      <a class="btn btn-ghost btn-sm<?= $p === $sellerPage ? ' is-active' : '' ?>" href="?event=<?= (int) $eventId ?>&per_page=<?= $perPage ?>&seller_page=<?= $p ?>&buyer_page=<?= $buyerPage ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="font-bold mb-2" style="font-size:1.1rem">Your top buyers</h2>
  <p class="text-sm text-muted mb-2">Completed purchases at your stall (all events), privacy-safe metrics only.</p>
  <?php if (empty($topBuyers)): ?>
    <p class="text-muted text-sm mb-0">No sales yet.</p>
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
        <?php foreach ($topBuyers as $row): ?>
        <?php $bid = (int) ($row['buyer_id'] ?? 0); ?>
        <tr>
          <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span><?php if ($rankBadge((int) $row['rank']) !== ''): ?> <span class="qb-lb-medal"><?= $rankBadge((int) $row['rank']) ?></span><?php endif; ?></td>
          <td class="font-bold"><?= htmlspecialchars($row['label']) ?></td>
          <td class="text-right"><?= (int) $row['orders'] ?></td>
          <td class="text-right text-sm"><?= (int) ($buyerEventStats[$bid] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($buyerTotalPages > 1): ?>
  <div class="qb-lb-pagination">
    <?php for ($p = 1; $p <= $buyerTotalPages; $p++): ?>
      <a class="btn btn-ghost btn-sm<?= $p === $buyerPage ? ' is-active' : '' ?>" href="?event=<?= (int) $eventId ?>&per_page=<?= $perPage ?>&seller_page=<?= $sellerPage ?>&buyer_page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php qb_page_end(); ?>
