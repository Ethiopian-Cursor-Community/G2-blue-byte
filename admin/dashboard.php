<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$users = db()->fetchOne("SELECT COUNT(*) as c FROM app_users")['c'];
$events = db()->fetchOne("SELECT COUNT(*) as c FROM bazar_events")['c'];
$eventsLive = db()->fetchOne("SELECT COUNT(*) as c FROM bazar_events WHERE status IN ('published','live')")['c'];
$eventsDraft = db()->fetchOne("SELECT COUNT(*) as c FROM bazar_events WHERE status = 'draft'")['c'];
$products = db()->fetchOne("SELECT COUNT(*) as c FROM products")['c'];
$revenue = db()->fetchOne("SELECT COALESCE(SUM(total_amount), 0) as t FROM transactions WHERE payment_status = 'completed'")['t'];
$txN = db()->fetchOne("SELECT COUNT(*) as c FROM transactions WHERE payment_status = 'completed'")['c'];
$txPendingN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status = 'pending'")['c'] ?? 0);
$txFailedN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status = 'failed'")['c'] ?? 0);
$revToday = db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM transactions WHERE payment_status='completed' AND DATE(created_at)=CURDATE()")['t'] ?? 0;
$txToday = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status='completed' AND DATE(created_at)=CURDATE()")['c'] ?? 0);
$ticketsN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM tickets")['c'] ?? 0);
$scansN = 0;
$ratingsN = 0;
try {
    $scansN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM analytics_events WHERE event_type='qr_scan'")['c'] ?? 0);
} catch (Throwable $e) { /* */ }
try {
    $ratingsN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM ratings")['c'] ?? 0);
} catch (Throwable $e) { /* */ }
$pendingProducts = qb_pending_product_count();

$pendingRoles = 0;
if (function_exists('qb_role_request_columns_ready') && qb_role_request_columns_ready()) {
    try {
        $pendingRoles = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role_request_status = 'pending'")['c'] ?? 0);
    } catch (Throwable $e) { /* */ }
}

$sellerProfiles = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM sellers WHERE is_active = 1")['c'] ?? 0);
$avgOrder = (float) (db()->fetchOne("SELECT COALESCE(AVG(total_amount), 0) AS a FROM transactions WHERE payment_status = 'completed'")['a'] ?? 0);
$txWeek = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['c'] ?? 0);

try {
    $notifN = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM notifications')['c'] ?? 0);
} catch (Throwable $e) {
    $notifN = 0;
}

$buyers = db()->fetchOne("SELECT COUNT(*) as c FROM app_users WHERE role = 'buyer'")['c'];
$sellers = db()->fetchOne("SELECT COUNT(*) as c FROM app_users WHERE role = 'seller'")['c'];
$organizers = db()->fetchOne("SELECT COUNT(*) as c FROM app_users WHERE role = 'organizer'")['c'];

