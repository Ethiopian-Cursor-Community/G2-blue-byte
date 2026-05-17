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

qb_ensure_category_schema();
qb_apply_event_special_access_schema();

$uid = (int) $_SESSION['app_user_id'];
$ew = qb_organizer_event_alias_access_sql('e');
$eb = qb_organizer_event_access_bind($uid);
$events = db()->fetchAll("SELECT e.id, e.name, e.status FROM bazar_events e WHERE $ew ORDER BY e.name", $eb);

$selectedEvent = qb_organizer_resolve_event_id(
    isset($_GET['event']) ? (int) $_GET['event'] : null,
    $events
);

$error = '';
$success = '';

$nextStallNumber = static function (int $eventId): string {
    $rows = db()->fetchAll('SELECT stall_number FROM stalls WHERE event_id = ? ORDER BY id ASC', [$eventId]);
    $max = 0;
    foreach ($rows as $r) {
        $sn = trim((string) ($r['stall_number'] ?? ''));
        if (preg_match('/(\d{1,5})$/', $sn, $m)) {
            $n = (int) $m[1];
            if ($n > $max) {
                $max = $n;
            }
        }
    }
    $next = $max + 1;
    if ($next < 1) {
        $next = 1;
    }

    return 'S-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postEv = (int) ($_POST['event_id'] ?? 0);
    if (!qb_organizer_event_id_allowed($postEv, $events)) {
        $error = 'Invalid event or you do not have access.';
    } else {
        $stEv = db()->fetchOne(
            "SELECT e.status FROM bazar_events e WHERE e.id = ? AND $ew",
            array_merge([$postEv], $eb)
        );
        if (!$stEv) {
            $error = 'Invalid event or you do not have access.';
        } elseif (($stEv['status'] ?? '') === 'canceled') {
            $error = 'This bazar was canceled; seller assignments cannot be changed.';
        }
    }
    if ($error === '') {
        $selectedEvent = $postEv;
        $action = $_POST['action'] ?? '';
        if ($action === 'add' && $selectedEvent) {
            $sellerUid = sanitize($_POST['seller_uid']);
            $stallNum = sanitize($_POST['stall_number']);
            $participantType = (string) ($_POST['participant_type'] ?? 'standard_seller');
            $categoryEnforcement = (string) ($_POST['category_enforcement'] ?? 'strict');
            $pricePolicy = (string) ($_POST['price_policy'] ?? 'normal');
            $checkoutPolicy = (string) ($_POST['checkout_policy'] ?? 'allow_checkout');
            $visibilityBadge = sanitize((string) ($_POST['visibility_badge'] ?? ''));
            if (!in_array($participantType, ['standard_seller', 'guest_seller', 'service_booth', 'sponsor_booth'], true)) $participantType = 'standard_seller';
            if (!in_array($categoryEnforcement, ['strict', 'bypass'], true)) $categoryEnforcement = 'strict';
            if (!in_array($pricePolicy, ['normal', 'free_only', 'mixed'], true)) $pricePolicy = 'normal';
            if (!in_array($checkoutPolicy, ['allow_checkout', 'display_only'], true)) $checkoutPolicy = 'allow_checkout';
            if ($stallNum === '') {
                $stallNum = $nextStallNumber($selectedEvent);
            }

            $seller = db()->fetchOne('SELECT * FROM sellers WHERE uid = ?', [$sellerUid]);
            $ev = db()->fetchOne('SELECT * FROM bazar_events WHERE id = ?', [$selectedEvent]);
            $skipCategory = $categoryEnforcement === 'bypass' || in_array($participantType, ['guest_seller', 'service_booth', 'sponsor_booth'], true);
            if ($seller && $ev && !$skipCategory && !qb_seller_eligible_for_event_categories($seller, $ev)) {
                $error = 'This seller\'s stall categories do not match this bazar\'s eligible categories. Ask the seller to update their profile categories, or widen the event\'s eligible list.';
            } elseif ($seller) {
                $evCap = db()->fetchOne('SELECT COALESCE(max_sellers, 50) AS max_sellers FROM bazar_events WHERE id = ?', [$selectedEvent]);
                $maxS = (int) ($evCap['max_sellers'] ?? 50);
                if (qb_event_assigned_seller_count($selectedEvent) >= $maxS) {
                    $error = 'This bazar is at max seller capacity. Raise max sellers on the event before assigning.';
                }
                $exists = $error === '' ? db()->fetchOne('SELECT 1 FROM stalls WHERE event_id = ? AND seller_id = ? LIMIT 1', [$selectedEvent, (int) $seller['id']]) : null;
                if ($error === '' && $exists) {
                    $error = 'This seller is already assigned to the selected event.';
                } elseif ($error === '') {
                    $dupStall = db()->fetchOne('SELECT 1 FROM stalls WHERE event_id = ? AND stall_number = ? LIMIT 1', [$selectedEvent, $stallNum]);
                    if ($dupStall) {
                        $stallNum = $nextStallNumber($selectedEvent);
                    }
                    db()->execute("INSERT IGNORE INTO event_participants (event_id, app_user_id, role_in_event, status) VALUES (?, ?, 'seller', 'approved')", [$selectedEvent, $seller['app_user_id']]);
                    db()->execute(
                        "UPDATE event_participants
                         SET participant_type = ?, category_enforcement = ?, price_policy = ?, checkout_policy = ?, visibility_badge = ?
                         WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller'",
                        [$participantType, $categoryEnforcement, $pricePolicy, $checkoutPolicy, $visibilityBadge !== '' ? $visibilityBadge : null, $selectedEvent, (int) $seller['app_user_id']]
                    );
                    db()->execute("INSERT INTO stalls (event_id, seller_id, stall_number) VALUES (?, ?, ?)", [$selectedEvent, $seller['id'], $stallNum]);
                    $success = 'Seller assigned to shop number ' . $stallNum . '.';
                }
            } else {
                $error = 'Seller UID not found.';
            }
        } elseif ($action === 'approve_application' && $selectedEvent) {
            $appUserId = (int) ($_POST['app_user_id'] ?? 0);
            $stallNum = sanitize((string) ($_POST['stall_number'] ?? ''));
            $participantType = (string) ($_POST['participant_type'] ?? 'standard_seller');
            $categoryEnforcement = (string) ($_POST['category_enforcement'] ?? 'strict');
            $pricePolicy = (string) ($_POST['price_policy'] ?? 'normal');
            $checkoutPolicy = (string) ($_POST['checkout_policy'] ?? 'allow_checkout');
            $visibilityBadge = sanitize((string) ($_POST['visibility_badge'] ?? ''));
            if (!in_array($participantType, ['standard_seller', 'guest_seller', 'service_booth', 'sponsor_booth'], true)) $participantType = 'standard_seller';
            if (!in_array($categoryEnforcement, ['strict', 'bypass'], true)) $categoryEnforcement = 'strict';
            if (!in_array($pricePolicy, ['normal', 'free_only', 'mixed'], true)) $pricePolicy = 'normal';
            if (!in_array($checkoutPolicy, ['allow_checkout', 'display_only'], true)) $checkoutPolicy = 'allow_checkout';
            if ($stallNum === '') {
                $stallNum = $nextStallNumber($selectedEvent);
            }
            $evRow = db()->fetchOne('SELECT max_sellers FROM bazar_events WHERE id = ?', [$selectedEvent]);
            $maxS = (int) ($evRow['max_sellers'] ?? 50);
            $used = qb_event_assigned_seller_count($selectedEvent);
            if ($used >= $maxS) {
                $error = 'This bazar is at max seller capacity. Raise max sellers on the event or remove a stall before approving.';
            } else {
                $seller = db()->fetchOne('SELECT * FROM sellers WHERE app_user_id = ?', [$appUserId]);
                if (!$seller) {
                    $error = 'Seller record not found for this application.';
                } else {
                    $ep = db()->fetchOne(
                        "SELECT application_products_json, application_visibility_mode
                         FROM event_participants
                         WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller'
                         LIMIT 1",
                        [$selectedEvent, $appUserId]
                    ) ?: [];
                    $exists = db()->fetchOne('SELECT 1 FROM stalls WHERE event_id = ? AND seller_id = ? LIMIT 1', [$selectedEvent, (int) $seller['id']]);
                    if (!$exists) {
                        db()->execute('INSERT INTO stalls (event_id, seller_id, stall_number) VALUES (?, ?, ?)', [$selectedEvent, (int) $seller['id'], $stallNum]);
                    }
                    db()->execute(
                        "UPDATE event_participants
                         SET status = 'approved',
                             participant_type = ?,
                             category_enforcement = ?,
                             price_policy = ?,
                             checkout_policy = ?,
                             visibility_badge = ?
                         WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller'",
                        [$participantType, $categoryEnforcement, $pricePolicy, $checkoutPolicy, $visibilityBadge !== '' ? $visibilityBadge : null, $selectedEvent, $appUserId]
                    );
                    $visibilityMode = (string) ($ep['application_visibility_mode'] ?? 'selected');
                    if (!in_array($visibilityMode, ['selected', 'all'], true)) {
                        $visibilityMode = 'selected';
                    }
                    if ($visibilityMode === 'all') {
                        $all = db()->fetchAll('SELECT id FROM products WHERE seller_id = ?', [(int) $seller['id']]);
                        foreach ($all as $r) {
                            $pid = (int) ($r['id'] ?? 0);
                            if ($pid <= 0) continue;
                            db()->execute(
                                "INSERT INTO event_products (event_id, seller_id, product_id, is_active)
                                 VALUES (?, ?, ?, 1)
                                 ON DUPLICATE KEY UPDATE is_active = 1",
                                [$selectedEvent, (int) $seller['id'], $pid]
                            );
                        }
                    } else {
                        db()->execute('UPDATE event_products SET is_active = 0 WHERE event_id = ? AND seller_id = ?', [$selectedEvent, (int) $seller['id']]);
                        $selected = json_decode((string) ($ep['application_products_json'] ?? '[]'), true);
                        if (is_array($selected)) {
                            foreach ($selected as $pr) {
                                $pid = (int) (($pr['id'] ?? 0));
                                if ($pid <= 0) continue;
                                db()->execute(
                                    "INSERT INTO event_products (event_id, seller_id, product_id, is_active)
                                     VALUES (?, ?, ?, 1)
                                     ON DUPLICATE KEY UPDATE is_active = 1",
                                    [$selectedEvent, (int) $seller['id'], $pid]
                                );
                            }
                        }
                    }
                    $success = 'Seller application approved.';
                }
            }
        } elseif ($action === 'reject_application' && $selectedEvent) {
            $appUserId = (int) ($_POST['app_user_id'] ?? 0);
            db()->execute("UPDATE event_participants SET status = 'rejected' WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller'", [$selectedEvent, $appUserId]);
            $success = 'Seller application rejected.';
        } elseif ($action === 'remove' && $selectedEvent) {
            $sellerId = (int) $_POST['seller_id'];
            $row = db()->fetchOne('SELECT app_user_id FROM sellers WHERE id = ?', [$sellerId]);
            $appUserId = $row ? (int) $row['app_user_id'] : 0;

            db()->execute('DELETE FROM stalls WHERE event_id = ? AND seller_id = ?', [$selectedEvent, $sellerId]);
            if ($appUserId > 0) {
                db()->execute("DELETE FROM event_participants WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller'", [$selectedEvent, $appUserId]);
            }
            $success = 'Seller removed from event.';
        }
    }
}

