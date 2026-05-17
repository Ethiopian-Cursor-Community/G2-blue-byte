<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_admin_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) {
    $days = 30;
}
$roleFilter = isset($_GET['role']) ? (string) $_GET['role'] : 'all';
$evStatus = isset($_GET['ev_status']) ? (string) $_GET['ev_status'] : 'all';

$validRoles = ['super_admin', 'organizer', 'seller', 'buyer'];
if ($roleFilter !== 'all' && !in_array($roleFilter, $validRoles, true)) {
    $roleFilter = 'all';
}
$validEv = ['draft', 'published', 'live', 'ended'];
if ($evStatus !== 'all' && !in_array($evStatus, $validEv, true)) {
    $evStatus = 'all';
}

$since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

$newUsers = (int) (db()->fetchOne(
    'SELECT COUNT(*) AS c FROM app_users WHERE created_at >= ?',
    [$since]
)['c'] ?? 0);

$newEvents = (int) (db()->fetchOne(
    'SELECT COUNT(*) AS c FROM bazar_events WHERE created_at >= ?',
    [$since]
)['c'] ?? 0);

$txInRange = (int) (db()->fetchOne(
    "SELECT COUNT(*) AS c FROM transactions WHERE payment_status = 'completed' AND created_at >= ?",
    [$since]
)['c'] ?? 0);

$txVol = (float) (db()->fetchOne(
    "SELECT COALESCE(SUM(total_amount), 0) AS t FROM transactions WHERE payment_status = 'completed' AND created_at >= ?",
    [$since]
)['t'] ?? 0);

$ticketsInRange = 0;
try {
    $ticketsInRange = (int) (db()->fetchOne(
        'SELECT COUNT(*) AS c FROM tickets WHERE issued_at >= ?',
        [$since]
    )['c'] ?? 0);
} catch (Throwable $e) {
    /* optional table */
}

$userSql = 'SELECT id, login_uid, display_name, role, created_at FROM app_users WHERE created_at >= ?';
$userParams = [$since];
if ($roleFilter !== 'all') {
    $userSql .= ' AND role = ?';
    $userParams[] = $roleFilter;
}
$userSql .= ' ORDER BY created_at DESC LIMIT 40';
$recentUsers = db()->fetchAll($userSql, $userParams);

$evSql = 'SELECT id, name, slug, city, status, created_at, event_start FROM bazar_events WHERE created_at >= ?';
$evParams = [$since];
if ($evStatus !== 'all') {
    $evSql .= ' AND status = ?';
    $evParams[] = $evStatus;
}
$evSql .= ' ORDER BY created_at DESC LIMIT 40';
$recentEvents = db()->fetchAll($evSql, $evParams);

$feed = [];
foreach ($recentUsers as $u) {
    $feed[] = [
        'at' => $u['created_at'],
        'kind' => 'user',
        'label' => $u['display_name'],
        'meta' => $u['role'] . ' · ' . ($u['login_uid'] ?? ''),
        'id' => (int) $u['id'],
    ];
}
foreach ($recentEvents as $e) {
    $feed[] = [
        'at' => $e['created_at'],
        'kind' => 'event',
        'label' => $e['name'],
        'meta' => ($e['status'] ?? '') . ($e['city'] ? ' · ' . $e['city'] : ''),
        'id' => (int) $e['id'],
    ];
}

try {
    $recentTx = db()->fetchAll(
        "SELECT id, tx_id, total_amount, created_at, payment_method FROM transactions
         WHERE payment_status = 'completed' AND created_at >= ?
         ORDER BY created_at DESC LIMIT 25",
        [$since]
    );
    foreach ($recentTx as $t) {
        $feed[] = [
            'at' => $t['created_at'],
            'kind' => 'sale',
            'label' => number_format((float) $t['total_amount'], 2) . ' ETB',
            'meta' => ($t['tx_id'] ?? '') . ' · ' . ($t['payment_method'] ?? ''),
            'id' => (int) $t['id'],
        ];
    }
} catch (Throwable $e) {
    $recentTx = [];
}

usort($feed, static function ($a, $b) {
    return strcmp((string) $b['at'], (string) $a['at']);
});
$feed = array_slice($feed, 0, 60);

