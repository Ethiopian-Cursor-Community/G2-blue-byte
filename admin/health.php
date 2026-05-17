<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_admin_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$mysqlVer = db()->fetchOne('SELECT VERSION() AS v');
$auditSchema = qb_audit_admin_schema();
$fraudTable = (bool)db()->fetchOne(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fraud_signals'"
);

$counts = [
    'Users'       => (int)(db()->fetchOne('SELECT COUNT(*) AS c FROM app_users')['c'] ?? 0),
    'Events'      => (int)(db()->fetchOne('SELECT COUNT(*) AS c FROM bazar_events')['c'] ?? 0),
    'Products'    => (int)(db()->fetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0),
    'Transactions'=> (int)(db()->fetchOne('SELECT COUNT(*) AS c FROM transactions')['c'] ?? 0),
    'Tickets'     => (int)(db()->fetchOne('SELECT COUNT(*) AS c FROM tickets')['c'] ?? 0),
];

$auditLogging = function_exists('qb_audit_table_exists') && qb_audit_table_exists();
if (isset($_GET['detail'])) {
    header('Content-Type: application/json');
    $type = (string) $_GET['detail'];
    $details = [];
    if ($type === 'Users') {
        $details[] = ['k' => 'Buyers', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role='buyer'")['c']];
        $details[] = ['k' => 'Sellers', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role='seller'")['c']];
        $details[] = ['k' => 'Organizers', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role='organizer'")['c']];
        $details[] = ['k' => 'Admins', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role='super_admin'")['c']];
    } elseif ($type === 'Events') {
        $details[] = ['k' => 'Live', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status IN ('published','live')")['c']];
        $details[] = ['k' => 'Draft', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status='draft'")['c']];
        $details[] = ['k' => 'Ended', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status='ended'")['c']];
    } elseif ($type === 'Products') {
        $details[] = ['k' => 'Active', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM products WHERE is_active=1")['c']];
        $details[] = ['k' => 'Hidden', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM products WHERE is_active=0")['c']];
    } elseif ($type === 'Transactions') {
        $details[] = ['k' => 'Completed', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status='completed'")['c']];
        $details[] = ['k' => 'Pending', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status='pending'")['c']];
        $details[] = ['k' => 'Failed', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status='failed'")['c']];
    } elseif ($type === 'Tickets') {
        $details[] = ['k' => 'Active', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM tickets WHERE status='active'")['c']];
        $details[] = ['k' => 'Used/Scanned', 'v' => (int)db()->fetchOne("SELECT COUNT(*) AS c FROM tickets WHERE status='used'")['c']];
    }
    echo json_encode(['details' => $details]);
    exit;
}

qb_page_start('admin', 'Platform health', 'health.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Platform health</h1>
    <p class="page-subtitle">Runtime, database, and feature flags at a glance.</p>
  </div>
</div>

<div class="card mb-3">
  <h2 class="text-sm font-bold mb-2">Runtime</h2>
  <ul class="text-sm" style="line-height:1.8">
    <li>PHP <code><?= htmlspecialchars(PHP_VERSION) ?></code></li>
    <li>MySQL <code><?= htmlspecialchars((string)($mysqlVer['v'] ?? '?')) ?></code></li>
    <li>App <code><?= htmlspecialchars(APP_NAME) ?></code></li>
  </ul>
</div>

<div class="card mb-3">
  <h2 class="text-sm font-bold mb-2">Data volumes</h2>
  <div class="table-wrapper">
    <table class="health-table">
      <tbody>
        <?php foreach ($counts as $label => $n): ?>
        <tr ondblclick="showHealthDetail('<?= $label ?>')">
          <td><?= htmlspecialchars($label) ?></td>
          <td class="font-bold"><?= number_format($n) ?></td>
          <td class="text-xs text-muted">Double-click for details</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="healthModal" class="qb-mini-modal" hidden>
  <div class="qb-mini-modal__content card">
    <h3 class="font-bold mb-2" id="healthModalTitle"></h3>
    <div id="healthModalBody" class="text-sm py-2"></div>
    <button type="button" class="btn btn-primary btn-sm mt-3" onclick="document.getElementById('healthModal').hidden = true">Close</button>
  </div>
</div>

<script>
function showHealthDetail(type) {
  const title = document.getElementById('healthModalTitle');
  const body = document.getElementById('healthModalBody');
  const modal = document.getElementById('healthModal');
  title.textContent = type + ' Details';
  body.innerHTML = '<p class="loading">Loading details...</p>';
  modal.hidden = false;

  fetch('health.php?detail=' + type)
    .then(r => r.json())
    .then(data => {
       if (data.error) {
         body.textContent = data.error;
       } else {
         let html = '<ul class="text-sm">';
         data.details.forEach(d => {
            html += `<li><strong>${d.k}:</strong> ${d.v}</li>`;
         });
         html += '</ul>';
         body.innerHTML = html;
       }
    })
    .catch(e => body.textContent = 'Error loading details.');
}
</script>

<style>
.health-table tr { cursor: pointer; transition: background 0.2s; }
.health-table tr:hover { background: var(--bg-soft); }
.qb-mini-modal {
  position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5);
  display:none; align-items:center; justify-content:center; z-index:9999;
}
.qb-mini-modal:not([hidden]) {
  display: flex;
}
.qb-mini-modal__content { width:90%; max-width:400px; padding:1.5rem; }
</style>

<div class="card">
  <h2 class="text-sm font-bold mb-2">Security modules</h2>
  <ul class="text-sm" style="line-height:1.8">
    <li>Audit UI table: <?php if ($auditSchema): ?>✓ <code><?= htmlspecialchars($auditSchema['table']) ?></code><?php else: ?><span class="text-muted">No <code>audit_logs</code> / <code>audit_log</code></span><?php endif; ?></li>
    <li>Live audit writes (<code>audit_logs</code>): <?= $auditLogging ? '✓ enabled' : '<span class="text-muted">table missing — writes skipped</span>' ?></li>
    <li>Fraud signals table: <?= $fraudTable ? '✓ <code>fraud_signals</code>' : '<span class="text-muted">not installed</span>' ?></li>
  </ul>
</div>

<?php qb_page_end(); ?>