$selectedEvRow = null;
foreach ($events as $er) {
    if ((int) ($er['id'] ?? 0) === (int) $selectedEvent) {
        $selectedEvRow = $er;
        break;
    }
}
$canManageSellers = $selectedEvRow && qb_organizer_may_manage_event($selectedEvRow);

// Get assigned sellers
$stalls = [];
if ($selectedEvent) {
    $stalls = db()->fetchAll("
        SELECT st.id as stall_id, st.stall_number, s.uid, s.market_name, s.category, s.categories_json, s.id as seller_id,
               ep.participant_type, ep.category_enforcement, ep.price_policy, ep.checkout_policy, ep.visibility_badge
        FROM stalls st
        JOIN sellers s ON st.seller_id = s.id
        LEFT JOIN event_participants ep ON ep.event_id = st.event_id AND ep.app_user_id = s.app_user_id AND ep.role_in_event = 'seller'
        WHERE st.event_id = ?
        ORDER BY st.stall_number
    ", [$selectedEvent]);
}
$pendingApplications = [];
$eventSlotsInfo = null;
if ($selectedEvent) {
    $evCap = db()->fetchOne('SELECT COALESCE(max_sellers, 50) AS max_sellers FROM bazar_events WHERE id = ?', [$selectedEvent]);
    $eventSlotsInfo = [
        'max' => (int) ($evCap['max_sellers'] ?? 50),
        'used' => qb_event_assigned_seller_count((int) $selectedEvent),
    ];
    $snapCol = qb_has_column('event_participants', 'application_categories_json') ? 'ep.application_categories_json' : 'NULL AS application_categories_json';
    $snapProductsCol = qb_has_column('event_participants', 'application_products_json') ? 'ep.application_products_json' : 'NULL AS application_products_json';
    $pendingApplications = db()->fetchAll(
        "SELECT ep.app_user_id, {$snapCol}, {$snapProductsCol}, ep.application_visibility_mode, u.display_name, u.phone, s.uid AS seller_uid, s.market_name, s.categories_json AS seller_categories_json
         FROM event_participants ep
         INNER JOIN app_users u ON u.id = ep.app_user_id
         LEFT JOIN sellers s ON s.app_user_id = ep.app_user_id
         WHERE ep.event_id = ? AND ep.role_in_event = 'seller' AND ep.status = 'pending'
         ORDER BY ep.assigned_at DESC",
        [$selectedEvent]
    );
}

qb_page_start('organizer', 'Manage Sellers', 'sellers.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Assigned Sellers</h1>
    <p class="page-subtitle">Map sellers to stalls in your bazars. Only events you manage are available.</p>
  </div>
  <?php if ($events && $canManageSellers): ?>
  <button type="button" class="btn btn-primary" onclick="document.getElementById('addSellerModal').style.display='flex'"><?= qb_icon('plus') ?> Add Seller to Event</button>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if (!$events): ?>
  <div class="empty-state">
    <p>You need to create an event first.</p>
  </div>
<?php else: ?>
  <?php if ($selectedEvent > 0 && !$canManageSellers): ?>
  <div class="alert alert-warning mb-3" role="status">This bazar was <strong>canceled</strong>. Stall assignments are read-only; you can still review the list below.</div>
  <?php endif; ?>
  <form method="get" class="mb-3">
    <div style="display:flex;gap:0.5rem;align-items:center;max-width:400px">
        <select name="event" class="form-control" onchange="this.form.submit()">
            <?php foreach ($events as $e): ?>
                <option value="<?= (int) $e['id'] ?>" <?= (int) $selectedEvent === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
  </form>

  <?php if ($eventSlotsInfo !== null): ?>
  <?php $open = max(0, $eventSlotsInfo['max'] - $eventSlotsInfo['used']); ?>
  <div class="alert <?= $open > 0 ? 'alert-success' : 'alert-warning' ?> mb-3" role="status">
    <strong>Seller capacity:</strong> <?= (int) $eventSlotsInfo['used'] ?> assigned · <?= (int) $eventSlotsInfo['max'] ?> max ·
    <strong><?= (int) $open ?></strong> open <?= $open === 1 ? 'slot' : 'slots' ?> for new approvals.
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-wrapper">
        <table>
        <thead>
            <tr>
                <th>Stall</th>
                <th>Market Name</th>
                <th>Stall categories</th>
                <th>Policy</th>
                <th>UID</th>
                <th style="text-align:right">Action</th>
            </tr>
        </thead>
            <tbody>
                <?php if (empty($stalls)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No sellers assigned to this event.</td></tr>
                <?php else: ?>
                    <?php foreach ($stalls as $s): ?>
                    <tr>
                        <td class="font-bold text-accent"><?= htmlspecialchars($s['stall_number']) ?></td>
                        <td class="font-bold"><?= htmlspecialchars($s['market_name']) ?></td>
                        <td><?= htmlspecialchars(qb_seller_categories_labels(qb_seller_categories_from_row($s))) ?></td>
                        <td class="text-xs">
                          <span class="badge badge-gray"><?= htmlspecialchars((string) (($s['participant_type'] ?? '') !== '' ? $s['participant_type'] : 'standard_seller')) ?></span>
                          <?php if (!empty($s['visibility_badge'])): ?><div><?= htmlspecialchars((string) $s['visibility_badge']) ?></div><?php endif; ?>
                        </td>
                        <td><code style="font-size:0.75rem"><?= htmlspecialchars($s['uid']) ?></code></td>
                        <td style="text-align:right">
                            <form method="post" onsubmit="return confirm('Remove seller from event?');">
                                <input type="hidden" name="event_id" value="<?= (int) $selectedEvent ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="seller_id" value="<?= (int) $s['seller_id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm text-danger" <?= $canManageSellers ? '' : 'disabled' ?>><?= qb_icon('x') ?> Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
  </div>
  <?php if (!empty($pendingApplications)): ?>
  <div class="card mt-3">
    <h3 class="font-bold mb-2">Pending seller applications</h3>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Seller</th>
            <th>Categories at apply</th>
            <th>Selected products</th>
            <th>Phone</th>
            <th>Apply visibility</th>
            <th>UID</th>
            <th style="text-align:right">Review</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingApplications as $pa): ?>
          <?php
              $snap = qb_application_categories_label($pa['application_categories_json'] ?? null);
              if ($snap === '') {
                  $snap = qb_seller_categories_labels(qb_seller_categories_from_row(['categories_json' => $pa['seller_categories_json'] ?? null, 'category' => '']));
              }
              $snap = $snap !== '' ? $snap : '—';
              $prodSnap = function_exists('qb_application_products_label') ? qb_application_products_label($pa['application_products_json'] ?? null) : '';
              $prodSnap = $prodSnap !== '' ? $prodSnap : '—';
          ?>
          <tr>
            <td class="font-bold"><?= htmlspecialchars((string) ($pa['market_name'] ?: $pa['display_name'])) ?></td>
            <td class="text-sm" style="max-width:16rem"><?= htmlspecialchars($snap) ?></td>
            <td class="text-sm" style="max-width:18rem"><?= htmlspecialchars($prodSnap) ?></td>
            <td><?= htmlspecialchars((string) ($pa['phone'] ?? '')) ?></td>
            <td class="text-xs"><?= htmlspecialchars((string) (($pa['application_visibility_mode'] ?? 'selected') === 'all' ? 'All products' : 'Selected products')) ?></td>
            <td><code><?= htmlspecialchars((string) ($pa['seller_uid'] ?? '—')) ?></code></td>
            <td style="text-align:right">
              <form method="post" style="display:inline-flex;gap:0.4rem;align-items:center;flex-wrap:wrap;justify-content:flex-end">
                <input type="hidden" name="event_id" value="<?= (int) $selectedEvent ?>"/>
                <input type="hidden" name="app_user_id" value="<?= (int) $pa['app_user_id'] ?>"/>
                <input type="hidden" name="action" value="approve_application"/>
                <input type="text" name="stall_number" class="form-control" placeholder="Auto stall if empty" style="max-width:170px"/>
                <select name="participant_type" class="form-control" style="max-width:170px">
                  <option value="standard_seller">Standard</option>
                  <option value="guest_seller">Guest</option>
                  <option value="service_booth">Service booth</option>
                  <option value="sponsor_booth">Sponsor booth</option>
                </select>
                <select name="category_enforcement" class="form-control" style="max-width:155px">
                  <option value="strict">Strict category</option>
                  <option value="bypass">Bypass category</option>
                </select>
                <select name="price_policy" class="form-control" style="max-width:140px">
                  <option value="normal">Normal price</option>
                  <option value="free_only">Free only</option>
                  <option value="mixed">Mixed</option>
                </select>
                <select name="checkout_policy" class="form-control" style="max-width:145px">
                  <option value="allow_checkout">Allow checkout</option>
                  <option value="display_only">Display only</option>
                </select>
                <input type="text" name="visibility_badge" class="form-control" placeholder="Badge (optional)" style="max-width:155px"/>
                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Reject this application?')">
                <input type="hidden" name="event_id" value="<?= (int) $selectedEvent ?>"/>
                <input type="hidden" name="app_user_id" value="<?= (int) $pa['app_user_id'] ?>"/>
                <input type="hidden" name="action" value="reject_application"/>
                <button type="submit" class="btn btn-ghost btn-sm text-danger">Reject</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<div id="addSellerModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
  <div class="card" style="width:100%; max-width:400px; background:var(--bg-card);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
      <h3 class="font-bold">Add Seller</h3>
      <button type="button" onclick="document.getElementById('addSellerModal').style.display='none'" class="btn btn-ghost" style="padding:4px"><?= qb_icon('x') ?></button>
    </div>
    <form method="post">
      <input type="hidden" name="event_id" value="<?= (int) $selectedEvent ?>">
      <input type="hidden" name="action" value="add">
      <div class="form-group mb-2">
        <label class="form-label">Seller UID</label>
        <input type="text" name="seller_uid" class="form-control" placeholder="e.g. SELLER1DEMO" required>
        <p class="text-xs text-muted mt-1">Found on the seller's QR or Profile page.</p>
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Stall Number</label>
        <input type="text" name="stall_number" class="form-control" placeholder="Leave blank for auto (e.g. S-001)">
        <p class="text-xs text-muted mt-1">If left empty, system auto-assigns the next available shop number for this event.</p>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Participant type</label>
        <select name="participant_type" class="form-control">
          <option value="standard_seller">Standard seller</option>
          <option value="guest_seller">Guest seller</option>
          <option value="service_booth">Service booth</option>
          <option value="sponsor_booth">Sponsor booth</option>
        </select>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Category enforcement</label>
        <select name="category_enforcement" class="form-control">
          <option value="strict">Strict</option>
          <option value="bypass">Bypass (invited exception)</option>
        </select>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Price policy</label>
        <select name="price_policy" class="form-control">
          <option value="normal">Normal</option>
          <option value="free_only">Free only</option>
          <option value="mixed">Mixed</option>
        </select>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Checkout policy</label>
        <select name="checkout_policy" class="form-control">
          <option value="allow_checkout">Allow checkout</option>
          <option value="display_only">Display only</option>
        </select>
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Visibility badge (optional)</label>
        <input type="text" name="visibility_badge" class="form-control" placeholder="Guest / Free Service / Sponsor">
      </div>
      <button type="submit" class="btn btn-primary btn-full">Assign Seller</button>
    </form>
  </div>
</div>

<?php qb_page_end(); ?>