$criticalTimeline = [];
$criticalActions = [
    'admin.lock_user',
    'admin.ban_user',
    'admin.downgrade_seller',
    'payment.telebirr.completed',
    'payment.telebirr.failed',
    'payment.cash.completed',
];
$auditSchema = qb_audit_admin_schema();
if ($auditSchema) {
    $actionPh = implode(',', array_fill(0, count($criticalActions), '?'));
    if (($auditSchema['kind'] ?? '') === 'audit_logs') {
        $rows = db()->fetchAll(
            "SELECT a.created_at, a.action, a.entity_type, a.entity_id, a.meta, u.display_name, u.login_uid
             FROM audit_logs a
             LEFT JOIN app_users u ON u.id = a.actor_app_user_id
             WHERE a.created_at >= ? AND a.action IN ($actionPh)
             ORDER BY a.id DESC
             LIMIT 80",
            array_merge([$since], $criticalActions)
        );
    } else {
        $rows = db()->fetchAll(
            "SELECT a.created_at, a.action, a.target_type AS entity_type, CAST(a.target_id AS CHAR) AS entity_id, a.metadata AS meta, u.display_name, u.login_uid
             FROM audit_log a
             LEFT JOIN app_users u ON u.id = a.user_id
             WHERE a.created_at >= ? AND a.action IN ($actionPh)
             ORDER BY a.id DESC
             LIMIT 80",
            array_merge([$since], $criticalActions)
        );
    }
    foreach ($rows as $r) {
        $criticalTimeline[] = [
            'at' => (string) ($r['created_at'] ?? ''),
            'action' => (string) ($r['action'] ?? ''),
            'entity' => trim((string) ($r['entity_type'] ?? '') . ' #' . (string) ($r['entity_id'] ?? '')),
            'actor' => trim((string) ($r['display_name'] ?? '') . ' ' . (string) ($r['login_uid'] ?? '')),
            'meta' => (string) ($r['meta'] ?? ''),
        ];
    }
}
if (function_exists('qb_moderation_history_table_ready') && qb_moderation_history_table_ready()) {
    $modRows = db()->fetchAll(
        "SELECT h.created_at, h.action, h.target_app_user_id, h.reason, u.display_name, u.login_uid
         FROM user_moderation_history h
         LEFT JOIN app_users u ON u.id = h.actor_app_user_id
         WHERE h.created_at >= ?
         ORDER BY h.id DESC
         LIMIT 40",
        [$since]
    );
    foreach ($modRows as $m) {
        $criticalTimeline[] = [
            'at' => (string) ($m['created_at'] ?? ''),
            'action' => 'moderation.' . (string) ($m['action'] ?? ''),
            'entity' => 'app_user #' . (string) ($m['target_app_user_id'] ?? ''),
            'actor' => trim((string) ($m['display_name'] ?? '') . ' ' . (string) ($m['login_uid'] ?? '')),
            'meta' => (string) ($m['reason'] ?? ''),
        ];
    }
}
usort($criticalTimeline, static function ($a, $b) {
    return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
});
$criticalTimeline = array_slice($criticalTimeline, 0, 80);

