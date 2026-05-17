<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

$seller = getCurrentSeller();
if (!$seller) {
    header('Location: ' . APP_URL . '/login.php?portal=seller&error=session');
    exit;
}
$sid = (int)$seller['id'];

// Quick stats
$trustScore  = computeTrustScore($sid);
$badge       = getTrustBadge($trustScore);

$rev = db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM transactions WHERE seller_id = ? AND payment_status = 'completed'", [$sid])['t'] ?? 0;
$txN = db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE seller_id = ? AND payment_status = 'completed'", [$sid])['c'] ?? 0;
$scans = db()->fetchOne("SELECT COUNT(*) AS c FROM analytics_events WHERE seller_id = ? AND event_type = 'qr_scan'", [$sid])['c'] ?? 0;
$unitsSold = (int) (db()->fetchOne(
    "SELECT COALESCE(SUM(ti.quantity),0) AS q
     FROM transaction_items ti
     INNER JOIN transactions t ON t.id = ti.transaction_id
     WHERE t.seller_id = ? AND t.payment_status = 'completed'",
    [$sid]
)['q'] ?? 0);
$unitMixRows = db()->fetchAll(
    "SELECT COALESCE(NULLIF(p.unit,''), 'unit') AS unit_key, COALESCE(SUM(ti.quantity),0) AS qty
     FROM transaction_items ti
     INNER JOIN transactions t ON t.id = ti.transaction_id
     LEFT JOIN products p ON p.id = ti.product_id
     WHERE t.seller_id = ? AND t.payment_status = 'completed'
     GROUP BY unit_key
     ORDER BY qty DESC
     LIMIT 4",
    [$sid]
);
$soldProductsN = (int) (db()->fetchOne(
    "SELECT COUNT(DISTINCT ti.product_id) AS c
     FROM transaction_items ti
     INNER JOIN transactions t ON t.id = ti.transaction_id
     WHERE t.seller_id = ? AND t.payment_status = 'completed' AND ti.product_id IS NOT NULL",
    [$sid]
)['c'] ?? 0);

$vatRate = 0.15; // display metric for dashboard only
$vatCollected = (float) $rev * ($vatRate / (1 + $vatRate)); // inclusive split estimate
$profitRate = 0.28; // coarse estimate until product cost tracking exists
$profitEstimate = (float) $rev * $profitRate;
$avgOrderValue = $txN > 0 ? ((float) $rev / (float) $txN) : 0.0;
$marginPct = $rev > 0 ? (int) round(($profitEstimate / (float) $rev) * 100) : 0;

$todayRev = db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM transactions WHERE seller_id = ? AND payment_status = 'completed' AND DATE(created_at) = CURDATE()", [$sid])['t'] ?? 0;

$revLastHour = (float) (db()->fetchOne(
    "SELECT COALESCE(SUM(total_amount),0) AS t FROM transactions WHERE seller_id = ? AND payment_status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    [$sid]
)['t'] ?? 0);

$scansToday = (int) (db()->fetchOne(
    "SELECT COUNT(*) AS c FROM analytics_events WHERE seller_id = ? AND event_type = 'qr_scan' AND DATE(created_at) = CURDATE()",
    [$sid]
)['c'] ?? 0);
$scansYesterday = (int) (db()->fetchOne(
    "SELECT COUNT(*) AS c FROM analytics_events WHERE seller_id = ? AND event_type = 'qr_scan' AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
    [$sid]
)['c'] ?? 0);

$paySplit = db()->fetchAll(
    "SELECT payment_method, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt FROM transactions WHERE seller_id = ? AND payment_status = 'completed' GROUP BY payment_method ORDER BY amt DESC",
    [$sid]
);

