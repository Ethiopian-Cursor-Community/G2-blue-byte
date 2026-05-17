<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

qb_ensure_category_schema();
qb_apply_event_special_access_schema();

$seller = getCurrentSeller();
$appUserId = (int) ($_SESSION['app_user_id'] ?? 0);
$msg = '';
$err = '';
$ap = qb_sql_product_approved_plain();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $paymentMethod = (string) ($_POST['payment_method'] ?? 'chapa');
    if ($eventId <= 0) {
        $err = 'Invalid event.';
    } else {
        $ev = db()->fetchOne(
            "SELECT id, status, eligible_categories_json, COALESCE(max_sellers, 50) AS max_sellers
             FROM bazar_events
             WHERE id = ? AND status IN ('published','live')",
            [$eventId]
        );
        if (!$ev) {
            $err = 'This event is not open for applications.';
        } else {
            $exists = db()->fetchOne(
                "SELECT id, status FROM event_participants WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller' LIMIT 1",
                [$eventId, $appUserId]
            );
            if ($exists) {
                $msg = 'You already have an application or assignment for this event.';
            } else {
                $used = qb_event_assigned_seller_count($eventId);
                $maxSellers = (int) ($ev['max_sellers'] ?? 50);
                $open = max(0, $maxSellers - $used);
                if ($open <= 0) {
                    $err = 'This bazar has no open seller slots left. Ask the organizer to raise capacity or wait for a spot.';
                } else {
                    if ($paymentMethod !== 'chapa') {
                        $err = 'Only Chapa payment is enabled.';
                    } elseif (!qb_chapa_ready()) {
                        $err = 'Chapa is not configured yet.';
                    } else {
                        $eligibleSlugs = qb_event_eligible_slugs($ev['eligible_categories_json'] ?? null);
                        $prodSql = "SELECT id, name, category
                                    FROM products
                                    WHERE seller_id = ? AND is_available = 1 AND ($ap)";
                        $prodParams = [$seller['id']];
                        if ($eligibleSlugs !== []) {
                            $phCats = implode(',', array_fill(0, count($eligibleSlugs), '?'));
                            $prodSql .= " AND category IN ($phCats)";
                            foreach ($eligibleSlugs as $slug) {
                                $prodParams[] = $slug;
                            }
                        }
                        $compatibleProducts = db()->fetchAll($prodSql . ' ORDER BY name ASC', $prodParams);
                        if ($compatibleProducts === []) {
                            $err = 'You have no compatible approved products for this event category. Add matching products first.';
                        }
                    }
                    $applyVisibilityMode = (string) ($_POST['visibility_mode'] ?? 'selected');
                    if (!in_array($applyVisibilityMode, ['selected', 'all'], true)) {
                        $applyVisibilityMode = 'selected';
                    }
                    if ($err === '') {
                        $selectedIdsRaw = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];
                        $selectedMap = [];
                        foreach ($selectedIdsRaw as $pidRaw) {
                            $pid = (int) $pidRaw;
                            if ($pid > 0) {
                                $selectedMap[$pid] = true;
                            }
                        }
                        $selectedProducts = [];
                        foreach ($compatibleProducts as $cp) {
                            $pid = (int) ($cp['id'] ?? 0);
                            if ($pid > 0 && isset($selectedMap[$pid])) {
                                $selectedProducts[] = [
                                    'id' => $pid,
                                    'name' => (string) ($cp['name'] ?? 'Product'),
                                    'category' => (string) ($cp['category'] ?? ''),
                                ];
                            }
                        }
                        if ($applyVisibilityMode === 'selected' && $selectedProducts === []) {
                            $err = 'Select at least one compatible product for this event.';
                        }
                    }
                    if ($err === '') {
                        $sellerEventFeeEtb = qb_setting_get_float('seller_event_fee_etb', (float) CHAPA_SELLER_EVENT_FEE_ETB);
                        $intent = qb_payment_intent_create(
                            $appUserId,
                            'seller_event_apply',
                            'event:' . $eventId,
                            $sellerEventFeeEtb,
                            [
                                'event_id' => $eventId,
                                'application_visibility_mode' => $applyVisibilityMode,
                                'application_product_ids' => array_map(static fn(array $p): int => (int) $p['id'], $selectedProducts),
                                'application_products' => $selectedProducts,
                            ]
                        );
                        $intentRow = qb_payment_intent_get((string) $intent['intent_id']);
                        if (!$intentRow) {
                            $err = 'Could not create payment intent.';
                        } else {
                            $u = currentUser() ?? [];
                            $start = qb_chapa_checkout_start($intentRow, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? 'Seller'), (string) ($u['phone'] ?? ''));
                            if (!$start['ok']) {
                                qb_audit_log('payment.chapa.init_failed', 'payment_intents', (int) ($intentRow['id'] ?? 0), [
                                    'flow' => 'seller_event_apply',
                                    'event_id' => (int) $eventId,
                                    'error' => (string) ($start['error'] ?? 'unknown'),
                                ]);
                                $nextUrl = APP_URL . '/seller/events.php';
                                $failUrl = APP_URL . '/buyer/payment_result.php?intent=' . rawurlencode((string) ($intentRow['intent_id'] ?? '')) . '&status=failed&error=' . rawurlencode((string) ($start['error'] ?? 'Could not start Chapa checkout.')) . '&next=' . rawurlencode($nextUrl);
                                header('Location: ' . $failUrl, true, 302);
                                exit;
                            } else {
                                header('Location: ' . (string) $start['checkout_url'], true, 302);
                                exit;
                            }
                        }
                    }
                }
            }
        }
    }
}

