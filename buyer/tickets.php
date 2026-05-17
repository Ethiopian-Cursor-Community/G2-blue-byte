<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyer();

$user = currentUser();
$uid = (int)$user['id'];

// Get notifications
$notifications = getNotifications($uid);
// Mark all as read when opening page
markAllRead($uid);

qb_page_start('buyer', 'Tickets & Alerts', 'tickets.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main">
  <div class="page-header qb-dash-header mb-3">
    <div>
      <h1 class="page-title qb-dash-title">Tickets &amp; alerts</h1>
      <p class="page-subtitle qb-dash-subtitle">Notifications, tickets, and announcements.</p>
    </div>
    <div>
      <a href="#purchaseTickets" class="btn btn-primary btn-sm">Purchase tickets</a>
    </div>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="empty-state">
      <?= qb_icon('bell', 'qb-icon', 48) ?>
      <h3>No new notifications</h3>
      <p class="text-sm">You'll see tickets, flash sales, and announcements here.</p>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:0.45rem">
      <?php foreach ($notifications as $n): 
        $unreadClass = $n['is_read'] ? '' : 'unread';
      ?>
      <a href="<?= qb_esc_html(trim((string)($n['link'] ?? '')) !== '' ? (string)$n['link'] : '#') ?>" class="notif-item <?= htmlspecialchars($n['type']) ?> qb-ticket-notif-compact" style="text-decoration:none">
        <div class="notif-icon <?= htmlspecialchars($n['type']) ?>">
          <?= qb_icon($n['type'] === 'ticket' ? 'ticket' : ($n['type'] === 'flash_sale' ? 'flash' : ($n['type'] === 'purchase' ? 'receipt' : 'announce')), 'qb-icon', 20) ?>
        </div>
        <div class="notif-body">
          <div class="notif-title"><?= qb_esc_html($n['title'] ?? '') ?></div>
          <?php if($n['body']): ?>
            <div class="notif-text"><?= qb_esc_html($n['body']) ?></div>
          <?php endif; ?>
          <div class="notif-time"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="divider"></div>

  <?php
$themeSel = (function_exists('qb_has_column') && qb_has_column('bazar_events', 'theme_color'))
  ? ', e.theme_color'
  : '';
$coverSel = (function_exists('qb_has_column') && qb_has_column('bazar_events', 'cover_image'))
  ? ', e.cover_image'
  : ", '' AS cover_image";
$tkts = db()->fetchAll("
    SELECT t.*, e.name, e.city, e.event_start, e.event_end $themeSel $coverSel
    FROM tickets t
    JOIN bazar_events e ON t.event_id = e.id
    WHERE t.buyer_id = ? AND t.status = 'active'
    ORDER BY
      CASE
        WHEN e.status = 'live' THEN 0
        WHEN e.status = 'published' THEN 1
        WHEN (e.event_end IS NOT NULL AND e.event_end < NOW()) OR e.status IN ('ended','canceled') THEN 3
        ELSE 2
      END ASC,
      COALESCE(e.event_start, t.issued_at) ASC,
      t.issued_at DESC
  ", [$uid]);
  $tktsUsed = db()->fetchAll("
    SELECT t.*, e.name, e.city, e.event_start, e.event_end $themeSel $coverSel
    FROM tickets t
    JOIN bazar_events e ON t.event_id = e.id
    WHERE t.buyer_id = ? AND t.status = 'used'
    ORDER BY COALESCE(t.used_at, t.issued_at) DESC
    LIMIT 24
  ", [$uid]);
  ?>
  <div class="qb-tickets-section-head">
    <h2 class="font-bold mb-0 text-sm text-uppercase text-muted">My Unused Tickets</h2>
    <?php if (count($tkts) > 1): ?>
      <a href="ticket_print_all.php" class="btn btn-sm qb-btn-ticket-solid no-print">Print all</a>
    <?php endif; ?>
  </div>
  <?php

  if(empty($tkts)): ?>
    <p class="text-sm text-secondary text-center py-4">No active tickets.</p>
  <?php else: ?>
    <div class="grid gap-2 qb-ticket-square-grid qb-ticket-square-grid--compact">
      <?php foreach($tkts as $t): 
        $tier = qb_ticket_normalize_tier($t['ticket_tier'] ?? 'standard');
        $tierLabel = qb_ticket_tier_label($tier);
        $tierHint = qb_ticket_tier_rules_hint($tier);
        $face = isset($t['face_value_etb']) ? (float)$t['face_value_etb'] : 0.0;
        $priceLine = $face > 0.005 ? number_format($face, 2) . ' ETB' : 'Complimentary';
        $dispNo = trim((string)($t['display_no'] ?? ''));
        if ($dispNo === '') $dispNo = 'QB-' . str_pad((string)(int)$t['id'], 6, '0', STR_PAD_LEFT);
        $tierClass = match ($tier) {
            'vip' => 'badge-purple',
            'premium' => 'badge-gold',
            'day_pass' => 'badge-amber',
            default => 'badge-blue',
        };
        $accent = qb_theme_hex($t['theme_color'] ?? null);
        $evRow = ['event_start' => $t['event_start'] ?? null, 'event_end' => $t['event_end'] ?? null];
        $faceStatus = qb_ticket_buyer_status_label($t, $evRow);
        $statusClass = $faceStatus === 'Valid' ? 'qb-ticket-valid' : 'qb-ticket-used';
      ?>
      <div class="card qb-ticket-card qb-ticket-card--square qb-ticket-card--tiny" style="--qb-ticket-accent: <?= htmlspecialchars($accent) ?>">
        <div class="qb-ticket-square-media">
          <img src="<?= htmlspecialchars(qb_event_image_url($t)) ?>" alt="<?= htmlspecialchars((string) $t['name']) ?>" loading="lazy" decoding="async"/>
        </div>
        <div class="qb-ticket-square-body qb-ticket-square-body--tiny">
          <div class="qb-ticket-card-main">
            <div class="mb-1 qb-ticket-meta-row qb-ticket-square-meta">
              <span class="badge <?= htmlspecialchars($tierClass) ?>"><?= htmlspecialchars($tierLabel) ?></span>
              <span class="text-xs text-muted font-bold"><?= htmlspecialchars($priceLine) ?></span>
            </div>
            <h3 class="font-bold"><?= htmlspecialchars($t['name']) ?></h3>
            <p class="text-xs text-muted mb-1"><?= htmlspecialchars($t['city']) ?></p>
            <p class="text-xs text-secondary mb-1"><span class="font-mono font-bold"><?= htmlspecialchars($dispNo) ?></span></p>
            <div class="badge badge-gray qb-ticket-code-badge">
              <?= htmlspecialchars($t['ticket_code']) ?>
            </div>
          </div>
          <div class="qb-ticket-actions qb-ticket-square-actions">
            <div class="text-xs font-bold <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($faceStatus) ?></div>
            <a href="ticket_print.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm qb-btn-ticket-solid no-print">Print</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($tktsUsed)): ?>
  <div class="qb-tickets-section-head mt-4" id="purchaseTickets">
    <h2 class="font-bold mb-0 text-sm text-uppercase text-muted">Used / Expired Tickets</h2>
  </div>
  <p class="text-xs text-secondary mb-2">Standard tickets become used after one gate scan. Premium / VIP / Day pass stay active until the event rules say otherwise.</p>
  <div class="grid gap-2">
    <?php foreach ($tktsUsed as $t): 
      $tier = qb_ticket_normalize_tier($t['ticket_tier'] ?? 'standard');
      $tierLabel = qb_ticket_tier_label($tier);
      $tierClass = match ($tier) {
          'vip' => 'badge-purple',
          'premium' => 'badge-gold',
          'day_pass' => 'badge-amber',
          default => 'badge-blue',
      };
      $accent = qb_theme_hex($t['theme_color'] ?? null);
    ?>
    <div class="card qb-ticket-card qb-ticket-card--used" style="--qb-ticket-accent: <?= htmlspecialchars($accent) ?>">
      <div class="qb-ticket-card-inner">
        <div class="qb-ticket-card-main">
          <div class="mb-1 qb-ticket-meta-row">
            <span class="badge <?= htmlspecialchars($tierClass) ?>"><?= htmlspecialchars($tierLabel) ?></span>
            <span class="badge badge-gray">Used</span>
          </div>
          <h3 class="font-bold text-secondary"><?= htmlspecialchars($t['name']) ?></h3>
          <p class="text-xs text-muted mb-0"><?= htmlspecialchars($t['city']) ?></p>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
  $eventThemeSel = (function_exists('qb_has_column') && qb_has_column('bazar_events', 'theme_color'))
    ? ', e.theme_color'
    : ", '' AS theme_color";
  $eventCoverSel = (function_exists('qb_has_column') && qb_has_column('bazar_events', 'cover_image'))
    ? ', e.cover_image'
    : ", '' AS cover_image";
  $openEvents = db()->fetchAll("
    SELECT e.id, e.name, e.city, e.venue, e.event_start $eventThemeSel $eventCoverSel
    FROM bazar_events e
    WHERE e.status IN ('published','live')
    ORDER BY e.event_start ASC, e.id DESC
    LIMIT 12
  ");
  $eventTxStats = [];
  if (!empty($openEvents)) {
      $eventIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $openEvents), static fn($v) => $v > 0));
      if (!empty($eventIds)) {
          $ph = implode(',', array_fill(0, count($eventIds), '?'));
          $rows = db()->fetchAll(
              "SELECT event_id,
                      SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_n,
                      SUM(CASE WHEN DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS yesterday_n
               FROM transactions
               WHERE payment_status = 'completed'
                 AND event_id IN ($ph)
               GROUP BY event_id",
              $eventIds
          );
          foreach ($rows as $r) {
              $eventTxStats[(int) ($r['event_id'] ?? 0)] = [
                  'today' => (int) ($r['today_n'] ?? 0),
                  'yesterday' => (int) ($r['yesterday_n'] ?? 0),
              ];
          }
      }
  }
  ?>
  <div class="qb-tickets-section-head mt-4">
    <h2 class="font-bold mb-0 text-sm text-uppercase text-muted">Purchase ticket for bazar</h2>
  </div>
  <?php if (empty($openEvents)): ?>
    <p class="text-xs text-secondary">No bazars are open for ticket purchase right now.</p>
  <?php else: ?>
    <div class="grid gap-2 qb-ticket-square-grid">
      <?php foreach ($openEvents as $oe):
        $purchaseAccent = qb_theme_hex($oe['theme_color'] ?? null);
        $oeId = (int) ($oe['id'] ?? 0);
        $txToday = (int) (($eventTxStats[$oeId]['today'] ?? 0));
        $txYesterday = (int) (($eventTxStats[$oeId]['yesterday'] ?? 0));
        $txDelta = $txToday - $txYesterday;
        $txTrendClass = $txDelta > 0 ? 'qb-ticket-tx-trend--up' : ($txDelta < 0 ? 'qb-ticket-tx-trend--down' : 'qb-ticket-tx-trend--flat');
      ?>
      <div class="card qb-ticket-buy-card qb-ticket-buy-card--tiny" style="--qb-ticket-accent: <?= htmlspecialchars($purchaseAccent) ?>">
        <div class="qb-ticket-square-media">
          <img src="<?= htmlspecialchars(qb_event_image_url($oe)) ?>" alt="<?= htmlspecialchars((string) $oe['name']) ?>" loading="lazy" decoding="async"/>
        </div>
        <div class="qb-ticket-square-body">
          <div class="font-bold"><?= htmlspecialchars((string) $oe['name']) ?></div>
          <div class="text-xs text-muted"><?= htmlspecialchars((string) $oe['city']) ?> · <?= htmlspecialchars((string) ($oe['venue'] ?? '')) ?></div>
          <?php if (!empty($oe['event_start'])): ?>
          <div class="text-xs text-secondary mt-1"><?= date('D, M j · g:i A', strtotime((string) $oe['event_start'])) ?></div>
          <?php endif; ?>
          <div class="qb-ticket-tx-band">
            <div class="qb-ticket-tx-pill">
              <span class="qb-ticket-tx-label">Today</span>
              <strong class="qb-ticket-tx-number"><?= $txToday ?></strong>
            </div>
            <div class="qb-ticket-tx-pill">
              <span class="qb-ticket-tx-label">Yesterday</span>
              <strong class="qb-ticket-tx-number"><?= $txYesterday ?></strong>
            </div>
            <div class="qb-ticket-tx-trend <?= htmlspecialchars($txTrendClass) ?>">
              <?= $txDelta >= 0 ? '+' : '' ?><?= $txDelta ?>
            </div>
          </div>
          <form method="post" action="purchase_ticket.php" class="qb-ticket-square-buy-form js-pay-submit">
            <input type="hidden" name="event_id" value="<?= (int) $oe['id'] ?>"/>
            <input type="hidden" name="redirect" value="tickets.php"/>
            <?php
              $evDetail = db()->fetchOne('SELECT standard_ticket_price_etb, premium_ticket_price_etb, primary_rules FROM bazar_events WHERE id = ? LIMIT 1', [$oeId]) ?: [];
              $stdPrice = (float) ($evDetail['standard_ticket_price_etb'] ?? 0);
              $prmPrice = (float) ($evDetail['premium_ticket_price_etb'] ?? 0);
              $rules = trim((string) ($evDetail['primary_rules'] ?? ''));
            ?>
            <input type="hidden" name="payment_method" value="chapa"/>
            <label class="text-xs" style="display:block;margin:4px 0"><input type="radio" name="ticket_type" value="standard" checked> Standard · <?= number_format($stdPrice, 2) ?> ETB</label>
            <label class="text-xs" style="display:block;margin:4px 0"><input type="radio" name="ticket_type" value="premium"> Premium · <?= number_format($prmPrice, 2) ?> ETB</label>
            <label class="text-xs text-muted" style="display:block;margin:4px 0 8px"><input type="checkbox" name="agree_primary_rules" value="1" required> I accept rules<?= $rules !== '' ? ': ' . htmlspecialchars($rules) : '' ?></label>
            <button type="submit" class="btn btn-primary btn-sm btn-full">Purchase Ticket</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php
  // Duplicate purchase block removed; kept a single section above.
  ?>

</div>
</div>

<script>
document.querySelectorAll('form.js-pay-submit').forEach(function(form){
  form.addEventListener('submit', function(){
    var btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Redirecting...';
  });
});
</script>
<?php qb_page_end(); ?>
