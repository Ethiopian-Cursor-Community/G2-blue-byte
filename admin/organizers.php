<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$success = '';
$error = '';

$hasResidence = qb_has_column('app_users', 'residence_city');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && qb_table_exists('bazar_event_organizers')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_co') {
        $eid = (int) ($_POST['event_id'] ?? 0);
        $cid = (int) ($_POST['co_user_id'] ?? 0);
        $ev = db()->fetchOne('SELECT id, organizer_app_user_id, status FROM bazar_events WHERE id = ?', [$eid]);
        $u = db()->fetchOne("SELECT id, role FROM app_users WHERE id = ?", [$cid]);
        $primary = (int) ($ev['organizer_app_user_id'] ?? 0);
        if (($ev['status'] ?? '') === 'canceled') {
            $error = 'Canceled events cannot have co-organizers assigned.';
        } elseif ($ev && $u && ($u['role'] ?? '') === 'organizer' && $cid !== $primary && $cid > 0) {
            db()->execute(
                'INSERT IGNORE INTO bazar_event_organizers (event_id, app_user_id) VALUES (?, ?)',
                [$eid, $cid]
            );
            $success = 'Co-organizer added to the event.';
        } else {
            $error = 'Pick a valid event and another organizer (not the primary).';
        }
    }
    if ($action === 'remove_co') {
        $eid = (int) ($_POST['event_id'] ?? 0);
        $cid = (int) ($_POST['co_user_id'] ?? 0);
        db()->execute(
            'DELETE FROM bazar_event_organizers WHERE event_id = ? AND app_user_id = ?',
            [$eid, $cid]
        );
        $success = 'Co-organizer removed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasResidence && ($_POST['action'] ?? '') === 'save_org_city') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $city = trim(sanitize($_POST['residence_city'] ?? ''));
    if ($uid > 0) {
        $chk = db()->fetchOne("SELECT id FROM app_users WHERE id = ? AND role = 'organizer'", [$uid]);
        if ($chk) {
            db()->execute(
                'UPDATE app_users SET residence_city = ? WHERE id = ?',
                [$city !== '' ? $city : null, $uid]
            );
            $success = 'Living location updated.';
        } else {
            $error = 'Invalid organizer account.';
        }
    }
}

$orgCols = $hasResidence
    ? 'id, display_name, login_uid, phone, email, is_active, residence_city'
    : 'id, display_name, login_uid, phone, email, is_active';
$organizerUsers = db()->fetchAll(
    "SELECT $orgCols FROM app_users WHERE role = 'organizer' ORDER BY display_name ASC"
);

$eventsAll = db()->fetchAll(
    'SELECT e.id, e.name, e.slug, e.status, e.organizer_app_user_id, u.display_name AS primary_name
     FROM bazar_events e
     LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
     ORDER BY e.event_start DESC, e.id DESC'
);

$eventsForCo = array_values(array_filter($eventsAll, static function ($ev) {
    return ($ev['status'] ?? '') !== 'canceled';
}));

$coByEvent = [];
if (qb_table_exists('bazar_event_organizers')) {
    foreach ($eventsAll as $ev) {
        $eid = (int) $ev['id'];
        $coByEvent[$eid] = db()->fetchAll(
            'SELECT u.id, u.display_name, u.login_uid
             FROM bazar_event_organizers eo
             INNER JOIN app_users u ON u.id = eo.app_user_id
             WHERE eo.event_id = ?
             ORDER BY u.display_name',
            [$eid]
        );
    }
}

/** @var array<int, list<array{id:int,name:string}>> $eventsPerOrganizer */
$eventsPerOrganizer = [];
foreach ($organizerUsers as $ou) {
    $oid = (int) $ou['id'];
    $eventsPerOrganizer[$oid] = [];
}
foreach ($eventsAll as $ev) {
    $eid = (int) $ev['id'];
    $pid = (int) ($ev['organizer_app_user_id'] ?? 0);
    if ($pid > 0 && isset($eventsPerOrganizer[$pid])) {
        $eventsPerOrganizer[$pid][] = ['id' => $eid, 'name' => (string) $ev['name']];
    }
    if (!empty($coByEvent[$eid])) {
        foreach ($coByEvent[$eid] as $co) {
            $cid = (int) $co['id'];
            if (isset($eventsPerOrganizer[$cid])) {
                $eventsPerOrganizer[$cid][] = ['id' => $eid, 'name' => (string) $ev['name']];
            }
        }
    }
}

