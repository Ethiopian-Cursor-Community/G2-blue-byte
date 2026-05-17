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
qb_apply_event_ticket_pricing_schema();
qb_apply_event_special_access_schema();
$approvalSchema = qb_event_approval_schema_ready();
if (!$approvalSchema) {
    $ar = qb_apply_event_approval_schema();
    $approvalSchema = !empty($ar['ok']) && qb_event_approval_schema_ready();
}
$cityOptions = qb_ethiopian_cities();
$catCatalog = qb_seller_category_catalog();

$uid = (int)$_SESSION['app_user_id'];
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$event = null;
$coOnlyAccount = qb_organizer_is_co_only($uid);
$canEditCore = !$coOnlyAccount;
$currentRole = (string) (currentRole() ?? '');

if ($eventId) {
    $w = qb_organizer_bazar_events_access_sql();
    $bind = qb_organizer_event_access_bind($uid);
    $event = db()->fetchOne(
        "SELECT * FROM bazar_events WHERE id = ? AND $w",
        array_merge([$eventId], $bind)
    );
    if (!$event) {
        header('Location: ' . APP_URL . '/organizer/dashboard.php?notice=event_not_found', true, 302);
        exit;
    }
    $canEditCore = qb_organizer_can_edit_event_core($uid, $event);
}
$eventOwnershipLabel = '';
if ($eventId > 0 && $event) {
    $eventOwnershipLabel = ((int) ($event['organizer_app_user_id'] ?? 0) === $uid)
        ? 'Primary'
        : 'Assigned Co-organizer';
}

$success = '';
$error = '';

