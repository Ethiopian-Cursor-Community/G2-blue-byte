<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$days = (int) ($_GET['days'] ?? 14);
if (!in_array($days, [1, 3, 7, 14, 30, 90], true)) {
    $days = 14;
}
$methodFilter = trim((string) ($_GET['method'] ?? 'all'));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$validMethods = ['all', 'chapa'];
$validStatuses = ['all', 'pending', 'completed', 'failed', 'cancelled'];
if (!in_array($methodFilter, $validMethods, true)) {
    $methodFilter = 'all';
}
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

$since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
$where = ['created_at >= ?'];
$params = [$since];
if ($methodFilter !== 'all') {
    $where[] = 'payment_method = ?';
    $params[] = $methodFilter;
}
if ($statusFilter !== 'all') {
    $where[] = 'payment_status = ?';
    $params[] = $statusFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalRow = db()->fetchOne("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS amt FROM transactions $whereSql", $params);
$statusRows = db()->fetchAll(
    "SELECT payment_status, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS amt
     FROM transactions
     $whereSql
     GROUP BY payment_status
     ORDER BY c DESC",
    $params
);
$methodStatusRows = db()->fetchAll(
    "SELECT payment_method, payment_status, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS amt
     FROM transactions
     $whereSql
     GROUP BY payment_method, payment_status
     ORDER BY payment_method ASC, payment_status ASC",
    $params
);

$staleRows = db()->fetchAll(
    "SELECT id, tx_id, payment_method, payment_status, total_amount, created_at
     FROM transactions
     WHERE payment_method = 'chapa'
       AND payment_status = 'failed'
       AND created_at >= ?
     ORDER BY created_at DESC
     LIMIT 150",
    [$since]
);

$chapaIntents = [];
if (qb_table_exists('payment_intents')) {
    $chapaIntents = db()->fetchAll(
        "SELECT intent_id, target_type, provider_tx_ref, provider_status, amount, currency, paid_at, consumed_at, created_at
         FROM payment_intents
         ORDER BY id DESC
         LIMIT 60"
    );
}

$statusMap = [];
foreach ($statusRows as $r) {
    $statusMap[(string) ($r['payment_status'] ?? '')] = [
        'count' => (int) ($r['c'] ?? 0),
        'amount' => (float) ($r['amt'] ?? 0),
    ];
}
$statusCount = static fn (string $k): int => (int) ($statusMap[$k]['count'] ?? 0);
$statusAmount = static fn (string $k): float => (float) ($statusMap[$k]['amount'] ?? 0);

$matrix = [];
foreach ($methodStatusRows as $r) {
    $m = (string) ($r['payment_method'] ?? 'unknown');
    $s = (string) ($r['payment_status'] ?? 'unknown');
    if (!isset($matrix[$m])) {
        $matrix[$m] = [];
    }
    $matrix[$m][$s] = [
        'count' => (int) ($r['c'] ?? 0),
        'amount' => (float) ($r['amt'] ?? 0),
    ];
}

qb_page_start('admin', 'Payment reconciliation', 'reconciliation.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Payment reconciliation</h1>
    <p class="page-subtitle">Track Chapa transaction health, failed cases, and intent lifecycle in one place.</p>
  </div>
  <a href="audit.php?action=payment." class="btn btn-ghost btn-sm"><?= qb_icon('list', 'qb-icon', 14) ?> Payment audit</a>
</div>

<form method="get" class="card mb-3" style="padding:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">
  <label class="form-group mb-0">
    <span class="form-label">Window</span>
    <select name="days" class="form-control">
      <option value="1" <?= $days === 1 ? 'selected' : '' ?>>Last 24h</option>
      <option value="3" <?= $days === 3 ? 'selected' : '' ?>>Last 3 days</option>
      <option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
      <option value="14" <?= $days === 14 ? 'selected' : '' ?>>Last 14 days</option>
      <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
      <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
    </select>
  </label>
  <label class="form-group mb-0">
    <span class="form-label">Method</span>
    <select name="method" class="form-control">
      <?php foreach ($validMethods as $m): ?>
      <option value="<?= htmlspecialchars($m) ?>" <?= $methodFilter === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="form-group mb-0">
    <span class="form-label">Status</span>
    <select name="status" class="form-control">
      <?php foreach ($validStatuses as $s): ?>
      <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="reconciliation.php" class="btn btn-ghost">Clear</a>
</form>

<div class="grid grid-4 gap-2 mb-4">
  <div class="stat-card">
    <div class="stat-label">Transactions</div>
    <div class="stat-value"><?= number_format((int) ($totalRow['c'] ?? 0)) ?></div>
    <div class="stat-change up"><?= number_format((float) ($totalRow['amt'] ?? 0), 0) ?> ETB in window</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Pending</div>
    <div class="stat-value"><?= number_format($statusCount('pending')) ?></div>
    <div class="stat-change"><?= number_format($statusAmount('pending'), 0) ?> ETB</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Failed</div>
    <div class="stat-value"><?= number_format($statusCount('failed')) ?></div>
    <div class="stat-change"><?= number_format($statusAmount('failed'), 0) ?> ETB</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Completed</div>
    <div class="stat-value"><?= number_format($statusCount('completed')) ?></div>
    <div class="stat-change up"><?= number_format($statusAmount('completed'), 0) ?> ETB</div>
  </div>
</div>

<div class="card">
  <h3 class="font-bold mb-2 qb-chart-card__title">Needs attention</h3>
  <p class="text-xs text-muted mb-2">Recent failed Chapa transactions in the selected window.</p>
  <div class="table-wrapper">
    <table>
      <thead>
      <tr>
        <th>When</th>
        <th>TX</th>
        <th>Method</th>
        <th>Status</th>
        <th>Amount</th>
      </tr>
      </thead>
      <tbody>
      <?php if (empty($staleRows)): ?>
        <tr><td colspan="5" class="text-muted">No risky transactions found in this window.</td></tr>
      <?php else: ?>
        <?php foreach ($staleRows as $t): ?>
          <?php $sm = qb_payment_status_meta((string) ($t['payment_status'] ?? '')); ?>
          <tr class="qb-row-danger">
            <td class="text-xs"><?= htmlspecialchars((string) ($t['created_at'] ?? '')) ?></td>
            <td><code class="text-xs"><?= htmlspecialchars((string) ($t['tx_id'] ?? '')) ?></code></td>
            <td class="text-xs"><?= htmlspecialchars((string) ($t['payment_method'] ?? '')) ?></td>
            <td><span class="badge <?= htmlspecialchars((string) ($sm['class'] ?? 'badge-gray')) ?>"><?= htmlspecialchars((string) ($sm['label'] ?? 'Unknown')) ?></span></td>
            <td class="text-xs"><?= number_format((float) ($t['total_amount'] ?? 0), 2) ?> ETB</td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-3">
  <h3 class="font-bold mb-2 qb-chart-card__title">Latest Chapa intents</h3>
  <p class="text-xs text-muted mb-2">
    Flow meaning: <strong>pending</strong> = intent exists but provider has not confirmed success yet,
    <strong>paid</strong> = Chapa verify says success, and <strong>fulfilled</strong> = QR Bazar has completed the business action
    (ticket issued / order saved / slot added). New rows append automatically.
  </p>
  <div class="qb-intents-filters" id="qb-intents-filters">
    <button type="button" class="btn btn-ghost btn-sm is-active" data-filter="all">All</button>
    <button type="button" class="btn btn-ghost btn-sm" data-filter="pending">Pending</button>
    <button type="button" class="btn btn-ghost btn-sm" data-filter="paid">Paid</button>
    <button type="button" class="btn btn-ghost btn-sm" data-filter="fulfilled">Fulfilled</button>
    <button type="button" class="btn btn-ghost btn-sm" data-filter="failed_cancelled">Failed/Cancelled</button>
  </div>
  <div class="table-wrapper">
    <table id="qb-intents-table">
      <thead>
      <tr>
        <th>Created</th>
        <th>Intent</th>
        <th>Target</th>
        <th>TX Ref</th>
        <th>Status</th>
        <th>Amount</th>
        <th>Paid at</th>
        <th>Fulfilled at</th>
      </tr>
      </thead>
      <tbody id="qb-intents-body">
      <?php if (empty($chapaIntents)): ?>
        <tr><td colspan="8" class="text-muted">No Chapa intents found yet.</td></tr>
      <?php else: ?>
        <?php foreach ($chapaIntents as $pi): ?>
          <?php
            $ps = strtolower((string) ($pi['provider_status'] ?? 'pending'));
            $isFailedLike = (strpos($ps, 'failed') !== false) || (strpos($ps, 'cancel') !== false);
            $badgeClass = 'badge-gray';
            if ($ps === 'fulfilled') $badgeClass = 'badge-green';
            elseif ($ps === 'paid') $badgeClass = 'badge-blue';
            elseif ($ps === 'pending') $badgeClass = 'badge-amber';
            elseif ($isFailedLike) $badgeClass = 'badge-red';
          ?>
          <?php $rowClass = $isFailedLike ? 'qb-row-danger' : ''; ?>
          <tr class="<?= htmlspecialchars($rowClass) ?>" data-status="<?= htmlspecialchars($ps) ?>">
            <td class="text-xs"><?= htmlspecialchars((string) ($pi['created_at'] ?? '')) ?></td>
            <td><code class="text-xs"><?= htmlspecialchars((string) ($pi['intent_id'] ?? '')) ?></code></td>
            <td class="text-xs"><?= htmlspecialchars((string) ($pi['target_type'] ?? '')) ?></td>
            <td><code class="text-xs"><?= htmlspecialchars((string) ($pi['provider_tx_ref'] ?? '')) ?></code></td>
            <td>
              <span class="badge <?= htmlspecialchars($badgeClass) ?>">
                <?= htmlspecialchars($ps) ?>
              </span>
            </td>
            <td class="text-xs"><?= number_format((float) ($pi['amount'] ?? 0), 2) ?> <?= htmlspecialchars((string) ($pi['currency'] ?? 'ETB')) ?></td>
            <td class="text-xs"><?= htmlspecialchars((string) (($pi['paid_at'] ?? '') ?: '—')) ?></td>
            <td class="text-xs"><?= htmlspecialchars((string) (($pi['consumed_at'] ?? '') ?: '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
#qb-intents-table tbody tr.qb-row-danger td,
.table-wrapper table tbody tr.qb-row-danger td{
  background: #fee2e2 !important;
  color: #7f1d1d !important;
}
.qb-intents-filters{
  display:flex;
  gap:.45rem;
  flex-wrap:wrap;
  margin-bottom:.65rem;
}
.qb-intents-filters .btn.is-active{
  background: var(--qb-brand-orange);
  color: #fff;
  border-color: var(--qb-brand-orange);
}
</style>
<script>
(function(){
  var body = document.getElementById('qb-intents-body');
  var filtersWrap = document.getElementById('qb-intents-filters');
  if (!body) return;
  var currentFilter = 'all';
  var seen = new Set();
  body.querySelectorAll('tr code').forEach(function(el){ seen.add((el.textContent || '').trim()); });
  function isFailedLike(ps){
    return ps.indexOf('failed') !== -1 || ps.indexOf('cancel') !== -1;
  }
  function matchesFilter(ps){
    if (currentFilter === 'all') return true;
    if (currentFilter === 'failed_cancelled') return isFailedLike(ps);
    return ps === currentFilter;
  }
  function applyFilter(){
    body.querySelectorAll('tr[data-status]').forEach(function(row){
      var ps = String(row.getAttribute('data-status') || '').toLowerCase();
      row.style.display = matchesFilter(ps) ? '' : 'none';
    });
  }
  function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function badge(ps){
    var cls = 'badge-gray';
    if (ps === 'fulfilled') cls = 'badge-green';
    else if (ps === 'paid') cls = 'badge-blue';
    else if (ps === 'pending') cls = 'badge-amber';
    else if (isFailedLike(ps)) cls = 'badge-red';
    return '<span class="badge ' + cls + '">' + esc(ps) + '</span>';
  }
  function rowHtml(pi){
    var ps = String((pi.provider_status || 'pending')).toLowerCase();
    var rowCls = isFailedLike(ps) ? ' qb-row-danger' : '';
    return '<tr class="' + rowCls.trim() + '" data-status="' + esc(ps) + '">' +
      '<td class="text-xs">' + esc(pi.created_at || '') + '</td>' +
      '<td><code class="text-xs">' + esc(pi.intent_id || '') + '</code></td>' +
      '<td class="text-xs">' + esc(pi.target_type || '') + '</td>' +
      '<td><code class="text-xs">' + esc(pi.provider_tx_ref || '') + '</code></td>' +
      '<td>' + badge(ps) + '</td>' +
      '<td class="text-xs">' + Number(pi.amount || 0).toFixed(2) + ' ' + esc(pi.currency || 'ETB') + '</td>' +
      '<td class="text-xs">' + esc(pi.paid_at || '—') + '</td>' +
      '<td class="text-xs">' + esc(pi.consumed_at || '—') + '</td>' +
    '</tr>';
  }
  function poll(){
    fetch('../api/admin_chapa_intents.php', { credentials: 'same-origin' })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(data){
        if (!data || !data.ok || !Array.isArray(data.items)) return;
        var added = 0;
        data.items.forEach(function(pi){
          var id = String(pi.intent_id || '');
          if (!id || seen.has(id)) return;
          seen.add(id);
          body.insertAdjacentHTML('afterbegin', rowHtml(pi));
          added += 1;
        });
        if (added > 0) {
          var rows = body.querySelectorAll('tr');
          for (var i = 60; i < rows.length; i++) rows[i].remove();
          applyFilter();
        }
      })
      .catch(function(){});
  }
  if (filtersWrap) {
    filtersWrap.addEventListener('click', function(e){
      var btn = e.target.closest('button[data-filter]');
      if (!btn) return;
      currentFilter = String(btn.getAttribute('data-filter') || 'all');
      filtersWrap.querySelectorAll('button[data-filter]').forEach(function(b){
        b.classList.toggle('is-active', b === btn);
      });
      applyFilter();
    });
  }
  applyFilter();
  setInterval(poll, 8000);
})();
</script>

<?php qb_page_end(); ?>