$chartData = db()->fetchAll("
    SELECT DATE(created_at) as dt, SUM(total_amount) as rev 
    FROM transactions 
    WHERE payment_status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
    GROUP BY dt ORDER BY dt ASC
");

$dates = [];
$revs = [];
foreach ($chartData as $d) {
    $dates[] = $d['dt'];
    $revs[] = (float) $d['rev'];
}

$txCountData = db()->fetchAll("
    SELECT DATE(created_at) AS dt, COUNT(*) AS cnt
    FROM transactions
    WHERE payment_status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY dt ORDER BY dt ASC
");
$txDates = [];
$txCounts = [];
foreach ($txCountData as $r) {
    $txDates[] = $r['dt'];
    $txCounts[] = (int) $r['cnt'];
}

$ticketsWeek = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM tickets WHERE issued_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['c'] ?? 0);
$ticketSalesByDay = db()->fetchAll("
    SELECT DATE(issued_at) AS dt, COUNT(*) AS cnt
    FROM tickets
    WHERE issued_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY dt ORDER BY dt ASC
");
$ticketChartDates = [];
$ticketChartCounts = [];
foreach ($ticketSalesByDay as $td) {
    $ticketChartDates[] = $td['dt'];
    $ticketChartCounts[] = (int) $td['cnt'];
}

$ticketsByEventRows = db()->fetchAll("
    SELECT e.name AS n, COUNT(t.id) AS c
    FROM bazar_events e
    LEFT JOIN tickets t ON t.event_id = e.id
    GROUP BY e.id, e.name
    ORDER BY c DESC
    LIMIT 8
");
$ticketsEvLabels = [];
$ticketsEvCounts = [];
foreach ($ticketsByEventRows as $ter) {
    $ticketsEvLabels[] = mb_substr((string) $ter['n'], 0, 18);
    $ticketsEvCounts[] = (int) $ter['c'];
}
if ($ticketsEvLabels === []) {
    $ticketsEvLabels = ['—'];
    $ticketsEvCounts = [0];
}

$eventStatusRows = db()->fetchAll("SELECT status, COUNT(*) AS c FROM bazar_events GROUP BY status");
$evLabels = [];
$evCounts = [];
$evPolarBg = [];
$palette = ['#64748b', '#475569', '#94a3b8', '#334155', '#cbd5e1', '#e2e8f0'];
foreach ($eventStatusRows as $i => $row) {
    $evLabels[] = $row['status'];
    $evCounts[] = (int) $row['c'];
    $evPolarBg[] = $palette[$i % count($palette)];
}
if (empty($evLabels)) {
    $evLabels = ['—'];
    $evCounts = [0];
    $evPolarBg = ['#cbd5e1'];
}

$newUsersWeek = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['c'] ?? 0);

$eventsPostponed = 0;
$eventsCanceled = 0;
try {
    $eventsPostponed = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status = 'postponed'")['c'] ?? 0);
    $eventsCanceled = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status = 'canceled'")['c'] ?? 0);
} catch (Throwable $e) {
    /* Older schema without postponed/canceled */
}

qb_page_start('admin', 'Admin Dashboard', 'dashboard.php', true);
?>

<div class="page-header qb-dash-header">
  <div>
    <h1 class="page-title qb-dash-title">System Overview</h1>
    <p class="page-subtitle qb-dash-subtitle">Full platform statistics and activity.</p>
  </div>
  <div style="display:flex;gap:0.5rem">
    <a href="reconciliation.php" class="btn btn-secondary"><?= qb_icon('receipt') ?> Reconcile payments</a>
    <a href="users.php" class="btn btn-primary qb-btn-mustard"><?= qb_icon('plus') ?> New User</a>
  </div>
</div>

<div class="grid grid-4 gap-2 mb-4 dash-stat-row dash-stat-row--1">
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-1 stat-card--data-users stat-card--elevated dash-card-skin dash-card-skin--a">
    <div class="stat-label">Total users</div>
    <div class="stat-value" id="dash-stat-users"><?= number_format($users) ?></div>
    <div class="stat-change up" id="dash-stat-users-sub"><?= number_format($buyers) ?> buyers · <?= number_format($sellers) ?> sellers · <?= number_format($organizers) ?> orgs</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-1 stat-card--data-events stat-card--elevated dash-card-skin dash-card-skin--a">
    <div class="stat-label">Events (live / draft)</div>
    <div class="stat-value" id="dash-stat-events"><?= number_format($eventsLive) ?> <span class="text-sm text-muted">/ <?= number_format($eventsDraft) ?></span></div>
    <div class="stat-change up" id="dash-stat-events-sub"><?= number_format($events) ?> total bazars</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-1 stat-card--data-insight stat-card--elevated dash-card-skin dash-card-skin--a">
    <div class="stat-label">Revenue today</div>
    <div class="stat-value" id="dash-stat-rev-today"><?= number_format((float) $revToday, 0) ?> <span class="text-sm text-muted">ETB</span></div>
    <div class="stat-change up" id="dash-stat-rev-today-sub"><?= $txToday ?> tx today · <?= number_format($txWeek) ?> tx (7d)</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-1 stat-card--data-revenue stat-card--elevated dash-card-skin dash-card-skin--a">
    <div class="stat-label">All-time revenue</div>
    <div class="stat-value" id="dash-stat-revenue"><?= number_format((float) $revenue, 0) ?> <span class="text-sm text-muted">ETB</span></div>
    <div class="stat-change up" id="dash-stat-revenue-sub"><?= number_format($txN) ?> completed sales</div>
  </div>
</div>

<div class="grid grid-2 gap-2 mb-4">
  <a href="<?= APP_URL ?>/admin/events.php?view=calendar" class="stat-card qb-admin-card qb-dash-stat stat-card--data-growth stat-card--elevated dash-card-skin dash-card-skin--a" style="text-decoration:none;color:inherit;display:block">
    <div class="stat-label">Postponed events</div>
    <div class="stat-value"><?= number_format($eventsPostponed) ?></div>
    <div class="stat-change up">Rescheduled bazars · Calendar</div>
  </a>
  <a href="<?= APP_URL ?>/admin/events.php?view=calendar" class="stat-card qb-admin-card qb-dash-stat stat-card--data-moderation stat-card--elevated dash-card-skin dash-card-skin--a" style="text-decoration:none;color:inherit;display:block">
    <div class="stat-label">Canceled events</div>
    <div class="stat-value"><?= number_format($eventsCanceled) ?></div>
    <div class="stat-change">Stopped bazars · Calendar</div>
  </a>
</div>

<div class="grid grid-2 gap-2 mb-4">
  <a href="reconciliation.php?status=pending" class="stat-card qb-admin-card qb-dash-stat stat-card--data-insight stat-card--soft-gradient dash-card-skin dash-card-skin--b" style="text-decoration:none;color:inherit;display:block">
    <div class="stat-label">Payments pending action</div>
    <div class="stat-value"><?= number_format($txPendingN) ?></div>
    <div class="stat-change">Pending Chapa gateway confirmation</div>
  </a>
  <a href="reconciliation.php?status=failed" class="stat-card qb-admin-card qb-dash-stat stat-card--data-moderation stat-card--soft-gradient dash-card-skin dash-card-skin--b" style="text-decoration:none;color:inherit;display:block">
    <div class="stat-label">Payments failed</div>
    <div class="stat-value"><?= number_format($txFailedN) ?></div>
    <div class="stat-change">Review callbacks, phone mismatch, amount mismatch</div>
  </a>
</div>

<div class="grid grid-4 gap-2 mb-4 dash-stat-row dash-stat-row--2">
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-2 stat-card--data-commerce stat-card--soft-gradient dash-card-skin dash-card-skin--b">
    <div class="stat-label">Products</div>
    <div class="stat-value" id="dash-stat-products"><?= number_format($products) ?></div>
    <?php if ($pendingProducts > 0): ?>
    <div class="stat-change up" id="dash-stat-products-sub"><a href="products_pending.php"><?= $pendingProducts ?> pending approval</a></div>
    <?php else: ?>
    <div class="stat-change up" id="dash-stat-products-sub">No pending listings</div>
    <?php endif; ?>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-2 stat-card--data-stores stat-card--soft-gradient dash-card-skin dash-card-skin--b">
    <div class="stat-label">Seller stores</div>
    <div class="stat-value" id="dash-stat-sellers"><?= number_format($sellerProfiles) ?></div>
    <div class="stat-change up">Active profiles</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-2 stat-card--data-orders stat-card--soft-gradient dash-card-skin dash-card-skin--b">
    <div class="stat-label">Avg. order</div>
    <div class="stat-value" id="dash-stat-avg"><?= number_format($avgOrder, 0) ?> <span class="text-sm text-muted">ETB</span></div>
    <div class="stat-change up">Completed transactions</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-2 stat-card--data-tickets stat-card--soft-gradient dash-card-skin dash-card-skin--b">
    <div class="stat-label">Tickets purchased (issued)</div>
    <div class="stat-value" id="dash-stat-tickets"><?= number_format($ticketsN) ?></div>
    <div class="stat-change up"><?= number_format($ticketsWeek) ?> in last 7 days · all-time</div>
  </div>
</div>

<div class="grid grid-4 gap-2 mb-4 dash-stat-row dash-stat-row--3">
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-3 stat-card--data-scans stat-card--accent-bar dash-card-skin dash-card-skin--c">
    <div class="stat-label">QR scans</div>
    <div class="stat-value" id="dash-stat-scans"><?= number_format($scansN) ?></div>
    <div class="stat-change up">Platform total</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-3 stat-card--data-ratings stat-card--accent-bar dash-card-skin dash-card-skin--c">
    <div class="stat-label">Ratings</div>
    <div class="stat-value" id="dash-stat-ratings"><?= number_format($ratingsN) ?></div>
    <div class="stat-change up">Seller feedback</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-3 stat-card--data-growth stat-card--accent-bar dash-card-skin dash-card-skin--c">
    <div class="stat-label">New users (7d)</div>
    <div class="stat-value" id="dash-stat-newusers"><?= number_format($newUsersWeek) ?></div>
    <div class="stat-change up">Registrations</div>
  </div>
  <div class="stat-card qb-admin-card qb-dash-stat qb-dash-stat--tier-3 stat-card--data-moderation stat-card--accent-bar dash-card-skin dash-card-skin--c">
    <div class="stat-label">Moderation</div>
    <div class="stat-value" id="dash-stat-roles"><?= number_format($pendingRoles) ?> <span class="text-sm text-muted">roles</span></div>
    <div class="stat-change up">
      <?php if ($pendingRoles > 0): ?><a href="role_requests.php">Review requests</a><?php else: ?>No role requests<?php endif; ?>
      <?php 
        $pendingCatReqs = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM category_change_requests WHERE status = 'pending'")['c'] ?? 0);
        if ($pendingCatReqs > 0):
      ?>
      · <a href="category_requests.php" class="text-amber font-bold"><?= $pendingCatReqs ?> Cat Reqs</a>
      <?php endif; ?>
      · <a href="promos.php">Promos</a>
    </div>
  </div>
</div>

<div class="grid grid-2 gap-2 mb-4">
    <div class="card qb-chart-card-admin card--data-revenue">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Revenue (Last 7 Days)</h3>
          <span class="qb-chart-badge qb-chart-badge--live" id="dash-chart-live-a" title="Updates every 30s"><span class="qb-chart-badge__pulse" aria-hidden="true"></span>Live</span>
        </div>
        <div class="chart-wrapper chart-wrapper--tall"><canvas id="revChart"></canvas></div>
    </div>
    <div class="card qb-chart-card-admin card--data-transactions">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Transactions per day</h3>
          <span class="qb-chart-badge qb-chart-badge--live" id="dash-chart-live-b" title="Updates every 30s"><span class="qb-chart-badge__pulse" aria-hidden="true"></span>Live</span>
        </div>
        <div class="chart-wrapper chart-wrapper--tall"><canvas id="txCountChart"></canvas></div>
    </div>
</div>

<div class="grid grid-2 gap-2 mb-4">
    <div class="card qb-chart-card-admin card--data-tickets">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Tickets issued per day (7 days)</h3>
        </div>
        <div class="chart-wrapper chart-wrapper--tall"><canvas id="ticketSalesChart"></canvas></div>
    </div>
    <div class="card qb-chart-card-admin card--data-tickets">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Tickets by bazar (top 8)</h3>
        </div>
        <div class="chart-wrapper chart-wrapper--tall"><canvas id="ticketsByEventChart"></canvas></div>
    </div>
</div>

<div class="grid grid-3 gap-2 mb-4">
    <div class="card qb-chart-card-admin card--data-roles">
        <h3 class="font-bold mb-2 qb-chart-card__title">Users by role</h3>
        <div class="chart-wrapper" style="height:240px;position:relative">
            <canvas id="roleChart"></canvas>
        </div>
    </div>
    <div class="card qb-chart-card-admin card--data-status">
        <h3 class="font-bold mb-2 qb-chart-card__title">Events by status</h3>
        <div class="chart-wrapper" style="height:240px;position:relative">
            <canvas id="eventStatusChart"></canvas>
        </div>
    </div>
    <div class="card qb-chart-card-admin card--data-insight">
        <h3 class="font-bold mb-2 qb-chart-card__title">Platform pulse</h3>
        <p class="text-sm text-secondary mb-2" style="line-height:1.6">
          <strong class="text-emerald"><?= number_format((float) $revenue, 2) ?> ETB</strong> lifetime volume.<br/>
          <strong><?= number_format($notifN) ?></strong> notifications in system.<br/>
          Avg. basket <strong><?= number_format($avgOrder, 2) ?> ETB</strong>.
        </p>
        <div style="margin-top:1rem;font-size:0.8rem">
          <a href="events.php" class="text-accent font-bold">Events &amp; branding</a>
          · <a href="products_pending.php" class="text-accent font-bold">Approvals</a>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var accent = "rgba(37, 99, 235, 0.95)";
    var accentSoft = "rgba(37, 99, 235, 0.35)";
    var accentLine = "rgba(37, 99, 235, 0.12)";
    var teal = "rgba(20, 184, 166, 0.92)";
    var purple = "rgba(168, 85, 247, 0.62)";
    var orange = "rgba(249, 115, 22, 0.78)";
    var pink = "rgba(236, 72, 153, 0.72)";
    var gridCol = "rgba(15, 23, 42, 0.08)";
    var chartAnim = {
        animation: { duration: 1100, easing: "easeOutQuart" },
        animations: {
            colors: { type: "color", duration: 500 },
            numbers: { type: "number", duration: 800 }
        }
    };

    var revCtx = document.getElementById("revChart");
    var txCtx = document.getElementById("txCountChart");
    var ticketCtx = document.getElementById("ticketSalesChart");
    var ticketsEvCtx = document.getElementById("ticketsByEventChart");
    var roleCtx = document.getElementById("roleChart");
    var evCtx = document.getElementById("eventStatusChart");

    window.qbAdminDashCharts = {
        rev: new Chart(revCtx, {
            type: "bar",
            data: {
                labels: <?= json_encode($dates) ?>,
                datasets: [{
                    label: "Revenue (ETB)",
                    data: <?= json_encode($revs) ?>,
                    backgroundColor: accentSoft,
                    borderColor: accent,
                    borderWidth: 2,
                    borderRadius: 8,
                    maxBarThickness: 48
                }]
            },
            options: Object.assign({}, chartAnim, {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: "index" },
                plugins: { legend: { display: false }, tooltip: { animation: { duration: 150 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0 } },
                    y: { beginAtZero: true, grid: { color: gridCol } }
                }
            })
        }),
        tx: new Chart(txCtx, {
            type: "line",
            data: {
                labels: <?= json_encode($txDates) ?>,
                datasets: [{
                    label: "Transactions",
                    data: <?= json_encode($txCounts) ?>,
                    borderColor: teal,
                    backgroundColor: accentLine,
                    borderWidth: 3,
                    tension: 0.35,
                    fill: true,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: teal,
                    pointBorderColor: "#fff",
                    pointBorderWidth: 2
                }]
            },
            options: Object.assign({}, chartAnim, {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: gridCol } }
                }
            })
        }),
        tickets: new Chart(ticketCtx, {
            type: "bar",
            data: {
                labels: <?= json_encode($ticketChartDates) ?>,
                datasets: [{
                    label: "Tickets issued",
                    data: <?= json_encode($ticketChartCounts) ?>,
                    backgroundColor: orange,
                    borderColor: "rgba(194, 65, 12, 0.95)",
                    borderWidth: 2,
                    borderRadius: 8,
                    maxBarThickness: 44
                }]
            },
            options: Object.assign({}, chartAnim, {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0 } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: gridCol } }
                }
            })
        }),
        ticketsByEvent: new Chart(ticketsEvCtx, {
            type: "bar",
            data: {
                labels: <?= json_encode($ticketsEvLabels) ?>,
                datasets: [{
                    label: "Tickets",
                    data: <?= json_encode($ticketsEvCounts) ?>,
                    backgroundColor: [teal, purple, orange, pink, accentSoft, "rgba(34,197,94,0.62)", "rgba(14,165,233,0.62)", "rgba(234,179,8,0.62)"],
                    borderRadius: 6,
                    maxBarThickness: 32
                }]
            },
            options: Object.assign({}, chartAnim, {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: gridCol } },
                    y: { grid: { display: false } }
                }
            })
        }),
        roles: new Chart(roleCtx, {
            type: "bar",
            data: {
                labels: ["Buyers", "Sellers", "Organizers"],
                datasets: [{
                    label: "Users",
                    data: [<?= (int) $buyers ?>, <?= (int) $sellers ?>, <?= (int) $organizers ?>],
                    backgroundColor: [accentSoft, teal, purple],
                    borderRadius: 6,
                    maxBarThickness: 56
                }]
            },
            options: Object.assign({}, chartAnim, {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: gridCol } },
                    y: { grid: { display: false } }
                }
            })
        }),
        events: new Chart(evCtx, {
            type: "pie",
            data: {
                labels: <?= json_encode($evLabels) ?>,
                datasets: [{
                    data: <?= json_encode($evCounts) ?>,
                    backgroundColor: <?= json_encode($evPolarBg) ?>,
                    borderWidth: 2,
                    borderColor: "#fff",
                    hoverOffset: 10
                }]
            },
            options: Object.assign({}, chartAnim, {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: "right", labels: { boxWidth: 14, padding: 10 } }
                }
            })
        })
    };

    function nf(n) {
        try { return new Intl.NumberFormat().format(Math.round(n)); } catch (e) { return String(n); }
    }

    function flash(el) {
        if (!el) return;
        el.classList.add("dash-stat--flash");
        setTimeout(function() { el.classList.remove("dash-stat--flash"); }, 600);
    }

    function applyStats(s) {
        var set = function(id, v) {
            var el = document.getElementById(id);
            if (!el) return;
            var t = typeof v === "number" ? nf(v) : String(v);
            if (el.textContent.replace(/\s/g, "") !== t.replace(/\s/g, "")) {
                el.textContent = t;
                flash(el);
            }
        };
        set("dash-stat-users", s.users);
        var evEl = document.getElementById("dash-stat-events");
        if (evEl) {
            var evHtml = nf(s.eventsLive) + ' <span class="text-sm text-muted">/ ' + nf(s.eventsDraft) + "</span>";
            if (evEl.innerHTML.replace(/\s/g, "") !== evHtml.replace(/\s/g, "")) {
                evEl.innerHTML = evHtml;
                flash(evEl);
            }
        }
        var rvt = document.getElementById("dash-stat-rev-today");
        if (rvt) {
            var rTodayHtml = nf(Math.round(s.revToday)) + ' <span class="text-sm text-muted">ETB</span>';
            if (rvt.innerHTML.replace(/\s/g, "") !== rTodayHtml.replace(/\s/g, "")) {
                rvt.innerHTML = rTodayHtml;
                flash(rvt);
            }
        }
        var revEl = document.getElementById("dash-stat-revenue");
        if (revEl) {
            var revHtml = nf(Math.round(s.revenue)) + ' <span class="text-sm text-muted">ETB</span>';
            if (revEl.innerHTML.replace(/\s/g, "") !== revHtml.replace(/\s/g, "")) {
                revEl.innerHTML = revHtml;
                flash(revEl);
            }
        }

        set("dash-stat-products", s.products);
        var ps = document.getElementById("dash-stat-products-sub");
        if (ps) {
            if (s.pendingProducts > 0) {
                ps.innerHTML = '<a href="products_pending.php">' + s.pendingProducts + " pending approval</a>";
            } else {
                ps.textContent = "No pending listings";
            }
        }
        set("dash-stat-sellers", s.sellerProfiles);
        var avgEl = document.getElementById("dash-stat-avg");
        if (avgEl) {
            var avgHtml = nf(Math.round(s.avgOrder)) + ' <span class="text-sm text-muted">ETB</span>';
            if (avgEl.innerHTML.replace(/\s/g, "") !== avgHtml.replace(/\s/g, "")) {
                avgEl.innerHTML = avgHtml;
                flash(avgEl);
            }
        }
        set("dash-stat-tickets", s.ticketsN);
        set("dash-stat-scans", s.scansN);
        set("dash-stat-ratings", s.ratingsN);
        set("dash-stat-newusers", s.newUsersWeek);
        var prEl = document.getElementById("dash-stat-roles");
        if (prEl) prEl.innerHTML = nf(s.pendingRoles) + ' <span class="text-sm text-muted">roles</span>';

        var us = document.getElementById("dash-stat-users-sub");
        if (us) us.textContent = nf(s.buyers) + " buyers · " + nf(s.sellers) + " sellers · " + nf(s.organizers) + " orgs";
        var es = document.getElementById("dash-stat-events-sub");
        if (es) es.textContent = nf(s.events) + " total bazars";
        var rts = document.getElementById("dash-stat-rev-today-sub");
        if (rts) rts.textContent = s.txToday + " tx today · " + nf(s.txWeek) + " tx (7d)";
        var rs = document.getElementById("dash-stat-revenue-sub");
        if (rs) rs.textContent = nf(s.txN) + " completed sales";

        var C = window.qbAdminDashCharts;
        if (C && C.rev && s.charts && s.charts.rev) {
            C.rev.data.labels = s.charts.rev.labels;
            C.rev.data.datasets[0].data = s.charts.rev.values;
            C.rev.update("none");
        }
        if (C && C.tx && s.charts && s.charts.tx) {
            C.tx.data.labels = s.charts.tx.labels;
            C.tx.data.datasets[0].data = s.charts.tx.values;
            C.tx.update("none");
        }
        if (C && C.roles && s.charts && s.charts.roles) {
            C.roles.data.datasets[0].data = s.charts.roles;
            C.roles.update("none");
        }
        if (C && C.events && s.charts && s.charts.eventsByStatus) {
            var L = s.charts.eventsByStatus.labels;
            var N = s.charts.eventsByStatus.counts;
            C.events.data.labels = L;
            C.events.data.datasets[0].data = N;
            var pal = ["#64748b", "#475569", "#94a3b8", "#334155", "#cbd5e1", "#e2e8f0"];
            var bg = [];
            for (var i = 0; i < L.length; i++) bg.push(pal[i % pal.length]);
            C.events.data.datasets[0].backgroundColor = bg;
            C.events.update("none");
        }

        var t = new Date();
        var ts = t.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit", second: "2-digit" });
        ["dash-chart-live-a", "dash-chart-live-b"].forEach(function(id) {
            var b = document.getElementById(id);
            if (b) b.setAttribute("title", "Last sync: " + ts);
        });
    }

    var apiUrl = <?= json_encode(rtrim(APP_URL, '/') . '/api/admin_dashboard_stats.php') ?>;
    function poll() {
        fetch(apiUrl, { credentials: "same-origin", cache: "no-store" })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.ok && data.stats) applyStats(Object.assign({}, data.stats, { charts: data.charts }));
            })
            .catch(function() {});
    }
    setTimeout(poll, 1000);
    setInterval(poll, 30000);
});
</script>

<?php qb_page_end(); ?>