$eventEligible = $event ? qb_event_eligible_slugs($event['eligible_categories_json'] ?? null) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($eventId && $event && (($event['status'] ?? '') === 'canceled')) {
        $error = 'This bazar was canceled by the admin. Details are read-only.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if ($eventId > 0 && !$canEditCore) {
        $error = 'Co-organizers can manage operations, but only the primary organizer can edit core event settings.';
    } elseif ($eventId <= 0 && $coOnlyAccount) {
        $error = 'Co-organizer accounts cannot create new events. Ask an admin to assign you as primary organizer.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $name = sanitize($_POST['name']);
    $slug = sanitize($_POST['slug']);
    $venue = sanitize($_POST['venue']);
    $city = sanitize($_POST['city'] ?? '');
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
    $lat = ($_POST['lat'] ?? '') !== '' ? (float) $_POST['lat'] : null;
    $lng = ($_POST['lng'] ?? '') !== '' ? (float) $_POST['lng'] : null;
    $radius = isset($event['radius_meters']) ? (int) ($event['radius_meters'] ?? 500) : 500;
    $isDraftToggle = !empty($_POST['is_draft']);
    $maxSellers = (int)($_POST['max_sellers'] ?? 50);
    $notes = sanitize($_POST['notes']);
    $liveAudioUrl = null;
    $liveAudioTitle = null;
    $liveAudioArtist = null;
    $liveAudioCover = null;
    $primaryRules = trim((string) ($_POST['primary_rules'] ?? ''));
    $standardTicketPrice = (float) ($_POST['standard_ticket_price_etb'] ?? 0);
    $premiumTicketPrice = (float) ($_POST['premium_ticket_price_etb'] ?? 0);
    $eventStartRaw = trim((string) ($_POST['event_start'] ?? ''));
    $eventEndRaw = trim((string) ($_POST['event_end'] ?? ''));
    $eventStartTs = $eventStartRaw !== '' ? strtotime($eventStartRaw) : false;
    $eventEndTs = $eventEndRaw !== '' ? strtotime($eventEndRaw) : false;
    $eventStart = ($eventStartRaw !== '' && $eventStartTs !== false) ? date('Y-m-d H:i:s', $eventStartTs) : null;
    $eventEnd = ($eventEndRaw !== '' && $eventEndTs !== false) ? date('Y-m-d H:i:s', $eventEndTs) : null;

    // Status automation logic
    $now = time();
    if ($isDraftToggle) {
        $status = 'draft';
    } else {
        if ($eventStartTs === false) {
            $status = 'draft';
        } elseif ($now < $eventStartTs) {
            $status = 'published';
        } elseif ($eventEndTs === false || $now < $eventEndTs) {
            $status = 'live';
        } else {
            $status = 'ended';
        }
    }

    if (!$name || !$slug || $city === '' || !in_array($city, $cityOptions, true)) {
        $error = 'Name, slug, and a valid city are required.';
    } elseif ($eventStartRaw !== '' && $eventStartTs === false) {
        $error = 'Event start date is invalid.';
    } elseif ($eventEndRaw !== '' && $eventEndTs === false) {
        $error = 'Event end date is invalid.';
    } elseif (!$isDraftToggle && $eventStartTs !== false && $eventStartTs < ($now - 60)) {
        // Allow 1 minute grace for submission latency
        $error = 'Starting day cannot be in the past.';
    } elseif ($eventStartRaw !== '' && $eventEndRaw !== '' && $eventEndTs < $eventStartTs) {
        $error = 'Event end must be after event start.';
    } elseif ($standardTicketPrice < 0 || $premiumTicketPrice < 0) {
        $error = 'Ticket prices cannot be negative.';
    } elseif ($premiumTicketPrice < $standardTicketPrice) {
        $error = 'Premium ticket price should be greater than or equal to standard.';
    } elseif (empty($eligibleSlugs)) {
        $error = 'Select at least one eligible seller category for this bazar.';
    } else {
        if ($eventId) {
            $w = qb_organizer_bazar_events_access_sql();
            $bind = qb_organizer_event_access_bind($uid);
            $hasElig = qb_has_column('bazar_events', 'eligible_categories_json');
            $approvalStatusCurrent = (string) ($event['approval_status'] ?? 'approved');
            if ($hasElig) {
                if ($approvalSchema) {
                    $nextApproval = in_array($approvalStatusCurrent, ['pending', 'rejected'], true) ? 'pending' : $approvalStatusCurrent;
                    db()->execute(
                        "
                    UPDATE bazar_events
                    SET name=?, slug=?, venue=?, city=?, lat=?, lng=?, radius_meters=?, max_sellers=?, status=?, notes=?, event_start=?, event_end=?, eligible_categories_json=?, approval_status='approved', approval_note='Auto-approved'
                    WHERE id=? AND $w
                ",
                        array_merge(
                            [$name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $status, $notes, $eventStart, $eventEnd, $eligibleJson, $eventId],
                            $bind
                        )
                    );
                } else {
                    db()->execute(
                        "
                    UPDATE bazar_events 
                    SET name=?, slug=?, venue=?, city=?, lat=?, lng=?, radius_meters=?, max_sellers=?, status=?, notes=?, event_start=?, event_end=?, eligible_categories_json=?
                    WHERE id=? AND $w
                ",
                        array_merge(
                            [$name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $status, $notes, $eventStart, $eventEnd, $eligibleJson, $eventId],
                            $bind
                        )
                    );
                }
            } else {
                if ($approvalSchema) {
                    $nextApproval = in_array($approvalStatusCurrent, ['pending', 'rejected'], true) ? 'pending' : $approvalStatusCurrent;
                    db()->execute(
                        "
                    UPDATE bazar_events
                    SET name=?, slug=?, venue=?, city=?, lat=?, lng=?, radius_meters=?, max_sellers=?, status=?, notes=?, event_start=?, event_end=?, approval_status='approved', approval_note='Auto-approved'
                    WHERE id=? AND $w
                ",
                        array_merge(
                            [$name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $status, $notes, $eventStart, $eventEnd, $eventId],
                            $bind
                        )
                    );
                } else {
                    db()->execute(
                        "
                    UPDATE bazar_events 
                    SET name=?, slug=?, venue=?, city=?, lat=?, lng=?, radius_meters=?, max_sellers=?, status=?, notes=?, event_start=?, event_end=?
                    WHERE id=? AND $w
                ",
                        array_merge(
                            [$name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $status, $notes, $eventStart, $eventEnd, $eventId],
                            $bind
                        )
                    );
                }
            }
            $success = "Event updated!";
            $event = db()->fetchOne("SELECT * FROM bazar_events WHERE id = ?", [$eventId]);
            $eventEligible = $event ? qb_event_eligible_slugs($event['eligible_categories_json'] ?? null) : [];
        } else {
            try {
                $createStatus = 'draft';
                if (qb_has_column('bazar_events', 'eligible_categories_json')) {
                    if ($approvalSchema) {
                        db()->execute("
                        INSERT INTO bazar_events 
                        (organizer_app_user_id, name, slug, venue, city, lat, lng, radius_meters, max_sellers, status, notes, event_start, event_end, eligible_categories_json, approval_status, approval_note)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 'Auto-approved')
                    ", [$uid, $name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $createStatus, $notes, $eventStart, $eventEnd, $eligibleJson]);
                    } else {
                        db()->execute("
                        INSERT INTO bazar_events 
                        (organizer_app_user_id, name, slug, venue, city, lat, lng, radius_meters, max_sellers, status, notes, event_start, event_end, eligible_categories_json)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [$uid, $name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $createStatus, $notes, $eventStart, $eventEnd, $eligibleJson]);
                    }
                } else {
                    db()->execute("
                    INSERT INTO bazar_events 
                    (organizer_app_user_id, name, slug, venue, city, lat, lng, radius_meters, max_sellers, status, notes, event_start, event_end)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [$uid, $name, $slug, $venue, $city, $lat, $lng, $radius, $maxSellers, $createStatus, $notes, $eventStart, $eventEnd]);
                }
                $eventId = db()->lastInsertId();
                header("Location: event.php?id=$eventId&success=created");
                exit;
            } catch (PDOException $e) {
                $error = "Slug must be unique.";
            }
        }
        if ($error === '' && $eventId > 0 && !empty($_FILES['cover_image']['tmp_name']) && qb_has_column('bazar_events', 'cover_image')) {
            $up = qb_save_event_cover($_FILES['cover_image'], (int) $eventId);
            if (!empty($up['error'])) {
                $error = (string) $up['error'];
            } else {
                db()->execute('UPDATE bazar_events SET cover_image = ? WHERE id = ?', [(string) $up['path'], (int) $eventId]);
            }
        }
        if ($error === '' && $eventId > 0) {
            db()->execute(
                'UPDATE bazar_events SET standard_ticket_price_etb = ?, premium_ticket_price_etb = ?, primary_rules = ? WHERE id = ?',
                [$standardTicketPrice, $premiumTicketPrice, $primaryRules !== '' ? $primaryRules : null, (int) $eventId]
            );
        }
        if ($error === '' && $eventId > 0) {
            $event = db()->fetchOne("SELECT * FROM bazar_events WHERE id = ?", [$eventId]);
            $eventEligible = $event ? qb_event_eligible_slugs($event['eligible_categories_json'] ?? null) : [];
        }
    }
}
if(isset($_GET['success']) && !$_POST) $success = "Event created successfully!";

