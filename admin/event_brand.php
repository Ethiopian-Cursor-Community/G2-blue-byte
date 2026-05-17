<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

qb_ensure_category_schema();
$catCatalog = qb_seller_category_catalog();

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($eventId <= 0) {
    header('Location: ' . APP_URL . '/admin/events.php');
    exit;
}

$event = db()->fetchOne('SELECT * FROM bazar_events WHERE id = ?', [$eventId]);
if (!$event) {
    header('Location: ' . APP_URL . '/admin/events.php');
    exit;
}

$organizers = db()->fetchAll("SELECT id, display_name, login_uid FROM app_users WHERE role = 'organizer' ORDER BY display_name ASC");

$coOrganizerIds = [];
if (qb_table_exists('bazar_event_organizers')) {
    $rows = db()->fetchAll('SELECT app_user_id FROM bazar_event_organizers WHERE event_id = ?', [$eventId]);
    $coOrganizerIds = array_map('intval', array_column($rows, 'app_user_id'));
}

$success = '';
$error = '';

$eventIsCanceled = (($event['status'] ?? '') === 'canceled');
$eventEligible = qb_event_eligible_slugs($event['eligible_categories_json'] ?? null);
$inviteSuccess = '';
$inviteError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize((string) ($_POST['action'] ?? 'save_branding'));
    if ($action === 'invite_seller') {
        $appUserId = (int) ($_POST['invite_app_user_id'] ?? 0);
        if ($appUserId <= 0) {
            $inviteError = 'Invalid seller for invitation.';
        } else {
            $sellerRow = db()->fetchOne(
                "SELECT s.id, s.app_user_id, s.categories_json, s.category, s.market_name, s.is_active
                 FROM sellers s WHERE s.app_user_id = ? LIMIT 1",
                [$appUserId]
            );
            if (!$sellerRow || (int) ($sellerRow['is_active'] ?? 0) !== 1) {
                $inviteError = 'Seller not found or not active.';
            } elseif (!qb_seller_eligible_for_event_categories((array) $sellerRow, (array) $event)) {
                $inviteError = 'Seller categories do not match this event.';
            } else {
                $already = db()->fetchOne(
                    "SELECT id FROM event_participants
                     WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller' LIMIT 1",
                    [$eventId, $appUserId]
                );
                if ($already) {
                    $inviteSuccess = 'Seller already linked to this event.';
                } else {
                    db()->execute(
                        "INSERT INTO event_participants (event_id, app_user_id, role_in_event, status)
                         VALUES (?, ?, 'seller', 'approved')",
                        [$eventId, $appUserId]
                    );
                    $inviteSuccess = 'Seller invited and auto-approved.';
                }
            }
        }
    } elseif ($action === 'invite_top_matched') {
        $limit = max(1, min(30, (int) ($_POST['invite_limit'] ?? 5)));
        $globalTop = qb_lb_sellers_global(150);
        $rankMap = [];
        foreach ($globalTop as $r) {
            $rankMap[(int) ($r['seller_id'] ?? 0)] = (int) ($r['rank'] ?? 0);
        }
        $candidates = db()->fetchAll(
            "SELECT s.id, s.app_user_id, s.market_name, s.categories_json, s.category, s.is_active
             FROM sellers s
             WHERE s.is_active = 1
             ORDER BY s.id DESC"
        );
        $picked = [];
        foreach ($candidates as $row) {
            $sid = (int) ($row['id'] ?? 0);
            $au = (int) ($row['app_user_id'] ?? 0);
            if ($sid <= 0 || $au <= 0) {
                continue;
            }
            if (!qb_seller_eligible_for_event_categories((array) $row, (array) $event)) {
                continue;
            }
            $exists = db()->fetchOne(
                "SELECT id FROM event_participants
                 WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller' LIMIT 1",
                [$eventId, $au]
            );
            if ($exists) {
                continue;
            }
            $picked[] = [
                'seller_id' => $sid,
                'app_user_id' => $au,
                'rank' => (int) ($rankMap[$sid] ?? 999999),
            ];
        }
        usort($picked, static function (array $a, array $b): int {
            return $a['rank'] <=> $b['rank'];
        });
        $picked = array_slice($picked, 0, $limit);
        $invited = 0;
        foreach ($picked as $p) {
            db()->execute(
                "INSERT INTO event_participants (event_id, app_user_id, role_in_event, status)
                 VALUES (?, ?, 'seller', 'approved')",
                [$eventId, (int) $p['app_user_id']]
            );
            $invited++;
        }
        $inviteSuccess = $invited > 0
            ? ('Invited ' . $invited . ' matched top sellers.')
            : 'No new matched top sellers found to invite.';
    } else {
    $theme = qb_theme_hex(sanitize($_POST['theme_color'] ?? '#C48A32'));
    $marquee = qb_sanitize_plain_text((string) ($_POST['marquee_text'] ?? ''), 500);
    $orgUid = (int) ($_POST['organizer_app_user_id'] ?? 0);
    $oaStart = $_POST['organizer_active_start'] ?? '';
    $oaEnd = $_POST['organizer_active_end'] ?? '';
    $oaStart = $oaStart !== '' ? $oaStart : null;
    $oaEnd = $oaEnd !== '' ? $oaEnd : null;

    $eligibleRaw = isset($_POST['eligible_categories']) && is_array($_POST['eligible_categories']) ? $_POST['eligible_categories'] : [];
    $eligibleSlugs = [];
    foreach ($eligibleRaw as $x) {
        $s = sanitize((string) $x);
        if (isset($catCatalog[$s])) {
            $eligibleSlugs[] = $s;
        }
    }
    $eligibleSlugs = array_values(array_unique($eligibleSlugs));
    $eligibleJson = !empty($eligibleSlugs) ? json_encode($eligibleSlugs, JSON_UNESCAPED_UNICODE) : null;

    $coverPath = $event['cover_image'] ?? null;
    if (!empty($_FILES['cover_image']['tmp_name'])) {
        $up = qb_save_event_cover($_FILES['cover_image'], $eventId);
        if ($up['error']) {
            $error = $up['error'];
        } else {
            if ($coverPath) {
                qb_delete_upload_file($coverPath);
            }
            $coverPath = $up['path'];
        }
    }

    if ($error === '') {
        $hasEligCol = qb_has_column('bazar_events', 'eligible_categories_json');
        if ($hasEligCol && empty($eligibleSlugs)) {
            $error = 'Select at least one eligible seller category for this bazar.';
        }
    }

    if ($error === '') {
        $hasTheme = qb_has_column('bazar_events', 'theme_color');
        $hasEligCol = qb_has_column('bazar_events', 'eligible_categories_json');
        if ($hasTheme) {
            if ($hasEligCol) {
                db()->execute(
                    'UPDATE bazar_events SET theme_color=?, marquee_text=?, cover_image=?, organizer_app_user_id=?, organizer_active_start=?, organizer_active_end=?, eligible_categories_json=? WHERE id=?',
                    [$theme, $marquee !== '' ? $marquee : null, $coverPath, $orgUid > 0 ? $orgUid : null, $oaStart, $oaEnd, $eligibleJson, $eventId]
                );
            } else {
                db()->execute(
                    'UPDATE bazar_events SET theme_color=?, marquee_text=?, cover_image=?, organizer_app_user_id=?, organizer_active_start=?, organizer_active_end=? WHERE id=?',
                    [$theme, $marquee !== '' ? $marquee : null, $coverPath, $orgUid > 0 ? $orgUid : null, $oaStart, $oaEnd, $eventId]
                );
            }
        } elseif ($hasEligCol) {
            db()->execute(
                'UPDATE bazar_events SET organizer_app_user_id=?, eligible_categories_json=? WHERE id=?',
                [$orgUid > 0 ? $orgUid : null, $eligibleJson, $eventId]
            );
        } else {
            db()->execute(
                'UPDATE bazar_events SET organizer_app_user_id=? WHERE id=?',
                [$orgUid > 0 ? $orgUid : null, $eventId]
            );
        }
        if (qb_has_column('bazar_events', 'default_ticket_tier')) {
            $defTier = sanitize($_POST['default_ticket_tier'] ?? 'standard');
            if (!in_array($defTier, ['standard', 'premium', 'vip', 'day_pass'], true)) {
                $defTier = 'standard';
            }
            $defFace = max(0.0, (float) ($_POST['default_ticket_face_etb'] ?? 0));
            db()->execute(
                'UPDATE bazar_events SET default_ticket_tier = ?, default_ticket_face_etb = ? WHERE id = ?',
                [$defTier, $defFace, $eventId]
            );
            if (qb_has_column('tickets', 'ticket_tier')) {
                db()->execute(
                    'UPDATE tickets SET ticket_tier = ?, face_value_etb = ? WHERE event_id = ?',
                    [$defTier, $defFace, $eventId]
                );
            }
        }
        $success = 'Event branding saved.';
        $event = db()->fetchOne('SELECT * FROM bazar_events WHERE id = ?', [$eventId]);
        $eventEligible = qb_event_eligible_slugs($event['eligible_categories_json'] ?? null);
        $eventIsCanceled = (($event['status'] ?? '') === 'canceled');

        if (qb_table_exists('bazar_event_organizers') && !$eventIsCanceled) {
            $coSel = isset($_POST['co_organizer_ids']) && is_array($_POST['co_organizer_ids'])
                ? array_map('intval', $_POST['co_organizer_ids'])
                : [];
            $primary = (int) ($orgUid > 0 ? $orgUid : 0);
            db()->execute('DELETE FROM bazar_event_organizers WHERE event_id = ?', [$eventId]);
            foreach ($coSel as $cid) {
                if ($cid <= 0 || $cid === $primary) {
                    continue;
                }
                $chk = db()->fetchOne("SELECT id FROM app_users WHERE id = ? AND role = 'organizer'", [$cid]);
                if ($chk) {
                    db()->execute(
                        'INSERT IGNORE INTO bazar_event_organizers (event_id, app_user_id) VALUES (?, ?)',
                        [$eventId, $cid]
                    );
                }
            }
            $rows = db()->fetchAll('SELECT app_user_id FROM bazar_event_organizers WHERE event_id = ?', [$eventId]);
            $coOrganizerIds = array_map('intval', array_column($rows, 'app_user_id'));
        }
    }
    }
}