$appUid = (int) $_SESSION['app_user_id'];
$eventMode = qb_event_mode_get($appUid);
$eventModeRow = null;
if (!empty($eventMode['event_id'])) {
    $eventModeRow = db()->fetchOne('SELECT id, name, status FROM bazar_events WHERE id = ?', [(int) $eventMode['event_id']]);
}
$liveEvent = db()->fetchOne(
    "SELECT e.name, e.status FROM event_participants ep INNER JOIN bazar_events e ON e.id = ep.event_id WHERE ep.app_user_id = ? AND ep.role_in_event = 'seller' AND e.status IN ('published','live') ORDER BY e.event_start DESC LIMIT 1",
    [$appUid]
);
$liveEventClass = '';
if (!empty($liveEvent['status'])) {
    $liveEventClass = 'qb-event-status qb-event-status--' . preg_replace('/[^a-z_]/', '', strtolower((string) $liveEvent['status']));
}
$attendanceLabel = 'Not present yet';
if ($eventModeRow) {
    $ping = db()->fetchOne(
        "SELECT lat, lng, created_at FROM location_pings WHERE app_user_id = ? AND event_id = ? ORDER BY id DESC LIMIT 1",
        [$appUid, (int) $eventModeRow['id']]
    );
    if ($ping) {
        $geo = isInsideGeofence((float) $ping['lat'], (float) $ping['lng'], (int) $eventModeRow['id']);
        if (!empty($geo['inside'])) {
            $attendanceLabel = 'Present in event area';
        } else {
            $attendanceLabel = 'Outside event area (' . (int) ($geo['distance'] ?? 0) . 'm)';
        }
    }
}

$scanHint = '';
if ($scansYesterday > 0) {
    $pct = (int) round((($scansToday - $scansYesterday) / $scansYesterday) * 100);
    $scanHint = $pct >= 0 ? ('+' . $pct . '% vs yesterday') : ($pct . '% vs yesterday');
} elseif ($scansToday > 0) {
    $scanHint = 'first scans today';
}

$trustTip = 'Maintain accurate stock and friendly service — buyers notice.';
if ($trustScore < 35) {
    $trustTip = 'Complete more completed sales and encourage buyers to rate your stall.';
} elseif ($trustScore < 65) {
    $trustTip = 'Update inventory often and keep your QR visible for more scans.';
}

$lowStock = db()->fetchAll('SELECT name, stock FROM products WHERE seller_id = ? AND is_available = 1 AND stock <= 5 ORDER BY stock ASC LIMIT 5', [$sid]);

// Sales Chart Data
$salesData = db()->fetchAll("
    SELECT DATE(created_at) as dt, SUM(total_amount) as rev 
    FROM transactions 
    WHERE seller_id = ? AND payment_status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
    GROUP BY dt ORDER BY dt ASC
", [$sid]);
$scanTrendRows = db()->fetchAll(
    "SELECT DATE(created_at) AS dt, COUNT(*) AS c
     FROM analytics_events
     WHERE seller_id = ? AND event_type = 'qr_scan' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY dt ORDER BY dt ASC",
    [$sid]
);
$byDay = [];
foreach ($salesData as $d) {
    $byDay[$d['dt']] = (float) $d['rev'];
}
$scanByDay = [];
foreach ($scanTrendRows as $sr) {
    $scanByDay[(string) $sr['dt']] = (int) ($sr['c'] ?? 0);
}
// Full last 7 calendar days (zeros when no sales) — stable charts and axis labels
$dates = [];
$revs = [];
$scanTrend = [];
$chartLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $dates[] = $day;
    $revs[] = $byDay[$day] ?? 0.0;
    $scanTrend[] = $scanByDay[$day] ?? 0;
    $chartLabels[] = date('M j', strtotime($day));
}