qb_page_start('organizer', $event ? 'Edit Event' : 'New Event', 'event.php', false);
?>

<!-- Leaflet for location picker -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="page-header">
  <div>
    <a href="dashboard.php" class="btn btn-ghost btn-sm" style="padding-left:0">&larr; Back</a>
    <h1 class="page-title mt-1"><?= $event ? 'Configure Event' : 'Create New Event' ?></h1>
    <div class="qb-role-identity-stack mt-1">
      <span class="badge badge-blue">Role: <?= $currentRole === 'co_organizer' ? 'Co-organizer' : 'Organizer' ?></span>
      <?php if ($eventOwnershipLabel !== ''): ?>
      <span class="badge <?= $eventOwnershipLabel === 'Primary' ? 'badge-blue' : 'badge-violet' ?>">
        Event ownership: <?= htmlspecialchars($eventOwnershipLabel) ?>
      </span>
      <?php elseif ($coOnlyAccount): ?>
      <span class="badge badge-violet">Event ownership: Assigned Co-organizer</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php
$eventCanceled = $event && (($event['status'] ?? '') === 'canceled');
$formReadOnly = $eventCanceled || !$canEditCore || ($eventId <= 0 && $coOnlyAccount);
?>
<?php if ($eventCanceled): ?>
<div class="alert alert-warning mb-3" role="status">This bazar was <strong>canceled</strong> by the admin. You can review details below; saving changes is disabled.</div>
<?php endif; ?>
<?php if (!$canEditCore && $eventId > 0): ?>
<div class="alert alert-info mb-3" role="status">You are assigned as a <strong>co-organizer</strong> for this event. Event settings are read-only here; use Sellers, Staff, Announcements, and Scan tools for operations.</div>
<?php endif; ?>
<?php if ($coOnlyAccount && $eventId <= 0): ?>
<div class="alert alert-info mb-3" role="status">Your account is configured as <strong>co-organizer</strong>. Event creation is disabled; request primary organizer assignment to create new events.</div>
<?php endif; ?>

