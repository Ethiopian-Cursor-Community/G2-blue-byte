<?php
/**
 * Assign gatekeepers to bazars you manage (phone or login lookup).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/event_staff_assign.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireOrganizer();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
if (function_exists('qb_organizer_is_co_only') && qb_organizer_is_co_only($uid)) {
    header('Location: dashboard.php', true, 302);
    exit;
}

$uid = (int) (currentUser()['id'] ?? 0);
$ew = qb_organizer_event_alias_access_sql('e');
$eb = qb_organizer_event_access_bind($uid);
$events = db()->fetchAll(
    "SELECT e.id, e.name, e.city, e.event_start FROM bazar_events e WHERE $ew ORDER BY e.event_start DESC",
    $eb
);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && qb_csrf_verify($_POST['csrf'] ?? null)) {
    $op = (string) ($_POST['op'] ?? '');
    if ($op === 'assign') {
        $eid = (int) ($_POST['event_id'] ?? 0);
        if (!qb_organizer_event_id_allowed($eid, $events)) {
            $err = 'You cannot assign staff to that bazar.';
        } else {
            $phone = trim((string) ($_POST['phone_or_login'] ?? ''));
            $until = trim((string) ($_POST['valid_until'] ?? ''));
            $r = qb_event_staff_assign_gatekeeper($eid, $phone, $until, $uid);
            if ($r['ok']) {
                $msg = $r['message'];
            } else {
                $err = $r['message'];
            }
        }
    } elseif ($op === 'remove') {
        $sid = (int) ($_POST['staff_id'] ?? 0);
        $r = qb_event_staff_remove($sid, $uid, false);
        if ($r['ok']) {
            $msg = $r['message'];
        } else {
            $err = $r['message'];
        }
    }
}

$staffRows = [];
if ($events !== [] && qb_event_staff_table_exists()) {
    $eids = array_map(static fn ($x) => (int) ($x['id'] ?? 0), $events);
    $eids = array_values(array_filter($eids, static fn ($x) => $x > 0));
    if ($eids !== []) {
        $ph = implode(',', array_fill(0, count($eids), '?'));
        $staffRows = db()->fetchAll(
            "SELECT es.id AS staff_row_id, es.event_id, es.valid_until, u.display_name, u.login_uid, u.phone, u.role, e.name AS event_name
             FROM event_staff es
             JOIN app_users u ON u.id = es.app_user_id
             JOIN bazar_events e ON e.id = es.event_id
             WHERE es.event_id IN ($ph)
             ORDER BY e.event_start DESC, u.display_name ASC",
            $eids
        );
    }
}

qb_page_start('organizer', 'Gate staff', 'staff.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Gate staff</h1>
    <p class="page-subtitle">Assign trusted people to scan tickets and view leaderboards. They use the <strong>Gatekeeper</strong> portal (phone + password). Only buyer accounts (or existing gatekeepers) can be linked.</p>
  </div>
</div>

<?php if ($msg !== ''): ?><div class="alert alert-success mb-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert alert-danger mb-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if (empty($events)): ?>
  <p class="text-secondary">Create a bazar first, then return here to assign gate staff.</p>
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
          <option value="<?= (int) $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?> — <?= htmlspecialchars((string) ($ev['city'] ?? '')) ?></option>
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
        value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime('+7 days'))) ?>"/>
    </div>
    <div class="form-group mb-0">
      <button type="submit" class="btn btn-primary btn-full">Save</button>
    </div>
  </form>
</div>

<div class="card">
  <h2 class="font-bold mb-2" style="font-size:1.05rem">Current assignments</h2>
  <?php if (empty($staffRows)): ?>
    <p class="text-muted text-sm mb-0">No gate staff yet.</p>
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
          <td><?= htmlspecialchars((string) ($sr['display_name'] ?? '')) ?><br/><span class="text-xs text-muted"><?= htmlspecialchars((string) ($sr['role'] ?? '')) ?></span></td>
          <td class="text-sm"><span class="font-mono"><?= htmlspecialchars((string) ($sr['phone'] ?? '—')) ?></span><br/><code class="text-xs"><?= htmlspecialchars((string) ($sr['login_uid'] ?? '')) ?></code></td>
          <td class="text-sm"><?= htmlspecialchars((string) ($sr['valid_until'] ?? '')) ?></td>
          <td style="text-align:right">
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this gate assignment?');">
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
<?php endif; ?>
<?php qb_page_end(); ?>
