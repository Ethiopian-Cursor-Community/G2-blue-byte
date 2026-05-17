<?php
/**
 * Admin: assign gatekeepers to any bazar (demo / support).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/event_staff_assign.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$adminId = (int) ($_SESSION['app_user_id'] ?? 0);
$msg = '';
$err = '';

$events = db()->fetchAll(
    "SELECT id, name, city, event_start, status FROM bazar_events ORDER BY event_start DESC LIMIT 200"
) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && qb_csrf_verify($_POST['csrf'] ?? null)) {
    $op = (string) ($_POST['op'] ?? '');
    if ($op === 'assign') {
        $eid = (int) ($_POST['event_id'] ?? 0);
        $phone = trim((string) ($_POST['phone_or_login'] ?? ''));
        $until = trim((string) ($_POST['valid_until'] ?? ''));
        $r = qb_event_staff_assign_gatekeeper($eid, $phone, $until, $adminId);
        if ($r['ok']) {
            $msg = $r['message'];
        } else {
            $err = $r['message'];
        }
    } elseif ($op === 'remove') {
        $sid = (int) ($_POST['staff_id'] ?? 0);
        $r = qb_event_staff_remove($sid, $adminId, true);
        if ($r['ok']) {
            $msg = $r['message'];
        } else {
            $err = $r['message'];
        }
    }
}

$staffRows = [];
if (qb_event_staff_table_exists()) {
    $staffRows = db()->fetchAll(
        "SELECT es.id AS staff_row_id, es.event_id, es.valid_until, u.display_name, u.login_uid, u.phone, u.role, e.name AS event_name
         FROM event_staff es
         JOIN app_users u ON u.id = es.app_user_id
         JOIN bazar_events e ON e.id = es.event_id
         ORDER BY es.valid_until DESC, e.name ASC"
    );
}

qb_page_start('admin', 'Gate staff', 'event_staff.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Gate staff</h1>
    <p class="page-subtitle">Assign gatekeepers to any bazar. They sign in at <code>login.php?portal=gatekeeper</code> with phone or username.</p>
  </div>
</div>

<?php if ($msg !== ''): ?><div class="alert alert-success mb-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert alert-danger mb-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if (empty($events)): ?>
<div class="alert alert-warning mb-3" role="status">
  <p class="mb-0 qb-alert-prose">No bazars in the database yet. Create events first, then assign gate staff.</p>
</div>
<?php else: ?>
<div class="card mb-3" style="padding:1.25rem">
  <h2 class="font-bold mb-2" style="font-size:1.05rem">Add assignment</h2>
  <form method="post" class="grid gap-2" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:end">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(qb_csrf_token()) ?>"/>
    <input type="hidden" name="op" value="assign"/>
    <div class="form-group mb-0">
      <label class="form-label" for="event_id">Bazar</label>
      <select name="event_id" id="event_id" class="form-control" required>
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?> — <?= htmlspecialchars((string) ($ev['city'] ?? '')) ?> (<?= htmlspecialchars((string) ($ev['status'] ?? '')) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group mb-0" style="grid-column:span 2;min-width:200px">
      <label class="form-label" for="phone_or_login">Phone or login</label>
      <input type="text" name="phone_or_login" id="phone_or_login" class="form-control" required placeholder="09… or username" autocomplete="off"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label" for="valid_until">Active until</label>
      <input type="datetime-local" name="valid_until" id="valid_until" class="form-control" required
        value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime('+30 days'))) ?>"/>
    </div>
    <div class="form-group mb-0">
      <button type="submit" class="btn btn-primary btn-full">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="font-bold mb-2" style="font-size:1.05rem">All assignments</h2>
  <?php if (empty($staffRows)): ?>
    <p class="text-muted text-sm mb-0">No rows in <code>event_staff</code>. Run <code>install/migrate_event_staff.php</code> if missing.</p>
  <?php else: ?>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Bazar</th>
          <th>Person</th>
          <th>Contact</th>
          <th>Until</th>
          <th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($staffRows as $sr): ?>
        <tr>
          <td class="font-bold"><?= htmlspecialchars((string) ($sr['event_name'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string) ($sr['display_name'] ?? '')) ?></td>
          <td class="text-sm"><span class="font-mono"><?= htmlspecialchars((string) ($sr['phone'] ?? '—')) ?></span><br/><code class="text-xs"><?= htmlspecialchars((string) ($sr['login_uid'] ?? '')) ?></code></td>
          <td class="text-sm"><?= htmlspecialchars((string) ($sr['valid_until'] ?? '')) ?></td>
          <td style="text-align:right">
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this assignment?');">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(qb_csrf_token()) ?>"/>
              <input type="hidden" name="op" value="remove"/>
              <input type="hidden" name="staff_id" value="<?= (int) ($sr['staff_row_id'] ?? 0) ?>"/>
              <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php qb_page_end(); ?>