<div class="grid grid-2 gap-3">
  <!-- Left Form -->
  <div class="card">
    <h3 class="font-bold mb-2">Event Details</h3>
    <form method="post" enctype="multipart/form-data">
      <fieldset <?= $formReadOnly ? 'disabled' : '' ?> style="border:none;padding:0;margin:0">
      <div class="form-group mb-2">
        <label class="form-label">Event Name</label>
        <input type="text" name="name" class="form-control" value="<?= qb_esc_html($event['name'] ?? '') ?>" required>
      </div>
      <div class="grid grid-2 gap-2 mb-2">
        <div class="form-group">
          <label class="form-label">URL Slug (Unique)</label>
          <input type="text" name="slug" class="form-control" value="<?= qb_esc_html($event['slug'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Save as Draft</label>
          <div style="display:flex;align-items:center;gap:0.5rem;height:calc(var(--input-height, 42px))">
            <input type="checkbox" name="is_draft" id="is_draft_toggle" value="1" <?= (($event['status'] ?? 'draft') === 'draft') ? 'checked' : '' ?> style="width:20px;height:20px">
            <label for="is_draft_toggle" class="text-sm text-secondary">Draft mode (hidden from buyers)</label>
          </div>
          <?php if ($event && ($event['status'] ?? '') !== 'draft'): ?>
          <div class="text-xs text-muted mt-1">Current status: <span class="badge badge-gray"><?= htmlspecialchars((string) $event['status']) ?></span></div>
          <?php endif; ?>
          <?php if ($approvalSchema && $event && in_array((string) ($event['approval_status'] ?? ''), ['pending', 'rejected'], true)): ?>
          <div class="text-xs text-muted mt-1">Status unlocks after admin approval.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="grid grid-2 gap-2 mb-2">
        <div class="form-group">
          <label class="form-label">City</label>
          <select name="city" class="form-control" required>
            <option value="">— Select city —</option>
            <?php foreach ($cityOptions as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= (($event['city'] ?? '') === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Venue</label>
          <input type="text" name="venue" class="form-control" value="<?= qb_esc_html($event['venue'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Max Sellers</label>
        <input type="number" name="max_sellers" class="form-control" value="<?= $event['max_sellers'] ?? 50 ?>">
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Notes / Description</label>
        <textarea name="notes" class="form-control" rows="3"><?= qb_esc_html($event['notes'] ?? '') ?></textarea>
      </div>
      <div class="grid grid-2 gap-2 mb-2">
        <div class="form-group">
          <label class="form-label">Standard ticket price (ETB)</label>
          <input type="number" name="standard_ticket_price_etb" class="form-control" min="0" step="0.01"
            value="<?= htmlspecialchars((string) ($_POST['standard_ticket_price_etb'] ?? ($event['standard_ticket_price_etb'] ?? '0'))) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Premium ticket price (ETB)</label>
          <input type="number" name="premium_ticket_price_etb" class="form-control" min="0" step="0.01"
            value="<?= htmlspecialchars((string) ($_POST['premium_ticket_price_etb'] ?? ($event['premium_ticket_price_etb'] ?? '0'))) ?>">
        </div>
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Primary rules for buyers</label>
        <textarea name="primary_rules" class="form-control" rows="3" placeholder="Example: No outside food. Keep your ticket ready. Follow queue order."><?= htmlspecialchars((string) ($_POST['primary_rules'] ?? ($event['primary_rules'] ?? ''))) ?></textarea>
      </div>
      <div class="grid grid-2 gap-2 mb-3">
        <div class="form-group">
          <label class="form-label">Event start</label>
          <input type="datetime-local" name="event_start" class="form-control"
            value="<?= !empty($event['event_start']) ? htmlspecialchars(date('Y-m-d\\TH:i', strtotime((string) $event['event_start']))) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Event end</label>
          <input type="datetime-local" name="event_end" class="form-control"
            value="<?= !empty($event['event_end']) ? htmlspecialchars(date('Y-m-d\\TH:i', strtotime((string) $event['event_end']))) : '' ?>">
        </div>
      </div>

      <div class="form-group mb-3">
        <label class="form-label">Eligible seller categories</label>
        <p class="text-xs text-muted mb-2">Only sellers with at least one matching stall category can be assigned to this bazar. Choose all that apply.</p>
        <details class="qb-select-compact">
          <summary>
            <span>Select eligible categories</span>
            <span class="text-xs text-muted" id="qb-event-elig-count"><?= count($eventEligible) ?> selected</span>
          </summary>
          <div class="qb-event-elig-grid mt-2">
            <?php foreach ($catCatalog as $slug => $label): ?>
            <label class="qb-event-elig">
              <input type="checkbox" name="eligible_categories[]" value="<?= htmlspecialchars($slug) ?>" data-qb-elig-cb
                <?= in_array($slug, $eventEligible, true) ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($label) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </details>
      </div>

      <div class="form-group mb-3">
        <label class="form-label">Event image (cover)</label>
        <?php if (!empty($event['cover_image'])): ?>
          <div class="mb-2">
            <img src="<?= htmlspecialchars(qb_public_upload_url((string) $event['cover_image'])) ?>" alt="Event cover" style="max-width:100%;max-height:170px;border-radius:12px;object-fit:cover"/>
          </div>
        <?php endif; ?>
        <input type="file" name="cover_image" class="form-control" accept="image/jpeg,image/png"/>
        <div class="text-xs text-muted mt-1">JPG or PNG, up to 4MB.</div>
      </div>

      <!-- Hidden Lat/Lng populated by map -->
      <input type="hidden" name="lat" id="lat_input" value="<?= $event['lat'] ?? '' ?>">
      <input type="hidden" name="lng" id="lng_input" value="<?= $event['lng'] ?? '' ?>">

      <button type="submit" class="btn btn-primary btn-full"><?= $event ? 'Save Changes' : 'Create Event' ?></button>
      </fieldset>
    </form>
  </div>
  
  <!-- Right Map -->
  <div class="card" style="display:flex;flex-direction:column">
    <h3 class="font-bold mb-2">Venue location on map</h3>
    <p class="text-sm text-secondary mb-2">When you select a city, the map jumps there. Then click exact venue location to place the marker.</p>
    <div id="mapPicker" style="flex:1;min-height:400px;border-radius:var(--radius-md);border:1px solid var(--border)"></div>
  </div>
</div>

<script>
let map = L.map('mapPicker').setView([<?= $event['lat'] ?: '9.03' ?>, <?= $event['lng'] ?: '38.74' ?>], <?= $event['lat'] ? '14' : '11' ?>);
L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 20 }).addTo(map);

