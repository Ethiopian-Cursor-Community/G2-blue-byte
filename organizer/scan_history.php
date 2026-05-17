<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireOrganizer();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$w = qb_organizer_bazar_events_access_sql();
$bind = qb_organizer_event_access_bind($uid);

// Get allowed events for the filter
$events = db()->fetchAll(
    "SELECT id, name FROM bazar_events WHERE $w ORDER BY event_start DESC",
    $bind
);

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$where = "t.gate_scan_count > 0 AND ($w)";
$params = $bind;

if ($eventId > 0) {
    $where .= " AND t.event_id = ?";
    $params[] = $eventId;
}

// Fetch scan history
$scans = db()->fetchAll(
    "SELECT t.*, e.name AS event_name, u.display_name AS buyer_name
     FROM tickets t
     JOIN bazar_events e ON t.event_id = e.id
     LEFT JOIN app_users u ON t.buyer_id = u.id
     WHERE $where
     ORDER BY t.used_at DESC, t.id DESC
     LIMIT 100",
    $params
);

qb_page_start('organizer', 'Scan History', 'scan_history.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Scan History</h1>
    <p class="page-subtitle text-secondary">Recent ticket admissions across your bazars.</p>
  </div>
</div>

<div class="card mb-3">
  <form method="get" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group mb-0" style="min-width:200px">
      <label class="form-label">Filter by Event</label>
      <select name="event_id" class="form-control">
        <option value="">All Events</option>
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($eventId > 0): ?>
      <a href="scan_history.php" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($scans)): ?>
  <div class="card qb-empty-state" style="text-align:center;padding:3rem 1rem">
    <div class="mb-2" style="font-size:2rem;opacity:0.5"><?= qb_icon('ticket', '', 48) ?></div>
    <h3 class="font-bold">No scans found</h3>
    <p class="text-secondary">Tickets scanned at the gate will appear here.</p>
    <a href="ticket_scan.php" class="btn btn-secondary btn-sm mt-2">Start Scanning</a>
  </div>
<?php else: ?>
  <div class="card p-0 overflow-hidden">
    <table class="qb-table">
      <thead>
        <tr>
          <th>Time</th>
          <th>Buyer</th>
          <th>Ticket Code</th>
          <th>Event</th>
          <th>Tier</th>
          <th>Scans</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($scans as $s): ?>
          <tr>
            <td class="text-xs">
              <?= $s['used_at'] ? date('M j, H:i:s', strtotime((string) $s['used_at'])) : '<span class="text-muted">N/A</span>' ?>
            </td>
            <td>
              <div class="font-bold"><?= htmlspecialchars((string) ($s['buyer_name'] ?? 'Guest')) ?></div>
            </td>
            <td class="font-mono text-xs"><?= htmlspecialchars((string) $s['ticket_code']) ?></td>
            <td class="text-xs"><?= htmlspecialchars((string) $s['event_name']) ?></td>
            <td>
              <span class="badge badge-outline text-xs"><?= ucfirst((string) $s['ticket_tier']) ?></span>
            </td>
            <td class="font-bold"><?= (int) $s['gate_scan_count'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-xs text-muted mt-2">Showing up to 100 most recent admissions.</p>
<?php endif; ?>

<?php qb_page_end(); ?>
