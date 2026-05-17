<?php
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
        "SELECT id, name, city, status FROM bazar_events WHERE id IN ($ph) AND status IN ('published','live') ORDER BY event_start DESC",
        $eids
    );
}

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if ($eventId <= 0 && $events !== []) {
    $eventId = (int) ($events[0]['id'] ?? 0);
}

$event = null;
if ($eventId > 0 && in_array($eventId, $eids, true)) {
    $event = db()->fetchOne('SELECT id, name, status FROM bazar_events WHERE id = ? LIMIT 1', [$eventId]);
}

$message = '';
$error = '';
$rawInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session. Please retry.';
    } else {
        $rawInput = trim((string) ($_POST['seller_entry_qr'] ?? ''));
        if ($rawInput === '') {
            $error = 'Scan or paste seller entry QR payload.';
        } else {
            $parsed = qb_parse_seller_entry_qr($rawInput);
            if (!$parsed['ok']) {
                $error = (string) ($parsed['error'] ?? 'Invalid seller QR');
            } elseif ((int) ($parsed['event_id'] ?? 0) !== (int) $event['id']) {
                $error = 'This seller QR is for a different event.';
            } else {
                $sellerId = (int) ($parsed['seller_id'] ?? 0);
                $okEligible = db()->fetchOne(
                    "SELECT 1
                     FROM bazar_events e
                     WHERE e.id = ?
                       AND e.status IN ('published','live')
                       AND (
                           EXISTS (SELECT 1 FROM stalls st WHERE st.event_id = e.id AND st.seller_id = ?)
                           OR EXISTS (
                               SELECT 1 FROM event_participants ep
                               INNER JOIN sellers s ON s.app_user_id = ep.app_user_id
                               WHERE ep.event_id = e.id AND ep.role_in_event = 'seller' AND ep.status = 'approved' AND s.id = ?
                           )
                       )
                     LIMIT 1",
                    [(int) $event['id'], $sellerId, $sellerId]
                );
                if (!$okEligible) {
                    $error = 'Seller is not approved/assigned for this event.';
                } elseif (!qb_seller_gate_unlock($sellerId, (int) $event['id'], $uid)) {
                    $error = 'Could not unlock seller gate entry.';
                } else {
                    $seller = db()->fetchOne('SELECT market_name FROM sellers WHERE id = ? LIMIT 1', [$sellerId]) ?: [];
                    $message = 'Seller gate unlocked: ' . (string) ($seller['market_name'] ?? ('Seller #' . $sellerId));
                }
            }
        }
    }
}

qb_page_start('gatekeeper', 'Seller gate scan', 'seller_scan.php', false);
?>
<div class="organizer-wrap qb-gk-scan-wrap" style="max-width:620px;margin:0 auto">
  <div class="page-header mb-3">
    <h1 class="page-title">Seller gate scan</h1>
    <p class="page-subtitle">Scan seller entry QR once to unlock stall QR visibility for this event.</p>
  </div>
  <?php if ($message !== ''): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-warning mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($events === []): ?>
    <div class="alert alert-info">No live/published event assignment found for your gate account.</div>
  <?php else: ?>
    <form method="get" class="mb-3">
      <label class="form-label" for="event_id">Event</label>
      <select name="event_id" id="event_id" class="form-control" onchange="this.form.submit()">
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $eventId ? 'selected' : '' ?>>
            <?= htmlspecialchars((string) $ev['name']) ?> — <?= htmlspecialchars((string) ($ev['city'] ?? '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($event): ?>
      <form method="post" class="card" style="padding:1rem">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(qb_csrf_token()) ?>">
        <div class="form-group mb-2">
          <label class="form-label" for="seller_entry_qr">Seller entry QR payload</label>
          <input type="text" id="seller_entry_qr" name="seller_entry_qr" class="form-control font-mono" value="<?= htmlspecialchars($rawInput) ?>" placeholder="SE|sellerId|sellerUid|eventId|timestamp|signature" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Unlock seller for event entry</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php qb_page_end(); ?>