// Top products by units sold
$topSales = db()->fetchAll("
  SELECT p.name, COALESCE(SUM(ti.quantity),0) AS units
  FROM transaction_items ti
  INNER JOIN transactions t ON t.id = ti.transaction_id
  INNER JOIN products p ON p.id = ti.product_id
  WHERE t.seller_id = ? AND t.payment_status = 'completed'
  GROUP BY p.id, p.name
  ORDER BY units DESC
  LIMIT 5
", [$sid]);
$pNames = []; $pUnits = [];
foreach ($topSales as $row) {
    $pNames[] = mb_strlen($row['name']) > 14 ? mb_substr($row['name'], 0, 12) . '…' : $row['name'];
    $pUnits[] = (float) $row['units'];
}
if (empty($pNames)) {
    $pNames = ['—'];
    $pUnits = [0];
}

$productCountDash = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM products WHERE seller_id = ?', [$sid])['c'] ?? 0);
$sellerGraceLeft = qb_seller_grace_seconds_remaining($sid, (string) ($seller['created_at'] ?? ''));

$firstName = htmlspecialchars(explode(' ', $seller['full_name'])[0]);
$h = (int) date('G');
if ($h < 12) {
    $greeting = 'Good morning';
} elseif ($h < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

qb_page_start('seller', 'Dashboard', 'dashboard.php', true);
?>

<div class="seller-dashboard">
<div class="page-header qb-dash-header">
  <div>
    <h1 class="page-title qb-dash-title"><?= $greeting ?>, <?= $firstName ?></h1>
    <p class="page-subtitle qb-dash-subtitle"><?= htmlspecialchars($seller['market_name']) ?> · <?= htmlspecialchars($seller['location']) ?></p>
  </div>
  <div class="qb-dash-actions">
    <span class="qb-trust-pill <?= htmlspecialchars($badge['class']) ?>">
      <?= qb_icon($badge['icon_key'], 'qb-icon', 14) ?> <?= htmlspecialchars(strtoupper($badge['label'])) ?>
    </span>
    <a href="payments.php" class="btn btn-secondary btn-sm"><?= qb_icon('receipt', 'qb-icon', 16) ?> Payments</a>
    <a href="flash_sale.php" class="btn btn-secondary btn-sm"><?= qb_icon('flash', 'qb-icon', 16) ?> Flash</a>
    <a href="products.php" class="btn btn-primary qb-btn-mustard"><?= qb_icon('plus') ?> Add Product</a>
  </div>
</div>

<?php
$sellerPromos = qb_fetch_active_promos_for('seller');
if (!empty($sellerPromos)) {
    $promos = $sellerPromos;
    $promoHeading = 'Spotlight';
    require __DIR__ . '/../includes/partials/promo_spotlight.php';
}
?>

<?php
$liveEvDialog = $liveEvent ? [
    'name' => (string) ($liveEvent['name'] ?? ''),
    'status' => (string) ($liveEvent['status'] ?? ''),
    'venue' => '',
    'city' => '',
    'start' => '',
    'end' => '',
    'organizers' => '',
    'notes' => 'Shown on your dashboard as the active bazar tied to your stall.',
    'products' => '',
    'attendance' => '',
] : [];
$modeEvDialog = $eventModeRow ? [
    'name' => (string) ($eventModeRow['name'] ?? ''),
    'status' => (string) ($eventModeRow['status'] ?? ''),
    'venue' => '',
    'city' => '',
    'start' => '',
    'end' => '',
    'organizers' => '',
    'notes' => 'Event mode uses your last location ping to show whether you are inside the venue geo-fence.',
    'products' => '',
    'attendance' => $attendanceLabel,
] : [];
?>
<?php if ($liveEvent): ?>
<div class="card card--pulse-event mb-3 qb-event-card--dblinfo" tabindex="0" title="Double-click for details"<?= qb_event_dialog_data_attr($liveEvDialog) ?>>
  <div class="qb-pulse-event__row">
    <?= qb_icon('calendar', 'qb-icon', 20) ?>
    <div>
      <div class="text-xs text-muted text-uppercase font-bold">Active bazar</div>
      <div class="font-bold"><?= htmlspecialchars($liveEvent['name']) ?> <span class="badge <?= htmlspecialchars($liveEventClass) ?>"><?= htmlspecialchars($liveEvent['status']) ?></span></div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($eventModeRow): ?>
<div class="card mb-3 qb-event-card--dblinfo" style="border:1px solid var(--accent-soft)" tabindex="0" title="Double-click for details"<?= qb_event_dialog_data_attr($modeEvDialog) ?>>
  <div class="text-xs text-muted text-uppercase font-bold mb-1">Event mode</div>
  <div class="font-bold"><?= htmlspecialchars($eventModeRow['name']) ?></div>
  <div class="text-sm mt-1"><?= htmlspecialchars($attendanceLabel) ?></div>
</div>
<?php endif; ?>

<div class="card qb-pulse-strip mb-3">
  <div class="qb-pulse-strip__title text-xs text-muted text-uppercase font-bold mb-2">Live pulse</div>
  <div class="grid grid-3 gap-2 qb-pulse-grid">
    <div class="qb-pulse-cell">
      <div class="qb-pulse-cell__label">Last hour sales</div>
      <div class="qb-pulse-cell__val"><?= number_format($revLastHour, 2) ?> <span class="text-xs">ETB</span></div>
    </div>
    <div class="qb-pulse-cell">
      <div class="qb-pulse-cell__label">QR scans today</div>
      <div class="qb-pulse-cell__val"><?= number_format($scansToday) ?></div>
      <?php if ($scanHint !== ''): ?><div class="qb-pulse-cell__hint text-xs text-muted"><?= htmlspecialchars($scanHint) ?></div><?php endif; ?>
    </div>
    <div class="qb-pulse-cell">
      <div class="qb-pulse-cell__label">Yesterday scans</div>
      <div class="qb-pulse-cell__val"><?= number_format($scansYesterday) ?></div>
    </div>
  </div>
  <div class="qb-pulse-footer">
    <p class="text-xs text-muted mb-0 qb-pulse-footer__tip"><?= htmlspecialchars($trustTip) ?></p>
    <a href="export_sales.php" class="btn btn-secondary btn-sm"><?= qb_icon('download', 'qb-icon', 16) ?> Export CSV</a>
  </div>
</div>

<?php if (!empty($lowStock)): ?>
<div class="alert alert-warning mb-3 qb-alert-soft">
  <?= qb_icon('alert', 'qb-icon', 20) ?>
  <div>
    <strong>Low Stock Warning</strong>
    <ul style="margin-top:0.25rem;padding-left:1.25rem;font-size:0.8rem">
      <?php foreach ($lowStock as $ls): ?>
        <li><?= htmlspecialchars($ls['name']) ?> (<?= $ls['stock'] ?> left)</li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-4 gap-2 mb-4 qb-stat-grid">
  <div class="stat-card qb-stat-card stat-card--data-revenue stat-card--solid-blue">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('chart', 'qb-icon', 22) ?></div>
    <div class="stat-label">Total Revenue</div>
    <div class="stat-value"><?= number_format($rev, 0) ?> <span class="qb-currency">ETB</span></div>
    <div class="stat-change up">↑ Today: <?= number_format($todayRev, 2) ?> ETB</div>
  </div>
  <div class="stat-card qb-stat-card stat-card--data-transactions stat-card--solid-indigo">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('cart', 'qb-icon', 22) ?></div>
    <div class="stat-label">Transactions</div>
    <div class="stat-value"><?= number_format($txN) ?></div>
    <div class="stat-change up">↑ All time</div>
  </div>
  <div class="stat-card qb-stat-card stat-card--data-scans stat-card--solid-teal">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('qr', 'qb-icon', 22) ?></div>
    <div class="stat-label">QR Scans</div>
    <div class="stat-value"><?= number_format($scans) ?></div>
    <div class="stat-change up">↑ Total scans</div>
  </div>
  <div class="stat-card qb-stat-card stat-card--data-trust stat-card--solid-emerald">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('star', 'qb-icon', 22) ?></div>
    <div class="stat-label">Trust Score</div>
    <div class="stat-value qb-trust-num"><?= (int) $trustScore ?></div>
    <div class="stat-change up">/ 100 points</div>
  </div>
</div>

<div class="grid grid-4 gap-2 mb-4 qb-stat-grid">
  <div class="stat-card qb-stat-card">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('package', 'qb-icon', 22) ?></div>
    <div class="stat-label">Units sold</div>
    <div class="stat-value"><?= number_format($unitsSold) ?></div>
    <div class="stat-change up"><?= number_format($soldProductsN) ?> products sold</div>
  </div>
  <div class="stat-card qb-stat-card">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('receipt', 'qb-icon', 22) ?></div>
    <div class="stat-label">Estimated VAT collected</div>
    <div class="stat-value"><?= number_format($vatCollected, 2) ?> <span class="qb-currency">ETB</span></div>
    <div class="stat-change">at 15% inclusive split</div>
  </div>
  <div class="stat-card qb-stat-card">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('chart', 'qb-icon', 22) ?></div>
    <div class="stat-label">Estimated net profit</div>
    <div class="stat-value"><?= number_format($profitEstimate, 2) ?> <span class="qb-currency">ETB</span></div>
    <div class="stat-change up"><?= $marginPct ?>% margin estimate</div>
  </div>
  <div class="stat-card qb-stat-card">
    <div class="stat-icon qb-stat-icon"><?= qb_icon('cart', 'qb-icon', 22) ?></div>
    <div class="stat-label">Average order value</div>
    <div class="stat-value"><?= number_format($avgOrderValue, 2) ?> <span class="qb-currency">ETB</span></div>
    <div class="stat-change up"><?= number_format($txN) ?> completed orders</div>
  </div>
</div>

<?php if (!empty($unitMixRows)): ?>
<div class="card mb-4">
  <h3 class="font-bold mb-2" style="font-size:1rem">Sold items by unit</h3>
  <div style="display:flex;flex-wrap:wrap;gap:0.5rem">
    <?php foreach ($unitMixRows as $ur): ?>
      <span class="badge"><?= htmlspecialchars((string) ($ur['unit_key'] ?? 'unit')) ?> · <?= number_format((int) ($ur['qty'] ?? 0)) ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2 gap-2 mb-4 qb-chart-grid">
    <div class="card qb-chart-card card--data-revenue">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Revenue (Last 7 Days)</h3>
          <span class="qb-chart-badge">ETB</span>
        </div>
        <div class="chart-wrapper qb-chart-wrap"><canvas id="salesChart"></canvas></div>
    </div>
    <div class="card qb-chart-card card--data-commerce">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Top Products by Sales</h3>
          <span class="qb-chart-badge">UNITS</span>
        </div>
        <div class="chart-wrapper qb-chart-wrap"><canvas id="topProductsChart"></canvas></div>
    </div>
    <div class="card qb-chart-card card--data-activity">
        <div class="qb-chart-card__head">
          <h3 class="qb-chart-card__title">Sales vs scans (7-day comparison)</h3>
          <span class="qb-chart-badge">COMPARE</span>
        </div>
        <div class="chart-wrapper qb-chart-wrap"><canvas id="salesVsScanChart"></canvas></div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    /* Golden Rod Q-Bazaar chart accents */
    var lineStroke = "rgba(218, 165, 32, 0.95)";
    var lineFill = "rgba(218, 165, 32, 0.18)";
    var barFill = ["rgba(218,165,32,0.62)","rgba(184,134,11,0.55)","rgba(30,132,73,0.52)","rgba(212,175,55,0.5)","rgba(139,90,43,0.48)"];
    var barHover = ["rgba(218,165,32,0.82)","rgba(184,134,11,0.78)","rgba(30,132,73,0.72)","rgba(212,175,55,0.72)","rgba(139,90,43,0.68)"];
    var anim = {
        animation: { duration: 1400, easing: "easeOutQuart" },
        animations: {
            colors: { type: "color", duration: 700 },
            numbers: { type: "number", duration: 1100 }
        }
    };

    new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Revenue (ETB)',
                data: <?= json_encode($revs) ?>,
                borderColor: lineStroke,
                backgroundColor: lineFill,
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: lineStroke,
                pointBorderColor: "#fff",
                pointBorderWidth: 2
            }]
        },
        options: Object.assign({}, anim, {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: "index" },
            plugins: { legend: { display: false }, tooltip: { animation: { duration: 180 } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#7a7163' } },
                y: { beginAtZero: true, grid: { color: 'rgba(60,48,28,0.08)' }, ticks: { font: { size: 11 }, color: '#7a7163' } }
            }
        })
    });

    new Chart(document.getElementById('topProductsChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($pNames) ?>,
            datasets: [{
                label: 'Units',
                data: <?= json_encode($pUnits) ?>,
                backgroundColor: barFill,
                hoverBackgroundColor: barHover,
                borderRadius: 10,
                maxBarThickness: 28
            }]
        },
        options: Object.assign({}, anim, {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: 'rgba(60,48,28,0.08)' }, ticks: { color: '#7a7163' } },
                y: { grid: { display: false }, ticks: { color: '#7a7163' } }
            }
        })
    });

    var compareCtx = document.getElementById('salesVsScanChart');
    if (compareCtx) {
      new Chart(compareCtx, {
        data: {
          labels: <?= json_encode($chartLabels) ?>,
          datasets: [
            {
              type: 'bar',
              label: 'Revenue (ETB)',
              data: <?= json_encode($revs) ?>,
              yAxisID: 'y',
              backgroundColor: 'rgba(218,165,32,0.5)',
              borderRadius: 8
            },
            {
              type: 'line',
              label: 'QR scans',
              data: <?= json_encode($scanTrend) ?>,
              yAxisID: 'y1',
              borderColor: 'rgba(30,132,73,0.92)',
              backgroundColor: 'rgba(30,132,73,0.18)',
              borderWidth: 2,
              tension: 0.35,
              pointRadius: 3,
              fill: false
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { display: true, position: 'bottom' } },
          scales: {
            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Revenue' } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Scans' } }
          }
        }
      });
    }
});
</script>

</div>

<?php require __DIR__ . '/../includes/partials/event_info_dialog.php'; ?>
<?php qb_page_end(); ?>