$events = db()->fetchAll(
    "SELECT e.id, e.name, e.city, e.venue, e.status, e.event_start, e.eligible_categories_json,
            COALESCE(e.max_sellers, 50) AS max_sellers,
            (SELECT COUNT(DISTINCT st.seller_id) FROM stalls st WHERE st.event_id = e.id) AS assigned_sellers,
            ep.status AS my_status
     FROM bazar_events e
     LEFT JOIN event_participants ep
       ON ep.event_id = e.id AND ep.app_user_id = ? AND ep.role_in_event = 'seller'
     WHERE e.status IN ('published','live')
     ORDER BY e.event_start ASC, e.id DESC",
    [$appUserId]
);
$compatibleProductsByEvent = [];
foreach ($events as $evt) {
    $eid = (int) ($evt['id'] ?? 0);
    if ($eid <= 0) {
        continue;
    }
    $eligibleSlugs = qb_event_eligible_slugs($evt['eligible_categories_json'] ?? null);
    $prodSql = "SELECT id, name, category
                FROM products
                WHERE seller_id = ? AND is_available = 1 AND ($ap)";
    $prodParams = [$seller['id']];
    if ($eligibleSlugs !== []) {
        $phCats = implode(',', array_fill(0, count($eligibleSlugs), '?'));
        $prodSql .= " AND category IN ($phCats)";
        foreach ($eligibleSlugs as $slug) {
            $prodParams[] = $slug;
        }
    }
    $compatibleProductsByEvent[$eid] = db()->fetchAll($prodSql . ' ORDER BY name ASC LIMIT 120', $prodParams);
}

