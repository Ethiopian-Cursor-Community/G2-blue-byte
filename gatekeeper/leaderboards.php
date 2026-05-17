<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireGatekeeper();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$eids = qb_event_staff_event_ids($uid);
$events = [];
if ($eids !== []) {
    $ph = implode(',', array_fill(0, count($eids), '?'));
    $events = db()->fetchAll(
        "SELECT e.id, e.name FROM bazar_events e WHERE e.id IN ($ph) ORDER BY e.event_start DESC",
        $eids
    );
}

$reqEv = isset($_GET['event']) ? (int) $_GET['event'] : 0;
$eventId = qb_organizer_resolve_event_id($reqEv > 0 ? $reqEv : null, $events);
if ($eventId > 0 && !qb_lb_gatekeeper_event_allowed($uid, $eventId)) {
    $eventId = 0;
}

$sellers = $eventId > 0 ? qb_lb_sellers_event($eventId, 30) : [];
$buyers = $eventId > 0 ? qb_lb_buyers_event($eventId, 30) : [];
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

qb_page_start('gatekeeper', 'Leaderboards', 'leaderboards.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= qb_icon('star', 'qb-icon', 22) ?> Leaderboards</h1>
    <p class="page-subtitle">Top sellers and buyers for bazars you are assigned to scan.</p>
  </div>
</div>

<?php if (empty($events)): ?>
  <div class="alert alert-warning">No bazar assignments yet. Ask your organizer to add you under Gate staff.</div>
<?php else: ?>
<form method="get" class="card mb-2" style="padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
  <div class="form-group mb-0" style="flex:1;min-width:200px">
    <label class="form-label" for="ev">Bazar</label>
    <select name="event" id="ev" class="form-control" onchange="this.form.submit()">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $eventId ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if ($eventId > 0): ?>
<p class="text-sm text-muted mb-2">Showing: <strong><?= htmlspecialchars($eventName) ?></strong></p>
<?php endif; ?>

<div class="grid grid-2 gap-2" style="align-items:start">
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1.1rem">Sellers</h2>
    <?php if (empty($sellers)): ?>
      <p class="text-muted text-sm mb-0">No sales data for this bazar yet.</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="qb-lb-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Market</th>
            <th class="text-right">Revenue</th>
            <th class="text-right">Orders</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sellers as $row): ?>
          <tr>
            <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span></td>
            <td class="font-bold"><?= htmlspecialchars($row['market_name']) ?></td>
            <td class="text-right font-bold text-emerald"><?= number_format($row['revenue'], 2) ?></td>
            <td class="text-right"><?= (int) $row['orders'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1.1rem">Buyers</h2>
    <?php if (empty($buyers)): ?>
      <p class="text-muted text-sm mb-0">No buyer spend recorded for this bazar yet.</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="qb-lb-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Buyer</th>
            <th class="text-right">Spend</th>
            <th class="text-right">Orders</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($buyers as $row): ?>
          <tr>
            <td><span class="<?= $rankClass((int) $row['rank']) ?>"><?= (int) $row['rank'] ?></span></td>
            <td class="font-bold"><?= htmlspecialchars($row['label']) ?></td>
            <td class="text-right font-bold text-emerald"><?= number_format($row['spend'], 2) ?></td>
            <td class="text-right"><?= (int) $row['orders'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php qb_page_end(); ?>
