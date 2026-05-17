<?php
/**
 * Public marketing home (index for guests) — marquee, promos, events, CTAs.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$events = getActiveEvents();
$endedEventIds = [];
foreach ($events as $ev) {
    if (strtolower((string) ($ev['status'] ?? '')) === 'ended') {
        $endedEventIds[] = (int) ($ev['id'] ?? 0);
    }
}
$endedEventStats = qb_event_overlay_summary_bulk($endedEventIds);
$livePublishedEvents = array_values(array_filter($events, static function (array $ev): bool {
    $st = strtolower((string) ($ev['status'] ?? ''));
    return in_array($st, ['published', 'live'], true);
}));
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
$eventCount = count($livePublishedEvents);
$cities = [];
foreach ($livePublishedEvents as $ev) {
    $c = trim((string) ($ev['city'] ?? ''));
    if ($c !== '') {
        $cities[$c] = true;
    }
}
$cityCount = count($cities);
$citySample = array_slice(array_keys($cities), 0, 5);

$homeUrl = rtrim((string) APP_URL, '/') . '/';
$loginBuyer = htmlspecialchars(APP_URL . '/login.php?portal=buyer', ENT_QUOTES, 'UTF-8');
$regSeller = htmlspecialchars(APP_URL . '/register.php', ENT_QUOTES, 'UTF-8');
$regBuyer = htmlspecialchars(APP_URL . '/register.php?portal=buyer', ENT_QUOTES, 'UTF-8');
$loginSeller = htmlspecialchars(APP_URL . '/login.php?portal=seller', ENT_QUOTES, 'UTF-8');
$loginOrg = htmlspecialchars(APP_URL . '/login.php?portal=organizer', ENT_QUOTES, 'UTF-8');
$afterLoginHome = rawurlencode('buyer/home.php');

$pageDesc = 'Browse bazars and promos on ' . APP_NAME . '. Register as a seller or buyer for tickets, maps, and QR checkout.';
$ogImage = '';
foreach ($events as $ev) {
    if (!empty($ev['cover_image'])) {
        $ogImage = qb_public_upload_url((string) $ev['cover_image']);
        break;
    }
}
if ($ogImage === '') {
    $ogImage = qb_spotlight_first_image_og_url($homePromos);
}

$trimNotes = static function (string $text, int $max = 200): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }

    return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
};

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => APP_NAME,
    'url' => $homeUrl,
    'description' => $pageDesc,
];

?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <script>
  (function () {
    try {
      document.documentElement.setAttribute('data-theme', 'light');
      localStorage.setItem('qb-theme', 'light');
    } catch (e) {
      document.documentElement.setAttribute('data-theme', 'light');
    }
  })();
  </script>
  <title><?= htmlspecialchars(APP_NAME) ?> — Live bazars, tickets &amp; marketplace</title>
  <meta name="description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>"/>
  <link rel="canonical" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta property="og:type" content="website"/>
  <meta property="og:title" content="<?= htmlspecialchars(APP_NAME . ' — Live bazars & marketplace', ENT_QUOTES, 'UTF-8') ?>"/>
  <meta property="og:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta property="og:url" content="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>"/>
  <?php if ($ogImage !== ''): ?>
  <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="twitter:card" content="summary_large_image"/>
  <meta name="twitter:title" content="<?= htmlspecialchars(APP_NAME . ' — Live bazars & marketplace', ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"/>
  <?php else: ?>
  <meta name="twitter:card" content="summary"/>
  <meta name="twitter:title" content="<?= htmlspecialchars(APP_NAME . ' — Live bazars & marketplace', ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>"/>
  <?php endif; ?>
  <meta name="theme-color" content="#2A3582"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/style.css"/>
  <link rel="stylesheet" href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/public-landing.css"/>
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body class="qb-public-landing qb-power-beauty">
  <a href="#main-content" class="skip-link">Skip to main content</a>

  <header class="qb-public-landing__nav">
    <div class="qb-public-landing__nav-inner">
      <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/" class="qb-public-landing__brand" aria-current="page">
        <?= qb_icon('scan', 'qb-icon', 22) ?>
        <span><?= htmlspecialchars(APP_NAME) ?></span>
      </a>
      <nav class="qb-public-landing__nav-links" aria-label="Sign in by role">
        <a href="<?= $loginSeller ?>" class="btn btn-ghost btn-sm qb-public-landing__nav-link--mute-sm"><?= qb_icon('store', 'qb-icon', 16) ?> Seller</a>
        <a href="<?= $loginOrg ?>" class="btn btn-ghost btn-sm qb-public-landing__nav-link--mute-sm"><?= qb_icon('calendar', 'qb-icon', 16) ?> Organizer</a>
        <a href="<?= $loginBuyer ?>" class="btn btn-secondary btn-sm">Sign in</a>
        <a href="<?= $regSeller ?>" class="btn btn-primary btn-sm">Join free</a>
      </nav>
    </div>
  </header>

  <div class="qb-public-landing__marquee-bleed" role="presentation">
    <?php qb_render_guest_marquee(); ?>
  </div>

  <main id="main-content" class="qb-public-landing__content" tabindex="-1">
  <section class="qb-public-landing__hero">
    <div class="qb-public-landing__hero-inner">
      <div class="qb-public-landing__hero-copy">
        <p class="qb-public-landing__eyebrow">Public calendar · no account to browse</p>
        <h1 class="qb-public-landing__title">Bazars, stalls, and tickets in one place</h1>
        <p class="qb-public-landing__lead">
          See what organizers have published, which promos are in the spotlight, and what is on sale before you sign up. Seller accounts list inventory; buyer accounts unlock tickets, the attendee map, and QR checkout at the venue.
        </p>
        <div class="qb-public-landing__hero-cta">
          <a href="#events" class="btn btn-primary"><?= qb_icon('calendar', 'qb-icon', 18) ?> Browse events</a>
          <a href="<?= $regSeller ?>" class="btn btn-secondary"><?= qb_icon('store', 'qb-icon', 18) ?> List as seller</a>
          <a href="<?= $regBuyer ?>" class="btn btn-ghost"><?= qb_icon('user', 'qb-icon', 18) ?> Register as buyer</a>
        </div>
        <ul class="qb-public-landing__hero-bullets" role="list">
          <li><?= qb_icon('check', 'qb-icon', 16) ?> Ticket windows and gate check-in when sales are open</li>
          <li><?= qb_icon('check', 'qb-icon', 16) ?> Live maps and geo-fenced venues during the bazar</li>
          <li><?= qb_icon('check', 'qb-icon', 16) ?> Scan a stall QR to pay the seller on the floor</li>
        </ul>
        <p class="qb-public-landing__hero-jump">
          <a href="#promos">Featured promos</a>
          <span class="qb-public-landing__hero-jump-sep" aria-hidden="true">·</span>
          <a href="#events">Events</a>
          <span class="qb-public-landing__hero-jump-sep" aria-hidden="true">·</span>
          <a href="#how-it-works">How it works</a>
        </p>
      </div>
      <aside class="qb-public-landing__hero-aside" aria-label="Calendar snapshot">
        <div class="qb-public-landing__hero-rail">
          <span class="qb-public-landing__hero-rail-label">Published &amp; live</span>
          <span class="qb-public-landing__hero-rail-stat"><span id="qb-public-live-event-count"><?= (int) $eventCount ?></span> <span id="qb-public-live-event-word"><?= $eventCount === 1 ? 'bazar' : 'bazars' ?></span></span>
          <?php if ($cityCount > 0): ?>
          <span><span id="qb-public-live-city-count"><?= (int) $cityCount ?></span> <span id="qb-public-live-city-word"><?= $cityCount === 1 ? 'city' : 'cities' ?></span> with venue detail on the calendar.</span>
          <?php else: ?>
          <span>Cities appear as organizers add venue locations.</span>
          <?php endif; ?>
          <span class="qb-public-landing__hero-rail-note">Everything below is readable as a guest. Sign in only when you are ready to buy or sell.</span>
        </div>
      </aside>
    </div>
  </section>

  <section class="qb-public-landing__trust" aria-label="Why use QR Bazar">
    <div class="qb-public-landing__trust-inner">
      <div class="qb-public-landing__trust-item">
        <span class="qb-public-landing__trust-icon" aria-hidden="true"><?= qb_icon('shield', 'qb-icon', 22) ?></span>
        <strong>Organizer-led</strong>
        <span>Every bazar is tied to a real event and venue.</span>
      </div>
      <div class="qb-public-landing__trust-item">
        <span class="qb-public-landing__trust-icon" aria-hidden="true"><?= qb_icon('scan', 'qb-icon', 22) ?></span>
        <strong>QR checkout</strong>
        <span>Buyers pay sellers with scanned codes at the stall.</span>
      </div>
      <div class="qb-public-landing__trust-item">
        <span class="qb-public-landing__trust-icon" aria-hidden="true"><?= qb_icon('ticket', 'qb-icon', 22) ?></span>
        <strong>Tickets &amp; map</strong>
        <span>One buyer login for entry and the attendee map.</span>
      </div>
    </div>
  </section>

  <section class="qb-public-landing__competition" aria-label="Competition showcase">
    <div class="qb-public-landing__competition-head">
      <h2 class="qb-public-landing__section-title"><?= qb_icon('star', 'qb-icon', 20) ?> Competition Showcase — Dire Dawa</h2>
      <span class="badge qb-event-status qb-event-status--published">Thu · Fri · Sat</span>
    </div>
    <p class="text-sm text-secondary mb-2">Judge-ready flow: browse events, inspect promos, simulate buyer journey, and verify organizer/admin analytics in under 5 minutes.</p>
    <div class="qb-public-landing__competition-actions">
      <a href="#events" class="btn btn-primary btn-sm"><?= qb_icon('calendar', 'qb-icon', 16) ?> Open event list</a>
      <a href="#promos" class="btn btn-secondary btn-sm"><?= qb_icon('flash', 'qb-icon', 16) ?> View promos</a>
      <a href="<?= $loginBuyer ?>" class="btn btn-ghost btn-sm"><?= qb_icon('user', 'qb-icon', 16) ?> Buyer sign in</a>
      <a href="<?= $loginOrg ?>" class="btn btn-ghost btn-sm"><?= qb_icon('chart', 'qb-icon', 16) ?> Organizer dashboard</a>
      <a href="<?= $loginSeller ?>" class="btn btn-ghost btn-sm"><?= qb_icon('store', 'qb-icon', 16) ?> Seller dashboard</a>
    </div>
  </section>

  <div class="qb-public-landing__main">
    <div id="promos" class="qb-public-landing__promo-region">
    <?php
    if (!empty($homePromos)) {
        $promos = $homePromos;
        $promoHeading = 'Featured promos';
        $promoSpotlightContext = 'public';
        require __DIR__ . '/includes/partials/promo_spotlight.php';
    } else {
        ?>
    <section class="qb-public-landing__promo-empty card" aria-label="Featured promos">
      <h2 class="qb-public-landing__promo-heading"><?= qb_icon('flash', 'qb-icon', 16) ?> Featured promos</h2>
      <p class="qb-public-landing__promo-empty-text">There are no spotlight promos yet. When organizers add them, they’ll appear here — full-screen, swipeable, and auto-rotating.</p>
      <div class="qb-public-landing__promo-empty-actions">
        <a href="<?= $regSeller ?>" class="btn btn-primary btn-sm"><?= qb_icon('store', 'qb-icon', 16) ?> List as a seller</a>
        <a href="<?= $loginOrg ?>" class="btn btn-ghost btn-sm"><?= qb_icon('calendar', 'qb-icon', 16) ?> Organizer sign in</a>
      </div>
    </section>
        <?php
    }
    ?>
    </div>

    <?php
    $promoFeedContext = 'public';
    $promoFeedSort = (isset($_GET['promo_sort']) && $_GET['promo_sort'] === 'fair') ? 'fair' : 'newest';
    require __DIR__ . '/includes/partials/promo_feed_section.php';
    ?>

    <section class="qb-public-landing__stats" aria-label="At a glance">
      <div class="qb-public-landing__stat card">
        <div class="qb-public-landing__stat-value" id="qb-public-stat-events"><?= (int) $eventCount ?></div>
        <div class="qb-public-landing__stat-label">Published &amp; live bazars</div>
      </div>
      <div class="qb-public-landing__stat card">
        <div class="qb-public-landing__stat-value" id="qb-public-stat-cities"><?= $cityCount > 0 ? (int) $cityCount : '—' ?></div>
        <div class="qb-public-landing__stat-label" id="qb-public-stat-cities-label"><?= $cityCount > 0 ? 'Cities on the calendar' : 'Cities appear with venue info' ?></div>
      </div>
      <div class="qb-public-landing__stat card">
        <div class="qb-public-landing__stat-value" id="qb-public-stat-promos"><?= count($homePromos) ?></div>
        <div class="qb-public-landing__stat-label">Featured spotlight slides</div>
      </div>
    </section>

    <?php if ($citySample !== []): ?>
    <p class="qb-public-landing__cities">
      <?= qb_icon('map', 'qb-icon', 14) ?>
      <?php foreach ($citySample as $i => $ct): ?><?= $i > 0 ? ' · ' : '' ?><?= htmlspecialchars($ct) ?><?php endforeach; ?>
      <?= $cityCount > 5 ? ' +' . ($cityCount - 5) . ' more' : '' ?>
    </p>
    <?php endif; ?>

    <section id="how-it-works" class="qb-public-landing__steps" aria-labelledby="how-heading">
      <h2 id="how-heading" class="qb-public-landing__section-title"><?= qb_icon('spark', 'qb-icon', 20) ?> How it works</h2>
      <ol class="qb-public-landing__step-list">
        <li>
          <span class="qb-public-landing__step-num">1</span>
          <div>
            <strong>Browse as a guest</strong>
            <p class="qb-public-landing__step-desc">Marquee, promos, and events on this page — no password.</p>
          </div>
        </li>
        <li>
          <span class="qb-public-landing__step-num">2</span>
          <div>
            <strong>Pick seller or buyer</strong>
            <p class="qb-public-landing__step-desc">Sellers add inventory; buyers get tickets and use the map at the bazar.</p>
          </div>
        </li>
        <li>
          <span class="qb-public-landing__step-num">3</span>
          <div>
            <strong>Show up &amp; participate</strong>
            <p class="qb-public-landing__step-desc">Check in, explore stalls, and pay with QR when the event is live.</p>
          </div>
        </li>
      </ol>
    </section>

    <section id="events" class="qb-public-landing__events" aria-labelledby="events-heading">
      <div class="qb-public-landing__events-head">
      <h2 id="events-heading" class="qb-public-landing__section-title"><?= qb_icon('calendar', 'qb-icon', 20) ?> Live, upcoming &amp; ended bazars</h2>
        <p class="qb-public-landing__events-sub">Sign in to buy tickets and open the attendee map.</p>
      </div>

      <?php if ($events === []): ?>
      <div class="qb-public-landing__empty card">
        <?= qb_icon('calendar', 'qb-icon', 40) ?>
        <h3 class="qb-public-landing__empty-title">No published bazars right now</h3>
        <p class="qb-public-landing__empty-text">Organizers publish events here first. Create an account so you’re ready when the next bazar goes live.</p>
        <div class="qb-public-landing__empty-actions">
          <a href="<?= $regBuyer ?>" class="btn btn-primary mt-3"><?= qb_icon('user', 'qb-icon', 16) ?> Register as buyer</a>
          <a href="<?= $regSeller ?>" class="btn btn-secondary mt-3"><?= qb_icon('store', 'qb-icon', 16) ?> Register as seller</a>
        </div>
      </div>
      <?php else: ?>
      <div class="qb-public-landing__event-grid">
        <?php foreach ($events as $ei => $e):
            $live = function_exists('qb_tickets_live_for_event') && qb_tickets_live_for_event((int) $e['id']);
            $notesLine = trim((string) ($e['notes'] ?? ''));
            $evId = (int) ($e['id'] ?? 0);
            $avail = $eventAvailability[$evId] ?? ['products' => 0, 'regular' => 0, 'flash_all' => 0, 'flash_event' => 0];
            $excerpt = $trimNotes($notesLine);
            $startLine = '';
            if (!empty($e['event_start'])) {
                $startLine = date('D, M j · g:i A', strtotime((string) $e['event_start']));
            }
            $endLine = '';
            if (!empty($e['event_end'])) {
                $endLine = date('D, M j · g:i A', strtotime((string) $e['event_end']));
            }
            $orgLine = trim((string) ($e['organizer_name'] ?? ''));
            if (!empty($e['co_organizer_names'])) {
                $orgLine = $orgLine === '' ? (string) $e['co_organizer_names'] : $orgLine . ', ' . $e['co_organizer_names'];
            }
            $coverAlt = trim((string) ($e['name'] ?? '')) !== '' ? 'Cover: ' . (string) $e['name'] : 'Event cover';
            $productsLine = '';
            if ((int) ($avail['products'] ?? 0) > 0) {
                $productsLine = (int) $avail['products'] . ' products available';
                if ((int) ($avail['regular'] ?? 0) > 0) {
                    $productsLine .= ' · ' . (int) $avail['regular'] . ' all-event discounts';
                }
                if ((int) ($avail['flash_all'] ?? 0) > 0) {
                    $productsLine .= ' · ' . (int) $avail['flash_all'] . ' flash (all events)';
                }
                if ((int) ($avail['flash_event'] ?? 0) > 0) {
                    $productsLine .= ' · ' . (int) $avail['flash_event'] . ' event-specific';
                }
            } else {
                $productsLine = 'Product availability is not published yet for this event.';
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
        <article class="event-card qb-event-card qb-public-landing__event-card qb-event-card--dblinfo" style="<?= !empty($e['theme_color']) ? '--ev-accent:' . htmlspecialchars((string) $e['theme_color'], ENT_QUOTES, 'UTF-8') : '' ?>" tabindex="0" title="Double-click for full event details"<?= qb_event_dialog_data_attr($evDialog) ?>>
          <?php if (!empty($e['cover_image'])): ?>
          <div class="qb-event-cover">
            <img src="<?= htmlspecialchars(qb_public_upload_url((string) $e['cover_image'])) ?>"
                 alt="<?= htmlspecialchars($coverAlt, ENT_QUOTES, 'UTF-8') ?>"
                 loading="<?= $ei === 0 ? 'eager' : 'lazy' ?>"
                 decoding="async"
                 <?php if ($ei === 0): ?>fetchpriority="high"<?php endif; ?>/>
          </div>
          <?php endif; ?>
          <div class="event-card-header">
            <div>
              <div class="event-card-name"><?= htmlspecialchars((string) $e['name']) ?></div>
              <div class="event-card-venue"><?= htmlspecialchars((string) ($e['venue'] ?? '')) ?> · <?= htmlspecialchars((string) ($e['city'] ?? '')) ?></div>
              <?php if ($startLine !== ''): ?>
              <div class="text-xs text-muted mt-1"><?= htmlspecialchars($startLine) ?><?php if ($endLine !== ''): ?> → <?= htmlspecialchars($endLine) ?><?php endif; ?></div>
              <?php endif; ?>
              <?php if ($orgLine !== ''): ?>
              <div class="text-xs text-muted mt-1"><span class="font-bold">Organizers</span> <?= htmlspecialchars($orgLine) ?></div>
              <?php endif; ?>
            </div>
            <?php $eventStatusClass = 'qb-event-status qb-event-status--' . preg_replace('/[^a-z_]/', '', strtolower((string) ($e['status'] ?? 'published'))); ?>
            <span class="badge <?= htmlspecialchars($eventStatusClass) ?>"><?= htmlspecialchars((string) $e['status']) ?></span>
          </div>
          <?php if ($excerpt !== ''): ?>
          <div class="text-sm text-secondary mt-2 qb-public-landing__event-notes"><?= htmlspecialchars($excerpt) ?></div>
          <?php endif; ?>
          <?php if ((int) ($avail['products'] ?? 0) > 0): ?>
          <div class="text-xs text-muted mt-1 qb-public-landing__event-notes">
            <?= (int) $avail['products'] ?> products available
            <?php if ((int) ($avail['regular'] ?? 0) > 0): ?> · <?= (int) $avail['regular'] ?> all-event discounts<?php endif; ?>
            <?php if ((int) ($avail['flash_all'] ?? 0) > 0): ?> · <?= (int) $avail['flash_all'] ?> flash (all events)<?php endif; ?>
            <?php if ((int) ($avail['flash_event'] ?? 0) > 0): ?> · <?= (int) $avail['flash_event'] ?> event-specific<?php endif; ?>
          </div>
          <?php else: ?>
          <div class="text-xs text-muted mt-1 qb-public-landing__event-notes">Product availability is not published yet for this event.</div>
          <?php endif; ?>
          <div class="qb-public-landing__event-actions">
            <a href="<?= $loginBuyer ?>&redirect=<?= $afterLoginHome ?>" class="btn btn-sm btn-primary"><?= qb_icon('ticket', 'qb-icon', 16) ?> Sign in for tickets</a>
            <a href="<?= $regBuyer ?>" class="btn btn-sm btn-secondary"><?= qb_icon('user', 'qb-icon', 16) ?> Buyer</a>
            <a href="<?= $regSeller ?>" class="btn btn-sm btn-ghost"><?= qb_icon('store', 'qb-icon', 16) ?> Seller</a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="qb-public-landing__cta-band card" aria-label="Get started">
      <div class="qb-public-landing__cta-band-inner">
        <div>
          <h2 class="qb-public-landing__cta-title">Ready when you are</h2>
          <p class="qb-public-landing__cta-lead">One account type at sign-up — seller or buyer — with dedicated portals after login.</p>
        </div>
        <div class="qb-public-landing__cta-band-btns">
          <a href="<?= $regSeller ?>" class="btn btn-primary"><?= qb_icon('store', 'qb-icon', 16) ?> Seller</a>
          <a href="<?= $regBuyer ?>" class="btn btn-secondary"><?= qb_icon('user', 'qb-icon', 16) ?> Buyer</a>
          <a href="<?= $loginBuyer ?>" class="btn btn-ghost"><?= qb_icon('arrow-right', 'qb-icon', 16) ?> Sign in</a>
        </div>
      </div>
    </section>
  </div>
  </main>

  <footer class="qb-public-landing__footer">
    <div class="qb-public-landing__footer-inner">
      <span class="qb-public-landing__footer-copy"><?= htmlspecialchars(APP_NAME) ?> · <?= htmlspecialchars((string) date('Y')) ?></span>
      <nav class="qb-public-landing__footer-links" aria-label="Footer">
        <a href="<?= $loginBuyer ?>">Buyer sign in</a>
        <a href="<?= $regBuyer ?>">Buyer register</a>
        <a href="<?= $regSeller ?>">Seller register</a>
        <a href="<?= $loginSeller ?>">Seller portal</a>
        <a href="<?= $loginOrg ?>">Organizer</a>
        <button type="button" class="qb-public-landing__footer-theme" id="qb-public-theme-toggle" aria-label="Switch between light and dark appearance">Light / dark</button>
      </nav>
    </div>
  </footer>

  <?php require __DIR__ . '/includes/partials/event_info_dialog.php'; ?>

  <script>
  document.querySelector('.skip-link')?.addEventListener('click', function () {
    var m = document.getElementById('main-content');
    if (m) { m.focus({ preventScroll: false }); }
  });
  (function () {
    function toggleTheme() {
      var h = document.documentElement;
      var d = h.getAttribute('data-theme');
      var next = d === 'dark' ? 'light' : 'dark';
      h.setAttribute('data-theme', next);
      try { localStorage.setItem('qb-theme', next); } catch (e) {}
    }
    document.getElementById('qb-public-theme-toggle')?.addEventListener('click', toggleTheme);
  })();
  (function () {
    var eventsStat = document.getElementById('qb-public-stat-events');
    var citiesStat = document.getElementById('qb-public-stat-cities');
    var citiesLabel = document.getElementById('qb-public-stat-cities-label');
    var promosStat = document.getElementById('qb-public-stat-promos');
    var heroEvents = document.getElementById('qb-public-live-event-count');
    var heroEventWord = document.getElementById('qb-public-live-event-word');
    var heroCities = document.getElementById('qb-public-live-city-count');
    var heroCityWord = document.getElementById('qb-public-live-city-word');

    function applyStats(s) {
      var ec = Number(s.eventCount || 0);
      var cc = Number(s.cityCount || 0);
      var pc = Number(s.promoCount || 0);
      if (eventsStat) eventsStat.textContent = String(ec);
      if (citiesStat) citiesStat.textContent = cc > 0 ? String(cc) : '—';
      if (citiesLabel) citiesLabel.textContent = cc > 0 ? 'Cities on the calendar' : 'Cities appear with venue info';
      if (promosStat) promosStat.textContent = String(pc);
      if (heroEvents) heroEvents.textContent = String(ec);
      if (heroEventWord) heroEventWord.textContent = ec === 1 ? 'bazar' : 'bazars';
      if (heroCities) heroCities.textContent = String(cc);
      if (heroCityWord) heroCityWord.textContent = cc === 1 ? 'city' : 'cities';
    }

    async function refreshStats() {
      try {
        var res = await fetch('<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/public_home_stats.php', { cache: 'no-store' });
        if (!res.ok) return;
        var json = await res.json();
        if (!json || json.ok !== true || !json.stats) return;
        applyStats(json.stats);
      } catch (e) {}
    }

    refreshStats();
    setInterval(refreshStats, 12000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refreshStats();
    });
    window.addEventListener('focus', refreshStats);
  })();
  </script>
</body>
</html>
