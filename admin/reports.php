<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_admin_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$tableOk = qb_reports_table_exists();
$filters = [
    'status' => isset($_GET['status']) ? (string) $_GET['status'] : 'all',
    'type'   => isset($_GET['type']) ? (string) $_GET['type'] : 'all',
    'q'      => isset($_GET['q']) ? (string) $_GET['q'] : '',
];
$reports = $tableOk ? qb_admin_reports_fetch($filters) : [];

qb_page_start('admin', 'Reports', 'reports.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Reports queue</h1>
    <p class="page-subtitle">Moderation reports from buyers and staff — filter by status or type.</p>
  </div>
</div>

<?php if (!$tableOk): ?>
  <div class="alert alert-warning">
    <?= qb_icon('alert', 'qb-icon', 18) ?>
    <div>The <code>reports</code> table is missing. Run the database schema (e.g. <code>sql/qrbazar_full.sql</code>) to enable reporting.</div>
  </div>
<?php else: ?>

<form class="admin-toolbar card mb-3" method="get" action="">
  <div class="admin-toolbar__row">
    <label class="admin-toolbar__field">
      <span class="admin-toolbar__label">Status</span>
      <select name="status" class="form-control">
        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="open" <?= $filters['status'] === 'open' ? 'selected' : '' ?>>Open</option>
        <option value="reviewed" <?= $filters['status'] === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
        <option value="resolved" <?= $filters['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
      </select>
    </label>
    <label class="admin-toolbar__field">
      <span class="admin-toolbar__label">Target type</span>
      <select name="type" class="form-control">
        <option value="all" <?= $filters['type'] === 'all' ? 'selected' : '' ?>>All types</option>
        <option value="seller" <?= $filters['type'] === 'seller' ? 'selected' : '' ?>>Seller</option>
        <option value="product" <?= $filters['type'] === 'product' ? 'selected' : '' ?>>Product</option>
        <option value="behavior" <?= $filters['type'] === 'behavior' ? 'selected' : '' ?>>Behavior</option>
        <option value="user" <?= $filters['type'] === 'user' ? 'selected' : '' ?>>User</option>
        <option value="event" <?= $filters['type'] === 'event' ? 'selected' : '' ?>>Event</option>
      </select>
    </label>
    <label class="admin-toolbar__field admin-toolbar__field--grow">
      <span class="admin-toolbar__label">Search text</span>
      <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Search in report body…"/>
    </label>
    <div class="admin-toolbar__actions">
      <button type="submit" class="btn btn-primary qb-btn-mustard">Apply</button>
      <a href="reports.php" class="btn btn-ghost">Reset</a>
    </div>
  </div>
</form>

<div class="card admin-reports-card card--data-moderation no-hover-anim">
    <div class="table-wrapper">
        <table class="admin-reports-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Target</th>
                    <th>Report</th>
                    <th>Reporter</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No reports match your filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($reports as $r):
                        $st = strtolower((string) ($r['status'] ?? 'open'));
                        $statusClass = 'badge-report-' . ($st === 'resolved' ? 'resolved' : ($st === 'reviewed' ? 'reviewed' : 'open'));
                        $tt = strtolower((string) ($r['target_type'] ?? ''));
                        $typeClass = 'badge-gray';
                        if ($tt === 'product') {
                            $typeClass = 'badge-type-product';
                        } elseif ($tt === 'seller') {
                            $typeClass = 'badge-type-seller';
                        } elseif ($tt === 'event') {
                            $typeClass = 'badge-type-event';
                        } elseif ($tt === 'user' || $tt === 'behavior') {
                            $typeClass = 'badge-type-user';
                        }
                        $text = trim((string) ($r['report_text'] ?? ''));
                        if ($text === '') {
                            $text = trim((string) ($r['reason'] ?? '') . ' ' . (string) ($r['details'] ?? ''));
                        }
                        if (function_exists('mb_strimwidth')) {
                            $excerpt = mb_strimwidth($text, 0, 280, '…', 'UTF-8');
                        } else {
                            $excerpt = strlen($text) > 277 ? substr($text, 0, 277) . '…' : $text;
                        }
                        ?>
                    <tr>
                        <td><span class="badge <?= htmlspecialchars($typeClass) ?> text-uppercase"><?= htmlspecialchars($r['target_type'] ?? '—') ?></span></td>
                        <td class="font-mono font-bold">#<?= (int) ($r['target_id'] ?? 0) ?></td>
                        <td class="admin-reports-table__body">
                            <div class="admin-reports-table__excerpt"><?= nl2br(htmlspecialchars($excerpt)) ?></div>
                        </td>
                        <td>
                            <div class="text-secondary"><?= htmlspecialchars($r['reporter_name'] ?: '—') ?></div>
                            <div class="text-xs text-muted"><?= htmlspecialchars($r['reporter_login'] ?: '') ?></div>
                        </td>
                        <td><span class="badge badge-gray"><?= (int) ($r['priority'] ?? 0) ?></span></td>
                        <td><span class="badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($st) ?></span></td>
                        <td class="text-xs text-muted admin-reports-table__when"><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php qb_page_end(); ?>
