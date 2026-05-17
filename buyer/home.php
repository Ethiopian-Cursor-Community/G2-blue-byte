<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyer();
qb_apply_event_ticket_pricing_schema();

qb_apply_seller_downgrade_schema();
$user = currentUser();
$uid = (int) $user['id'];

if (isset($_GET['ack_downgrade']) && (string) $_GET['ack_downgrade'] === '1') {
    qb_ack_seller_downgrade_notice($uid);
    header('Location: ' . APP_URL . '/buyer/home.php');
    exit;
}

$sellerDowngradeNotice = !empty($user['seller_downgrade_notice_pending']);

$ticketFlash = '';
$telebirrFlash = qb_flash_pull('telebirr_notice');
$ticketAlreadyExists = false;
if (isset($_GET['ticket_ok'])) {
    $ticketFlash = 'ok';
    $ticketAlreadyExists = isset($_GET['ticket_exists']) && (string) $_GET['ticket_exists'] === '1';
} elseif (isset($_GET['ticket_err'])) {
    $ticketFlash = 'err:' . (string) $_GET['ticket_err'];
}
$eventMode = qb_event_mode_get($uid);
$eventModeName = '';
if (!empty($eventMode['event_id'])) {
    $evm = db()->fetchOne('SELECT name FROM bazar_events WHERE id = ?', [(int) $eventMode['event_id']]);
    $eventModeName = (string) ($evm['name'] ?? '');
}

$events = getActiveEvents();
$endedEventIds = [];
foreach ($events as $ev) {
    if (strtolower((string) ($ev['status'] ?? '')) === 'ended') {
        $endedEventIds[] = (int) ($ev['id'] ?? 0);
    }
}
$endedEventStats = qb_event_overlay_summary_bulk($endedEventIds);
$eventAvailability = [];
if (qb_table_exists('stalls') && qb_table_exists('products')) {
    foreach ($events as $ev) {
        $evId = (int) ($ev['id'] ?? 0);
        if ($evId <= 0) {
            continue;
        }
        try {
            $ap = qb_sql_product_approved();
            $base = db()->fetchOne(
                "SELECT COUNT(*) AS n,
                        SUM(CASE WHEN COALESCE(p.discount_pct,0) > 0 THEN 1 ELSE 0 END) AS regular_n
                 FROM stalls st
                 JOIN products p ON p.seller_id = st.seller_id
                 WHERE st.event_id = ?
                   AND p.is_available = 1
                   AND p.stock > 0
                   AND ($ap)",
                [$evId]
            );
            $flashAll = 0;
            $flashEvent = 0;
            if (qb_table_exists('flash_sales')) {
                $fa = db()->fetchOne(
                    "SELECT COUNT(DISTINCT fs.product_id, fs.seller_id) AS n
                     FROM stalls st
                     JOIN flash_sales fs ON fs.seller_id = st.seller_id
                     JOIN products p ON p.id = fs.product_id AND p.seller_id = fs.seller_id
                     WHERE st.event_id = ?
                       AND fs.is_active = 1
                       AND NOW() >= fs.starts_at AND NOW() <= fs.ends_at
                       AND fs.event_id IS NULL
                       AND p.is_available = 1
                       AND p.stock > 0
                       AND ($ap)",
                    [$evId]
                );
                $fe = db()->fetchOne(
                    "SELECT COUNT(DISTINCT fs.product_id, fs.seller_id) AS n
                     FROM stalls st
                     JOIN flash_sales fs ON fs.seller_id = st.seller_id
                     JOIN products p ON p.id = fs.product_id AND p.seller_id = fs.seller_id
                     WHERE st.event_id = ?
                       AND fs.is_active = 1
                       AND NOW() >= fs.starts_at AND NOW() <= fs.ends_at
                       AND fs.event_id = ?
                       AND p.is_available = 1
                       AND p.stock > 0
                       AND ($ap)",
                    [$evId, $evId]
                );
                $flashAll = (int) ($fa['n'] ?? 0);
                $flashEvent = (int) ($fe['n'] ?? 0);
            }
            $eventAvailability[$evId] = [
                'products' => (int) ($base['n'] ?? 0),
                'regular' => (int) ($base['regular_n'] ?? 0),
                'flash_all' => $flashAll,
                'flash_event' => $flashEvent,
            ];
        } catch (Throwable $e) {
            $eventAvailability[$evId] = ['products' => 0, 'regular' => 0, 'flash_all' => 0, 'flash_event' => 0];
        }
    }
}
$homePromos = qb_fetch_homepage_spotlight_slides();
$homeDash = qb_buyer_home_dashboard($uid);
$explorerBadge = function_exists('qb_buyer_bazar_explorer') ? qb_buyer_bazar_explorer($uid) : ['count' => 0, 'label' => ''];
$stallScanLb = function_exists('qb_leaderboard_stall_scans_last_hour') ? qb_leaderboard_stall_scans_last_hour(3) : [];
$mysteryDeals = [];
try {
    $apMystery = qb_sql_product_approved();
    $mysteryDeals = db()->fetchAll(
        "SELECT p.id AS product_id, p.name AS product_name, p.price, p.discount_pct, p.unit,
                s.uid AS seller_uid, s.market_name,
                e.id AS event_id, e.name AS event_name
         FROM stalls st
         INNER JOIN products p ON p.seller_id = st.seller_id
         INNER JOIN sellers s ON s.id = st.seller_id
         INNER JOIN bazar_events e ON e.id = st.event_id
         WHERE e.status IN ('published','live')
           AND p.is_available = 1
           AND p.stock > 0
           AND ($apMystery)
         ORDER BY COALESCE(p.discount_pct, 0) DESC, p.id DESC
         LIMIT 24"
    );
} catch (Throwable $e) {
    $mysteryDeals = [];
}

