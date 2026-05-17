<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_admin_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();
qb_apply_observability_schema();

$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, [1, 3, 7, 14, 30], true)) {
    $days = 7;
}
$qRaw = trim((string) ($_GET['q'] ?? ''));
$q = $qRaw !== '' ? sanitize($qRaw) : '';
$since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

$where = ['created_at >= ?'];
$params = [$since];
if ($q !== '') {
    $where[] = '(event_key LIKE ? OR target_type LIKE ? OR target_id LIKE ? OR actor_role LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totals = db()->fetchOne(
    "SELECT COUNT(*) AS n,
            COUNT(DISTINCT event_key) AS key_n,
            COUNT(DISTINCT COALESCE(app_user_id,0)) AS actor_n
     FROM qb_event_logs
     $whereSql",
    $params
) ?: ['n' => 0, 'key_n' => 0, 'actor_n' => 0];

$eventBreakdown = db()->fetchAll(
    "SELECT event_key, COUNT(*) AS n
     FROM qb_event_logs
     $whereSql
     GROUP BY event_key
     ORDER BY n DESC, event_key ASC
     LIMIT 30",
    $params
);

$recent = db()->fetchAll(
    "SELECT *
     FROM qb_event_logs
     $whereSql
     ORDER BY id DESC
     LIMIT 120",
    $params
);

$funnelRows = db()->fetchAll(
    "SELECT event_key, COUNT(*) AS n
     FROM qb_event_logs
     WHERE created_at >= ?
       AND event_key IN (
           'ticket.purchase.intent_created',
           'ticket.purchase.checkout_started',
           'payment.verify.success',
           'payment.intent.fulfilled',
           'product.checkout.intent_created',
           'product.checkout.started',
           'product.checkout.start_failed'
       )
     GROUP BY event_key",
    [$since]
);
$funnel = [];
foreach ($funnelRows as $r) {
    $funnel[(string) ($r['event_key'] ?? '')] = (int) ($r['n'] ?? 0);
}
$failedStarts = (int) ($funnel['product.checkout.start_failed'] ?? 0);
$started = (int) ($funnel['product.checkout.started'] ?? 0);
$startFailRate = $started > 0 ? round(($failedStarts / max(1, $started)) * 100, 1) : 0.0;
$pendingIntents = 0;
if (qb_table_exists('payment_intents')) {
    $pi = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM payment_intents WHERE provider_status = 'pending' AND created_at >= ?",
        [$since]
    );
    $pendingIntents = (int) ($pi['c'] ?? 0);
}
$auditRisk = 0;
$auditSchema = qb_audit_admin_schema();
if ($auditSchema) {
    $auditTable = (string) ($auditSchema['table'] ?? '');
    if ($auditTable !== '') {
        $ar = db()->fetchOne(
            "SELECT COUNT(*) AS c FROM $auditTable WHERE created_at >= ? AND (action LIKE '%fail%' OR action LIKE '%denied%' OR action LIKE '%forbid%')",
            [$since]
        );
        $auditRisk = (int) ($ar['c'] ?? 0);
    }
}

$href = static function (int $d, string $qv): string {
    $p = ['days' => $d];
    if ($qv !== '') {
        $p['q'] = $qv;
    }
    return 'observability.php?' . http_build_query($p);
};

