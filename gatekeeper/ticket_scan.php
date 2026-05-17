<?php
/**
 * Ticket scan for assigned gatekeepers only (scoped events).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireGatekeeper();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$eids = qb_event_staff_event_ids($uid);
$events = [];
if ($eids !== []) {
    $ph = implode(',', array_fill(0, count($eids), '?'));
    $events = db()->fetchAll(
        "SELECT id, name, city, event_start, status FROM bazar_events WHERE id IN ($ph) ORDER BY event_start DESC",
        $eids
    );
}

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if ($eventId <= 0 && !empty($events)) {
    $eventId = (int) $events[0]['id'];
}

$event = null;
if ($eventId > 0 && in_array($eventId, $eids, true)) {
    $event = db()->fetchOne('SELECT * FROM bazar_events WHERE id = ?', [$eventId]);
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

qb_page_start('gatekeeper', 'Ticket scan', 'ticket_scan.php', false);
?>
<div class="organizer-wrap qb-gk-scan-wrap" style="max-width:560px;margin:0 auto">
  <div class="page-header mb-3">
    <h1 class="page-title">Ticket scan</h1>
    <p class="page-subtitle text-secondary">Large keypad-friendly field — verify codes at the gate for your assigned bazar only.</p>
  </div>

  <?php if ($message !== ''): ?>
    <div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-warning mb-3"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (empty($events)): ?>
    <p class="text-secondary">No active gate assignment. Ask an organizer or admin to add you under Gate staff.</p>
  <?php else: ?>
    <form method="get" class="mb-3">
      <label class="form-label" for="event_id">Bazar</label>
      <select name="event_id" id="event_id" class="form-control" style="min-height:2.75rem;font-size:1rem" onchange="this.form.submit()">
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $eventId ? 'selected' : '' ?>>
            <?= htmlspecialchars($ev['name']) ?> — <?= htmlspecialchars($ev['city'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if ($event): ?>
      <div class="card mb-3">
        <p class="text-sm text-secondary mb-0"><strong>Rules:</strong> Standard — single entry. Premium, VIP &amp; Day pass follow printed rules.</p>
      </div>
      <form method="post" class="card" style="padding:1.25rem">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(qb_csrf_token()) ?>"/>
        <div class="form-group mb-3">
          <label class="form-label" for="ticket_code">Ticket code</label>
          <input type="text" name="ticket_code" id="ticket_code" inputmode="text" autocapitalize="characters" autocomplete="off"
            class="form-control font-mono qb-gk-ticket-input" style="min-height:3.25rem;font-size:1.15rem;letter-spacing:0.04em"
            placeholder="e.g. TKT…" value="<?= htmlspecialchars($ticketCodeInput) ?>"/>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="min-height:3rem;font-size:1.05rem">Check &amp; admit</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php qb_page_end(); ?>
