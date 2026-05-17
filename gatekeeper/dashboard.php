<?php
/**
 * Gate portal home — assigned bazars, phone-friendly quick stats.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireGatekeeper();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$u = currentUser();
$phone = trim((string) ($u['phone'] ?? ''));
$eids = qb_event_staff_event_ids($uid);
$events = [];
if ($eids !== []) {
    $ph = implode(',', array_fill(0, count($eids), '?'));
    $events = db()->fetchAll(
        "SELECT id, name, city, venue, event_start, event_end, status
         FROM bazar_events
         WHERE id IN ($ph)
         ORDER BY event_start DESC",
        $eids
    );
}

$todayAdmits = 0;
$txCount = 0;
$txSum = 0.0;
if ($eids !== []) {
    $ph = implode(',', array_fill(0, count($eids), '?'));
    $r1 = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM tickets WHERE event_id IN ($ph) AND status = 'used' AND DATE(COALESCE(used_at, created_at)) = CURDATE()",
        $eids
    );
    $todayAdmits = (int) ($r1['c'] ?? 0);
    $r2 = db()->fetchOne(
        "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS s FROM transactions WHERE event_id IN ($ph) AND payment_status = 'completed'",
        $eids
    );
    $txCount = (int) ($r2['c'] ?? 0);
    $txSum = (float) ($r2['s'] ?? 0);
}

qb_page_start('gatekeeper', 'Gate dashboard', 'dashboard.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Gate dashboard</h1>
    <p class="page-subtitle">Your assigned bazars, admissions today, and marketplace activity — optimized for phones.</p>
  </div>
</div>

<?php if ($phone !== ''): ?>
<div class="card mb-3 qb-gk-phone-card" style="padding:1rem 1.1rem">
  <div class="text-xs text-uppercase text-muted mb-1">Your sign-in phone</div>
  <div class="font-bold" style="font-size:1.15rem;letter-spacing:0.02em"><?= htmlspecialchars($phone) ?></div>
  <p class="text-sm text-secondary mb-0 mt-2">Use this number at login with your password. Ask the organizer if you lose access.</p>
</div>
<?php else: ?>
<div class="alert alert-warning mb-3" role="status">
  <p class="mb-0 qb-alert-prose">Add a phone number to your account (admin <strong>Users</strong>) so you can sign in easily at the gate.</p>
</div>
<?php endif; ?>

<?php if (empty($events)): ?>
  <div class="alert alert-info" role="status">
    <p class="mb-0 qb-alert-prose">You are not assigned to any bazar yet. An <strong>organizer</strong> or <strong>admin</strong> must add you under <strong>Gate staff</strong> with an end date for your access.</p>
  </div>
<?php else: ?>

<div class="grid grid-2 gap-2 mb-3" style="align-items:stretch">
  <div class="stat-card" style="padding:1rem">
    <div class="stat-label mb-1">Admissions today</div>
    <div class="stat-value text-xl"><?= (int) $todayAdmits ?></div>
    <div class="text-xs text-muted mt-1">Tickets marked used today across your bazars.</div>
  </div>
  <div class="stat-card" style="padding:1rem">
    <div class="stat-label mb-1">Completed sales (all time)</div>
    <div class="stat-value text-xl"><?= (int) $txCount ?></div>
    <div class="text-xs text-muted mt-1">Total <strong><?= number_format($txSum, 2) ?></strong> ETB on-record for these events.</div>
  </div>
</div>

<div class="card mb-2">
  <h2 class="font-bold mb-2" style="font-size:1.05rem">Your bazars</h2>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Bazar</th>
          <th>When</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
        <tr>
          <td>
            <div class="font-bold"><?= htmlspecialchars((string) $ev['name']) ?></div>
            <div class="text-xs text-muted"><?= htmlspecialchars((string) ($ev['city'] ?? '')) ?><?= !empty($ev['venue']) ? ' · ' . htmlspecialchars((string) $ev['venue']) : '' ?></div>
          </td>
          <td class="text-sm">
            <?= !empty($ev['event_start']) ? htmlspecialchars(date('D M j · g:i A', strtotime((string) $ev['event_start']))) : 'TBD' ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-secondary btn-sm" href="leaderboards.php?event=<?= (int) $ev['id'] ?>">Leaderboard</a>
            <a class="btn btn-primary btn-sm" href="ticket_scan.php?event_id=<?= (int) $ev['id'] ?>">Scan</a>
            <a class="btn btn-secondary btn-sm" href="seller_scan.php?event_id=<?= (int) $ev['id'] ?>">Seller gate</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php qb_page_end(); ?>