$globalTop = qb_lb_sellers_global(200);
$rankMap = [];
foreach ($globalTop as $r) {
    $rankMap[(int) ($r['seller_id'] ?? 0)] = (int) ($r['rank'] ?? 0);
}
$smartInviteRows = db()->fetchAll(
    "SELECT s.id AS seller_id, s.app_user_id, s.market_name, s.categories_json, s.category, s.is_active,
            u.display_name, u.phone
     FROM sellers s
     INNER JOIN app_users u ON u.id = s.app_user_id
     WHERE s.is_active = 1
     ORDER BY s.id DESC"
);
$smartInviteRows = array_values(array_filter($smartInviteRows, static function (array $row) use ($event, $eventId): bool {
    if ((int) ($row['app_user_id'] ?? 0) <= 0) {
        return false;
    }
    if (!qb_seller_eligible_for_event_categories($row, $event)) {
        return false;
    }
    $exists = db()->fetchOne(
        "SELECT id FROM event_participants WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller' LIMIT 1",
        [$eventId, (int) $row['app_user_id']]
    );
    return !$exists;
}));
foreach ($smartInviteRows as &$sir) {
    $sid = (int) ($sir['seller_id'] ?? 0);
    $sir['rank'] = (int) ($rankMap[$sid] ?? 999999);
    $sir['cat_labels'] = qb_seller_categories_labels(qb_seller_categories_from_row($sir));
}
unset($sir);
usort($smartInviteRows, static function (array $a, array $b): int {
    return ((int) ($a['rank'] ?? 999999)) <=> ((int) ($b['rank'] ?? 999999));
});
$smartInviteRows = array_slice($smartInviteRows, 0, 12);

