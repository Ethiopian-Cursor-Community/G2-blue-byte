<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyer();

$buyerId = (int) ($_SESSION['app_user_id'] ?? 0);
$eventId = (int) ($_GET['event'] ?? 0);
$allMapEvents = db()->fetchAll(
    "SELECT e.id, e.name, e.status, e.event_start, e.event_end
     FROM bazar_events e
     WHERE e.status IN ('published', 'live')
     ORDER BY (e.status = 'live') DESC, e.event_start ASC, e.id DESC
     LIMIT 120"
);
$allMap = [];
foreach ($allMapEvents as $ae) {
    $eid = (int) ($ae['id'] ?? 0);
    if ($eid > 0) {
        $allMap[$eid] = true;
    }
}
$eligibleEvents = db()->fetchAll(
    "SELECT DISTINCT e.id, e.name, e.status, e.event_start, e.event_end
     FROM tickets t
     INNER JOIN bazar_events e ON e.id = t.event_id
     WHERE t.buyer_id = ?
       AND t.status = 'active'
       AND e.status IN ('published', 'live')
       AND (e.event_end IS NULL OR e.event_end >= NOW())
     ORDER BY (e.status = 'live') DESC, e.event_start ASC, e.id DESC",
    [$buyerId]
);
$eligibleMap = [];
foreach ($eligibleEvents as $ee) {
    $eid = (int) ($ee['id'] ?? 0);
    if ($eid > 0) {
        $eligibleMap[$eid] = true;
    }
}
$pickedEligibleEventId = 0;
if ($eventId > 0 && isset($allMap[$eventId])) {
    $pickedEligibleEventId = $eventId;
} elseif (!empty($eligibleEvents)) {
    $pickedEligibleEventId = (int) ($eligibleEvents[0]['id'] ?? 0);
} elseif (!empty($allMapEvents)) {
    $pickedEligibleEventId = (int) ($allMapEvents[0]['id'] ?? 0);
}

// Pick an active map
$event = null;
if (qb_table_exists('bazar_event_organizers')) {
    $evSql = "
        SELECT e.*, u.display_name AS organizer_name,
            (SELECT GROUP_CONCAT(DISTINCT u2.display_name ORDER BY u2.display_name SEPARATOR ', ')
             FROM bazar_event_organizers eo
             INNER JOIN app_users u2 ON u2.id = eo.app_user_id
             WHERE eo.event_id = e.id) AS co_organizer_names
        FROM bazar_events e
        LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
    ";
    if ($pickedEligibleEventId > 0) {
        $event = db()->fetchOne($evSql . ' WHERE e.id = ?', [$pickedEligibleEventId]);
    } else {
        $event = db()->fetchOne(
            $evSql . " WHERE e.status IN ('live', 'published') ORDER BY e.event_start ASC LIMIT 1"
        );
    }
} else {
    if ($pickedEligibleEventId > 0) {
        $event = db()->fetchOne(
            'SELECT e.*, u.display_name AS organizer_name FROM bazar_events e
             LEFT JOIN app_users u ON e.organizer_app_user_id = u.id WHERE e.id = ?',
            [$pickedEligibleEventId]
        );
    } else {
        $event = db()->fetchOne(
            "SELECT e.*, u.display_name AS organizer_name FROM bazar_events e
             LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
             WHERE e.status IN ('live', 'published') ORDER BY e.event_start ASC LIMIT 1"
        );
    }
}

if (!$event) {
    qb_page_start('buyer', 'Live Map', 'map.php', false);
    echo '<div class="buyer-dashboard"><div class="buyer-main"><div class="empty-state"><h3>No Active Map</h3><p>There are no live bazars to map right now.</p></div></div></div>';
    qb_page_end();
    exit;
}

