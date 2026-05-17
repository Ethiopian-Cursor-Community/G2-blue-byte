<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyer();

$viewerId = (int) ($_SESSION['app_user_id'] ?? 0);

$allowedPerPage = [10, 15, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 15);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}
$sellerPage = max(1, (int) ($_GET['seller_page'] ?? 1));
$buyerPage = max(1, (int) ($_GET['buyer_page'] ?? 1));
$sellersAll = qb_lb_sellers_global(180);
$buyersAll = qb_lb_buyers_buyer_portal(180, $viewerId);
$sellerStats = [];
$buyerStats = [];
if (!empty($sellersAll)) {
    $sellerIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['seller_id'] ?? 0), $sellersAll), static fn($v) => $v > 0));
    if (!empty($sellerIds)) {
        $ph = implode(',', array_fill(0, count($sellerIds), '?'));
        $rows = db()->fetchAll(
            "SELECT s.id AS seller_id,
                    COALESCE(r.avg_stars, 0) AS avg_stars,
                    COALESCE(r.rating_count, 0) AS rating_count
             FROM sellers s
             LEFT JOIN (
                SELECT seller_id, AVG(stars) AS avg_stars, COUNT(*) AS rating_count
                FROM ratings
                GROUP BY seller_id
             ) r ON r.seller_id = s.id
             WHERE s.id IN ($ph)",
            $sellerIds
        );
        foreach ($rows as $row) {
            $sid = (int) ($row['seller_id'] ?? 0);
            if ($sid <= 0) continue;
            $sellerStats[$sid] = [
                'avg_stars' => (float) ($row['avg_stars'] ?? 0),
                'rating_count' => (int) ($row['rating_count'] ?? 0),
            ];
        }
    }
}
if (!empty($buyersAll)) {
    $buyerIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['buyer_id'] ?? 0), $buyersAll), static fn($v) => $v > 0));
    if (!empty($buyerIds)) {
        $ph = implode(',', array_fill(0, count($buyerIds), '?'));
        $rows = db()->fetchAll(
            "SELECT buyer_id, COUNT(DISTINCT event_id) AS event_count
             FROM transactions
             WHERE payment_status = 'completed'
               AND buyer_id IN ($ph)
               AND event_id IS NOT NULL
             GROUP BY buyer_id",
            $buyerIds
        );
        foreach ($rows as $row) {
            $bid = (int) ($row['buyer_id'] ?? 0);
            if ($bid <= 0) continue;
            $buyerStats[$bid] = [
                'event_count' => (int) ($row['event_count'] ?? 0),
            ];
        }
    }
}
$sellerTotalPages = max(1, (int) ceil(count($sellersAll) / $perPage));
$buyerTotalPages = max(1, (int) ceil(count($buyersAll) / $perPage));
$sellerPage = min($sellerPage, $sellerTotalPages);
$buyerPage = min($buyerPage, $buyerTotalPages);
$sellers = array_slice($sellersAll, ($sellerPage - 1) * $perPage, $perPage);
$buyers = array_slice($buyersAll, ($buyerPage - 1) * $perPage, $perPage);
qb_lb_save_rank_snapshot($sellersAll, 'seller', 'global', null);
qb_lb_save_rank_snapshot($buyersAll, 'buyer', 'global', null);

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

qb_page_start('buyer', 'Leaderboards', 'leaderboards.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main">
  <div class="page-header qb-dash-header">
    <div>
      <h1 class="page-title qb-dash-title"><?= qb_icon('activity', 'qb-icon', 22) ?> Leaderboards</h1>
      <p class="page-subtitle qb-dash-subtitle">Privacy-safe rankings by activity only. No revenue or spending is shown.</p>
    </div>
    <div class="qb-dash-actions">
      <form method="get" class="form-group mb-0" style="display:flex;align-items:center;gap:0.5rem">
        <label class="form-label mb-0 text-xs" for="lbpp">Show</label>
        <select id="lbpp" name="per_page" class="form-control" style="min-width:90px" onchange="this.form.submit()">
          <?php foreach ($allowedPerPage as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="seller_page" value="1"/>
        <input type="hidden" name="buyer_page" value="1"/>
      </form>
      <a href="home.php" class="btn btn-secondary btn-sm"><?= qb_icon('home', 'qb-icon', 16) ?> Home</a>
    </div>
  </div>

  <div class="grid grid-2 gap-2" style="align-items:start">
    <div class="card">
      <h2 class="font-bold mb-2" style="font-size:1rem">Top sellers</h2>
      <p class="text-xs text-muted mb-2">Ranked by completed orders and trust score.</p>
      <?php if (empty($sellers)): ?>
        <p class="text-muted text-sm mb-0">No data yet.</p>
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
            <?php foreach ($sellers as $row): ?>
            <?php
              $sid = (int) ($row['seller_id'] ?? 0);
              $sStat = $sellerStats[$sid] ?? ['avg_stars' => 0.0, 'rating_count' => 0];
              $trustLabel = ((float) $sStat['avg_stars'] > 0) ? number_format((float) $sStat['avg_stars'], 1) . '/5' : 'New';
            ?>
            <tr>
              <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span><?php if ($rankBadge((int) $row['rank']) !== ''): ?> <span class="qb-lb-medal"><?= $rankBadge((int) $row['rank']) ?></span><?php endif; ?></td>
              <td class="font-bold"><?= htmlspecialchars($row['market_name']) ?></td>
              <td class="text-right font-bold text-emerald"><?= (int) ($row['orders'] ?? 0) ?></td>
              <td class="text-right text-sm"><?= htmlspecialchars($trustLabel) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($sellerTotalPages > 1): ?>
      <div class="qb-lb-pagination">
        <?php for ($p = 1; $p <= $sellerTotalPages; $p++): ?>
          <a class="btn btn-ghost btn-sm<?= $p === $sellerPage ? ' is-active' : '' ?>" href="?per_page=<?= $perPage ?>&seller_page=<?= $p ?>&buyer_page=<?= $buyerPage ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="font-bold mb-2" style="font-size:1rem">Top buyers</h2>
      <p class="text-xs text-muted mb-2">Ranked by completed orders and event participation. Your row is highlighted when you appear.</p>
      <?php if (empty($buyers)): ?>
        <p class="text-muted text-sm mb-0">No ranked buyers yet (linked purchases only).</p>
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
            <?php
              $bid = (int) ($row['buyer_id'] ?? 0);
              $bStat = $buyerStats[$bid] ?? ['event_count' => 0];
            ?>
            <tr class="<?= !empty($row['is_viewer']) ? 'qb-lb-row--me' : '' ?>">
              <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span><?php if ($rankBadge((int) $row['rank']) !== ''): ?> <span class="qb-lb-medal"><?= $rankBadge((int) $row['rank']) ?></span><?php endif; ?></td>
              <td class="font-bold"><?= htmlspecialchars($row['label']) ?><?= !empty($row['is_viewer']) ? ' (you)' : '' ?></td>
              <td class="text-right font-bold text-emerald"><?= (int) ($row['orders'] ?? 0) ?></td>
              <td class="text-right text-sm"><?= (int) ($bStat['event_count'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($buyerTotalPages > 1): ?>
      <div class="qb-lb-pagination">
        <?php for ($p = 1; $p <= $buyerTotalPages; $p++): ?>
          <a class="btn btn-ghost btn-sm<?= $p === $buyerPage ? ' is-active' : '' ?>" href="?per_page=<?= $perPage ?>&seller_page=<?= $sellerPage ?>&buyer_page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<?php qb_page_end(); ?>
