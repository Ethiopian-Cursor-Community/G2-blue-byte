<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyer();
qb_apply_event_special_access_schema();

$ap = qb_sql_product_approved();
$hasTagline = function_exists('qb_has_column') && qb_has_column('sellers', 'stall_tagline');
$stallCol = $hasTagline ? ', s.stall_tagline' : '';
$hasImage = function_exists('qb_has_column') && qb_has_column('products', 'image_url');
$imageCol = $hasImage ? ', p.image_url' : '';
$hasRegularDiscount = function_exists('qb_has_column') && qb_has_column('products', 'discount_pct');
$discountCol = $hasRegularDiscount ? ', p.discount_pct' : ', 0 AS discount_pct';
$hasFreeItemCol = function_exists('qb_has_column') && qb_has_column('products', 'is_free_item');
$freeItemCol = $hasFreeItemCol ? ', p.is_free_item, p.free_label' : ', 0 AS is_free_item, NULL AS free_label';

$q = trim((string) ($_GET['q'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$intent = trim((string) ($_GET['intent'] ?? ''));
$cat = trim((string) ($_GET['cat'] ?? 'all'));
$deal = trim((string) ($_GET['deal'] ?? 'all'));
$sort = trim((string) ($_GET['sort'] ?? 'hot'));
if ($intent !== '') {
    if ($search === '') {
        $search = $intent;
    }
    if ($q === '') {
        $q = $intent;
    }
}
if ($search !== '') {
    if ($q === '') {
        $q = $search;
    }
}
$eventMode = qb_event_mode_get((int) ($_SESSION['app_user_id'] ?? 0));
$eventModeId = ((string) ($eventMode['mode_source'] ?? '') === 'ticket_scan')
    ? (int) ($eventMode['event_id'] ?? 0)
    : 0;
if ($eventModeId <= 0) {
    qb_page_start('buyer', 'Discover', 'discover.php', false);
    ?>
    <div class="buyer-dashboard"><div class="buyer-main">
      <div class="alert alert-warning mb-3">Discover activates after gatekeeper scans your event ticket QR.</div>
      <div class="card">
        <h3 class="font-bold mb-1">Event access required</h3>
        <p class="text-sm text-muted mb-2">To prevent off-system buying/selling, only gate-scanned buyers can browse event products.</p>
        <a href="home.php" class="btn btn-primary btn-sm">Back to home</a>
      </div>
    </div></div>
    <?php
    qb_page_end();
    exit;
}
$eventFilterId = (int) ($_GET['event'] ?? 0);
if ($eventFilterId > 0) {
    $ticketOwnsEvent = db()->fetchOne(
        "SELECT 1
         FROM tickets t
         INNER JOIN bazar_events e ON e.id = t.event_id
         WHERE t.buyer_id = ?
           AND t.event_id = ?
           AND t.status IN ('active','used')
           AND e.status IN ('published','live')
         LIMIT 1",
        [(int) ($_SESSION['app_user_id'] ?? 0), $eventFilterId]
    );
    if (!$ticketOwnsEvent) {
        $eventFilterId = 0;
    }
}

if (!in_array($deal, ['all', 'discount', 'flash', 'event_specific', 'free'], true)) {
    $deal = 'all';
}
if (!in_array($sort, ['hot', 'new', 'price_low', 'price_high', 'discount'], true)) {
    $sort = 'hot';
}

$cats = [];
try {
    $cats = db()->fetchAll("
      SELECT DISTINCT s.category
      FROM sellers s
      JOIN products p ON p.seller_id = s.id
      WHERE s.category IS NOT NULL
        AND s.category <> ''
        AND p.is_available = 1
        AND p.stock > 0
      ORDER BY s.category ASC
      LIMIT 50
    ");
} catch (Throwable $e) {
    $cats = [];
}
$catNames = [];
$catMap = [];
foreach ($cats as $r) {
    $name = trim((string) ($r['category'] ?? ''));
    if ($name === '') {
        continue;
    }
    $catNames[] = $name;
    $catMap[strtolower($name)] = $name;
}
if ($cat !== 'all') {
    $catNorm = strtolower($cat);
    if (isset($catMap[$catNorm])) {
        $cat = $catMap[$catNorm];
    } else {
        $cat = 'all';
    }
}

$intentLower = strtolower($intent !== '' ? $intent : $q);
if ($intentLower !== '') {
    if (!isset($_GET['sort'])) {
        if (preg_match('/\b(new|latest|recent)\b/', $intentLower)) {
            $sort = 'new';
        } elseif (preg_match('/\b(cheap|lowest|budget|low price|affordable)\b/', $intentLower)) {
            $sort = 'price_low';
        } elseif (preg_match('/\b(premium|expensive|high price)\b/', $intentLower)) {
            $sort = 'price_high';
        } elseif (preg_match('/\b(discount|off|sale|deal|hot)\b/', $intentLower)) {
            $sort = 'discount';
        }
    }
    if (!isset($_GET['deal'])) {
        if (preg_match('/\b(flash)\b/', $intentLower)) {
            $deal = 'flash';
        } elseif (preg_match('/\b(free|gift|complimentary)\b/', $intentLower)) {
            $deal = 'free';
        } elseif (preg_match('/\b(event specific|event-only|event only)\b/', $intentLower)) {
            $deal = 'event_specific';
        } elseif (preg_match('/\b(discount|off|sale|deal|hot)\b/', $intentLower)) {
            $deal = 'discount';
        }
    }
    if ($cat === 'all') {
        foreach ($catMap as $key => $val) {
            if ($key !== '' && str_contains($intentLower, $key)) {
                $cat = $val;
                break;
            }
        }
    }
}

$where = ["p.is_available = 1", "p.stock > 0", "($ap)"];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.description LIKE ? OR p.category LIKE ? OR s.category LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($cat !== 'all') {
    $where[] = 's.category = ?';
    $params[] = $cat;
}

$sql = "SELECT p.id, p.seller_id, p.name, p.description, p.price, p.stock, p.unit,
        p.created_at {$discountCol} {$imageCol} {$freeItemCol},
        s.uid AS seller_uid, s.market_name, s.category AS seller_category {$stallCol}";

if (qb_table_exists('flash_sales')) {
    $sql .= ",
        fs_any.sale_price AS qb_flash_sale_price,
        fs_any.discount_pct AS qb_flash_discount,
        fs_scope.has_event_specific AS qb_has_event_specific_discount";
}

$sql .= "
        FROM products p
        JOIN sellers s ON p.seller_id = s.id";

if (qb_table_exists('flash_sales')) {
    $sql .= "
        LEFT JOIN (
          SELECT f1.product_id, f1.seller_id, f1.sale_price, f1.discount_pct
          FROM flash_sales f1
          INNER JOIN (
            SELECT product_id, seller_id, MIN(sale_price) AS min_sale
            FROM flash_sales
            WHERE is_active = 1 AND NOW() >= starts_at AND NOW() <= ends_at
            GROUP BY product_id, seller_id
          ) x ON x.product_id = f1.product_id AND x.seller_id = f1.seller_id AND x.min_sale = f1.sale_price
          WHERE f1.is_active = 1 AND NOW() >= f1.starts_at AND NOW() <= f1.ends_at
        ) fs_any ON fs_any.product_id = p.id AND fs_any.seller_id = p.seller_id
        LEFT JOIN (
          SELECT product_id, seller_id,
                 MAX(CASE WHEN event_id IS NOT NULL THEN 1 ELSE 0 END) AS has_event_specific
          FROM flash_sales
          WHERE is_active = 1 AND NOW() >= starts_at AND NOW() <= ends_at
          GROUP BY product_id, seller_id
        ) fs_scope ON fs_scope.product_id = p.id AND fs_scope.seller_id = p.seller_id";
}

$sql .= "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.id DESC
        LIMIT 220";

$rows = db()->fetchAll($sql, $params);

$sellerTrust = [];
$unlockedSellerIds = [];
$sellerIds = [];
foreach ($rows as $row) {
    $sid = (int) ($row['seller_id'] ?? 0);
    if ($sid > 0) {
        $sellerIds[$sid] = true;
    }
}
if ($sellerIds !== []) {
    $sidList = array_keys($sellerIds);
    $ph = implode(',', array_fill(0, count($sidList), '?'));
    try {
        $trustRows = db()->fetchAll(
            "SELECT s.id AS seller_id,
                    COALESCE(r.avg_stars, 0) AS avg_stars,
                    COALESCE(r.rating_count, 0) AS rating_count,
                    COALESCE(t.completed_sales, 0) AS completed_sales
             FROM sellers s
             LEFT JOIN (
                SELECT seller_id, AVG(stars) AS avg_stars, COUNT(*) AS rating_count
                FROM ratings
                GROUP BY seller_id
             ) r ON r.seller_id = s.id
             LEFT JOIN (
                SELECT seller_id, COUNT(*) AS completed_sales
                FROM transactions
                WHERE payment_status = 'completed'
                GROUP BY seller_id
             ) t ON t.seller_id = s.id
             WHERE s.id IN ($ph)",
            $sidList
        );
        foreach ($trustRows as $tr) {
            $sid = (int) ($tr['seller_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $sellerTrust[$sid] = [
                'avg' => (float) ($tr['avg_stars'] ?? 0),
                'ratings' => (int) ($tr['rating_count'] ?? 0),
                'sales' => (int) ($tr['completed_sales'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        $sellerTrust = [];
    }

    $buyerId = (int) ($_SESSION['app_user_id'] ?? 0);
    if ($buyerId > 0) {
        try {
            $txRows = db()->fetchAll(
                "SELECT DISTINCT seller_id
                 FROM transactions
                 WHERE buyer_id = ?
                   AND payment_status = 'completed'
                   AND seller_id IN ($ph)",
                array_merge([$buyerId], $sidList)
            );
            foreach ($txRows as $txr) {
                $sid = (int) ($txr['seller_id'] ?? 0);
                if ($sid > 0) {
                    $unlockedSellerIds[$sid] = true;
                }
            }
        } catch (Throwable $e) {
            $unlockedSellerIds = [];
        }
    }
}

$stallMap = [];
if (qb_table_exists('stalls') && qb_table_exists('bazar_events')) {
    $stallRows = db()->fetchAll("
      SELECT st.seller_id, st.stall_number, st.event_id, e.name AS event_name, e.status AS event_status,
             ep.participant_type, ep.price_policy, ep.checkout_policy, ep.visibility_badge
      FROM stalls st
      JOIN bazar_events e ON e.id = st.event_id
      LEFT JOIN sellers s ON s.id = st.seller_id
      LEFT JOIN event_participants ep ON ep.event_id = st.event_id AND ep.app_user_id = s.app_user_id AND ep.role_in_event = 'seller'
      WHERE e.status IN ('published','live')
      ORDER BY (e.status='live') DESC, e.event_start ASC, st.stall_number ASC
    ");
    foreach ($stallRows as $sr) {
        $sid = (int) ($sr['seller_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($stallMap[$sid])) {
            $stallMap[$sid] = [];
        }
        $stallMap[$sid][] = [
            'stall_number' => (string) ($sr['stall_number'] ?? ''),
            'event_id' => (int) ($sr['event_id'] ?? 0),
            'event_name' => (string) ($sr['event_name'] ?? ''),
            'event_status' => (string) ($sr['event_status'] ?? ''),
            'participant_type' => (string) ($sr['participant_type'] ?? 'standard_seller'),
            'price_policy' => (string) ($sr['price_policy'] ?? 'normal'),
            'checkout_policy' => (string) ($sr['checkout_policy'] ?? 'allow_checkout'),
            'visibility_badge' => (string) ($sr['visibility_badge'] ?? ''),
        ];
    }
}

$items = [];
foreach ($rows as $p) {
    $flashRow = null;
    if (qb_table_exists('flash_sales') && isset($p['qb_flash_sale_price']) && $p['qb_flash_sale_price'] !== null && (float) $p['qb_flash_sale_price'] > 0) {
        $flashRow = [
            'sale_price' => (float) $p['qb_flash_sale_price'],
            'discount_pct' => (int) ($p['qb_flash_discount'] ?? 0),
        ];
    }
    $pr = qb_resolve_product_pricing($p, $flashRow);
    $regularPct = (int) ($pr['regular_pct'] ?? 0);
    $flashPct = (int) (($pr['flash']['discount_pct'] ?? 0));
    $bestPct = $pr['badge'] === 'flash' ? max($flashPct, $regularPct) : $regularPct;
    $dealScore = ((float) $pr['list_price'] > 0)
        ? (int) round((1 - ((float) $pr['unit_price'] / (float) $pr['list_price'])) * 100)
        : 0;

    $hasEventSpecific = !empty($p['qb_has_event_specific_discount']);
    $discountScope = 'none';
    if ($pr['badge'] === 'flash') {
        $discountScope = $hasEventSpecific ? 'event_specific' : 'all_events';
    } elseif ($regularPct > 0) {
        $discountScope = 'all_events';
    }

    $isFreeItem = !empty($p['is_free_item']);
    if ($isFreeItem) {
        $pr['unit_price'] = 0.0;
        $pr['list_price'] = 0.0;
        $pr['badge'] = 'free';
        $regularPct = 0;
        $flashPct = 0;
        $bestPct = 0;
        $dealScore = 0;
        $discountScope = 'free';
    }

    if ($deal === 'flash' && $pr['badge'] !== 'flash') {
        continue;
    }
    if ($deal === 'discount' && !($pr['badge'] === 'flash' || $regularPct > 0)) {
        continue;
    }
    if ($deal === 'event_specific' && $discountScope !== 'event_specific') {
        continue;
    }
    if ($deal === 'free' && !$isFreeItem) {
        continue;
    }

    $p['qb_pricing'] = $pr;
    $p['qb_best_discount_pct'] = max($bestPct, $dealScore);
    $p['qb_deal_score'] = $dealScore;
    $p['qb_discount_scope'] = $discountScope;
    $p['qb_is_free_item'] = $isFreeItem ? 1 : 0;
    $p['qb_stalls'] = $stallMap[(int) ($p['seller_id'] ?? 0)] ?? [];
    if (empty($p['qb_stalls'])) {
        // Discover only shows event-inserted products; no off-event free listing.
        continue;
    }
    if ($eventFilterId > 0) {
        $inSelectedEvent = false;
        foreach ($p['qb_stalls'] as $st) {
            if ((int) ($st['event_id'] ?? 0) === $eventFilterId) {
                $inSelectedEvent = true;
                break;
            }
        }
        if (!$inSelectedEvent) {
            continue;
        }
    } elseif ($eventModeId > 0) {
        $inEventMode = false;
        foreach ($p['qb_stalls'] as $st) {
            if ((int) ($st['event_id'] ?? 0) === $eventModeId) {
                $inEventMode = true;
                break;
            }
        }
        if (!$inEventMode) {
            continue;
        }
    }
    $policy = [
        'participant_type' => 'standard_seller',
        'price_policy' => 'normal',
        'checkout_policy' => 'allow_checkout',
        'visibility_badge' => '',
    ];
    foreach ($p['qb_stalls'] as $st) {
        $sidEvent = (int) ($st['event_id'] ?? 0);
        if (($eventFilterId > 0 && $sidEvent === $eventFilterId) || ($eventModeId > 0 && $sidEvent === $eventModeId)) {
            $policy = [
                'participant_type' => (string) ($st['participant_type'] ?? 'standard_seller'),
                'price_policy' => (string) ($st['price_policy'] ?? 'normal'),
                'checkout_policy' => (string) ($st['checkout_policy'] ?? 'allow_checkout'),
                'visibility_badge' => (string) ($st['visibility_badge'] ?? ''),
            ];
            break;
        }
    }
    if (($policy['price_policy'] ?? 'normal') === 'free_only' && !$isFreeItem) {
        continue;
    }
    $items[] = $p;
    $items[count($items) - 1]['qb_policy'] = $policy;
}

usort($items, static function (array $a, array $b) use ($sort): int {
    $ap = $a['qb_pricing'];
    $bp = $b['qb_pricing'];
    if ($sort === 'price_low') {
        return ((float) $ap['unit_price'] <=> (float) $bp['unit_price']) ?: ((int) $b['id'] <=> (int) $a['id']);
    }
    if ($sort === 'price_high') {
        return ((float) $bp['unit_price'] <=> (float) $ap['unit_price']) ?: ((int) $b['id'] <=> (int) $a['id']);
    }
    if ($sort === 'discount') {
        return ((int) ($b['qb_best_discount_pct'] ?? 0) <=> (int) ($a['qb_best_discount_pct'] ?? 0))
            ?: ((int) $b['id'] <=> (int) $a['id']);
    }
    if ($sort === 'new') {
        return (int) $b['id'] <=> (int) $a['id'];
    }
    $aFlash = ($ap['badge'] === 'flash') ? 1 : 0;
    $bFlash = ($bp['badge'] === 'flash') ? 1 : 0;
    return ($bFlash <=> $aFlash)
        ?: ((int) ($b['qb_best_discount_pct'] ?? 0) <=> (int) ($a['qb_best_discount_pct'] ?? 0))
        ?: ((float) $ap['unit_price'] <=> (float) $bp['unit_price'])
        ?: ((int) $b['id'] <=> (int) $a['id']);
});

$totalCount = count($items);
$visible = array_slice($items, 0, 84);
$eventSet = [];
$discounted = 0;
$eventSpecificCount = 0;
foreach ($visible as $p) {
    $stalls = is_array($p['qb_stalls'] ?? null) ? $p['qb_stalls'] : [];
    foreach ($stalls as $st) {
        $evId = (int) ($st['event_id'] ?? 0);
        if ($evId > 0) {
            $eventSet[$evId] = true;
        }
    }
    if ((int) ($p['qb_best_discount_pct'] ?? 0) > 0) {
        $discounted++;
    }
    if (($p['qb_discount_scope'] ?? '') === 'event_specific') {
        $eventSpecificCount++;
    }
}
$eventCount = count($eventSet);

$queryBase = [
    'q' => $q,
    'cat' => $cat,
    'deal' => $deal,
    'sort' => $sort,
    'event' => $eventFilterId > 0 ? $eventFilterId : '',
];
$buildUrl = static function (array $over) use ($queryBase): string {
    $qv = array_merge($queryBase, $over);
    foreach ($qv as $k => $v) {
        if ($v === '' || $v === null) {
            unset($qv[$k]);
        }
    }

    return 'discover.php' . ($qv ? ('?' . http_build_query($qv)) : '');
};

qb_page_start('buyer', 'Discover', 'discover.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main">
  <section class="card qb-discover-hero-v2">
    <div class="qb-discover-hero-v2__content">
      <p class="qb-discover-hero-v2__kicker">Buyer marketplace</p>
      <h1 class="qb-discover-hero-v2__title">Discover event-ready products</h1>
      <p class="qb-discover-hero-v2__subtitle">Only products registered to live or published bazar events are shown here.</p>
      <div class="qb-discover-hero-v2__actions">
        <a href="home.php" class="btn btn-secondary btn-sm"><?= qb_icon('home', 'qb-icon', 16) ?> Home</a>
        <a href="map.php" class="btn btn-primary btn-sm"><?= qb_icon('map', 'qb-icon', 16) ?> Event map</a>
      </div>
    </div>
    <div class="qb-discover-hero-v2__metrics" aria-label="Discover highlights">
      <div class="qb-discover-metric"><strong><?= (int) $totalCount ?></strong><span>Products</span></div>
      <div class="qb-discover-metric"><strong><?= (int) $discounted ?></strong><span>Discounted</span></div>
      <div class="qb-discover-metric"><strong><?= (int) $eventSpecificCount ?></strong><span>Event-specific</span></div>
      <div class="qb-discover-metric"><strong><?= (int) $eventCount ?></strong><span>Events</span></div>
    </div>
  </section>

  <section class="mt-2" aria-label="Community promotions">
    <?php
    $promoFeedContext = 'buyer';
    $promoFeedSort = (isset($_GET['promo_sort']) && $_GET['promo_sort'] === 'fair') ? 'fair' : 'newest';
    require __DIR__ . '/../includes/partials/promo_feed_section.php';
    ?>
  </section>
  <?php if ($eventModeId > 0): ?>
  <div class="alert alert-success mb-3">
    Event mode is active. You are currently seeing availability for your scanned event only.
  </div>
  <?php elseif ($eventFilterId > 0): ?>
  <div class="alert alert-info mb-3">
    Showing products for your selected ticket event only.
  </div>
  <?php endif; ?>
  <div class="alert alert-warning qb-discover-trust-banner mb-3">
    Transactions outside of QR BAZAR are not protected by our 100% Secure Guarantee.
  </div>

  <section class="card qb-discover-toolbar qb-discover-toolbar--intent">
    <form method="get" class="qb-discover-intent-form" data-discover-intent-form>
      <div class="qb-discover-quick-tags qb-discover-quick-tags--top" aria-label="Popular filters">
        <a class="qb-discover-quick-tag <?= $sort === 'hot' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['sort' => 'hot'])) ?>">Hot Deals</a>
        <a class="qb-discover-quick-tag <?= $sort === 'discount' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['sort' => 'discount'])) ?>">Top Discount</a>
        <a class="qb-discover-quick-tag <?= $deal === 'flash' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['deal' => 'flash'])) ?>">Flash</a>
        <a class="qb-discover-quick-tag <?= $deal === 'free' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['deal' => 'free'])) ?>">Free</a>
        <?php foreach (array_slice($catNames, 0, 3) as $c): ?>
          <a class="qb-discover-quick-tag <?= $cat === $c ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['cat' => $c])) ?>"><?= htmlspecialchars($c) ?></a>
        <?php endforeach; ?>
      </div>

      <label class="qb-discover-intent-label" for="discover-intent">One-line intent</label>
      <div class="qb-discover-intent-wrap">
        <input
          id="discover-intent"
          type="search"
          name="intent"
          class="form-control qb-discover-intent-input"
          placeholder="What are you looking for today? (e.g., Fresh tomatoes in Bakery)"
          value="<?= htmlspecialchars($intent !== '' ? $intent : $q) ?>"
          autocomplete="off"
        />
        <input type="hidden" name="cat" value="<?= htmlspecialchars($cat) ?>"/>
        <input type="hidden" name="deal" value="<?= htmlspecialchars($deal) ?>"/>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>" data-discover-sort-hidden/>
        <details class="qb-discover-sort-menu" data-discover-sort-menu>
          <summary class="qb-discover-sort-trigger" aria-label="Open sort options">
            <svg class="qb-search-true-icon qb-search-true-icon--sm" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M12 3.6l2.45 4.97 5.49.8-3.97 3.87.94 5.47L12 16.13l-4.91 2.58.94-5.47L4.06 9.37l5.49-.8L12 3.6z" fill="currentColor"/>
            </svg>
          </summary>
          <div class="qb-discover-sort-list">
            <button type="button" class="qb-discover-sort-opt <?= $sort === 'hot' ? 'is-active' : '' ?>" data-discover-sort="hot">Hot deals</button>
            <button type="button" class="qb-discover-sort-opt <?= $sort === 'new' ? 'is-active' : '' ?>" data-discover-sort="new">Newest</button>
            <button type="button" class="qb-discover-sort-opt <?= $sort === 'discount' ? 'is-active' : '' ?>" data-discover-sort="discount">Highest discount</button>
            <button type="button" class="qb-discover-sort-opt <?= $sort === 'price_low' ? 'is-active' : '' ?>" data-discover-sort="price_low">Price low-high</button>
            <button type="button" class="qb-discover-sort-opt <?= $sort === 'price_high' ? 'is-active' : '' ?>" data-discover-sort="price_high">Price high-low</button>
          </div>
        </details>
        <button type="submit" class="qb-discover-intent-submit" aria-label="Search">
          <svg class="qb-search-true-icon qb-search-true-icon--sm" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="2.6"/>
            <path d="M16.2 16.2L20 20" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>
          </svg>
        </button>
      </div>

      <div class="qb-discover-chip-row qb-discover-chip-row--intent" aria-label="Category quick tags">
        <a class="qb-discover-chip <?= $cat === 'all' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['cat' => 'all'])) ?>">All</a>
        <?php foreach (array_slice($catNames, 0, 14) as $c): ?>
          <a class="qb-discover-chip <?= $cat === $c ? 'is-active' : '' ?>" href="<?= htmlspecialchars($buildUrl(['cat' => $c])) ?>"><?= htmlspecialchars($c) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="qb-discover-intent-actions">
        <a href="discover.php" class="btn btn-ghost btn-sm">Reset</a>
      </div>
    </form>
  </section>

  <div class="qb-discover-feed qb-discover-feed--v2 qb-discover-feed--blind">
    <?php if ($visible === []): ?>
    <div class="card qb-discover-empty">
      <h3 class="font-bold mb-2">No products match this filter.</h3>
      <p class="text-muted mb-2">Try removing one filter or searching broader terms.</p>
      <a href="discover.php" class="btn btn-primary btn-sm">Clear filters</a>
    </div>
    <?php endif; ?>

      <?php foreach ($visible as $p):
        $pr = $p['qb_pricing'];
        $img = trim((string) ($p['image_url'] ?? ''));
        $imgSrc = '';
        if ($img !== '') {
            $imgSrc = qb_promo_external_media_url($img) ? $img : qb_public_upload_url($img);
        }
        $desc = trim((string) ($p['description'] ?? ''));
        if ($desc !== '' && strlen($desc) > 140) {
            $desc = substr($desc, 0, 137) . '...';
        }
        $bestPct = (int) ($p['qb_best_discount_pct'] ?? 0);
        $scope = (string) ($p['qb_discount_scope'] ?? 'none');
        $stalls = is_array($p['qb_stalls'] ?? null) ? $p['qb_stalls'] : [];
        $sid = (int) ($p['seller_id'] ?? 0);
        $trust = $sellerTrust[$sid] ?? ['avg' => 0.0, 'ratings' => 0, 'sales' => 0];
        $avgStars = (float) ($trust['avg'] ?? 0);
        $avgStarsLabel = $avgStars > 0 ? number_format($avgStars, 1) : 'New';
        $salesCount = (int) ($trust['sales'] ?? 0);
        $sellerAlias = 'Verified Seller';
        $isUnlocked = !empty($unlockedSellerIds[$sid]);
        $policy = is_array($p['qb_policy'] ?? null) ? $p['qb_policy'] : ['participant_type' => 'standard_seller', 'checkout_policy' => 'allow_checkout', 'visibility_badge' => ''];
        $showBadge = trim((string) ($policy['visibility_badge'] ?? ''));
        if ($showBadge === '' && (string) ($policy['participant_type'] ?? 'standard_seller') !== 'standard_seller') {
            $showBadge = ucwords(str_replace('_', ' ', (string) $policy['participant_type']));
        }
    ?>
    <article class="card qb-discover-item qb-discover-item--v2<?= !empty($p['qb_is_free_item']) ? ' qb-discover-item--free-gold' : '' ?>">
      <div class="qb-discover-item__media">
        <?php if ($imgSrc !== ''): ?>
          <img src="<?= htmlspecialchars($imgSrc) ?>" alt="" loading="lazy" decoding="async"/>
        <?php else: ?>
          <div class="qb-discover-item__placeholder"><?= htmlspecialchars(strtoupper(substr((string) ($p['name'] ?? 'P'), 0, 1))) ?></div>
        <?php endif; ?>
      </div>
      <div class="qb-discover-item__body">
        <div class="qb-discover-item__head">
          <div class="qb-discover-item__seller"><?= htmlspecialchars($sellerAlias) ?></div>
          <span class="qb-discover-verified-badge" title="Verified seller">
            <?= qb_icon('shield', 'qb-icon', 12) ?> Verified
          </span>
          <?php if ($showBadge !== ''): ?>
            <span class="qb-discount-pill"><?= htmlspecialchars($showBadge) ?></span>
          <?php endif; ?>
          <?php if (!empty($p['qb_is_free_item'])): ?>
            <span class="qb-discount-pill">FREE</span>
          <?php elseif ($pr['badge'] === 'flash'): ?>
            <span class="qb-flash-pill">Flash <?= max((int) ($pr['flash']['discount_pct'] ?? 0), $bestPct) ?>%</span>
          <?php elseif ($bestPct > 0): ?>
            <span class="qb-discount-pill"><?= $bestPct ?>% off</span>
          <?php endif; ?>
        </div>
        <h3 class="qb-discover-item__title"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?></h3>
        <?php if ($desc !== ''): ?><p class="qb-discover-item__desc"><?= htmlspecialchars($desc) ?></p><?php endif; ?>
        <div class="qb-discover-item__chips" aria-label="Product quick facts">
          <span class="qb-discover-chip-pill"><?= htmlspecialchars((string) ($p['unit'] ?? 'unit')) ?></span>
          <span class="qb-discover-chip-pill"><?= (int) ($p['stock'] ?? 0) ?> in stock</span>
        </div>
        <div class="qb-discover-item__trust text-xs">
          <span class="badge">Verified · <?= htmlspecialchars($avgStarsLabel) ?>/5</span>
        </div>

        <div class="qb-discover-item__meta">
          <div class="qb-discover-item__price-row">
            <div class="qb-discover-item__price">
              <?php if (!empty($p['qb_is_free_item'])): ?>
                <span class="text-emerald">FREE</span>
              <?php elseif ($pr['badge'] === 'flash' && $pr['flash']): ?>
                <span class="qb-flash-strike text-muted"><?= number_format((float) $pr['list_price'], 2) ?></span>
                <span class="text-emerald"><?= number_format((float) $pr['unit_price'], 2) ?> ETB</span>
              <?php elseif ($bestPct > 0): ?>
                <span class="qb-flash-strike text-muted"><?= number_format((float) $pr['list_price'], 2) ?></span>
                <span class="text-emerald"><?= number_format((float) $pr['unit_price'], 2) ?> ETB</span>
              <?php else: ?>
                <span class="text-emerald"><?= number_format((float) $pr['unit_price'], 2) ?> ETB</span>
              <?php endif; ?>
            </div>
            <?php if (($policy['checkout_policy'] ?? 'allow_checkout') === 'display_only'): ?>
              <span class="btn btn-ghost btn-sm qb-discover-buy-btn" style="pointer-events:none;opacity:.75">Display Only</span>
            <?php else: ?>
              <a class="btn btn-primary btn-sm qb-discover-buy-btn" href="scan.php"><?= qb_icon('cart', 'qb-icon', 13) ?> <?= !empty($p['qb_is_free_item']) ? 'Claim Free' : 'Add to Cart' ?></a>
            <?php endif; ?>
          </div>
        </div>
        <button
          type="button"
          class="qb-discover-more-link"
          data-discover-open
          data-title="<?= htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          data-desc="<?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>"
          data-price="<?= htmlspecialchars(!empty($p['qb_is_free_item']) ? ('FREE / ' . (string) ($p['unit'] ?? 'unit')) : (number_format((float) $pr['unit_price'], 2) . ' ETB / ' . (string) ($p['unit'] ?? 'unit')), ENT_QUOTES, 'UTF-8') ?>"
          data-stock="<?= (int) ($p['stock'] ?? 0) ?>"
          data-scope="<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>"
          data-trust="<?= htmlspecialchars('Top Rated Seller · ' . $avgStarsLabel . '/5 · ' . $salesCount . ' successful sales', ENT_QUOTES, 'UTF-8') ?>"
          data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>"
          data-events="<?= htmlspecialchars(implode(' | ', array_map(static function (array $st) use ($isUnlocked): string {
              $eventName = (string) ($st['event_name'] ?? 'Event');
              if ($isUnlocked) {
                  return $eventName . ' · Stall ' . (string) ($st['stall_number'] ?? '?');
              }
              return $eventName;
          }, array_slice($stalls, 0, 5))), ENT_QUOTES, 'UTF-8') ?>"
          data-unlocked="<?= $isUnlocked ? '1' : '0' ?>"
        >Double-click card for details</button>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

</div>
</div>

<script>
(function () {
  var form = document.querySelector('[data-discover-intent-form]');
  if (!form) return;
  var input = form.querySelector('#discover-intent');
  var sortHidden = form.querySelector('[data-discover-sort-hidden]');
  var sortMenu = form.querySelector('[data-discover-sort-menu]');
  var sortButtons = form.querySelectorAll('[data-discover-sort]');

  if (input) {
    input.addEventListener('focus', function () {
      form.classList.add('is-awake');
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        form.classList.remove('is-awake');
      }, 120);
    });
  }

  sortButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var picked = btn.getAttribute('data-discover-sort') || 'hot';
      if (sortHidden) sortHidden.value = picked;
      sortButtons.forEach(function (x) { x.classList.remove('is-active'); });
      btn.classList.add('is-active');
      if (sortMenu) sortMenu.removeAttribute('open');
    });
  });
})();
</script>

<div class="qb-discover-modal" data-discover-modal hidden>
  <div class="qb-discover-modal__backdrop" data-discover-close></div>
  <div class="qb-discover-modal__panel" role="dialog" aria-modal="true" aria-label="Commodity details">
    <button type="button" class="qb-discover-modal__close" data-discover-close aria-label="Close">×</button>
    <button type="button" class="qb-discover-modal__image-wrap" data-discover-image-open title="Click to preview image">
      <img class="qb-discover-modal__image" data-discover-modal-image alt="Commodity image preview"/>
    </button>
    <h3 class="qb-discover-modal__title" data-discover-modal-title></h3>
    <p class="qb-discover-modal__desc" data-discover-modal-desc></p>
    <div class="qb-discover-modal__meta">
      <div><strong>Price:</strong> <span data-discover-modal-price></span></div>
      <div><strong>Trust:</strong> <span data-discover-modal-trust></span></div>
      <div><strong>Stock:</strong> <span data-discover-modal-stock></span></div>
      <div><strong>Discount scope:</strong> <span data-discover-modal-scope></span></div>
      <div><strong>Available at:</strong> <span data-discover-modal-events></span></div>
    </div>
    <p class="text-xs text-muted" data-discover-modal-locknote></p>
    <div class="qb-discover-modal__actions">
      <a class="btn btn-primary btn-sm" href="scan.php"><?= qb_icon('scan', 'qb-icon', 14) ?> Secure Scan</a>
      <a class="btn btn-secondary btn-sm" href="map.php"><?= qb_icon('map', 'qb-icon', 14) ?> Event map</a>
    </div>
  </div>
</div>

<div class="qb-discover-lightbox" data-discover-lightbox hidden>
  <div class="qb-discover-lightbox__backdrop" data-discover-lightbox-close></div>
  <div class="qb-discover-lightbox__panel">
    <button type="button" class="qb-discover-lightbox__close" data-discover-lightbox-close aria-label="Close image">×</button>
    <img class="qb-discover-lightbox__img" data-discover-lightbox-image alt="Commodity full preview"/>
  </div>
</div>

<script>
(function(){
  var modal = document.querySelector('[data-discover-modal]');
  if (!modal) return;
  var title = modal.querySelector('[data-discover-modal-title]');
  var desc = modal.querySelector('[data-discover-modal-desc]');
  var price = modal.querySelector('[data-discover-modal-price]');
  var trust = modal.querySelector('[data-discover-modal-trust]');
  var stock = modal.querySelector('[data-discover-modal-stock]');
  var scope = modal.querySelector('[data-discover-modal-scope]');
  var events = modal.querySelector('[data-discover-modal-events]');
  var lockNote = modal.querySelector('[data-discover-modal-locknote]');
  var modalImg = modal.querySelector('[data-discover-modal-image]');
  var modalImgBtn = modal.querySelector('[data-discover-image-open]');
  var lightbox = document.querySelector('[data-discover-lightbox]');
  var lightboxImg = lightbox ? lightbox.querySelector('[data-discover-lightbox-image]') : null;

  function closeModal() { modal.hidden = true; }
  function closeLightbox() { if (lightbox) lightbox.hidden = true; }
  function openLightbox() {
    if (!lightbox || !lightboxImg || !modalImg) return;
    if (!modalImg.getAttribute('src')) return;
    lightboxImg.setAttribute('src', modalImg.getAttribute('src'));
    lightbox.hidden = false;
  }
  function openModal(el) {
    title.textContent = el.getAttribute('data-title') || '';
    desc.textContent = el.getAttribute('data-desc') || '';
    price.textContent = el.getAttribute('data-price') || '';
    trust.textContent = el.getAttribute('data-trust') || '';
    stock.textContent = el.getAttribute('data-stock') || '0';
    scope.textContent = el.getAttribute('data-scope') || 'none';
    events.textContent = (el.getAttribute('data-events') || '').replaceAll(' | ', ', ');
    var img = el.getAttribute('data-image') || '';
    if (modalImg) {
      if (img) {
        modalImg.setAttribute('src', img);
        modalImg.style.display = '';
        if (modalImgBtn) modalImgBtn.style.display = '';
      } else {
        modalImg.removeAttribute('src');
        modalImg.style.display = 'none';
        if (modalImgBtn) modalImgBtn.style.display = 'none';
      }
    }
    lockNote.textContent = el.getAttribute('data-unlocked') === '1'
      ? 'Transaction verified: stall details unlocked.'
      : 'Exact stall/contact unlocks only after successful secure QR purchase.';
    modal.hidden = false;
  }

  document.querySelectorAll('.qb-discover-item').forEach(function(card){
    card.addEventListener('dblclick', function(){
      var trigger = card.querySelector('[data-discover-open]');
      if (trigger) openModal(trigger);
    });
  });
  document.querySelectorAll('[data-discover-open]').forEach(function(btn){
    btn.addEventListener('click', function(){ openModal(btn); });
  });
  modal.querySelectorAll('[data-discover-close]').forEach(function(el){
    el.addEventListener('click', closeModal);
  });
  if (modalImgBtn) modalImgBtn.addEventListener('click', openLightbox);
  if (lightbox) {
    lightbox.querySelectorAll('[data-discover-lightbox-close]').forEach(function(el){
      el.addEventListener('click', closeLightbox);
    });
  }
  document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    if (lightbox && !lightbox.hidden) {
      closeLightbox();
      return;
    }
    if (!modal.hidden) closeModal();
  });
})();
</script>

<?php qb_page_end(); ?>
