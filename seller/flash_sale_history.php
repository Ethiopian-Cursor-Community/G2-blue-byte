<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

$seller = getCurrentSeller();
$sid = (int) $seller['id'];
$tableReady = qb_table_exists('flash_sales');
$success = '';
$error = '';
$csrf = qb_csrf_token();
$now = time();

if (!$tableReady) {
    qb_page_start('seller', 'Flash Sales History', 'flash_sale.php', false);
    echo '<div class="alert alert-warning mb-2">Flash sales table is not installed.</div>';
    qb_page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $fid = (int) ($_POST['id'] ?? 0);
        $row = $fid > 0 ? db()->fetchOne('SELECT id FROM flash_sales WHERE id = ? AND seller_id = ?', [$fid, $sid]) : null;
        if (!$row) {
            $error = 'Flash sale not found.';
        } elseif ($action === 'toggle') {
            db()->execute('UPDATE flash_sales SET is_active = NOT is_active WHERE id = ? AND seller_id = ?', [$fid, $sid]);
            $success = 'Flash sale updated.';
        } elseif ($action === 'delete') {
            db()->execute('DELETE FROM flash_sales WHERE id = ? AND seller_id = ?', [$fid, $sid]);
            $success = 'Flash sale removed.';
        }
    }
}

$list = db()->fetchAll(
    "SELECT fs.*, p.name AS product_name, e.name AS event_name
     FROM flash_sales fs
     INNER JOIN products p ON p.id = fs.product_id
     LEFT JOIN bazar_events e ON e.id = fs.event_id
     WHERE fs.seller_id = ?
     ORDER BY fs.starts_at DESC",
    [$sid]
);

qb_page_start('seller', 'Flash Sales History', 'flash_sale.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Flash Sales · Scheduled &amp; Past</h1>
    <p class="page-subtitle">Manage all timed discount records from one table view.</p>
  </div>
  <a href="flash_sale.php" class="btn btn-secondary btn-sm"><?= qb_icon('plus', 'qb-icon', 16) ?> New flash sale</a>
</div>

<?php if ($success): ?>
  <div class="alert alert-success mb-2"><?= qb_icon('check', 'qb-icon', 16) ?> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger mb-2"><?= qb_icon('alert', 'qb-icon', 16) ?> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
  <?php if ($list === []): ?>
    <p class="text-muted text-sm mb-0">No flash sales yet.</p>
  <?php else: ?>
  <div class="qb-flash-tabs mb-2" role="tablist" aria-label="Flash sale table filter">
    <button type="button" class="btn btn-secondary btn-sm qb-flash-tab is-active" data-flash-filter="scheduled" aria-pressed="true">Scheduled</button>
    <button type="button" class="btn btn-ghost btn-sm qb-flash-tab" data-flash-filter="past" aria-pressed="false">Past</button>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Product</th>
          <th>Deal</th>
          <th>Window</th>
          <th>Scope</th>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $row):
            $st = strtotime((string) $row['starts_at']);
            $en = strtotime((string) $row['ends_at']);
            $active = !empty($row['is_active']);
            $phase = !$active ? 'off' : ($now < $st ? 'upcoming' : ($now > $en ? 'ended' : 'live'));
            $bucket = in_array($phase, ['ended', 'off'], true) ? 'past' : 'scheduled';
        ?>
        <tr data-flash-bucket="<?= htmlspecialchars($bucket, ENT_QUOTES, 'UTF-8') ?>">
          <td>
            <div class="font-bold"><?= htmlspecialchars((string) $row['product_name']) ?></div>
            <div class="text-xs text-muted"><?= (int) $row['discount_pct'] ?>% off</div>
          </td>
          <td>
            <span class="text-muted"><?= number_format((float) $row['original_price'], 2) ?></span>
            →
            <span class="font-bold text-emerald"><?= number_format((float) $row['sale_price'], 2) ?> ETB</span>
          </td>
          <td class="text-sm">
            <?= htmlspecialchars(date('M j, H:i', $st)) ?>
            <br><span class="text-muted">→ <?= htmlspecialchars(date('M j, H:i', $en)) ?></span>
          </td>
          <td class="text-sm"><?= $row['event_id'] ? htmlspecialchars((string) ($row['event_name'] ?? 'Event')) : 'Global' ?></td>
          <td>
            <?php if ($phase === 'live'): ?>
              <span class="badge badge-amber"><?= qb_icon('flash', 'qb-icon', 12) ?> Live</span>
            <?php elseif ($phase === 'upcoming'): ?>
              <span class="badge badge-blue">Soon</span>
            <?php elseif ($phase === 'ended'): ?>
              <span class="badge badge-gray">Ended</span>
            <?php else: ?>
              <span class="badge badge-gray">Off</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm"><?= $active ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this flash sale?')">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm text-danger"><?= qb_icon('trash', 'qb-icon', 16) ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<style>
.qb-flash-tabs{display:flex;gap:.5rem;align-items:center}
.qb-flash-tab.is-active{background:var(--accent);color:#fff;border-color:var(--accent)}
</style>
<script>
(function(){
  var tabs = document.querySelectorAll('.qb-flash-tab');
  if (!tabs.length) return;
  var rows = document.querySelectorAll('tr[data-flash-bucket]');
  function applyFilter(bucket){
    rows.forEach(function(row){
      row.style.display = row.getAttribute('data-flash-bucket') === bucket ? '' : 'none';
    });
    tabs.forEach(function(tab){
      var active = tab.getAttribute('data-flash-filter') === bucket;
      tab.classList.toggle('is-active', active);
      tab.classList.toggle('btn-secondary', active);
      tab.classList.toggle('btn-ghost', !active);
      tab.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }
  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      applyFilter(tab.getAttribute('data-flash-filter') || 'scheduled');
    });
  });
  applyFilter('scheduled');
})();
</script>
<?php qb_page_end(); ?>