qb_page_start('seller', 'Apply to Events', 'events.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Apply to Events</h1>
    <p class="page-subtitle">Each row shows this bazar&rsquo;s allowed stall types. When you apply, your profile categories are saved on the request for the organizer. Open slots are per event.</p>
  </div>
</div>

<?php if ($msg !== ''): ?><div class="alert alert-success mb-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert alert-danger mb-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead>
      <tr>
        <th>Event</th>
        <th>Location</th>
        <th>When</th>
        <th>Bazar categories</th>
        <th>Open slots</th>
        <th>Status</th>
        <th style="text-align:right">Action</th>
      </tr>
      </thead>
      <tbody>
      <?php if (empty($events)): ?>
        <tr><td colspan="7" class="text-center text-muted">No open events right now.</td></tr>
      <?php else: foreach ($events as $e): ?>
        <?php
          $my = (string) ($e['my_status'] ?? '');
          $badge = $my === 'approved' ? 'badge-green' : ($my === 'pending' ? 'badge-amber' : ($my === 'rejected' ? 'badge-red' : 'badge-gray'));
          $maxS = (int) ($e['max_sellers'] ?? 50);
          $asg = (int) ($e['assigned_sellers'] ?? 0);
          $openSlots = max(0, $maxS - $asg);
          $eligible = qb_event_eligible_slugs($e['eligible_categories_json'] ?? null);
          $eventCatLabels = $eligible !== []
              ? qb_event_eligible_categories_label($e['eligible_categories_json'] ?? null)
              : 'All stall types welcome';
          $catLabels = htmlspecialchars($eventCatLabels, ENT_QUOTES, 'UTF-8');
          $mine = qb_seller_categories_from_row((array) $seller);
          $matchHint = '';
          if ($eligible !== []) {
              $ok = qb_seller_eligible_for_event_categories((array) $seller, (array) $e);
              $matchHint = $ok
                  ? '<span class="text-xs text-muted">Your stall matches this list</span>'
                  : '<span class="text-xs" style="color:#b45309">Your stall may not match — organizer decides</span>';
          } else {
              $matchHint = '<span class="text-xs text-muted">No category restriction set for this bazar</span>';
          }
        ?>
        <tr>
          <td class="font-bold"><?= htmlspecialchars((string) $e['name']) ?></td>
          <td><?= htmlspecialchars((string) $e['venue']) ?> · <?= htmlspecialchars((string) $e['city']) ?></td>
          <td><?= !empty($e['event_start']) ? htmlspecialchars(date('D, M j · g:i A', strtotime((string) $e['event_start']))) : 'TBD' ?></td>
          <td class="text-sm">
            <div><?= $catLabels ?></div>
            <?= $matchHint ?>
          </td>
          <td class="text-sm">
            <strong><?= (int) $openSlots ?></strong> <span class="text-muted">/ <?= (int) $maxS ?> max</span>
          </td>
          <td><span class="badge <?= $badge ?>"><?= $my !== '' ? htmlspecialchars($my) : 'not applied' ?></span></td>
          <td style="text-align:right">
            <?php if ($my === ''): ?>
              <?php if ($openSlots <= 0): ?>
              <span class="text-xs text-muted">Full</span>
              <?php else: ?>
              <?php $compatible = $compatibleProductsByEvent[(int) ($e['id'] ?? 0)] ?? []; ?>
              <?php if ($compatible === []): ?>
                <span class="text-xs text-muted">No compatible products</span>
              <?php else: ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="event_id" value="<?= (int) $e['id'] ?>"/>
                <input type="hidden" name="payment_method" value="chapa"/>
                <label class="text-xs text-muted" style="display:inline-flex;gap:.35rem;align-items:center;margin-bottom:.35rem">
                  <input type="checkbox" name="visibility_mode" value="all">
                  Make all my products visible in this event (organizer may still review)
                </label>
                <select name="product_ids[]" class="form-control mb-1" multiple size="<?= count($compatible) > 3 ? 3 : max(1, count($compatible)) ?>" style="min-width:220px">
                  <?php foreach ($compatible as $cp): ?>
                  <option value="<?= (int) ($cp['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($cp['name'] ?? 'Product')) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="text-xs text-muted mb-1">If checkbox is unticked, selected items only are submitted. Payment: Chapa.</div>
                <button class="btn btn-primary btn-sm" type="submit">Apply</button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-xs text-muted">No action</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php qb_page_end(); ?>