$organizersByCity = [];
if ($hasResidence) {
    foreach ($organizerUsers as $ou) {
        $c = trim((string) ($ou['residence_city'] ?? ''));
        $key = $c !== '' ? $c : '__none__';
        if (!isset($organizersByCity[$key])) {
            $organizersByCity[$key] = [];
        }
        $organizersByCity[$key][] = $ou;
    }
    uksort($organizersByCity, static function ($a, $b) {
        if ($a === '__none__') {
            return 1;
        }
        if ($b === '__none__') {
            return -1;
        }
        return strcasecmp($a, $b);
    });
}

qb_page_start('admin', 'Organizers', 'organizers.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Manage organizers</h1>
    <p class="page-subtitle">
      Company teams: create one <strong>organizer</strong> account per worker (Users → Create user → role organizer), then assign them to events as primary or co-organizers.
      <?php if ($hasResidence): ?>
        Accounts with the same <strong>living location</strong> appear together; leave location empty to list someone as an individual.
      <?php else: ?>
        Run <code>php install/migrate_2026_qbazaar.php</code> to enable grouping by living location.
      <?php endif; ?>
    </p>
  </div>
  <a href="users.php?role=organizer" class="btn btn-secondary btn-sm">User list (organizers)</a>
</div>

<?php if (!qb_table_exists('bazar_event_organizers')): ?>
<div class="alert alert-warning mb-3">
  Co-organizer assignments require the database table <code>bazar_event_organizers</code>.
  Run <code>php install/migrate_2026_qbazaar.php</code> once, then refresh this page.
</div>
<?php endif; ?>

<?php if ($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid grid-2 gap-2 mb-4">
  <div class="card">
    <h3 class="font-bold mb-2">Organizer accounts</h3>
    <p class="text-sm text-secondary mb-3">Everyone with the <strong>organizer</strong> role. Primary assignment is set per event (Events → Theme &amp; promo).</p>
    <?php if ($hasResidence && !empty($organizersByCity)): ?>
      <?php foreach ($organizersByCity as $cityKey => $rows): ?>
        <div class="mb-4">
          <h4 class="text-sm font-bold mb-2" style="color:var(--accent)">
            <?php if ($cityKey === '__none__'): ?>
              Individual (no location set)
            <?php else: ?>
              <?= htmlspecialchars($cityKey) ?> <span class="text-muted font-normal">(<?= count($rows) ?>)</span>
            <?php endif; ?>
          </h4>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Login</th>
                  <th>Living location</th>
                  <th>Assigned events</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $ou):
                    $oid = (int) $ou['id'];
                    $assigned = $eventsPerOrganizer[$oid] ?? [];
                    ?>
                <tr>
                  <td>
                    <div class="font-bold"><?= htmlspecialchars($ou['display_name']) ?></div>
                    <div class="text-xs text-muted"><?= (int) $ou['is_active'] ? 'Active' : 'Disabled' ?></div>
                  </td>
                  <td><code class="text-xs"><?= htmlspecialchars($ou['login_uid']) ?></code></td>
                  <td>
                    <form method="post" class="flex gap-1 align-center flex-wrap" style="max-width:16rem">
                      <input type="hidden" name="action" value="save_org_city"/>
                      <input type="hidden" name="user_id" value="<?= $oid ?>"/>
                      <input type="text" name="residence_city" class="form-control" style="min-width:7rem;flex:1" placeholder="City / region" value="<?= htmlspecialchars((string) ($ou['residence_city'] ?? '')) ?>"/>
                      <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                    </form>
                  </td>
                  <td class="text-sm">
                    <?php if (empty($assigned)): ?>
                      <span class="text-muted">—</span>
                    <?php else: ?>
                      <?php foreach ($assigned as $i => $as): ?>
                        <?php if ($i > 0): ?><span class="text-muted"> · </span><?php endif; ?>
                        <span><?= htmlspecialchars($as['name']) ?></span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Login</th>
            <th>Assigned events</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($organizerUsers as $ou):
              $oid = (int) $ou['id'];
              $assigned = $eventsPerOrganizer[$oid] ?? [];
              ?>
          <tr>
            <td>
              <div class="font-bold"><?= htmlspecialchars($ou['display_name']) ?></div>
              <div class="text-xs text-muted"><?= (int) $ou['is_active'] ? 'Active' : 'Disabled' ?></div>
            </td>
            <td><code class="text-xs"><?= htmlspecialchars($ou['login_uid']) ?></code></td>
            <td class="text-sm">
              <?php if (empty($assigned)): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <?php foreach ($assigned as $i => $as): ?>
                  <?php if ($i > 0): ?><span class="text-muted"> · </span><?php endif; ?>
                  <span><?= htmlspecialchars($as['name']) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if (qb_table_exists('bazar_event_organizers')): ?>
  <div class="card">
    <h3 class="font-bold mb-2">Add co-organizer</h3>
    <p class="text-xs text-secondary mb-3">Co-organizers can manage the same event in the organizer portal. Canceled events are not listed.</p>
    <?php if (empty($eventsForCo)): ?>
      <p class="text-sm text-muted">No active events available (all are canceled or there are no events).</p>
    <?php else: ?>
    <form method="post" class="grid gap-2">
      <input type="hidden" name="action" value="add_co"/>
      <div class="form-group">
        <label class="form-label">Event</label>
        <select name="event_id" class="form-control" required>
          <?php foreach ($eventsForCo as $ev): ?>
            <option value="<?= (int) $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?> (<?= htmlspecialchars($ev['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Co-organizer</label>
        <select name="co_user_id" class="form-control" required>
          <option value="">— Select —</option>
          <?php foreach ($organizerUsers as $ou): ?>
            <option value="<?= (int) $ou['id'] ?>"><?= htmlspecialchars($ou['display_name']) ?> (<?= htmlspecialchars($ou['login_uid']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Assign</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3 class="font-bold mb-2">Events and organizers</h3>
  <p class="text-sm text-secondary mb-3">Primary organizer is stored on the event; additional rows are co-organizers. Canceled events keep a read-only row — clear co-organizers automatically when canceling from the calendar.</p>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Event</th>
          <th>Status</th>
          <th>Primary</th>
          <th>Co-organizers</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($eventsAll)): ?>
        <tr><td colspan="4" class="text-center text-muted">No events yet.</td></tr>
        <?php else: ?>
          <?php foreach ($eventsAll as $ev):
              $eid = (int) $ev['id'];
              $cos = $coByEvent[$eid] ?? [];
              $isCanceled = (($ev['status'] ?? '') === 'canceled');
              ?>
          <tr>
            <td>
              <div class="font-bold"><?= htmlspecialchars($ev['name']) ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars($ev['slug']) ?></div>
            </td>
            <td><span class="badge <?= $isCanceled ? 'badge-gray' : 'badge-blue' ?>"><?= htmlspecialchars($ev['status']) ?></span></td>
            <td><?= htmlspecialchars($ev['primary_name'] ?: '—') ?></td>
            <td>
              <?php if (empty($cos)): ?>
                <span class="text-muted"><?= $isCanceled ? '— (canceled)' : '—' ?></span>
              <?php else: ?>
                <?php if ($isCanceled): ?>
                  <p class="text-xs text-warning mb-1">Canceled — remove any legacy rows:</p>
                <?php endif; ?>
                <ul class="text-sm" style="margin:0;padding-left:1.1rem">
                  <?php foreach ($cos as $co): ?>
                  <li style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">
                    <span><?= htmlspecialchars($co['display_name']) ?> <code class="text-xs"><?= htmlspecialchars($co['login_uid']) ?></code></span>
                    <?php if (qb_table_exists('bazar_event_organizers')): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Remove this co-organizer from the event?');">
                      <input type="hidden" name="action" value="remove_co"/>
                      <input type="hidden" name="event_id" value="<?= $eid ?>"/>
                      <input type="hidden" name="co_user_id" value="<?= (int) $co['id'] ?>"/>
                      <button type="submit" class="btn btn-ghost btn-sm" style="padding:0.15rem 0.4rem">Remove</button>
                    </form>
                    <?php endif; ?>
                  </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php qb_page_end(); ?>