$first = trim((string) ($user['display_name'] ?? 'there'));
$parts = preg_split('/\s+/', $first, 2);
$greetName = $parts[0] !== '' ? $parts[0] : 'there';
$buyerAvatarUrl = qb_avatar_url($user);

qb_page_start('buyer', 'Home', 'home.php', false);
?>

<div class="buyer-dashboard qb-power-beauty">
<div class="buyer-main qb-page-container">
  <div class="qb-buyer-home">

  <?php if (!empty($sellerDowngradeNotice)): ?>
  <div class="alert alert-warning qb-buyer-home__flash qb-buyer-home__enter-item mb-3" role="alert">
    <?= qb_icon('alert', 'qb-icon', 18) ?>
    <span>
      Your seller account was switched to <strong>buyer</strong> because no products were added within <?= (int) QB_SELLER_ITEM_GRACE_DAYS ?> days of registration.
      You can still shop, get tickets, and scan QR codes. To sell again, contact support or request a seller role from your profile if available.
    </span>
    <a href="home.php?ack_downgrade=1" class="btn btn-secondary btn-sm qb-buyer-home__flash-action">Got it</a>
  </div>
  <?php endif; ?>

  <?php if ($telebirrFlash !== ''): ?>
  <div class="alert alert-info qb-buyer-home__flash qb-buyer-home__enter-item mb-3" role="status">
    <?= qb_icon('info', 'qb-icon', 16) ?>
    <span><?= htmlspecialchars($telebirrFlash) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($ticketFlash === 'ok'): ?>
  <div class="alert alert-success qb-buyer-home__flash qb-buyer-home__enter-item mb-3" role="status">
    <?= qb_icon('ticket', 'qb-icon', 18) ?>
    <?php if ($ticketAlreadyExists): ?>
    <span>Payment verified. You already had an active ticket for this event — open <a href="tickets.php" class="qb-buyer-home__flash-link">Tickets</a> to view/print it.</span>
    <?php else: ?>
    <span>Ticket confirmed — open <a href="tickets.php" class="qb-buyer-home__flash-link">Tickets</a> to print.</span>
    <?php endif; ?>
  </div>
  <?php elseif ($ticketFlash !== '' && strpos($ticketFlash, 'err:') === 0): ?>
  <div class="alert alert-warning qb-buyer-home__flash qb-buyer-home__enter-item mb-3" role="alert"><?= htmlspecialchars(substr($ticketFlash, 4)) ?></div>
  <?php endif; ?>
  <?php if (!empty($eventMode['event_id'])): ?>
  <div class="alert alert-success qb-buyer-home__flash qb-buyer-home__enter-item mb-3" role="status">
    Event mode active<?= $eventModeName !== '' ? ': ' . htmlspecialchars($eventModeName) : '' ?>. Discover and map can focus this event only.
  </div>
  <?php endif; ?>

  <header class="page-header qb-dash-header qb-buyer-home__header qb-buyer-home__enter-item">
    <div class="qb-buyer-home__header-text">
      <p class="qb-buyer-home__eyebrow">Buyer home
        <?php if (!empty($explorerBadge['label'])): ?>
          <span class="qb-explorer-badge" title="Distinct bazars you’ve joined or bought from"><?= htmlspecialchars($explorerBadge['label']) ?></span>
        <?php endif; ?>
      </p>
      <h1 class="page-title qb-dash-title">Hello, <?= htmlspecialchars($greetName) ?></h1>
      <p class="page-subtitle qb-dash-subtitle">Discover live bazars, get tickets, and scan seller QR codes.</p>
    </div>
    <div class="qb-dash-actions qb-buyer-home__header-actions">
      <a href="discover.php" class="btn btn-secondary btn-sm"><?= qb_icon('star', 'qb-icon', 16) ?> Discover</a>
      <a href="scan.php" class="btn btn-primary"><?= qb_icon('scan', 'qb-icon', 16) ?> Scan QR</a>
    </div>
  </header>

  <section class="card qb-buyer-home__competition qb-buyer-home__enter-item" aria-label="Competition mode">
    <div class="qb-buyer-home__competition-head">
      <div class="font-bold"><?= qb_icon('star', 'qb-icon', 16) ?> Competition Mode · Dire Dawa (Thu/Fri/Sat)</div>
      <span class="badge qb-event-status qb-event-status--live">Showcase</span>
    </div>
    <p class="text-sm text-muted mb-2">Fast demo route: Discover offers, check promo videos, view event cards, then scan seller QR for purchase flow.</p>
    <div class="qb-buyer-home__competition-actions">
      <a href="discover.php" class="btn btn-primary btn-sm"><?= qb_icon('search', 'qb-icon', 15) ?> Discover</a>
      <a href="map.php" class="btn btn-secondary btn-sm"><?= qb_icon('map', 'qb-icon', 15) ?> Event map</a>
      <a href="scan.php" class="btn btn-ghost btn-sm"><?= qb_icon('scan', 'qb-icon', 15) ?> Scan seller QR</a>
      <a href="leaderboards.php" class="btn btn-ghost btn-sm"><?= qb_icon('activity', 'qb-icon', 15) ?> Leaderboards</a>
    </div>
  </section>

  <section class="card qb-mystery-capsule qb-buyer-home__enter-item" aria-label="Mystery deal capsule">
    <div class="qb-mystery-capsule__head">
      <div class="font-bold"><?= qb_icon('spark', 'qb-icon', 16) ?> Mystery Deal Capsule</div>
      <div style="display:flex;gap:0.35rem;align-items:center">
        <span id="mysteryRarityBadge" class="badge qb-mystery-rarity qb-mystery-rarity--common">Common</span>
        <span class="badge qb-event-status qb-event-status--live">Interactive</span>
      </div>
    </div>
    <p class="text-sm text-muted mb-2">Tap reveal and get a random live bazar deal. Every reveal feels different.</p>
    <div class="qb-mystery-capsule__streak">
      <span><?= qb_icon('star', 'qb-icon', 14) ?> Daily streak</span>
      <strong id="mysteryStreakCount">0 day</strong>
    </div>
    <div class="qb-mystery-capsule__panel" id="mysteryPanel">
      <canvas id="mysteryConfetti" class="qb-mystery-capsule__confetti" aria-hidden="true"></canvas>
      <div class="qb-mystery-capsule__label">Your surprise is waiting...</div>
      <div class="qb-mystery-capsule__title" id="mysteryDealTitle">Tap reveal to unlock</div>
      <div class="qb-mystery-capsule__meta" id="mysteryDealMeta">We will pick from live event stalls.</div>
      <div class="qb-mystery-capsule__price" id="mysteryDealPrice">--</div>
      <div class="qb-mystery-capsule__actions">
        <button type="button" class="btn btn-primary btn-sm" id="mysteryRevealBtn"><?= qb_icon('gift', 'qb-icon', 14) ?> Reveal deal</button>
        <a class="btn btn-secondary btn-sm" id="mysteryGoBtn" href="discover.php"><?= qb_icon('search', 'qb-icon', 14) ?> Open deal</a>
      </div>
    </div>
  </section>

  <section class="qb-buyer-home__stats qb-buyer-home__enter-item" aria-label="At a glance">
    <article class="card qb-buyer-stat-card">
      <div class="qb-buyer-stat-card__label">Live bazars</div>
      <div class="qb-buyer-stat-card__value"><?= (int) $homeDash['live_bazars'] ?></div>
      <p class="qb-buyer-stat-card__hint">Published or live events you can browse.</p>
    </article>
    <article class="card qb-buyer-stat-card">
      <div class="qb-buyer-stat-card__label">Owned tickets</div>
      <div class="qb-buyer-stat-card__value"><?= (int) $homeDash['active_tickets'] ?></div>
      <p class="qb-buyer-stat-card__hint">All tickets you own (active + used).</p>
      <a href="tickets.php" class="qb-buyer-stat-card__link">Open tickets →</a>
    </article>
    <article class="card qb-buyer-stat-card">
      <div class="qb-buyer-stat-card__label">Used tickets</div>
      <div class="qb-buyer-stat-card__value"><?= (int) $homeDash['used_tickets'] ?></div>
      <p class="qb-buyer-stat-card__hint">Single-use or past events.</p>
    </article>
  </section>

  <?php
  if (!empty($homePromos)) {
      $promos = $homePromos;
      $promoHeading = 'Spotlight';
      echo '<div class="qb-buyer-home__enter-item">';
      require __DIR__ . '/../includes/partials/promo_spotlight.php';
      echo '</div>';
  }
  ?>

  <?php
  $promoFeedContext = 'buyer';
  $promoFeedSort = (isset($_GET['promo_sort']) && $_GET['promo_sort'] === 'fair') ? 'fair' : 'newest';
  echo '<div class="qb-buyer-home__enter-item">';
  require __DIR__ . '/../includes/partials/promo_feed_section.php';
  echo '</div>';
  ?>

  <?php if (!empty($stallScanLb)): ?>
  <section class="card qb-buyer-home__pulse-rank qb-buyer-home__enter-item mb-3" aria-label="Trending stalls">
    <div class="qb-buyer-home__section-title qb-buyer-home__section-title--compact"><?= qb_icon('activity', 'qb-icon', 16) ?> Hot stalls · last hour</div>
    <p class="text-xs text-muted mb-2">By QR scans — changes every hour.</p>
    <ol class="qb-pulse-rank-list">
      <?php foreach ($stallScanLb as $i => $row): ?>
      <li>
        <span class="qb-pulse-rank-idx"><?= (int) ($i + 1) ?></span>
        <span class="qb-pulse-rank-name"><?= htmlspecialchars($row['market_name'] ?? '') ?></span>
        <span class="qb-pulse-rank-n"><?= (int) ($row['scan_n'] ?? 0) ?> scans</span>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <section class="card mb-3 qb-buyer-account-card qb-buyer-home__account qb-buyer-home__enter-item" aria-label="Your account">
    <div class="qb-buyer-account-card__layout">
      <div class="qb-buyer-account-card__avatar" aria-hidden="true">
        <?php if ($buyerAvatarUrl): ?>
          <img src="<?= htmlspecialchars($buyerAvatarUrl) ?>" alt="" class="qb-buyer-account-card__avatar-img"/>
        <?php else: ?>
          <?= htmlspecialchars(mb_strtoupper(mb_substr($greetName, 0, 1))) ?>
        <?php endif; ?>
      </div>
      <div class="qb-buyer-account-card__body">
        <div class="qb-buyer-account-card__head">
          <span class="qb-buyer-account-label">Your account</span>
          <span class="qb-buyer-account-card__status">Active</span>
        </div>
        <div class="qb-buyer-account-name"><?= htmlspecialchars($user['display_name']) ?></div>
        <div class="qb-buyer-account-meta"><?= htmlspecialchars($user['phone'] ?: 'No phone linked') ?></div>
        <a href="profile.php" class="qb-buyer-account-card__link"><?= qb_icon('user', 'qb-icon', 15) ?> Edit profile</a>
      </div>
    </div>
  </section>

  <?php if (empty($events)): ?>
    <div class="empty-state qb-buyer-home__empty qb-buyer-home__enter-item">
      <?= qb_icon('calendar', 'qb-icon', 48) ?>
      <h3>No live bazars yet</h3>
      <p class="text-sm">When organizers publish events, they will show up here. Try <a href="discover.php">Discover</a> or check back soon.</p>
    </div>
  <?php else: ?>
    <section class="qb-buyer-home__section qb-buyer-home__section--events qb-buyer-home__enter-item" aria-label="Live and upcoming bazars">
      <h2 class="qb-buyer-home__section-title"><?= qb_icon('calendar', 'qb-icon', 16) ?> Live, upcoming &amp; ended</h2>
      <p class="text-xs text-muted mb-2">Compact cards shown. Double-click any event card for full details.</p>
      <div class="grid gap-2 qb-buyer-home__event-grid qb-buyer-home__event-grid--compact">
        <?php foreach ($events as $ei => $e):
          $live = qb_tickets_live_for_event((int) $e['id']);
          $tkt = db()->fetchOne('SELECT id FROM tickets WHERE buyer_id = ? AND event_id = ?', [$uid, $e['id']]);
          $notesLine = trim((string) ($e['notes'] ?? ''));
          $evId = (int) ($e['id'] ?? 0);
          $avail = $eventAvailability[$evId] ?? ['products' => 0, 'regular' => 0, 'flash_all' => 0, 'flash_event' => 0];
          $startLine = '';
          if (!empty($e['event_start'])) {
              $startLine = date('D, M j · g:i A', strtotime($e['event_start']));
          }
          $endLine = '';
          if (!empty($e['event_end'])) {
              $endLine = date('D, M j · g:i A', strtotime($e['event_end']));
          }
            $orgLine = trim((string) ($e['organizer_name'] ?? ''));
            if (!empty($e['co_organizer_names'])) {
                $orgLine = $orgLine === '' ? (string) $e['co_organizer_names'] : $orgLine . ', ' . $e['co_organizer_names'];
            }
            $productsLine = '';
            if ((int) ($avail['products'] ?? 0) > 0) {
                $productsLine = (int) $avail['products'] . ' products in this bazar';
                if ((int) ($avail['regular'] ?? 0) > 0) {
                    $productsLine .= ' · ' . (int) $avail['regular'] . ' with discounts';
                }
                if ((int) ($avail['flash_all'] ?? 0) > 0) {
                    $productsLine .= ' · ' . (int) $avail['flash_all'] . ' flash (all events)';
                }
                if ((int) ($avail['flash_event'] ?? 0) > 0) {
                    $productsLine .= ' · ' . (int) $avail['flash_event'] . ' event flash';
                }
            } else {
                $productsLine = 'Product availability not published yet for this event.';
            }
            $evDialog = [
                'name' => (string) ($e['name'] ?? ''),
                'status' => (string) ($e['status'] ?? ''),
                'venue' => (string) ($e['venue'] ?? ''),
                'city' => (string) ($e['city'] ?? ''),
                'start' => $startLine,
                'end' => $endLine,
                'organizers' => $orgLine,
                'notes' => $notesLine,
                'products' => $productsLine,
                'attendance' => '',
                'leaderboard' => '',
                'products_sold' => '',
                'orders_completed' => '',
                'payments_completed' => '',
            ];
            if (strtolower((string) ($e['status'] ?? '')) === 'ended') {
                $endedStats = $endedEventStats[$evId] ?? qb_event_overlay_summary($evId);
                $evDialog['leaderboard'] = $endedStats['top_seller'] !== '' ? ('Top seller: ' . $endedStats['top_seller']) : 'No seller ranking yet';
                $evDialog['products_sold'] = number_format((int) $endedStats['products_sold']);
                $evDialog['orders_completed'] = number_format((int) $endedStats['orders_completed']);
                $evDialog['payments_completed'] = number_format((float) $endedStats['payments_completed'], 2) . ' ETB';
            }
        ?>
        <article class="event-card qb-event-card qb-buyer-home__event-card qb-buyer-home__event-card--compact qb-event-card--dblinfo" style="<?= !empty($e['theme_color']) ? '--ev-accent:' . htmlspecialchars($e['theme_color']) : '' ?>" tabindex="0" title="Double-click for full event details"<?= qb_event_dialog_data_attr($evDialog) ?>>
          <div class="qb-event-cover qb-buyer-home__event-cover">
            <img src="<?= htmlspecialchars(qb_event_image_url($e)) ?>" alt="<?= htmlspecialchars((string) $e['name']) ?>" loading="<?= $ei === 0 ? 'eager' : 'lazy' ?>" decoding="async"/>
          </div>
          <div class="qb-buyer-home__event-content">
          <div class="event-card-header">
            <div>
              <div class="event-card-name"><?= htmlspecialchars($e['name']) ?></div>
              <div class="event-card-venue"><?= htmlspecialchars($e['venue']) ?> · <?= htmlspecialchars($e['city']) ?></div>
              <?php if ($startLine !== ''): ?>
              <div class="qb-buyer-home__event-when">
                <?= qb_icon('calendar', 'qb-icon', 14) ?>
                <span><?= htmlspecialchars($startLine) ?><?php if ($endLine !== ''): ?> → <?= htmlspecialchars($endLine) ?><?php endif; ?></span>
              </div>
              <?php endif; ?>
              <?php if ($orgLine !== ''): ?>
              <div class="qb-buyer-home__event-org text-xs text-muted"><span class="qb-buyer-home__event-org-label">Organizers</span> <?= htmlspecialchars($orgLine) ?></div>
              <?php endif; ?>
            </div>
            <?php $eventStatusClass = 'qb-event-status qb-event-status--' . preg_replace('/[^a-z_]/', '', strtolower((string) ($e['status'] ?? 'published'))); ?>
            <span class="badge <?= htmlspecialchars($eventStatusClass) ?> qb-buyer-home__event-badge"><?= htmlspecialchars($e['status']) ?></span>
          </div>

          <?php if ($notesLine !== ''): ?>
          <div class="qb-buyer-home__event-notes text-sm text-secondary"><?= htmlspecialchars($notesLine) ?></div>
          <?php endif; ?>
          <?php if ((int) ($avail['products'] ?? 0) > 0): ?>
          <div class="qb-buyer-home__event-notes text-xs text-muted">
            <?= (int) $avail['products'] ?> products available in this event
            <?php if ((int) ($avail['regular'] ?? 0) > 0): ?> · <?= (int) $avail['regular'] ?> with all-event discounts<?php endif; ?>
            <?php if ((int) ($avail['flash_all'] ?? 0) > 0): ?> · <?= (int) $avail['flash_all'] ?> flash offers (all events)<?php endif; ?>
            <?php if ((int) ($avail['flash_event'] ?? 0) > 0): ?> · <?= (int) $avail['flash_event'] ?> event-specific offers<?php endif; ?>
          </div>
          <?php else: ?>
          <div class="qb-buyer-home__event-notes text-xs text-muted">Product availability is not published yet for this event.</div>
          <?php endif; ?>

          <div class="qb-buyer-home__event-actions">
            <?php if ($tkt): ?>
              <a href="tickets.php" class="btn btn-secondary btn-sm qb-buyer-home__btn-flex"><?= qb_icon('ticket', 'qb-icon', 16) ?> View ticket</a>
              <a href="discover.php?event=<?= (int) $e['id'] ?>" class="btn btn-secondary btn-sm qb-buyer-home__btn-flex"><?= qb_icon('search', 'qb-icon', 16) ?> Event products</a>
              <a href="map.php?event=<?= (int) $e['id'] ?>" class="btn btn-sm qb-btn-ticket-solid"><?= qb_icon('map', 'qb-icon', 16) ?> Map</a>
            <?php else: ?>
              <form method="post" action="purchase_ticket.php" class="qb-buyer-home__ticket-form js-pay-submit">
                <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>">
                <input type="hidden" name="redirect" value="home.php">
                <input type="hidden" name="payment_method" value="chapa">
                <?php
                  $stdPrice = (float) ($e['standard_ticket_price_etb'] ?? 0);
                  $prmPrice = (float) ($e['premium_ticket_price_etb'] ?? 0);
                  $eventRules = trim((string) ($e['primary_rules'] ?? ''));
                ?>
                <div class="text-xs text-muted mb-1">Payment: Chapa</div>
                <div class="text-xs mb-1">
                  <label class="qb-buyer-home__ticket-option qb-buyer-home__ticket-option--first">
                    <input type="radio" name="ticket_type" value="standard" checked>
                    Standard · <?= number_format($stdPrice, 2) ?> ETB
                  </label>
                  <label class="qb-buyer-home__ticket-option">
                    <input type="radio" name="ticket_type" value="premium">
                    Premium · <?= number_format($prmPrice, 2) ?> ETB
                  </label>
                </div>
                <label class="text-xs text-muted qb-buyer-home__ticket-rules">
                  <input type="checkbox" name="agree_primary_rules" value="1" required>
                  I accept the primary rules for this event<?= $eventRules !== '' ? ': ' . htmlspecialchars($eventRules) : '' ?>.
                </label>
                <button type="submit" class="btn btn-sm qb-btn-ticket-solid btn-full" data-default-label="Get ticket"><?= qb_icon('ticket', 'qb-icon', 16) ?> Get ticket</button>
              </form>
            <?php endif; ?>
          </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="card mt-3 qb-buyer-cta-card qb-buyer-home__cta qb-buyer-home__enter-item" aria-label="Shop with scan">
    <div class="qb-buyer-home__cta-inner">
      <div class="qb-buyer-cta-icon" aria-hidden="true">
        <?= qb_icon('scan', 'qb-icon', 24) ?>
      </div>
      <div>
        <h2 class="qb-buyer-home__cta-title">Ready to buy?</h2>
        <p class="qb-buyer-home__cta-text">At a stall? Open <strong>Scan</strong> and read the seller&apos;s QR to pay or view products.</p>
      </div>
    </div>
  </section>

  </div>
