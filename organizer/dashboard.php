<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireOrganizer();

$user = currentUser();
$uid = (int)$user['id'];
$orgLocked = (currentRole() === 'organizer' && !qb_organizer_portal_open($uid));
$orgAssignment = qb_organizer_assignment_counts($uid);
$coOnlyAccount = qb_organizer_is_co_only($uid);
$approvalReady = qb_event_approval_schema_ready();

$ew = qb_organizer_event_alias_access_sql('e');
$eb = qb_organizer_event_access_bind($uid);

$events = db()->fetchAll(
    "SELECT * FROM bazar_events e WHERE $ew ORDER BY event_start DESC",
    $eb
);

// ── Dashboard stats cache (60-second TTL, per-organizer) ─────────────────────
$_dashCacheKey = 'qb_org_dash_stats_' . $uid;
$_dashCache    = $_SESSION[$_dashCacheKey] ?? null;
$_dashCacheHit = is_array($_dashCache) && isset($_dashCache['ts']) && (time() - (int) $_dashCache['ts']) < 60;

if (!$_dashCacheHit) {
    $statTickets = (int) (db()->fetchOne(
        "SELECT COUNT(*) AS c FROM tickets t INNER JOIN bazar_events e ON e.id = t.event_id WHERE $ew",
        $eb
    )['c'] ?? 0);
    $statRev = (float) (db()->fetchOne(
        "SELECT COALESCE(SUM(t.total_amount), 0) AS s FROM transactions t INNER JOIN bazar_events e ON e.id = t.event_id WHERE t.payment_status = 'completed' AND $ew",
        $eb
    )['s'] ?? 0);
    $statParticipants = (int) (db()->fetchOne(
        "SELECT COUNT(*) AS c FROM event_participants ep INNER JOIN bazar_events e ON e.id = ep.event_id WHERE $ew",
        $eb
    )['c'] ?? 0);
    $statSellers = (int) (db()->fetchOne(
        "SELECT COUNT(*) AS c FROM event_participants ep INNER JOIN bazar_events e ON e.id = ep.event_id WHERE ep.role_in_event = 'seller' AND $ew",
        $eb
    )['c'] ?? 0);

    $revLastHour = (float) (db()->fetchOne(
        "SELECT COALESCE(SUM(tx.total_amount), 0) AS s FROM transactions tx INNER JOIN bazar_events e ON e.id = tx.event_id WHERE tx.payment_status = 'completed' AND tx.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND $ew",
        $eb
    )['s'] ?? 0);

    $ticketsToday = (int) (db()->fetchOne(
        "SELECT COUNT(*) AS c FROM tickets tk INNER JOIN bazar_events e ON e.id = tk.event_id WHERE DATE(tk.issued_at) = CURDATE() AND $ew",
        $eb
    )['c'] ?? 0);
    $ticketsYesterday = (int) (db()->fetchOne(
        "SELECT COUNT(*) AS c FROM tickets tk INNER JOIN bazar_events e ON e.id = tk.event_id WHERE DATE(tk.issued_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND $ew",
        $eb
    )['c'] ?? 0);

    $paySplit = db()->fetchAll(
        "SELECT tx.payment_method, COUNT(*) AS n, COALESCE(SUM(tx.total_amount), 0) AS amt FROM transactions tx INNER JOIN bazar_events e ON e.id = tx.event_id WHERE tx.payment_status = 'completed' AND $ew GROUP BY tx.payment_method ORDER BY amt DESC",
        $eb
    );

    // Cache the computed stats in the session.
    $_SESSION[$_dashCacheKey] = [
        'ts'               => time(),
        'statTickets'      => $statTickets,
        'statRev'          => $statRev,
        'statParticipants' => $statParticipants,
        'statSellers'      => $statSellers,
        'revLastHour'      => $revLastHour,
        'ticketsToday'     => $ticketsToday,
        'ticketsYesterday' => $ticketsYesterday,
        'paySplit'         => $paySplit,
    ];
} else {
    $statTickets      = (int)   $_dashCache['statTickets'];
    $statRev          = (float) $_dashCache['statRev'];
    $statParticipants = (int)   $_dashCache['statParticipants'];
    $statSellers      = (int)   $_dashCache['statSellers'];
    $revLastHour      = (float) $_dashCache['revLastHour'];
    $ticketsToday     = (int)   $_dashCache['ticketsToday'];
    $ticketsYesterday = (int)   $_dashCache['ticketsYesterday'];
    $paySplit         = (array) $_dashCache['paySplit'];
}
// ─────────────────────────────────────────────────────────────────────────────