$stallLine = (function_exists('qb_has_column') && qb_has_column('sellers', 'stall_tagline')) ? ', s.stall_tagline' : '';
$stalls = db()->fetchAll("
    SELECT st.*, s.market_name, s.category $stallLine
    FROM stalls st 
    JOIN sellers s ON st.seller_id = s.id 
    WHERE st.event_id = ?
", [$event['id']]);
foreach ($stalls as &$st) {
    $st['story_line'] = function_exists('qb_seller_story_line')
        ? qb_seller_story_line([
            'stall_tagline' => $st['stall_tagline'] ?? '',
            'category' => $st['category'] ?? '',
            'market_name' => $st['market_name'] ?? '',
        ])
        : '';
}
unset($st);

$mapStart = '';
if (!empty($event['event_start'])) {
    $mapStart = date('D, M j · g:i A', strtotime((string) $event['event_start']));
}
$mapEnd = '';
if (!empty($event['event_end'])) {
    $mapEnd = date('D, M j · g:i A', strtotime((string) $event['event_end']));
}
$mapOrg = trim((string) ($event['organizer_name'] ?? ''));
if (!empty($event['co_organizer_names'])) {
    $mapOrg = $mapOrg === '' ? (string) $event['co_organizer_names'] : $mapOrg . ', ' . $event['co_organizer_names'];
}
$mapNotes = trim((string) ($event['notes'] ?? ''));
$mapDialog = [
    'name' => (string) ($event['name'] ?? ''),
    'status' => (string) ($event['status'] ?? ''),
    'venue' => (string) ($event['venue'] ?? ''),
    'city' => (string) ($event['city'] ?? ''),
    'start' => $mapStart,
    'end' => $mapEnd,
    'organizers' => $mapOrg,
    'notes' => $mapNotes,
    'products' => count($stalls) . ' stalls on this map',
    'attendance' => 'Geo-fence: ' . (int) ($event['radius_meters'] ?? 500) . ' m',
];

qb_page_start('buyer', 'Live Map', 'map.php', false);
?>

<!-- Include Leaflet.js -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="buyer-main qb-buyer-map-shell">
    <div class="qb-map-event-banner-wrap">
        <div class="card qb-map-event-banner qb-event-card--dblinfo" tabindex="0" title="Double-click for full event details"<?= qb_event_dialog_data_attr($mapDialog) ?>>
            <div class="qb-map-event-banner__main">
                <div class="status-dot qb-map-event-banner__dot" aria-hidden="true"></div>
                <div class="qb-map-event-banner__text">
                    <span class="font-bold text-sm"><?= htmlspecialchars((string) $event['name']) ?></span>
                    <?php if ($mapOrg !== ''): ?>
                    <div class="text-xs text-muted qb-map-event-banner__org">Organizers: <?= htmlspecialchars($mapOrg) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-xs text-muted font-bold qb-map-event-banner__tag">LIVE MAP</div>
        </div>
    </div>
    
    <div id="map" class="qb-buyer-map-canvas"></div>
    <?php if (!empty($allMapEvents)): ?>
    <div class="qb-map-event-picker qb-map-event-picker--floating">
        <label for="mapEventPicker" class="text-xs text-muted">Select event map</label>
        <select id="mapEventPicker" class="form-control qb-map-event-picker__select" onchange="if(this.value){location.href='map.php?event='+encodeURIComponent(this.value);}">
            <?php foreach ($allMapEvents as $ee): ?>
            <?php $eid = (int) ($ee['id'] ?? 0); ?>
            <option value="<?= $eid ?>" <?= $event && (int) ($event['id'] ?? 0) === $eid ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($ee['name'] ?? 'Event')) ?><?= !empty($ee['status']) ? ' · ' . htmlspecialchars((string) $ee['status']) : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($event && !isset($eligibleMap[(int) ($event['id'] ?? 0)])): ?>
          <span class="text-xs text-muted">Map preview mode. Buy ticket to access gate/scan flow for this event.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Map setup centered on event area
    const centerLat = <?= $event['lat'] ?: '9.0320' ?>;
    const centerLng = <?= $event['lng'] ?: '38.7469' ?>;
    const map = L.map('map', { zoomControl: false }).setView([centerLat, centerLng], 17);

    // Light map tiles
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap &copy; CARTO',
        subdomains: 'abcd',
        maxZoom: 20
    }).addTo(map);

    // Draw Event Geo-fence Radius
    L.circle([centerLat, centerLng], {
        color: 'var(--accent)',
        fillColor: 'var(--accent)',
        fillOpacity: 0.05,
        radius: <?= $event['radius_meters'] ?: 500 ?>
    }).addTo(map);

    // Custom Icon Maker
    const createStallIcon = (category) => {
        return L.divIcon({
            className: 'custom-stall-marker',
            html: `<div style="background:var(--accent);color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px #fff,0 2px 8px rgba(0,0,0,0.2)"><?= qb_icon('store', '', 16) ?></div>`,
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -10]
        });
    };

    // Add Stalls
    const stalls = <?= json_encode($stalls) ?>;
    stalls.forEach(s => {
        if(s.lat && s.lng) {
            L.marker([s.lat, s.lng], {icon: createStallIcon(s.category)})
             .bindPopup(`
                <div style="padding:0.25rem">
                    <div style="font-weight:800;font-size:1rem;color:var(--text);margin-bottom:0.25rem">${s.market_name}</div>
                    ${s.story_line ? `<div style="color:var(--text-secondary);font-size:0.75rem;font-style:italic;margin-bottom:0.35rem">${s.story_line}</div>` : ''}
                    <div style="color:var(--text-secondary);font-size:0.8rem;margin-bottom:0.5rem">${s.category} • Stall ${s.stall_number}</div>
                    <a href="scan.php" style="background:var(--accent);color:#fff;padding:0.4rem 0.8rem;border-radius:6px;display:block;text-align:center;text-decoration:none;font-weight:bold;font-size:0.8rem">Scan QR at Stall</a>
                </div>
             `)
             .addTo(map);
        }
    });

    // Simulate Buyer Live Location (Random point inside map for effect)
    setTimeout(() => {
        const userLoc = L.divIcon({
            className: 'user-marker',
            html: `<div style="background:var(--blue);width:16px;height:16px;border-radius:50%;box-shadow:0 0 0 4px rgba(37, 99, 235, 0.3), 0 0 0 2px #fff inset;"></div>`,
            iconSize: [16,16],
            iconAnchor: [8,8]
        });
        L.marker([centerLat - 0.0005, centerLng + 0.0002], {icon: userLoc}).addTo(map).bindPopup("<b>You are here</b>");
    }, 1000);
});
</script>

<?php require __DIR__ . '/../includes/partials/event_info_dialog.php'; ?>
<?php qb_page_end(); ?>