qb_page_start('admin', 'Event branding', 'events.php', false);
?>

<div class="page-header">
  <div>
    <a href="events.php" class="text-sm text-secondary">&larr; Back to events</a>
    <h1 class="page-title mt-1"><?= htmlspecialchars($event['name']) ?></h1>
    <p class="page-subtitle">Theme color, cover image, marquee text, and organizer access window.</p>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($inviteSuccess): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($inviteSuccess) ?></div><?php endif; ?>
<?php if ($inviteError): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($inviteError) ?></div><?php endif; ?>

<div class="card" style="max-width:720px">
  <form method="post" enctype="multipart/form-data">
    <div class="form-group mb-2">
      <label class="form-label">Theme color</label>
      <p class="text-xs text-muted mb-2">Used on the buyer home, ticket list accent, and event cards.</p>
      <div class="qb-theme-color-row" style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
        <input type="color" id="qb_theme_picker" class="qb-theme-color-swatch"
          value="<?= htmlspecialchars(qb_theme_hex($event['theme_color'] ?? '')) ?>"
          title="Pick color" aria-label="Pick theme color"/>
        <input type="text" name="theme_color" id="qb_theme_hex" class="form-control" style="max-width:11rem"
          value="<?= htmlspecialchars(qb_theme_hex($event['theme_color'] ?? '')) ?>" placeholder="#C48A32"
          pattern="#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})" autocomplete="off"/>
      </div>
    </div>
    <div class="form-group mb-2">
      <label class="form-label">Marquee text (buyer home)</label>
      <input type="text" name="marquee_text" class="form-control" value="<?= qb_esc_html($event['marquee_text'] ?? '') ?>" placeholder="Short line for scrolling banner"/>
    </div>
    <div class="form-group mb-2">
      <label class="form-label">Cover image (JPEG/PNG, max 4MB)</label>
      <p class="text-xs text-muted mb-2">Shown on the buyer home event card and on the <strong>printed ticket</strong> (event name, venue/city, and this image).</p>
      <?php if (!empty($event['cover_image'])): ?>
        <div class="mb-2"><img src="<?= htmlspecialchars(qb_public_upload_url($event['cover_image'])) ?>" alt="" style="max-width:100%;max-height:160px;border-radius:12px;object-fit:cover"/></div>
      <?php endif; ?>
      <input type="file" name="cover_image" class="form-control" accept="image/jpeg,image/png"/>
    </div>
    <?php if (qb_has_column('bazar_events', 'default_ticket_tier')): ?>
    <div class="form-group mb-2">
      <label class="form-label">Ticket tier (printed ticket style)</label>
      <p class="text-xs text-muted mb-2">Applies to <strong>all tickets</strong> for this event. Save to update existing buyer tickets too.</p>
      <select name="default_ticket_tier" class="form-control">
        <?php $curT = (string) ($event['default_ticket_tier'] ?? 'standard'); ?>
        <option value="standard" <?= $curT === 'standard' ? 'selected' : '' ?>>Standard (single entry)</option>
        <option value="premium" <?= $curT === 'premium' ? 'selected' : '' ?>>Premium (full event)</option>
        <option value="vip" <?= $curT === 'vip' ? 'selected' : '' ?>>VIP (full event)</option>
        <option value="day_pass" <?= $curT === 'day_pass' ? 'selected' : '' ?>>Day pass (event day only)</option>
      </select>
    </div>
    <div class="form-group mb-2">
      <label class="form-label">Ticket face value (ETB)</label>
      <input type="number" name="default_ticket_face_etb" class="form-control" min="0" step="0.01"
        value="<?= htmlspecialchars((string) ($event['default_ticket_face_etb'] ?? '0')) ?>" placeholder="0 = complimentary"/>
    </div>
    <?php endif; ?>
    <div class="form-group mb-2">
      <label class="form-label">Assigned organizer</label>
      <select name="organizer_app_user_id" class="form-control">
        <option value="0">— None —</option>
        <?php foreach ($organizers as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= ((int)($event['organizer_app_user_id'] ?? 0) === (int)$o['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($o['display_name'] . ' (' . $o['login_uid'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (qb_table_exists('bazar_event_organizers')): ?>
      <?php if ($eventIsCanceled): ?>
        <div class="alert alert-warning mb-2">
          This event is <strong>canceled</strong>. Co-organizer assignments are not available; any previous co-organizers were cleared when the event was canceled.
        </div>
      <?php else: ?>
    <div class="form-group mb-2">
      <label class="form-label">Co-organizers (optional)</label>
      <p class="text-xs text-muted mb-2">Selected organizers can manage this event alongside the primary. Hold Ctrl/Cmd to pick more than one. Give each worker an <strong>organizer</strong> account (Users → role organizer) so they can log in to the organizer portal.</p>
      <select name="co_organizer_ids[]" class="form-control" multiple size="<?= min(8, max(3, count($organizers))) ?>" style="min-height:7rem">
        <?php foreach ($organizers as $o):
            $oid = (int) $o['id'];
            if ($oid === (int) ($event['organizer_app_user_id'] ?? 0)) {
                continue;
            }
            ?>
          <option value="<?= $oid ?>" <?= in_array($oid, $coOrganizerIds, true) ? 'selected' : '' ?>>
            <?= htmlspecialchars($o['display_name'] . ' (' . $o['login_uid'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="grid grid-2 gap-2 mb-2">
      <div class="form-group">
        <label class="form-label">Organizer portal opens</label>
        <input type="datetime-local" name="organizer_active_start" class="form-control"
          value="<?= !empty($event['organizer_active_start']) ? date('Y-m-d\TH:i', strtotime($event['organizer_active_start'])) : '' ?>"/>
      </div>
      <div class="form-group">
        <label class="form-label">Organizer portal closes</label>
        <input type="datetime-local" name="organizer_active_end" class="form-control"
          value="<?= !empty($event['organizer_active_end']) ? date('Y-m-d\TH:i', strtotime($event['organizer_active_end'])) : '' ?>"/>
      </div>
    </div>
    <p class="text-xs text-muted mb-3">If left empty, the system falls back to the event start/end dates for access checks.</p>

    <div class="form-group mb-3">
      <label class="form-label">Eligible seller categories</label>
      <p class="text-xs text-muted mb-2">Sellers must have at least one matching stall category to be assigned to this bazar by organizers.</p>
      <details class="qb-select-compact">
        <summary>
          <span>Select eligible categories</span>
          <span class="text-xs text-muted" id="qb-admin-elig-count"><?= count($eventEligible) ?> selected</span>
        </summary>
        <div class="qb-event-elig-grid mt-2">
          <?php foreach ($catCatalog as $slug => $label): ?>
          <label class="qb-event-elig">
            <input type="checkbox" name="eligible_categories[]" value="<?= htmlspecialchars($slug) ?>" data-qb-admin-elig-cb
              <?= in_array($slug, $eventEligible, true) ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($label) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </details>
    </div>

    <button type="submit" class="btn btn-primary">Save branding</button>
  </form>
</div>

<div class="card mt-3" style="max-width:920px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
    <div>
      <h3 class="font-bold mb-1">Smart seller invites</h3>
      <p class="text-xs text-muted mb-2">Invite special sellers automatically based on category match and recent leaderboard achievement.</p>
    </div>
    <form method="post" style="display:flex;gap:0.5rem;align-items:center">
      <input type="hidden" name="action" value="invite_top_matched"/>
      <label class="text-xs text-muted">Top</label>
      <select name="invite_limit" class="form-control" style="max-width:90px">
        <option value="3">3</option>
        <option value="5" selected>5</option>
        <option value="10">10</option>
        <option value="15">15</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Invite top matched</button>
    </form>
  </div>
  <div class="table-wrapper mt-2">
    <table>
      <thead>
        <tr>
          <th>Seller</th>
          <th>Categories</th>
          <th>Leaderboard</th>
          <th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($smartInviteRows)): ?>
        <tr><td colspan="4" class="text-center text-muted">No eligible sellers available to invite right now.</td></tr>
      <?php else: foreach ($smartInviteRows as $row): ?>
        <tr>
          <td>
            <div class="font-bold"><?= htmlspecialchars((string) ($row['market_name'] ?: $row['display_name'])) ?></div>
            <div class="text-xs text-muted"><?= htmlspecialchars((string) ($row['phone'] ?? '')) ?></div>
          </td>
          <td class="text-xs"><?= htmlspecialchars((string) ($row['cat_labels'] ?: 'General')) ?></td>
          <td>
            <?php if ((int) ($row['rank'] ?? 999999) < 999999): ?>
              <span class="badge badge-blue">Top #<?= (int) $row['rank'] ?></span>
            <?php else: ?>
              <span class="badge badge-gray">No rank yet</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="invite_seller"/>
              <input type="hidden" name="invite_app_user_id" value="<?= (int) $row['app_user_id'] ?>"/>
              <button type="submit" class="btn btn-secondary btn-sm">Invite</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
.qb-theme-color-swatch {
  width: 3rem;
  height: 2.5rem;
  padding: 0;
  border: 1px solid var(--border);
  border-radius: 10px;
  cursor: pointer;
  background: transparent;
}
.qb-select-compact {
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--bg-soft);
  padding: 0.65rem 0.75rem;
}
.qb-select-compact summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  list-style: none;
  font-weight: 600;
}
.qb-select-compact summary::-webkit-details-marker { display: none; }
</style>
<script>
(function () {
  var p = document.getElementById('qb_theme_picker');
  var h = document.getElementById('qb_theme_hex');
  if (!p || !h) return;
  function expandHex(v) {
    v = (v || '').trim();
    if (v.length && v[0] !== '#') v = '#' + v;
    if (/^#[0-9a-fA-F]{4}$/.test(v)) {
      return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    }
    return v;
  }
  p.addEventListener('input', function () { h.value = p.value; });
  h.addEventListener('input', function () {
    var x = expandHex(h.value);
    if (/^#[0-9a-fA-F]{6}$/.test(x)) p.value = x;
  });
  h.addEventListener('change', function () {
    var x = expandHex(h.value);
    if (/^#[0-9a-fA-F]{6}$/.test(x)) { h.value = x; p.value = x; }
  });
})();

(function () {
  var countEl = document.getElementById('qb-admin-elig-count');
  function syncCount() {
    if (!countEl) return;
    var n = document.querySelectorAll('[data-qb-admin-elig-cb]:checked').length;
    countEl.textContent = n + ' selected';
  }
  document.querySelectorAll('[data-qb-admin-elig-cb]').forEach(function (el) {
    el.addEventListener('change', syncCount);
  });
  syncCount();
})();
</script>

<?php qb_page_end(); ?>