</div>
</div>

<?php require __DIR__ . '/../includes/partials/event_info_dialog.php'; ?>
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
<script>
(function(){
  var deals = <?= json_encode(array_map(static function (array $d): array {
      $price = (float) ($d['price'] ?? 0);
      $disc = max(0, (int) ($d['discount_pct'] ?? 0));
      $final = $disc > 0 ? round($price * (1 - ($disc / 100)), 2) : $price;
      return [
          'product' => (string) ($d['product_name'] ?? 'Deal'),
          'event' => (string) ($d['event_name'] ?? 'Live event'),
          'market' => (string) ($d['market_name'] ?? 'Seller stall'),
          'price' => number_format($final, 2) . ' ETB / ' . (string) ($d['unit'] ?? 'unit'),
          'discount' => $disc,
          'href' => 'vendor.php?uid=' . rawurlencode((string) ($d['seller_uid'] ?? '')),
          'rarity' => $disc >= 35 ? 'epic' : ($disc >= 20 ? 'rare' : 'common'),
      ];
  }, $mysteryDeals), JSON_UNESCAPED_SLASHES) ?>;
  var btn = document.getElementById('mysteryRevealBtn');
  var panel = document.getElementById('mysteryPanel');
  var rarityBadge = document.getElementById('mysteryRarityBadge');
  var streakCount = document.getElementById('mysteryStreakCount');
  var confettiCanvas = document.getElementById('mysteryConfetti');
  var title = document.getElementById('mysteryDealTitle');
  var meta = document.getElementById('mysteryDealMeta');
  var price = document.getElementById('mysteryDealPrice');
  var go = document.getElementById('mysteryGoBtn');
  if (!btn || !panel || !title || !meta || !price || !go || !rarityBadge || !streakCount) return;

  var streakKey = 'qbMysteryStreak';
  var lastKey = 'qbMysteryStreakLast';
  var revealCountKey = 'qbMysteryRevealCount';
  function readInt(k) {
    var n = parseInt(localStorage.getItem(k) || '0', 10);
    return Number.isFinite(n) ? n : 0;
  }
  function saveInt(k, v) {
    try { localStorage.setItem(k, String(v)); } catch (e) {}
  }
  function formatStreak(n) {
    return String(n) + (n === 1 ? ' day' : ' days');
  }
  function updateStreakUI() {
    streakCount.textContent = formatStreak(readInt(streakKey));
  }
  function updateStreakOnReveal() {
    var today = new Date();
    var key = today.getFullYear() + '-' + (today.getMonth() + 1) + '-' + today.getDate();
    var last = localStorage.getItem(lastKey) || '';
    if (last === key) return;
    var y = new Date(today.getTime() - 86400000);
    var yKey = y.getFullYear() + '-' + (y.getMonth() + 1) + '-' + y.getDate();
    var cur = readInt(streakKey);
    cur = (last === yKey) ? (cur + 1) : 1;
    saveInt(streakKey, cur);
    try { localStorage.setItem(lastKey, key); } catch (e) {}
    updateStreakUI();
  }
  function applyRarity(r) {
    var cls = 'qb-mystery-rarity--common';
    var label = 'Common';
    if (r === 'rare') { cls = 'qb-mystery-rarity--rare'; label = 'Rare'; }
    if (r === 'epic') { cls = 'qb-mystery-rarity--epic'; label = 'Epic'; }
    rarityBadge.className = 'badge qb-mystery-rarity ' + cls;
    rarityBadge.textContent = label;
  }
  function popConfetti(power) {
    if (!confettiCanvas || !confettiCanvas.getContext) return;
    var rect = panel.getBoundingClientRect();
    confettiCanvas.width = Math.max(10, Math.floor(rect.width));
    confettiCanvas.height = Math.max(10, Math.floor(rect.height));
    var ctx = confettiCanvas.getContext('2d');
    var count = power === 'epic' ? 42 : 24;
    var parts = [];
    for (var i = 0; i < count; i++) {
      parts.push({
        x: confettiCanvas.width * 0.5,
        y: 16,
        vx: (Math.random() - 0.5) * 6.5,
        vy: Math.random() * 2.3 + 1.8,
        w: Math.random() * 5 + 3,
        h: Math.random() * 7 + 4,
        c: ['#EB670E','#2A3582','#16a34a','#f59e0b','#8b5cf6'][Math.floor(Math.random()*5)],
        life: 36 + Math.floor(Math.random() * 20)
      });
    }
    var tick = 0;
    function draw() {
      tick += 1;
      ctx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
      for (var j = 0; j < parts.length; j++) {
        var p = parts[j];
        if (p.life <= 0) continue;
        p.life -= 1;
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.09;
        ctx.globalAlpha = Math.max(0, p.life / 48);
        ctx.fillStyle = p.c;
        ctx.fillRect(p.x, p.y, p.w, p.h);
      }
      ctx.globalAlpha = 1;
      if (tick < 64) requestAnimationFrame(draw);
      else ctx.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
    }
    draw();
  }
  updateStreakUI();
  btn.addEventListener('click', function(){
    if (!Array.isArray(deals) || deals.length === 0) {
      title.textContent = 'No live mystery deals right now';
      meta.textContent = 'Check Discover for newly published stalls.';
      price.textContent = '--';
      go.setAttribute('href', 'discover.php');
      applyRarity('common');
      return;
    }
    updateStreakOnReveal();
    saveInt(revealCountKey, readInt(revealCountKey) + 1);
    var pick = deals[Math.floor(Math.random() * deals.length)];
    panel.classList.remove('is-revealed');
    void panel.offsetWidth;
    panel.classList.add('is-revealed');
    var badge = pick.discount > 0 ? (' · ' + pick.discount + '% off') : '';
    title.textContent = pick.product;
    meta.textContent = pick.market + ' · ' + pick.event + badge;
    price.textContent = pick.price;
    go.setAttribute('href', pick.href);
    applyRarity(pick.rarity || 'common');
    if (pick.rarity === 'rare' || pick.rarity === 'epic') {
      popConfetti(pick.rarity);
    }
  });
})();
</script>
<?php qb_page_end(); ?>