qb_page_start('admin', 'Activity', 'activity.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">System activity</h1>
    <p class="page-subtitle">Users, events, and sales in the selected window — filter to drill down.</p>
  </div>
  <a href="dashboard.php" class="btn btn-ghost btn-sm"><?= qb_icon('chart', 'qb-icon', 16) ?> Dashboard</a>
</div>

<form class="admin-toolbar card mb-3" method="get" action="">
  <div class="admin-toolbar__row">
    <label class="admin-toolbar__field">
      <span class="admin-toolbar__label">Time range</span>
      <select name="days" class="form-control" onchange="this.form.submit()">
        <option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
        <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
        <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
        <option value="365" <?= $days === 365 ? 'selected' : '' ?>>Last 12 months</option>
      </select>
    </label>
    <label class="admin-toolbar__field">
      <span class="admin-toolbar__label">User list role</span>
      <select name="role" class="form-control">
        <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All roles</option>
        <option value="buyer" <?= $roleFilter === 'buyer' ? 'selected' : '' ?>>Buyers</option>
        <option value="seller" <?= $roleFilter === 'seller' ? 'selected' : '' ?>>Sellers</option>
        <option value="organizer" <?= $roleFilter === 'organizer' ? 'selected' : '' ?>>Organizers</option>
        <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>>Admins</option>
      </select>
    </label>
    <label class="admin-toolbar__field">
      <span class="admin-toolbar__label">Event list status</span>
      <select name="ev_status" class="form-control">
        <option value="all" <?= $evStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="draft" <?= $evStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= $evStatus === 'published' ? 'selected' : '' ?>>Published</option>
        <option value="live" <?= $evStatus === 'live' ? 'selected' : '' ?>>Live</option>
        <option value="ended" <?= $evStatus === 'ended' ? 'selected' : '' ?>>Ended</option>
      </select>
    </label>
    <div class="admin-toolbar__actions">
      <button type="submit" class="btn btn-primary qb-btn-mustard">Apply filters</button>
    </div>
  </div>
</form>

<div class="grid grid-4 gap-2 mb-4">
  <div class="stat-card qb-admin-card stat-card--data-growth">
    <div class="stat-label">New users</div>
    <div class="stat-value"><?= number_format($newUsers) ?></div>
    <div class="stat-change up">Registered in period</div>
  </div>
  <div class="stat-card qb-admin-card stat-card--data-events">
    <div class="stat-label">New events</div>
    <div class="stat-value"><?= number_format($newEvents) ?></div>
    <div class="stat-change up">Bazars created</div>
  </div>
  <div class="stat-card qb-admin-card stat-card--data-revenue">
    <div class="stat-label">Completed sales</div>
    <div class="stat-value"><?= number_format($txInRange) ?></div>
    <div class="stat-change up"><?= number_format($txVol, 0) ?> ETB volume</div>
  </div>
  <div class="stat-card qb-admin-card stat-card--data-tickets">
    <div class="stat-label">Tickets issued</div>
    <div class="stat-value"><?= number_format($ticketsInRange) ?></div>
    <div class="stat-change up">In selected window</div>
  </div>
</div>

<div class="grid grid-2 gap-2 mb-4">
  <div class="card">
    <h3 class="font-bold mb-2 qb-chart-card__title">Recent signups</h3>
    <p class="text-xs text-muted mb-2">Role filter applies to this table only.</p>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Joined</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentUsers)): ?>
            <tr><td colspan="3" class="text-center text-muted">No users in this range.</td></tr>
          <?php else: ?>
            <?php foreach ($recentUsers as $u): ?>
              <tr>
                <td>
                  <div class="font-bold"><?= htmlspecialchars($u['display_name']) ?></div>
                  <div class="text-xs text-muted"><?= htmlspecialchars($u['login_uid']) ?></div>
                </td>
                <td><span class="badge badge-gray"><?= htmlspecialchars($u['role']) ?></span></td>
                <td class="text-xs text-muted"><?= htmlspecialchars($u['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <h3 class="font-bold mb-2 qb-chart-card__title">Recent events</h3>
    <p class="text-xs text-muted mb-2">Status filter applies to this table only.</p>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Event</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentEvents)): ?>
            <tr><td colspan="3" class="text-center text-muted">No events in this range.</td></tr>
          <?php else: ?>
            <?php foreach ($recentEvents as $e): ?>
              <tr>
                <td>
                  <div class="font-bold"><?= htmlspecialchars($e['name']) ?></div>
                  <div class="text-xs text-muted"><?= htmlspecialchars($e['city'] ?? '') ?></div>
                </td>
                <td>
                  <?php
                  $bs = $e['status'] ?? 'draft';
                  $bc = 'badge-draft';
                  if ($bs === 'live' || $bs === 'published') {
                      $bc = 'badge-live';
                  }
                  if ($bs === 'ended') {
                      $bc = 'badge-ended';
                  }
                  ?>
                  <span class="badge <?= $bc ?>"><?= htmlspecialchars($bs) ?></span>
                </td>
                <td class="text-xs text-muted"><?= htmlspecialchars($e['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <h3 class="font-bold mb-2 qb-chart-card__title">Live feed</h3>
  <p class="text-xs text-muted mb-3">Mixed signups, new bazars, and completed sales — newest first.</p>
  <ul class="admin-activity-feed">
    <?php if (empty($feed)): ?>
      <li class="text-muted">No activity in this range.</li>
    <?php else: ?>
      <?php foreach ($feed as $item): ?>
        <li class="admin-activity-feed__item admin-activity-feed__item--<?= htmlspecialchars($item['kind']) ?>">
          <span class="admin-activity-feed__badge"><?= htmlspecialchars($item['kind']) ?></span>
          <div class="admin-activity-feed__body">
            <div class="admin-activity-feed__label"><?= htmlspecialchars($item['label']) ?></div>
            <div class="admin-activity-feed__meta"><?= htmlspecialchars($item['meta']) ?> · #<?= (int) $item['id'] ?></div>
          </div>
          <time class="admin-activity-feed__time"><?= htmlspecialchars($item['at']) ?></time>
        </li>
      <?php endforeach; ?>
    <?php endif; ?>
  </ul>
</div>

<div class="card mt-3">
  <div style="display:flex;flex-wrap:wrap;gap:0.5rem;justify-content:space-between;align-items:center">
    <h3 class="font-bold mb-0 qb-chart-card__title">Critical action timeline</h3>
    <a href="audit.php" class="btn btn-ghost btn-sm"><?= qb_icon('list', 'qb-icon', 14) ?> Full audit</a>
  </div>
  <p class="text-xs text-muted mb-3">Security-sensitive moderation and payment events, newest first.</p>
  <ul class="admin-activity-feed">
    <?php if (empty($criticalTimeline)): ?>
      <li class="text-muted">No critical actions in this range.</li>
    <?php else: ?>
      <?php foreach ($criticalTimeline as $item): ?>
      <li class="admin-activity-feed__item admin-activity-feed__item--sale">
        <span class="admin-activity-feed__badge"><?= htmlspecialchars(qb_shorten_audit_action((string) ($item['action'] ?? 'event'))) ?></span>
        <div class="admin-activity-feed__body">
          <div class="admin-activity-feed__label"><?= htmlspecialchars((string) ($item['entity'] ?? '')) ?></div>
          <div class="admin-activity-feed__meta">
            <?= htmlspecialchars((string) ($item['actor'] !== '' ? $item['actor'] : 'system')) ?>
            <?php if (!empty($item['meta'])): ?>
              · <?= htmlspecialchars(mb_substr((string) $item['meta'], 0, 140)) ?><?= mb_strlen((string) $item['meta']) > 140 ? '…' : '' ?>
            <?php endif; ?>
          </div>
        </div>
        <time class="admin-activity-feed__time"><?= htmlspecialchars((string) ($item['at'] ?? '')) ?></time>
      </li>
      <?php endforeach; ?>
    <?php endif; ?>
  </ul>
</div>

<?php qb_page_end(); ?>
