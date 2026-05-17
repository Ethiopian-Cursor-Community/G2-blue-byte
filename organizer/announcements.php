<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireOrganizer();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
if (function_exists('qb_organizer_is_co_only') && qb_organizer_is_co_only($uid)) {
    header('Location: dashboard.php', true, 302);
    exit;
}

$uid = (int) $_SESSION['app_user_id'];
$ew = qb_organizer_event_alias_access_sql('e');
$eb = qb_organizer_event_access_bind($uid);
$events = db()->fetchAll(
    "SELECT e.id, e.name, e.status FROM bazar_events e WHERE $ew ORDER BY e.event_start DESC",
    $eb
);

$selectedEvent = qb_organizer_resolve_event_id(
    isset($_GET['event']) ? (int) $_GET['event'] : null,
    $events
);

$selectedEventRow = null;
foreach ($events as $evr) {
    if ((int) ($evr['id'] ?? 0) === (int) $selectedEvent) {
        $selectedEventRow = $evr;
        break;
    }
}
$canManageAnnounce = $selectedEventRow && qb_organizer_may_manage_event($selectedEventRow);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token. Refresh and try again.';
    } else {
        $postEv = (int) ($_POST['event_id'] ?? 0);
        $title = qb_sanitize_plain_text((string) ($_POST['title'] ?? ''), 200);
        $body = qb_sanitize_plain_text((string) ($_POST['body'] ?? ''), 2000);
        $link = qb_sanitize_plain_text((string) ($_POST['link'] ?? ''), 255);

        if (!qb_organizer_event_id_allowed($postEv, $events)) {
            $error = 'Invalid event or you do not have access.';
        } else {
            $evSt = db()->fetchOne(
                "SELECT e.status FROM bazar_events e WHERE e.id = ? AND $ew",
                array_merge([$postEv], $eb)
            );
            if (!$evSt) {
                $error = 'Invalid event or you do not have access.';
            } elseif (($evSt['status'] ?? '') === 'canceled') {
                $error = 'This bazar was canceled by the admin. New announcements cannot be sent.';
            }
        }
        if ($error === '') {
            if ($title === '' || mb_strlen($title) > 200) {
                $error = 'Title is required (max 200 characters).';
            } elseif ($body === '' || mb_strlen($body) > 2000) {
                $error = 'Message is required (max 2000 characters).';
            } else {
                $selectedEvent = $postEv;
                qb_broadcast_event_announcement($uid, $postEv, $title, $body, $link);
                header('Location: ' . APP_URL . '/organizer/announcements.php?event=' . $postEv . '&notice=sent', true, 302);
                exit;
            }
        }
    }
}

if (($_GET['notice'] ?? '') === 'sent') {
    $success = 'Notification queued for all buyers registered in this bazar (see Tickets / notifications on their phones).';
}

$history = [];
if ($selectedEvent > 0 && qb_table_exists('event_announcements')) {
    $history = db()->fetchAll(
        'SELECT a.title, a.body, a.created_at, u.display_name AS organizer_name
         FROM event_announcements a
         LEFT JOIN app_users u ON u.id = a.organizer_id
         WHERE a.event_id = ?
         ORDER BY a.created_at DESC
         LIMIT 25',
        [$selectedEvent]
    );
}

$buyerCount = 0;
if ($selectedEvent > 0) {
    $bc = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM event_participants WHERE event_id = ? AND role_in_event = 'buyer'",
        [$selectedEvent]
    );
    $buyerCount = (int) ($bc['c'] ?? 0);
}

qb_page_start('organizer', 'Announcements', 'announcements.php', false);
$csrf = qb_csrf_token();
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= qb_icon('announce', 'qb-icon', 22) ?> Announcements</h1>
    <p class="page-subtitle">Send in-app notifications to every buyer registered for a bazar (same list as event participants with role buyer).</p>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success mb-3" role="status"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (empty($events)): ?>
  <div class="empty-state card" style="padding:2rem">
    <?= qb_icon('calendar', 'qb-icon', 48) ?>
    <h3 class="mt-2">No events</h3>
    <p class="text-muted">Create a bazar first, then you can broadcast to buyers who join it.</p>
    <a href="event.php" class="btn btn-primary mt-2"><?= qb_icon('plus') ?> Create event</a>
  </div>
<?php else: ?>

<form method="get" class="card mb-2" style="padding:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
  <div class="form-group mb-0">
    <label class="form-label" for="ev">Bazar</label>
    <select name="event" id="ev" class="form-control" onchange="this.form.submit()">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= (int) $selectedEvent === (int) $ev['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if ($selectedEvent > 0): ?>
<p class="text-sm text-muted mb-2">
  <strong><?= number_format($buyerCount) ?></strong> registered buyer<?= $buyerCount === 1 ? '' : 's' ?> will receive this (if they use the buyer app).
</p>
<?php endif; ?>

<?php if ($selectedEvent > 0 && !$canManageAnnounce): ?>
<div class="alert alert-warning mb-3" role="status">
  This bazar was <strong>canceled</strong> by the admin. You can read broadcast history below; sending new notices is disabled.
</div>
<?php endif; ?>

<div class="grid grid-2 gap-2" style="align-items:start">
  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1.1rem">New announcement</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="event_id" value="<?= (int) $selectedEvent ?>">
      <div class="form-group mb-2">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" maxlength="200" required placeholder="e.g. Gates open at 9:00">
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Message</label>
        <textarea name="body" class="form-control" rows="4" maxlength="2000" required placeholder="Short message shown in buyer notifications."></textarea>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Optional link</label>
        <input type="text" name="link" class="form-control" maxlength="255" placeholder="e.g. buyer/map.php (relative path)">
        <p class="text-xs text-muted mt-1">Opens when the buyer taps the notification (optional).</p>
      </div>
      <button type="submit" class="btn btn-primary" <?= ($selectedEvent <= 0 || !$canManageAnnounce) ? 'disabled' : '' ?>><?= qb_icon('announce', 'qb-icon', 18) ?> Send to buyers</button>
    </form>
  </div>

  <div class="card">
    <h2 class="font-bold mb-2" style="font-size:1.1rem">Recent broadcasts</h2>
    <?php if (!qb_table_exists('event_announcements')): ?>
      <p class="text-sm text-muted mb-0">Run <code>php install/migrate_event_announcements.php</code> to enable history logging. Notifications still send without it.</p>
    <?php elseif (empty($history)): ?>
      <p class="text-sm text-muted mb-0">No announcements logged for this event yet.</p>
    <?php else: ?>
    <ul class="qb-announce-history">
      <?php foreach ($history as $h): ?>
      <li class="mb-3 pb-3" style="border-bottom:1px solid var(--border)">
        <div class="font-bold"><?= qb_esc_html($h['title'] ?? '') ?></div>
        <div class="text-sm text-muted mt-1"><?= htmlspecialchars(date('M j, Y H:i', strtotime((string) $h['created_at']))) ?>
          <?php if (!empty($h['organizer_name'])): ?> · <?= qb_esc_html($h['organizer_name'] ?? '') ?><?php endif; ?>
        </div>
        <?php if (!empty($h['body'])): ?>
          <p class="text-sm mt-2 mb-0"><?= nl2br(qb_esc_html($h['body'] ?? '')) ?></p>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php qb_page_end(); ?>