$ticketHint = '';
if ($ticketsYesterday > 0) {
    $pct = (int) round((($ticketsToday - $ticketsYesterday) / $ticketsYesterday) * 100);
    $ticketHint = $pct >= 0 ? ('+' . $pct . '% vs yesterday') : ($pct . '% vs yesterday');
} elseif ($ticketsToday > 0) {
    $ticketHint = 'first tickets today';
}

$orgTip = 'Publish events early and keep seller lists current — buyers plan around your window.';
if ($statParticipants < 5) {
    $orgTip = 'Invite sellers and open ticket sales to build momentum before the bazar date.';
}

$liveOrgEvent = db()->fetchOne(
    "SELECT e.name, e.status FROM bazar_events e WHERE $ew AND e.status IN ('published','live') ORDER BY e.event_start DESC LIMIT 1",
    $eb
);
$liveOrgEventClass = '';
if (!empty($liveOrgEvent['status'])) {
    $liveOrgEventClass = 'qb-event-status qb-event-status--' . preg_replace('/[^a-z_]/', '', strtolower((string) $liveOrgEvent['status']));
}

// Get Chart Data - Ticket Sales per Event
$chartQuery = db()->fetchAll("
    SELECT e.name, COUNT(t.id) as tickets 
    FROM bazar_events e 
    LEFT JOIN tickets t ON e.id = t.event_id 
    WHERE $ew 
    GROUP BY e.id, e.name LIMIT 5
", $eb);
$evNames = []; $tkts = [];
foreach($chartQuery as $c) {
    array_push($evNames, substr($c['name'],0,15));
    array_push($tkts, $c['tickets']);
}

$ticketTrend = db()->fetchAll("
    SELECT DATE(tk.issued_at) AS dt, COUNT(*) AS cnt
    FROM tickets tk
    INNER JOIN bazar_events e ON e.id = tk.event_id
    WHERE $ew AND tk.issued_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY dt ORDER BY dt ASC
", $eb);
$ticketTrendDates = [];
$ticketTrendCounts = [];
foreach ($ticketTrend as $tr) {
    $ticketTrendDates[] = $tr['dt'];
    $ticketTrendCounts[] = (int) $tr['cnt'];
}
$eventRevenue = [];
$eventTickets = [];
$eventStatusLabels = [];
$eventStatusCounts = [];
foreach ($events as $evr) {
    $eventRevenue[] = (float) (db()->fetchOne(
        'SELECT COALESCE(SUM(total_amount),0) AS s FROM transactions WHERE event_id = ? AND payment_status = ?',
        [(int) $evr['id'], 'completed']
    )['s'] ?? 0);
    $eventTickets[] = (int) (db()->fetchOne(
        'SELECT COUNT(*) AS c FROM tickets WHERE event_id = ?',
        [(int) $evr['id']]
    )['c'] ?? 0);
    $st = (string) ($evr['status'] ?? 'draft');
    if (!isset($eventStatusCounts[$st])) {
        $eventStatusCounts[$st] = 0;
    }
    $eventStatusCounts[$st]++;
}
$eventStatusLabels = array_keys($eventStatusCounts);
$eventStatusValues = array_values($eventStatusCounts);

qb_page_start('organizer', 'My Events', 'dashboard.php', true);

$notice = $_GET['notice'] ?? '';
$noticeMsg = '';
$noticeClass = 'alert-warning';
$rawName = trim((string) ($user['display_name'] ?? ''));
$firstName = $rawName !== '' ? htmlspecialchars(explode(' ', $rawName)[0], ENT_QUOTES, 'UTF-8') : 'Organizer';
$h = (int) date('G');
if ($h < 12) {
    $greeting = 'Good morning';
} elseif ($h < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}
$dashSubtitle = 'Your bazar programs';
if (!empty($events[0])) {
    $dashSubtitle = htmlspecialchars($events[0]['venue']) . ' · ' . htmlspecialchars($events[0]['city']);
}
if ($notice === 'event_not_found') {
    $noticeMsg = 'That event was not found or you do not have access to it.';
    $noticeClass = 'alert-danger';
} elseif ($notice === 'announcement_sent') {
    $noticeMsg = 'Announcement sent to buyers registered for that bazar.';
    $noticeClass = 'alert-success';
}
?>

<?php if ($noticeMsg !== ''): ?>
<div class="alert <?= htmlspecialchars($noticeClass) ?> mb-3" role="status"><?= htmlspecialchars($noticeMsg) ?></div>
<?php endif; ?>

<div class="organizer-dashboard">

<div class="page-header qb-dash-header">
  <div>
    <h1 class="page-title qb-dash-title"><?= htmlspecialchars($greeting) ?>, <?= $firstName ?></h1>
    <p class="page-subtitle qb-dash-subtitle"><?= $dashSubtitle ?></p>
    <div class="qb-role-identity-stack mt-1">
      <span class="badge badge-blue">Role: <?= $coOnlyAccount ? 'Co-organizer' : 'Organizer' ?></span>
      <?php if ((int) $orgAssignment['primary'] > 0): ?>
      <span class="badge badge-blue">Event ownership: Primary (<?= (int) $orgAssignment['primary'] ?>)</span>
      <?php endif; ?>
      <?php if ((int) $orgAssignment['co'] > 0): ?>
      <span class="badge badge-violet">Event ownership: Assigned Co-organizer (<?= (int) $orgAssignment['co'] ?>)</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="qb-dash-actions">
    <span class="qb-org-pill"><?= qb_icon('calendar', 'qb-icon', 14) ?> <?= $coOnlyAccount ? 'Co-organizer' : 'Organizer' ?></span>
    <a href="leaderboards.php" class="btn btn-secondary btn-sm"><?= qb_icon('star', 'qb-icon', 16) ?> Ranks</a>
    <?php if (!$coOnlyAccount): ?>
    <a href="announcements.php" class="btn btn-secondary btn-sm"><?= qb_icon('announce', 'qb-icon', 16) ?> Notify</a>
    <a href="event.php" class="btn btn-primary"><?= qb_icon('plus') ?> Create Event</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($coOnlyAccount): ?>
<div class="alert alert-info mb-3" role="status">
  You are assigned as a <strong>co-organizer</strong> (<?= (int) $orgAssignment['co'] ?> event<?= (int) $orgAssignment['co'] === 1 ? '' : 's' ?>). You can run operations, but only primary organizers can create/edit core event settings.
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="font-bold mb-2"><?= qb_icon('list', 'qb-icon', 16) ?> Bulk operations hub</div>
  <div class="text-xs text-muted mb-2">Use one quick selector to jump to bulk actions.</div>
  <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:end">
    <div class="form-group mb-0" style="min-width:260px;max-width:460px;flex:1">
      <label class="form-label" for="bulkQuickAction">Quick action</label>
      <select id="bulkQuickAction" class="form-control">
        <option value="">Select action...</option>
        <?php if (!$coOnlyAccount): ?>
        <option value="sellers.php">Seller assignments</option>
        <option value="announcements.php">Announcements</option>
        <?php endif; ?>
        <option value="ticket_scan.php">Gate scanning</option>
        <option value="leaderboards.php">Leaderboards</option>
        <option value="scan_history.php">Scan History</option>
      </select>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="(function(){var v=document.getElementById('bulkQuickAction').value;if(v){window.location=v;}})()">Go</button>
  </div>
</div>

<?php
$orgPromos = qb_fetch_active_promos_for('organizer');
if (!empty($orgPromos)) {
    $promos = $orgPromos;
    $promoHeading = 'Spotlight';
    require __DIR__ . '/../includes/partials/promo_spotlight.php';
}
?>

<?php if ($orgLocked): ?>
<div class="alert alert-warning mb-3" style="border-radius:14px">
  <?= qb_icon('alert', 'qb-icon', 18) ?>
  <div>
    <strong>Organizer portal is outside your assigned time window.</strong>
    <p class="text-sm mb-0 mt-1">Ask the admin to set <em>Organizer portal opens / closes</em> on your event, or use the event start/end dates. You can still view past events below.</p>
  </div>
</div>
<?php endif; ?>

<?php if ($liveOrgEvent): ?>
<div class="card card--pulse-event mb-3">
  <div class="qb-pulse-event__row">
    <?= qb_icon('calendar', 'qb-icon', 20) ?>
    <div>
      <div class="text-xs text-muted text-uppercase font-bold">Active bazar</div>
      <div class="font-bold"><?= htmlspecialchars($liveOrgEvent['name']) ?> <span class="badge <?= htmlspecialchars($liveOrgEventClass) ?>"><?= htmlspecialchars($liveOrgEvent['status']) ?></span></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card qb-pulse-strip mb-3">
  <div class="qb-pulse-strip__title text-xs text-muted text-uppercase font-bold mb-2">Live pulse</div>
  <div class="grid grid-3 gap-2 qb-pulse-grid">
    <div class="qb-pulse-cell">
      <div class="qb-pulse-cell__label">Last hour revenue</div>
      <div class="qb-pulse-cell__val"><?= number_format($revLastHour, 2) ?> <span class="text-xs">ETB</span></div>
    </div>
    <div class="qb-pulse-cell">
      <div class="qb-pulse-cell__label">Tickets issued today</div>
      <div class="qb-pulse-cell__val"><?= number_format($ticketsToday) ?></div>
      <?php if ($ticketHint !== ''): ?><div class="qb-pulse-cell__hint text-xs text-muted"><?= htmlspecialchars($ticketHint) ?></div><?php endif; ?>
    </div>
    <div class="qb-pulse-cell">
      <div class="qb-pulse-cell__label">Yesterday's tickets</div>
      <div class="qb-pulse-cell__val"><?= number_format($ticketsYesterday) ?></div>
    </div>
  </div>
  <div class="qb-pulse-footer">
    <p class="text-xs text-muted mb-0 qb-pulse-footer__tip"><?= htmlspecialchars($orgTip) ?></p>
    <?php if (!empty($events)): ?>
    <a href="export_sales.php?event=<?= (int) $events[0]['id'] ?>" class="btn btn-secondary btn-sm"><?= qb_icon('download', 'qb-icon', 16) ?> Export CSV</a>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-4 gap-2 mb-4 qb-stat-grid">
  <div class="stat-card qb-stat-card stat-card--data-revenue">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('chart', 'qb-icon', 22) ?></div>
    <div class="stat-label">Event revenue</div>
    <div class="stat-value"><?= number_format($statRev, 0) ?> <span class="qb-currency">ETB</span></div>
    <div class="stat-change up">↑ Completed sales</div>
  </div>
  <div class="stat-card qb-stat-card stat-card--data-tickets">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('ticket', 'qb-icon', 22) ?></div>
    <div class="stat-label">Tickets sold</div>
    <div class="stat-value"><?= number_format($statTickets) ?></div>
    <div class="stat-change up">↑ Across your bazars</div>
  </div>
  <div class="stat-card qb-stat-card stat-card--data-events">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('calendar', 'qb-icon', 22) ?></div>
    <div class="stat-label">Your events</div>
    <div class="stat-value"><?= number_format(count($events)) ?></div>
    <div class="stat-change up">↑ Assigned to you</div>
  </div>
  <div class="stat-card qb-stat-card stat-card--data-activity">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('people', 'qb-icon', 22) ?></div>
    <div class="stat-label">Participants</div>
    <div class="stat-value"><?= number_format($statParticipants) ?></div>
    <div class="stat-change up"><?= number_format($statSellers) ?> seller roles</div>
  </div>
</div>

<?php if (empty($events)): ?>
  <div class="empty-state">
    <?= qb_icon('calendar', 'qb-icon', 48) ?>
    <h3>No Events Found</h3>
    <p>You haven't organized any bazar events yet.</p>
    <a href="event.php" class="btn btn-primary btn-lg mt-3" style="padding:0.75rem 2rem"><?= qb_icon('plus') ?> Create your first event</a>
  </div>
<?php else: ?>

  <div class="card qb-chart-card card--data-tickets mb-4">
      <div class="qb-chart-card__head">
        <h3 class="qb-chart-card__title">Ticket issuance by event</h3>
        <span class="qb-chart-badge">Tickets</span>
      </div>
      <div class="chart-wrapper qb-chart-wrap"><canvas id="tktsChart"></canvas></div>
  </div>
  <div class="grid grid-2 gap-2 mb-4">
    <div class="card qb-chart-card card--data-revenue">
      <div class="qb-chart-card__head">
        <h3 class="qb-chart-card__title">Tickets vs revenue by event</h3>
        <span class="qb-chart-badge">COMPARE</span>
      </div>
      <div class="chart-wrapper qb-chart-wrap"><canvas id="orgCompareChart"></canvas></div>
    </div>
    <div class="card qb-chart-card card--data-status">
      <div class="qb-chart-card__head">
        <h3 class="qb-chart-card__title">Event status distribution</h3>
        <span class="qb-chart-badge">STATUS</span>
      </div>
      <div class="chart-wrapper qb-chart-wrap"><canvas id="orgStatusChart"></canvas></div>
    </div>
  </div>

  <div class="grid grid-2 gap-2">
    <?php foreach ($events as $e): 
        $stats = qb_event_stats((int)$e['id']);
        $live = qb_tickets_live_for_event((int)$e['id']);
        $status = (string) ($e['status'] ?? 'draft');
        $evIsCanceled = ($status === 'canceled');
        $statusClass = 'qb-event-status qb-event-status--' . preg_replace('/[^a-z_]/', '', strtolower($status));
        $approvalStatus = $approvalReady ? (string) ($e['approval_status'] ?? 'approved') : 'approved';
        $approvalBadge = [
          'approved' => 'badge-green',
          'pending' => 'badge-amber',
          'rejected' => 'badge-red',
        ][$approvalStatus] ?? 'badge-gray';
        $audioOn = !empty($e['live_audio_url']);
    ?>
    <div class="event-card card qb-organizer-event-card">
      <div class="event-card-header qb-organizer-event-card__header">
        <div>
          <div class="event-card-name text-lg mb-1"><?= htmlspecialchars($e['name']) ?></div>
          <div class="event-card-venue text-xs font-bold text-muted text-uppercase">
            <?= htmlspecialchars($e['venue']) ?> • <?= htmlspecialchars($e['city']) ?>
            <span class="text-secondary ml-1">— <?= ((int)($e['organizer_app_user_id'] ?? 0) === $uid) ? 'Primary' : 'Co-organizer' ?></span>
          </div>
        </div>
        <span class="badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($status) ?></span>
      </div>
      
      <p class="text-xs text-secondary mb-3 qb-organizer-event-card__ticket-line">Tickets: <?= $e['ticket_sales_start'] ?: 'TBD' ?> to <?= $e['ticket_sales_end'] ?: 'TBD' ?></p>
      
      <div class="grid grid-2 gap-1 mb-3 qb-organizer-event-card__stats">
        <div class="qb-organizer-event-card__stat-box">
          <div class="text-xs text-muted font-bold text-uppercase mb-1">Participants</div>
          <div class="font-black text-2xl qb-organizer-event-card__stat-value"><?= $stats['participants'] ?></div>
          <div class="text-xs text-secondary mt-1"><?= $stats['buyers'] ?> Buyers, <?= $stats['sellers'] ?> Sellers</div>
        </div>
        <div class="qb-organizer-event-card__stat-box">
          <div class="text-xs text-muted font-bold text-uppercase mb-1">Sales Flow</div>
          <div class="font-black text-2xl text-emerald"><?= number_format($stats['revenue'], 0) ?></div>
          <div class="text-xs text-emerald mt-1 font-bold"><?= $stats['tx_count'] ?> Transactions</div>
        </div>
      </div>
      
      <div class="qb-organizer-event-card__actions" style="display:flex;flex-wrap:wrap;gap:0.4rem">
          <a href="ticket_scan.php?event_id=<?= $e['id'] ?>" class="btn btn-primary btn-full btn-sm" style="flex:1 1 100px"><?= qb_icon('scan', 'qb-icon', 14) ?> Scan</a>
          <a href="leaderboards.php?event=<?= (int) $e['id'] ?>" class="btn btn-secondary btn-full btn-sm" style="flex:1 1 100px"><?= qb_icon('star', 'qb-icon', 14) ?> Ranks</a>
          <?php if (!$coOnlyAccount): ?>
          <a href="event.php?id=<?= $e['id'] ?>" class="btn btn-ghost btn-full btn-sm" style="flex:1 1 80px"><?= $evIsCanceled ? 'View' : 'Edit' ?></a>
          <a href="sellers.php?event=<?= $e['id'] ?>" class="btn btn-ghost btn-full btn-sm" style="flex:1 1 80px" <?= $evIsCanceled ? 'style="opacity:0.65"' : '' ?>><?= $evIsCanceled ? 'Sellers' : 'Sellers' ?></a>
          <a href="announcements.php?event=<?= (int) $e['id'] ?>" class="btn btn-ghost btn-full btn-sm" style="flex:1 1 80px"><?= qb_icon('announce', 'qb-icon', 14) ?> <?= $evIsCanceled ? 'History' : 'Notify' ?></a>
          <a href="export_sales.php?event=<?= (int) $e['id'] ?>" class="btn btn-ghost btn-full btn-sm" style="flex:1 1 60px"><?= qb_icon('download', 'qb-icon', 14) ?> CSV</a>
          <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>

<script>
<?php if (!empty($events)): ?>
document.addEventListener("DOMContentLoaded", function() {
    const palette = ['#2563eb','#16a34a','#f97316','#a855f7','#06b6d4','#e11d48','#0f766e','#9333ea','#f59e0b'];
    new Chart(document.getElementById('tktsChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($evNames) ?>,
            datasets: [{
                label: 'Tickets',
                data: <?= json_encode($tkts) ?>,
                backgroundColor: palette.map((c) => c + '88'),
                borderRadius: 10,
                maxBarThickness: 28
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, suggestedMax: 10 } }
        }
    });
    const cmp = document.getElementById('orgCompareChart');
    if (cmp) {
      new Chart(cmp, {
        data: {
          labels: <?= json_encode($evNames) ?>,
          datasets: [
            {
              type: 'bar',
              label: 'Tickets',
              data: <?= json_encode($eventTickets) ?>,
              yAxisID: 'y',
              backgroundColor: 'rgba(37,99,235,0.55)',
              borderRadius: 8
            },
            {
              type: 'line',
              label: 'Revenue (ETB)',
              data: <?= json_encode($eventRevenue) ?>,
              yAxisID: 'y1',
              borderColor: 'rgba(22,163,74,0.95)',
              backgroundColor: 'rgba(22,163,74,0.15)',
              borderWidth: 2,
              tension: 0.35
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { display: true, position: 'bottom' } },
          scales: {
            y: { beginAtZero: true, title: { display: true, text: 'Tickets' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Revenue' } }
          }
        }
      });
    }
    const statusCtx = document.getElementById('orgStatusChart');
    if (statusCtx) {
      new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: <?= json_encode($eventStatusLabels) ?>,
          datasets: [{
            data: <?= json_encode($eventStatusValues) ?>,
            backgroundColor: ['#16a34a','#2563eb','#f59e0b','#6b7280','#ef4444','#a855f7'],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: true, position: 'bottom' } }
        }
      });
    }
    var ttc = document.getElementById('orgTicketTrendChart');
    if (ttc) {
        new Chart(ttc, {
            type: 'line',
            data: {
                labels: <?= json_encode($ticketTrendDates) ?>,
                datasets: [{
                    label: 'Tickets',
                    data: <?= json_encode($ticketTrendCounts) ?>,
                    borderColor: 'rgba(46, 134, 193, 0.9)',
                    backgroundColor: 'rgba(46, 134, 193, 0.12)',
                    borderWidth: 2,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
});
<?php endif; ?>
</script>

<?php qb_page_end(); ?>