qb_page_start('admin', 'Observability', 'observability.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Observability</h1>
    <p class="page-subtitle">Track payment funnel and endpoint behavior from structured system events.</p>
  </div>
  <a href="dashboard.php" class="btn btn-secondary btn-sm"><?= qb_icon('chart', 'qb-icon', 16) ?> Dashboard</a>
</div>

<form method="get" class="card mb-3" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group mb-0">
    <label class="form-label" for="obs-days">Window</label>
    <select id="obs-days" name="days" class="form-control">
      <?php foreach ([1, 3, 7, 14, 30] as $opt): ?>
      <option value="<?= $opt ?>" <?= $days === $opt ? 'selected' : '' ?>>Last <?= $opt ?> day<?= $opt > 1 ? 's' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group mb-0" style="min-width:240px">
    <label class="form-label" for="obs-q">Filter</label>
    <input id="obs-q" type="search" name="q" class="form-control" value="<?= htmlspecialchars($qRaw) ?>" placeholder="event key / target / role">
  </div>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a href="<?= htmlspecialchars($href($days, '')) ?>" class="btn btn-ghost">Clear</a>
</form>

<div class="grid grid-3 gap-2 mb-3">
  <div class="card"><div class="text-xs text-muted">Events logged</div><div class="font-black text-2xl"><?= number_format((int) ($totals['n'] ?? 0)) ?></div></div>
  <div class="card"><div class="text-xs text-muted">Event keys</div><div class="font-black text-2xl"><?= number_format((int) ($totals['key_n'] ?? 0)) ?></div></div>
  <div class="card"><div class="text-xs text-muted">Actors observed</div><div class="font-black text-2xl"><?= number_format((int) ($totals['actor_n'] ?? 0)) ?></div></div>
</div>

<div class="grid grid-2 gap-2 mb-3">
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1rem">Alert panel</h2>
    <div class="text-sm mb-1">
      <?php if ($startFailRate >= 8.0): ?>
        <span class="badge badge-red">High</span>
      <?php elseif ($startFailRate >= 3.0): ?>
        <span class="badge badge-amber">Medium</span>
      <?php else: ?>
        <span class="badge badge-green">Low</span>
      <?php endif; ?>
      Checkout start failures: <strong><?= number_format($failedStarts) ?></strong> (<?= number_format($startFailRate, 1) ?>%)
    </div>
    <div class="text-sm mb-1">
      <?php if ($pendingIntents >= 20): ?>
        <span class="badge badge-red">Attention</span>
      <?php elseif ($pendingIntents >= 8): ?>
        <span class="badge badge-amber">Watch</span>
      <?php else: ?>
        <span class="badge badge-green">Healthy</span>
      <?php endif; ?>
      Pending intents in window: <strong><?= number_format($pendingIntents) ?></strong>
    </div>
    <div class="text-sm">
      <?php if ($auditRisk >= 15): ?>
        <span class="badge badge-red">Security risk</span>
      <?php elseif ($auditRisk >= 5): ?>
        <span class="badge badge-amber">Review</span>
      <?php else: ?>
        <span class="badge badge-green">Stable</span>
      <?php endif; ?>
      Audit suspicious actions: <strong><?= number_format($auditRisk) ?></strong>
    </div>
    <div class="text-xs text-muted mt-2">Thresholds are tunable in code; this panel is intended as an on-call signal.</div>
    <div class="mt-2" style="display:flex;gap:.4rem;flex-wrap:wrap">
      <a href="reconciliation.php?status=failed" class="btn btn-ghost btn-sm">Failed payments</a>
      <a href="audit.php" class="btn btn-ghost btn-sm">Security audit</a>
      <a href="health.php" class="btn btn-ghost btn-sm">Platform health</a>
    </div>
  </div>
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1rem">Payment funnel</h2>
    <div class="text-sm">Ticket intents: <strong><?= number_format((int) ($funnel['ticket.purchase.intent_created'] ?? 0)) ?></strong></div>
    <div class="text-sm">Ticket checkout started: <strong><?= number_format((int) ($funnel['ticket.purchase.checkout_started'] ?? 0)) ?></strong></div>
    <div class="text-sm">Cart intents: <strong><?= number_format((int) ($funnel['product.checkout.intent_created'] ?? 0)) ?></strong></div>
    <div class="text-sm">Cart checkout started: <strong><?= number_format((int) ($funnel['product.checkout.started'] ?? 0)) ?></strong></div>
    <div class="text-sm">Checkout start failed: <strong><?= number_format((int) ($funnel['product.checkout.start_failed'] ?? 0)) ?></strong></div>
    <div class="text-sm">Verify success: <strong><?= number_format((int) ($funnel['payment.verify.success'] ?? 0)) ?></strong></div>
    <div class="text-sm">Fulfilled intents: <strong><?= number_format((int) ($funnel['payment.intent.fulfilled'] ?? 0)) ?></strong></div>
  </div>
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1rem">Top event keys</h2>
    <?php if ($eventBreakdown === []): ?>
      <p class="text-sm text-muted mb-0">No events in this window.</p>
    <?php else: ?>
      <ol class="text-sm" style="margin:0;padding-left:1rem;display:grid;gap:0.25rem">
      <?php foreach ($eventBreakdown as $r): ?>
        <li><code><?= htmlspecialchars((string) ($r['event_key'] ?? '')) ?></code> — <strong><?= number_format((int) ($r['n'] ?? 0)) ?></strong></li>
      <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2 class="font-bold mb-2" style="font-size:1rem">Recent events</h2>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Key</th>
          <th>Actor</th>
          <th>Target</th>
          <th>Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recent === []): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">No observability events found.</td></tr>
        <?php else: foreach ($recent as $row): ?>
        <tr>
          <td class="text-xs"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
          <td><code><?= htmlspecialchars((string) ($row['event_key'] ?? '')) ?></code></td>
          <td class="text-xs"><?= htmlspecialchars(trim(((string) ($row['actor_role'] ?? '')) . ' #' . ((string) ($row['app_user_id'] ?? '0')))) ?></td>
          <td class="text-xs"><?= htmlspecialchars(trim(((string) ($row['target_type'] ?? '')) . ' ' . ((string) ($row['target_id'] ?? '')))) ?></td>
          <td class="text-xs text-muted"><?= htmlspecialchars(mb_strimwidth((string) ($row['meta_json'] ?? ''), 0, 140, '…')) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php qb_page_end(); ?>
