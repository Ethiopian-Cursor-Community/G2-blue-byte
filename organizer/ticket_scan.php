<?php
/**
 * Gate check: scan buyer ticket code for an event (organizer).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireOrganizer(); // allows organizer + co_organizer; blocks all others
$role = (string) ($_SESSION['app_role'] ?? '');
$uid  = (int) ($_SESSION['app_user_id'] ?? 0);

// Gatekeepers use their own dedicated scan page
if ($role === 'gatekeeper') {
    header('Location: ' . APP_URL . '/gatekeeper/ticket_scan.php', true, 302);
    exit;
}

$w = qb_organizer_bazar_events_access_sql();
$bind = qb_organizer_event_access_bind($uid);

$events = db()->fetchAll(
    "SELECT id, name, city, event_start, status FROM bazar_events WHERE $w ORDER BY event_start DESC",
    $bind
);

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if ($eventId <= 0 && !empty($events)) {
    $eventId = (int) $events[0]['id'];
}

$event = null;
if ($eventId > 0) {
    $event = db()->fetchOne(
        "SELECT * FROM bazar_events WHERE id = ? AND $w",
        array_merge([$eventId], $bind)
    );
}

$message = '';
$error = '';
$ticketCodeInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session. Please try again.';
    } else {
        $ticketCodeInput = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['ticket_code'] ?? '')));
        if ($ticketCodeInput === '') {
            $error = 'Enter the ticket code from the buyer’s QR or printout.';
        } else {
            $ticket = db()->fetchOne(
                'SELECT * FROM tickets WHERE ticket_code = ? AND event_id = ?',
                [$ticketCodeInput, (int) $event['id']]
            );
            if (!$ticket) {
                $error = 'No ticket matches that code for this bazar.';
            } else {
                $elig = qb_ticket_gate_eligibility($ticket, $event);
                if (!$elig['ok']) {
                    $error = $elig['reason'];
                } else {
                    $r = qb_ticket_record_gate_scan($ticket, $event);
                    if ($r['ok']) {
                        $message = $r['message'];
                    } else {
                        $error = $r['message'];
                    }
                }
            }
        }
    }
}

qb_page_start('organizer', 'Ticket scan', 'ticket_scan.php', false);
?>
<div class="organizer-wrap" style="max-width:560px">
  <div class="page-header mb-3">
    <h1 class="page-title">Ticket scan</h1>
    <p class="page-subtitle text-secondary">Verify admission at the gate. Standard = one scan; Premium, VIP &amp; Day pass follow the rules shown below.</p>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-warning mb-3"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (empty($events)): ?>
    <p class="text-secondary">You don’t have any bazars yet.</p>
  <?php else: ?>
    <form method="get" class="mb-3">
      <label class="form-label" for="event_id">Bazar</label>
      <select name="event_id" id="event_id" class="form-control" onchange="this.form.submit()">
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $eventId ? 'selected' : '' ?>>
            <?= htmlspecialchars($ev['name']) ?> — <?= htmlspecialchars($ev['city'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if ($event): ?>
      <div class="card mb-3">
        <p class="text-sm text-secondary mb-2"><strong>Rules:</strong> Standard — single entry (then <em>used</em>). Premium &amp; VIP — valid for the full event (re-scan OK). Day pass — unlimited scans on the event calendar day only.</p>
      </div>
      <form method="post" class="card" style="padding:1.25rem">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(qb_csrf_token()) ?>"/>
        <div class="form-group mb-3">
          <label class="form-label" for="ticket_code">Ticket code</label>
          <input type="text" name="ticket_code" id="ticket_code" class="form-control font-mono" autocomplete="off" placeholder="e.g. TKT…" value="<?= htmlspecialchars($ticketCodeInput) ?>"/>
        </div>
        <button type="submit" class="btn btn-primary">Check &amp; admit</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php qb_page_end(); ?>
