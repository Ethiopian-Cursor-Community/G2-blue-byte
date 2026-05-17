<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$tableOk = function_exists('qb_fraud_table_exists') && qb_fraud_table_exists();
$hasResolved = false;
if ($tableOk) {
    $hasResolved = (bool)db()->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fraud_signals' AND COLUMN_NAME = 'resolved'"
    );
}

$showResolved = $hasResolved && (($_GET['filter'] ?? '') === 'all');
$flash = '';

if ($tableOk && $hasResolved && ($_POST['action'] ?? '') === 'resolve') {
    $sid = (int)($_POST['signal_id'] ?? 0);
    if ($sid > 0) {
        db()->execute('UPDATE fraud_signals SET resolved = 1 WHERE id = ?', [$sid]);
        if (function_exists('qb_audit_log')) {
            qb_audit_log('fraud.signal.resolved', 'fraud_signal', (string)$sid, []);
        }
        $flash = 'Signal marked resolved.';
    }
}

$rows = [];
if ($tableOk) {
    if ($hasResolved && !$showResolved) {
        $rows = db()->fetchAll(
            'SELECT * FROM fraud_signals WHERE resolved = 0 ORDER BY score DESC, id DESC LIMIT 150'
        );
    } else {
        $rows = db()->fetchAll(
            'SELECT * FROM fraud_signals ORDER BY score DESC, id DESC LIMIT 150'
        );
    }
}

qb_page_start('admin', 'Fraud signals', 'fraud.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Fraud &amp; risk signals</h1>
    <p class="page-subtitle">Velocity and large-sale rules enqueue rows for review.</p>
  </div>
  <?php if ($tableOk && $hasResolved): ?>
  <div class="auth-portal-tabs" style="max-width:320px">
    <a href="fraud.php" class="auth-tab <?= !$showResolved ? 'active' : '' ?>">Open</a>
    <a href="fraud.php?filter=all" class="auth-tab <?= $showResolved ? 'active' : '' ?>">All</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($flash !== ''): ?>
<div class="card mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if (!$tableOk): ?>
<div class="card">
  <p class="text-muted">The <code>fraud_signals</code> table is not installed. Import <code>sql/migrate_audit_fraud_tables.sql</code> or run migrations.</p>
</div>
<?php elseif (!$rows): ?>
<div class="card">
  <p class="text-muted"><?= $showResolved ? 'No signals recorded yet.' : 'No open signals — thresholds create rows when hit.' ?></p>
</div>
<?php else: ?>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Type</th>
          <th>Score</th>
          <th>Reference</th>
          <th>When</th>
          <th>Meta</th>
          <?php if ($hasResolved): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $metaRaw = $r['meta'] ?? null;
            $metaShow = is_string($metaRaw) ? $metaRaw : (is_array($metaRaw) ? json_encode($metaRaw, JSON_UNESCAPED_UNICODE) : '');
            $isOpen = !$hasResolved || empty($r['resolved']);
        ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)$r['signal_type']) ?></strong></td>
          <td><span class="badge badge-gray"><?= (int)$r['score'] ?></span></td>
          <td class="text-xs">
            <code><?= htmlspecialchars((string)$r['ref_type']) ?></code>
            · <?= htmlspecialchars((string)$r['ref_id']) ?>
          </td>
          <td class="text-xs"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td>
          <td class="text-xs" style="max-width:220px;word-break:break-word"><?= htmlspecialchars(mb_substr($metaShow, 0, 200)) ?><?= mb_strlen($metaShow) > 200 ? '…' : '' ?></td>
          <?php if ($hasResolved): ?>
          <td>
            <?php if ($isOpen): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="resolve">
              <input type="hidden" name="signal_id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-ghost"><?= qb_icon('check', 'qb-icon', 14) ?> Resolve</button>
            </form>
            <?php else: ?>
            <span class="text-xs text-muted">Resolved</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php qb_page_end(); ?>
