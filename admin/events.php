<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/event_lifecycle_migrate.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();
$approvalReady = qb_event_approval_schema_ready();
if (!$approvalReady) {
    $apr = qb_apply_event_approval_schema();
    $approvalReady = !empty($apr['ok']) && qb_event_approval_schema_ready();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['qb_apply_event_lifecycle'] ?? '') === '1') {
    $r = qb_apply_event_lifecycle_schema();
    $rv = isset($_POST['return_view']) && $_POST['return_view'] === 'table' ? 'table' : 'calendar';
    $ry = max(2000, min(2100, (int) ($_POST['return_y'] ?? (int) date('Y'))));
    $rm = max(1, min(12, (int) ($_POST['return_m'] ?? (int) date('n'))));
    $q = ['view' => $rv, 'y' => $ry, 'm' => $rm];
    if ($r['ok']) {
        $q['lifecycle_ok'] = '1';
    } else {
        $q['lifecycle_err'] = rawurlencode($r['error'] ?? 'Migration failed');
    }
    header('Location: events.php?' . http_build_query($q));
    exit;
}

$lifecycleReady = qb_event_lifecycle_ready();
$autoLifecycleBanner = false;
if (!$lifecycleReady && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $r = qb_apply_event_lifecycle_schema();
    $lifecycleReady = qb_event_lifecycle_ready();
    if ($r['ok'] && $lifecycleReady) {
        $autoLifecycleBanner = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lifecycleReady) {
    $action = $_POST['cal_action'] ?? '';
    $eid = (int) ($_POST['event_id'] ?? 0);
    $y = max(2000, min(2100, (int) ($_POST['y'] ?? (int) date('Y'))));
    $m = max(1, min(12, (int) ($_POST['m'] ?? (int) date('n'))));
    $redirect = 'events.php?view=calendar&y=' . $y . '&m=' . $m;

    if ($eid > 0 && $action === 'cancel') {
        $reason = trim(sanitize($_POST['reason'] ?? ''));
        if ($reason !== '') {
            db()->execute(
                'UPDATE bazar_events SET status = ?, lifecycle_note = ? WHERE id = ?',
                ['canceled', $reason, $eid]
            );
            qb_event_coorganizers_clear($eid);
        }
        header('Location: ' . $redirect);
        exit;
    }

    if ($eid > 0 && $action === 'postpone') {
        $reason = trim(sanitize($_POST['reason'] ?? ''));
        $ns = $_POST['new_event_start'] ?? '';
        $ne = $_POST['new_event_end'] ?? '';
        $ts = $ns !== '' ? strtotime($ns) : false;
        $te = $ne !== '' ? strtotime($ne) : false;
        if ($reason !== '' && $ts && $te && $te >= $ts) {
            db()->execute(
                'UPDATE bazar_events SET status = ?, event_start = ?, event_end = ?, lifecycle_note = ? WHERE id = ?',
                ['postponed', date('Y-m-d H:i:s', $ts), date('Y-m-d H:i:s', $te), $reason, $eid]
            );
        }
        header('Location: ' . $redirect);
        exit;
    }

    if ($approvalReady && $eid > 0 && $action === 'approve') {
        db()->execute(
            "UPDATE bazar_events
             SET approval_status = 'approved', approval_note = ?, approval_reviewed_by = ?, approval_reviewed_at = NOW()
             WHERE id = ?",
            ['Approved by admin', (int) ($_SESSION['app_user_id'] ?? 0), $eid]
        );
        header('Location: ' . $redirect);
        exit;
    }

    if ($approvalReady && $eid > 0 && $action === 'reject') {
        $reason = trim(sanitize($_POST['reason'] ?? ''));
        if ($reason !== '') {
            db()->execute(
                "UPDATE bazar_events
                 SET approval_status = 'rejected', approval_note = ?, approval_reviewed_by = ?, approval_reviewed_at = NOW(), status = 'draft'
                 WHERE id = ?",
                [$reason, (int) ($_SESSION['app_user_id'] ?? 0), $eid]
            );
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$view = isset($_GET['view']) && $_GET['view'] === 'table' ? 'table' : 'calendar';
$year = isset($_GET['y']) ? max(2000, min(2100, (int) $_GET['y'])) : (int) date('Y');
$month = isset($_GET['m']) ? max(1, min(12, (int) $_GET['m'])) : (int) date('n');

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEndDt = new DateTime($monthStart);
$monthEndDt->modify('last day of this month');
$monthEnd = $monthEndDt->format('Y-m-d');

$overlapSql = '
  e.event_start IS NOT NULL
  AND DATE(e.event_start) <= ?
  AND COALESCE(DATE(e.event_end), DATE(e.event_start)) >= ?
';

if (qb_table_exists('bazar_event_organizers')) {
    $eventsList = db()->fetchAll(
        "
        SELECT e.*, u.display_name AS organizer_name,
            (SELECT GROUP_CONCAT(DISTINCT u2.display_name ORDER BY u2.display_name SEPARATOR ', ')
             FROM bazar_event_organizers eo
             INNER JOIN app_users u2 ON u2.id = eo.app_user_id
             WHERE eo.event_id = e.id) AS co_organizer_names
        FROM bazar_events e
        LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
        ORDER BY e.created_at DESC
    "
    );
    $calEvents = db()->fetchAll(
        "
        SELECT e.*, u.display_name AS organizer_name
        FROM bazar_events e
        LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
        WHERE $overlapSql
        ORDER BY e.event_start ASC
    ",
        [$monthEnd, $monthStart]
    );
} else {
    $eventsList = db()->fetchAll(
        '
        SELECT e.*, u.display_name AS organizer_name
        FROM bazar_events e
        LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
        ORDER BY e.created_at DESC
    '
    );
    $calEvents = db()->fetchAll(
        "
        SELECT e.*, u.display_name AS organizer_name
        FROM bazar_events e
        LEFT JOIN app_users u ON e.organizer_app_user_id = u.id
        WHERE $overlapSql
        ORDER BY e.event_start ASC
    ",
        [$monthEnd, $monthStart]
    );
}

$calEventsJs = [];
foreach ($calEvents as $ev) {
    $eid = (int) $ev['id'];
    $sellerC = (int) (db()->fetchOne('SELECT COUNT(DISTINCT seller_id) AS c FROM stalls WHERE event_id = ?', [$eid])['c'] ?? 0);
    $ticketC = (int) (db()->fetchOne(
        "SELECT COUNT(*) AS c FROM tickets WHERE event_id = ? AND status <> 'cancelled'",
        [$eid]
    )['c'] ?? 0);
    $calEventsJs[] = [
        'id'                  => $eid,
        'name'                => $ev['name'],
        'slug'                => $ev['slug'],
        'status'              => $ev['status'],
        'notes'               => (string) ($ev['notes'] ?? ''),
        'organizer_name'      => (string) ($ev['organizer_name'] ?? ''),
        'event_start'         => $ev['event_start'],
        'event_end'           => $ev['event_end'],
        'max_sellers'         => (int) ($ev['max_sellers'] ?? 0),
        'ticket_sales_start'  => $ev['ticket_sales_start'],
        'ticket_sales_end'    => $ev['ticket_sales_end'],
        'seller_count'        => $sellerC,
        'tickets_issued'      => $ticketC,
        'lifecycle_note'      => $lifecycleReady ? (string) ($ev['lifecycle_note'] ?? '') : '',
    ];
}

$first = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$daysInMonth = (int) $first->format('t');
$padBefore = (int) $first->format('N') - 1;
$cells = [];
for ($i = 0; $i < $padBefore; $i++) {
    $cells[] = null;
}
for ($d = 1; $d <= $daysInMonth; $d++) {
    $cells[] = sprintf('%04d-%02d-%02d', $year, $month, $d);
}
while (count($cells) % 7 !== 0) {
    $cells[] = null;
}

$prevM = $month - 1;
$prevY = $year;
if ($prevM < 1) {
    $prevM = 12;
    $prevY--;
}
$nextM = $month + 1;
$nextY = $year;
if ($nextM > 12) {
    $nextM = 1;
    $nextY++;
}

$statusCalClass = static function (string $st): string {
    return [
        'draft'      => 'qb-cal-ev--draft',
        'published'  => 'qb-cal-ev--published',
        'live'       => 'qb-cal-ev--live',
        'ended'      => 'qb-cal-ev--ended',
        'postponed'  => 'qb-cal-ev--postponed',
        'canceled'   => 'qb-cal-ev--canceled',
    ][$st] ?? 'qb-cal-ev--draft';
};

$overlapDay = static function (array $e, string $dateStr): bool {
    if (empty($e['event_start'])) {
        return false;
    }
    $es = date('Y-m-d', strtotime((string) $e['event_start']));
    $ee = !empty($e['event_end']) ? date('Y-m-d', strtotime((string) $e['event_end'])) : $es;

    return $dateStr >= $es && $dateStr <= $ee;
};

qb_page_start('admin', 'Events', 'events.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Events overview</h1>
    <p class="page-subtitle">Calendar view with schedules, or switch to the table list.</p>
  </div>
</div>

<?php if (!$lifecycleReady): ?>
<div class="alert alert-warning mb-3" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;justify-content:space-between">
  <div>
    <strong>Database update still required</strong> (automatic apply failed). Use the button below or run
    <code style="opacity:0.95">php install/migrate_2026_event_lifecycle.php</code>
  </div>
  <form method="post" class="flex gap-2 align-center" style="flex-shrink:0">
    <input type="hidden" name="qb_apply_event_lifecycle" value="1"/>
    <input type="hidden" name="return_view" value="<?= htmlspecialchars($view) ?>"/>
    <input type="hidden" name="return_y" value="<?= (int) $year ?>"/>
    <input type="hidden" name="return_m" value="<?= (int) $month ?>"/>
    <button type="submit" class="btn btn-primary btn-sm">Apply update now</button>
  </form>
</div>
<?php endif; ?>

<?php if ($autoLifecycleBanner || isset($_GET['lifecycle_ok'])): ?>
<div class="alert alert-success mb-3">Event lifecycle database update applied. Postpone and cancel are enabled.</div>
<?php endif; ?>
<?php if (!empty($_GET['lifecycle_err'])): ?>
<div class="alert alert-danger mb-3">Migration failed: <?= htmlspecialchars(rawurldecode((string) $_GET['lifecycle_err'])) ?></div>
<?php endif; ?>

<div class="auth-portal-tabs mb-3" style="max-width:420px">
  <a href="events.php?view=calendar&amp;y=<?= (int) $year ?>&amp;m=<?= (int) $month ?>" class="auth-tab <?= $view === 'calendar' ? 'active' : '' ?>">Calendar</a>
  <a href="events.php?view=table" class="auth-tab <?= $view === 'table' ? 'active' : '' ?>">Table</a>
</div>

<?php if ($view === 'calendar'): ?>
<div class="card qb-cal-card card--data-events no-hover-anim mb-3">
  <div class="qb-cal-toolbar">
    <a href="events.php?view=calendar&amp;y=<?= (int) $prevY ?>&amp;m=<?= (int) $prevM ?>" class="btn btn-secondary btn-sm" title="Previous month">&larr;</a>
    <h2 class="qb-cal-title"><?= htmlspecialchars($first->format('F Y')) ?></h2>
    <a href="events.php?view=calendar&amp;y=<?= (int) $nextY ?>&amp;m=<?= (int) $nextM ?>" class="btn btn-secondary btn-sm" title="Next month">&rarr;</a>
    <a href="events.php?view=calendar" class="btn btn-ghost btn-sm">Today</a>
  </div>
  <div class="qb-cal-grid" role="grid" aria-label="Event calendar">
    <div class="qb-cal-head">Mon</div>
    <div class="qb-cal-head">Tue</div>
    <div class="qb-cal-head">Wed</div>
    <div class="qb-cal-head">Thu</div>
    <div class="qb-cal-head">Fri</div>
    <div class="qb-cal-head">Sat</div>
    <div class="qb-cal-head">Sun</div>
    <?php
    $today = date('Y-m-d');
    foreach ($cells as $dateStr):
        if ($dateStr === null):
            ?>
    <div class="qb-cal-cell qb-cal-cell--pad" aria-hidden="true"></div>
            <?php
        else:
            $dayNum = (int) substr($dateStr, 8, 2);
            $isToday = $dateStr === $today;
            ?>
    <div class="qb-cal-cell<?= $isToday ? ' qb-cal-cell--today' : '' ?>" data-cal-date="<?= htmlspecialchars($dateStr) ?>" title="Double-click this day for events on that date">
      <div class="qb-cal-daynum"><?= $dayNum ?></div>
      <div class="qb-cal-evlist">
        <?php foreach ($calEvents as $ev):
            if (!$overlapDay($ev, $dateStr)) {
                continue;
            }
            $cls = $statusCalClass($ev['status'] ?? 'draft');
            ?>
        <button type="button" class="qb-cal-ev <?= $cls ?>" data-ev-id="<?= (int) $ev['id'] ?>" title="<?= htmlspecialchars($ev['name']) ?> — double-click for event details">
          <span class="qb-cal-ev__bar"></span>
          <span class="qb-cal-ev__txt"><?php
            $nm = (string) $ev['name'];
            if (function_exists('mb_strlen') && mb_strlen($nm) > 22) {
                $nm = mb_substr($nm, 0, 20) . '…';
            } elseif (strlen($nm) > 24) {
                $nm = substr($nm, 0, 22) . '…';
            }
            echo htmlspecialchars($nm);
          ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
            <?php
        endif;
    endforeach;
    ?>
  </div>
  <div class="qb-cal-legend">
    <span><i class="qb-cal-dot qb-cal-ev--draft"></i> Draft</span>
    <span><i class="qb-cal-dot qb-cal-ev--published"></i> Published</span>
    <span><i class="qb-cal-dot qb-cal-ev--live"></i> Live</span>
    <span><i class="qb-cal-dot qb-cal-ev--ended"></i> Ended</span>
    <span><i class="qb-cal-dot qb-cal-ev--postponed"></i> Postponed</span>
    <span><i class="qb-cal-dot qb-cal-ev--canceled"></i> Canceled</span>
  </div>
</div>

<dialog class="qb-cal-modal" id="qb-cal-modal">
  <form method="dialog" class="qb-cal-modal__close">
    <button type="submit" class="btn btn-ghost btn-sm" value="cancel" aria-label="Close">&times;</button>
  </form>
  <h3 class="qb-cal-modal__title" id="qb-cal-modal-title">Event</h3>
  <div class="qb-cal-modal__meta text-sm text-secondary mb-2" id="qb-cal-modal-meta"></div>
  <div class="grid grid-2 gap-2 mb-3">
    <div class="card card--data-stores no-hover-anim" style="padding:0.75rem">
      <div class="text-xs text-muted text-uppercase mb-1">Sellers (stalls)</div>
      <div class="font-bold text-lg" id="qb-cal-sellers">0</div>
    </div>
    <div class="card card--data-tickets no-hover-anim" style="padding:0.75rem">
      <div class="text-xs text-muted text-uppercase mb-1">Tickets issued</div>
      <div class="font-bold text-lg" id="qb-cal-tickets">0</div>
    </div>
  </div>
  <div class="mb-2">
    <div class="text-xs text-muted text-uppercase mb-1">Stall capacity (max sellers)</div>
    <div class="font-bold" id="qb-cal-maxs"></div>
  </div>
  <div class="mb-2">
    <div class="text-xs text-muted text-uppercase mb-1">Ticket sales window</div>
    <div class="text-sm" id="qb-cal-sales"></div>
  </div>
  <div class="mb-3">
    <div class="text-xs text-muted text-uppercase mb-1">Description</div>
    <p class="text-sm" id="qb-cal-notes" style="white-space:pre-wrap;max-height:120px;overflow:auto"></p>
  </div>
  <div class="mb-2 text-sm text-secondary" id="qb-cal-lifecycle" style="display:none"></div>
  <?php if ($lifecycleReady): ?>
  <div class="qb-cal-actions grid gap-2">
    <button type="button" class="btn btn-secondary btn-sm" id="qb-cal-btn-postpone">Postpone…</button>
    <button type="button" class="btn btn-secondary btn-sm" id="qb-cal-btn-cancel" style="border-color:color-mix(in srgb,var(--danger) 35%,var(--border));color:var(--danger)">Cancel event…</button>
  </div>
  <div id="qb-cal-panel-postpone" class="qb-cal-panel mt-3" style="display:none">
    <form method="post" class="grid gap-2">
      <input type="hidden" name="cal_action" value="postpone"/>
      <input type="hidden" name="event_id" id="qb-form-postpone-eid" value=""/>
      <input type="hidden" name="y" value="<?= (int) $year ?>"/>
      <input type="hidden" name="m" value="<?= (int) $month ?>"/>
      <label class="form-label">New start</label>
      <input type="datetime-local" name="new_event_start" class="form-control" id="qb-form-ns" required/>
      <label class="form-label">New end</label>
      <input type="datetime-local" name="new_event_end" class="form-control" id="qb-form-ne" required/>
      <label class="form-label">Reason</label>
      <textarea name="reason" class="form-control" rows="2" required placeholder="Why postpone?"></textarea>
      <button type="submit" class="btn btn-primary btn-sm">Confirm postpone</button>
    </form>
  </div>
  <div id="qb-cal-panel-cancel" class="qb-cal-panel mt-3" style="display:none">
    <form method="post" class="grid gap-2">
      <input type="hidden" name="cal_action" value="cancel"/>
      <input type="hidden" name="event_id" id="qb-form-cancel-eid" value=""/>
      <input type="hidden" name="y" value="<?= (int) $year ?>"/>
      <input type="hidden" name="m" value="<?= (int) $month ?>"/>
      <label class="form-label">Reason for cancellation</label>
      <textarea name="reason" class="form-control" rows="2" required placeholder="Required"></textarea>
      <button type="submit" class="btn btn-primary btn-sm" style="background:var(--danger);border-color:var(--danger)">Confirm cancel</button>
    </form>
  </div>
  <?php endif; ?>
</dialog>

<dialog class="qb-cal-modal qb-cal-day-modal" id="qb-cal-day-modal" aria-labelledby="qb-cal-day-modal-title">
  <form method="dialog" class="qb-cal-modal__close">
    <button type="submit" class="btn btn-ghost btn-sm" value="cancel" aria-label="Close">&times;</button>
  </form>
  <h3 class="qb-cal-modal__title" id="qb-cal-day-modal-title">Day</h3>
  <p class="text-sm text-secondary mb-2" id="qb-cal-day-modal-hint">Double-click an event to open full details.</p>
  <ul class="qb-cal-day-list" id="qb-cal-day-list"></ul>
  <p class="text-sm text-muted mb-0" id="qb-cal-day-empty" style="display:none">No events on this day.</p>
</dialog>

<script>
(function(){
  var data = <?= json_encode($calEventsJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  var byId = {};
  data.forEach(function(r){ byId[r.id] = r; });
  var modal = document.getElementById('qb-cal-modal');
  var dayModal = document.getElementById('qb-cal-day-modal');
  var lastId = null;
  function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  function dayStrFromEv(ev){
    if(!ev || !ev.event_start) return '';
    var s = String(ev.event_start);
    return s.length >= 10 ? s.substring(0, 10) : '';
  }
  function eventOverlapsDay(ev, dateStr){
    if(!ev || !ev.event_start) return false;
    var es = dayStrFromEv(ev);
    if(!es) return false;
    var ee = ev.event_end ? String(ev.event_end).substring(0, 10) : es;
    return dateStr >= es && dateStr <= ee;
  }
  function openDay(dateStr){
    if(!dayModal) return;
    var list = document.getElementById('qb-cal-day-list');
    var emptyEl = document.getElementById('qb-cal-day-empty');
    var titleEl = document.getElementById('qb-cal-day-modal-title');
    if(titleEl) titleEl.textContent = dateStr;
    if(!list) return;
    list.innerHTML = '';
    var rows = data.filter(function(ev){ return eventOverlapsDay(ev, dateStr); });
    rows.sort(function(a,b){ return (a.event_start||'').localeCompare(b.event_start||''); });
    if(rows.length === 0){
      if(emptyEl){ emptyEl.style.display='block'; }
    } else {
      if(emptyEl){ emptyEl.style.display='none'; }
      rows.forEach(function(ev){
        var li = document.createElement('li');
        li.className = 'qb-cal-day-item ' + statusClass(ev.status);
        li.setAttribute('data-ev-id', String(ev.id));
        li.innerHTML = '<span class="qb-cal-day-item__status"><i class="qb-cal-dot '+statusClass(ev.status)+'"></i></span>'+
          '<span class="qb-cal-day-item__name">'+esc(ev.name)+'</span>'+
          '<span class="qb-cal-day-item__hint text-xs text-muted">dbl-click</span>';
        li.setAttribute('title', (ev.name||'')+' — double-click for details');
        list.appendChild(li);
      });
    }
    dayModal.showModal();
  }
  function statusClass(st){
    return ({
      'draft':'qb-cal-ev--draft',
      'published':'qb-cal-ev--published',
      'live':'qb-cal-ev--live',
      'ended':'qb-cal-ev--ended',
      'postponed':'qb-cal-ev--postponed',
      'canceled':'qb-cal-ev--canceled'
    })[st] || 'qb-cal-ev--draft';
  }
  function fmtDt(s){
    if(!s) return '—';
    try { var d=new Date(s.replace(' ','T')); return isNaN(d.getTime())?s:d.toLocaleString(); } catch(e){ return s; }
  }
  function openEv(id){
    var ev = byId[id];
    if(!ev) return;
    lastId = id;
    document.getElementById('qb-cal-modal-title').textContent = ev.name;
    document.getElementById('qb-cal-modal-meta').innerHTML = '<span class="badge badge-gray text-uppercase">'+esc(ev.status)+'</span> · '+esc(ev.organizer_name||'—');
    document.getElementById('qb-cal-sellers').textContent = ev.seller_count;
    document.getElementById('qb-cal-tickets').textContent = ev.tickets_issued;
    document.getElementById('qb-cal-maxs').textContent = ev.max_sellers || '—';
    document.getElementById('qb-cal-sales').textContent =
      (fmtDt(ev.ticket_sales_start)+' → '+fmtDt(ev.ticket_sales_end));
    document.getElementById('qb-cal-notes').textContent = ev.notes || '—';
    var lc = document.getElementById('qb-cal-lifecycle');
    if(ev.lifecycle_note){ lc.style.display='block'; lc.textContent = 'Last note: '+ev.lifecycle_note; } else { lc.style.display='none'; }
    var ns = document.getElementById('qb-form-ns');
    var ne = document.getElementById('qb-form-ne');
    if(ns && ev.event_start){ ns.value = ev.event_start.replace(' ','T').substring(0,16); }
    if(ne && ev.event_end){ ne.value = ev.event_end.replace(' ','T').substring(0,16); }
    var pe = document.getElementById('qb-form-postpone-eid');
    var ce = document.getElementById('qb-form-cancel-eid');
    if(pe) pe.value = id;
    if(ce) ce.value = id;
    var pp = document.getElementById('qb-cal-panel-postpone');
    var pc = document.getElementById('qb-cal-panel-cancel');
    if(pp) pp.style.display='none';
    if(pc) pc.style.display='none';
    modal.showModal();
  }
  document.querySelectorAll('.qb-cal-cell[data-cal-date]').forEach(function(cell){
    cell.addEventListener('dblclick', function(e){
      if (e.target.closest('.qb-cal-ev')) return;
      var ds = cell.getAttribute('data-cal-date');
      if (ds) openDay(ds);
    });
  });
  document.querySelectorAll('.qb-cal-ev').forEach(function(btn){
    btn.addEventListener('dblclick', function(e){
      e.preventDefault();
      e.stopPropagation();
      openEv(+btn.getAttribute('data-ev-id'));
    });
  });
  var dayListEl = document.getElementById('qb-cal-day-list');
  if (dayListEl) {
    dayListEl.addEventListener('dblclick', function(e){
      var li = e.target.closest('.qb-cal-day-item');
      if (!li) return;
      var id = +li.getAttribute('data-ev-id');
      if (dayModal) dayModal.close();
      openEv(id);
    });
  }
  var bp = document.getElementById('qb-cal-btn-postpone');
  var bc = document.getElementById('qb-cal-btn-cancel');
  if(bp) bp.addEventListener('click', function(){
    var pp = document.getElementById('qb-cal-panel-postpone');
    var pc = document.getElementById('qb-cal-panel-cancel');
    if(pp) pp.style.display='block';
    if(pc) pc.style.display='none';
  });
  if(bc) bc.addEventListener('click', function(){
    var pp = document.getElementById('qb-cal-panel-postpone');
    var pc = document.getElementById('qb-cal-panel-cancel');
    if(pc) pc.style.display='block';
    if(pp) pp.style.display='none';
  });
})();
</script>
<?php endif; ?>

<?php if ($view === 'table'): ?>
<div class="card card--data-events no-hover-anim">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Organizer</th>
                    <th>Location</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Branding</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($eventsList)): ?>
                    <tr><td colspan="<?= $approvalReady ? '7' : '6' ?>" class="text-center text-muted">No events created.</td></tr>
                <?php else: ?>
                    <?php foreach ($eventsList as $e):
                        $statusClass = [
                            'draft' => 'badge-gray',
                            'published' => 'badge-blue',
                            'live' => 'badge-green',
                            'ended' => 'badge-red',
                            'postponed' => 'badge-amber',
                            'canceled' => 'badge-gray',
                        ][$e['status']] ?? 'badge-gray';
                        ?>
                    <tr>
                        <td>
                            <div class="font-bold"><?= htmlspecialchars($e['name']) ?></div>
                            <code class="text-xs text-muted"><?= htmlspecialchars($e['slug']) ?></code>
                        </td>
                        <td>
                            <?= htmlspecialchars($e['organizer_name'] ?: '—') ?>
                            <?php if (!empty($e['co_organizer_names'])): ?>
                                <div class="text-xs text-muted mt-1">+ <?= htmlspecialchars($e['co_organizer_names']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-bold"><?= htmlspecialchars($e['city'] ?? '') ?></div>
                            <div class="text-xs text-secondary"><?= htmlspecialchars($e['venue'] ?? '') ?></div>
                        </td>
                        <td class="text-xs text-secondary">
                            Start: <?= $e['event_start'] ? date('M j Y, g:i A', strtotime($e['event_start'])) : 'TBD' ?><br>
                            End: <?= $e['event_end'] ? date('M j Y, g:i A', strtotime($e['event_end'])) : 'TBD' ?>
                        </td>
                        <td><span class="badge <?= $statusClass ?> text-uppercase"><?= htmlspecialchars($e['status']) ?></span></td>
                        <td><a href="event_brand.php?id=<?= (int) $e['id'] ?>" class="btn btn-secondary btn-sm">Theme &amp; promo</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php qb_page_end(); ?>