let marker = null;

<?php if($event && $event['lat']): ?>
updateMarker(<?= $event['lat'] ?>, <?= $event['lng'] ?>);
<?php endif; ?>

map.on('click', function(e) {
    updateMarker(e.latlng.lat, e.latlng.lng);
});

function updateMarker(lat, lng) {
    document.getElementById('lat_input').value = lat;
    document.getElementById('lng_input').value = lng;
    
    if(marker) map.removeLayer(marker);
    
    marker = L.marker([lat, lng]).addTo(map);
}

const qbCityCenters = {
  "Addis Ababa": [9.03, 38.74],
  "Dire Dawa": [9.6, 41.86],
  "Mekelle": [13.5, 39.47],
  "Adama (Nazret)": [8.54, 39.27],
  "Gondar": [12.6, 37.47],
  "Hawassa (Awassa)": [7.06, 38.47],
  "Bahir Dar": [11.59, 37.39],
  "Jimma": [7.67, 36.83],
  "Dessie": [11.13, 39.63],
  "Harar": [9.31, 42.12]
};

const citySelect = document.querySelector('select[name="city"]');
if (citySelect) {
  citySelect.addEventListener('change', function () {
    const city = citySelect.value;
    const point = qbCityCenters[city];
    if (!point) return;
    const hasManualPoint = (document.getElementById('lat_input').value || '') !== '' && (document.getElementById('lng_input').value || '') !== '';
    if (!hasManualPoint) {
      updateMarker(point[0], point[1]);
    }
    map.setView(point, 12);
  });
}

(function () {
  var countEl = document.getElementById('qb-event-elig-count');
  function syncCount() {
    if (!countEl) return;
    var n = document.querySelectorAll('[data-qb-elig-cb]:checked').length;
    countEl.textContent = n + ' selected';
  }
  document.querySelectorAll('[data-qb-elig-cb]').forEach(function (el) {
    el.addEventListener('change', syncCount);
  });
  syncCount();
  if (citySelect && citySelect.value && !((document.getElementById('lat_input').value || '') !== '' && (document.getElementById('lng_input').value || '') !== '')) {
    const point = qbCityCenters[citySelect.value];
    if (point) {
      map.setView(point, 12);
    }
  }
})();
</script>

<style>
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

<?php qb_page_end(); ?>
