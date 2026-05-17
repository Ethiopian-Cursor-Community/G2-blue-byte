<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$success = '';
$error = '';
$listScope = (string) ($_GET['scope'] ?? 'active');
$listType = (string) ($_GET['type'] ?? 'all');
$listSource = (string) ($_GET['source'] ?? 'all');
if (!in_array($listScope, ['active', 'available', 'all'], true)) {
    $listScope = 'active';
}
if (!in_array($listType, ['all', 'text', 'image', 'video'], true)) {
    $listType = 'all';
}
if (!in_array($listSource, ['all', 'official', 'community'], true)) {
    $listSource = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = db()->fetchOne('SELECT media_url FROM event_promotions WHERE id = ?', [$id]);
        if ($row) {
            qb_delete_upload_file($row['media_url'] ?? null);
            db()->execute('DELETE FROM event_promotions WHERE id = ?', [$id]);
            $success = 'Promo removed.';
        }
    } elseif ($act === 'add') {
        $title = qb_sanitize_plain_text((string) ($_POST['title'] ?? ''), 200);
        $eventIdPost = (int) ($_POST['event_id'] ?? 0);
        $eventIdFk = $eventIdPost > 0 ? $eventIdPost : null;
        $marquee = qb_sanitize_plain_text((string) ($_POST['marquee_text'] ?? ''), 500);
        $sort = (int) ($_POST['sort_order'] ?? 0);
        if (!$title) {
            $error = 'Title is required.';
        } elseif (empty($_FILES['media']['tmp_name'])) {
            $error = 'Please upload an image or MP4 video.';
        } else {
            $up = qb_save_promo_media($_FILES['media']);
            if ($up['error']) {
                $error = $up['error'];
            } else {
                $showBuyers = isset($_POST['show_buyers']) ? 1 : 0;
                $showSellers = isset($_POST['show_sellers']) ? 1 : 0;
                $showOrgs = isset($_POST['show_organizers']) ? 1 : 0;
                if (function_exists('qb_has_column') && qb_has_column('event_promotions', 'show_buyers')) {
                    db()->execute(
                        'INSERT INTO event_promotions (event_id, title, media_url, media_type, marquee_text, sort_order, is_active, show_buyers, show_sellers, show_organizers) VALUES (?,?,?,?,?,?,1,?,?,?)',
                        [
                            $eventIdFk,
                            $title,
                            $up['path'],
                            $up['type'] === 'video' ? 'video' : 'image',
                            $marquee !== '' ? $marquee : null,
                            $sort,
                            $showBuyers,
                            $showSellers,
                            $showOrgs,
                        ]
                    );
                } else {
                    db()->execute(
                        'INSERT INTO event_promotions (event_id, title, media_url, media_type, marquee_text, sort_order, is_active) VALUES (?,?,?,?,?,?,1)',
                        [
                            $eventIdFk,
                            $title,
                            $up['path'],
                            $up['type'] === 'video' ? 'video' : 'image',
                            $marquee !== '' ? $marquee : null,
                            $sort,
                        ]
                    );
                }
                $success = 'Promo added.';
            }
        }
    }
}

$officialPromos = [];
if (qb_has_column('event_promotions', 'id')) {
    try {
        $officialPromos = db()->fetchAll(
            "SELECT pr.*, e.name AS event_name, e.status AS event_status
             FROM event_promotions pr
             LEFT JOIN bazar_events e ON e.id = pr.event_id
             ORDER BY pr.sort_order ASC, pr.id DESC"
        );
    } catch (Throwable $e) {
        $officialPromos = [];
    }
}

$communityPromos = [];
if (function_exists('qb_promo_posts_ready') && qb_promo_posts_ready()) {
    try {
        $communityPromos = db()->fetchAll(
            "SELECT p.*,
              CASE p.owner_type
                WHEN 'seller' THEN (SELECT COALESCE(s.market_name, CONCAT('Seller #', s.id)) FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
                WHEN 'organization' THEN (SELECT COALESCE(u.display_name, CONCAT('Organizer #', u.id)) FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
              END AS owner_label
             FROM promo_posts p
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 200"
        );
    } catch (Throwable $e) {
        $communityPromos = [];
    }
}

$nowTs = time();
$promos = [];
foreach ($officialPromos as $pr) {
    $eventStatus = (string) ($pr['event_status'] ?? '');
    $isVisibleNow = !empty($pr['is_active']) && ((int) ($pr['event_id'] ?? 0) <= 0 || in_array($eventStatus, ['published', 'live'], true));
    $isAvailable = !$isVisibleNow;
    if ($listScope === 'active' && !$isVisibleNow) {
        continue;
    }
    if ($listScope === 'available' && !$isAvailable) {
        continue;
    }
    $mt = (string) ($pr['media_type'] ?? 'image');
    if ($listType !== 'all' && $mt !== $listType) {
        continue;
    }
    if (!in_array($listSource, ['all', 'official'], true)) {
        continue;
    }
    $promos[] = [
        'source' => 'official',
        'title' => (string) ($pr['title'] ?? ''),
        'event_name' => (string) ($pr['event_name'] ?? ''),
        'event_id' => (int) ($pr['event_id'] ?? 0),
        'media_type' => $mt,
        'owner' => 'QR Bazar (Official)',
        'status_label' => $isVisibleNow ? 'active' : 'available',
        'audience' => implode(', ', array_values(array_filter([
            !empty($pr['show_buyers']) ? 'Buyers' : null,
            !empty($pr['show_sellers']) ? 'Sellers' : null,
            !empty($pr['show_organizers']) ? 'Organizers' : null,
        ]))),
        'id' => (int) ($pr['id'] ?? 0),
        'is_deletable' => true,
    ];
}
foreach ($communityPromos as $row) {
    $ctype = (string) ($row['content_type'] ?? 'text');
    $expires = trim((string) ($row['expires_at'] ?? ''));
    $expiresTs = $expires !== '' ? strtotime($expires) : false;
    $notExpired = $expiresTs === false || $expiresTs > $nowTs;
    $isVisibleNow = (string) ($row['status'] ?? '') === 'active' && (string) ($row['target'] ?? '') === 'homepage' && $notExpired;
    $isAvailable = (string) ($row['status'] ?? '') === 'active' && !$isVisibleNow;
    if ($listScope === 'active' && !$isVisibleNow) {
        continue;
    }
    if ($listScope === 'available' && !$isAvailable) {
        continue;
    }
    if ($listScope === 'all') {
        // all rows are okay
    } elseif (!$isVisibleNow && !$isAvailable) {
        continue;
    }
    if ($listType !== 'all' && $ctype !== $listType) {
        continue;
    }
    if (!in_array($listSource, ['all', 'community'], true)) {
        continue;
    }
    $promos[] = [
        'source' => 'community',
        'title' => (string) ($row['title'] ?? ''),
        'event_name' => 'Homepage feed',
        'event_id' => 0,
        'media_type' => $ctype,
        'owner' => (string) ($row['owner_label'] ?? (($row['owner_type'] ?? 'seller') . ' #' . (string) ($row['owner_id'] ?? ''))),
        'status_label' => $isVisibleNow ? 'active' : ($isAvailable ? 'available' : (string) ($row['status'] ?? 'unknown')),
        'audience' => 'Homepage',
        'id' => (int) ($row['id'] ?? 0),
        'is_deletable' => false,
    ];
}

$events = db()->fetchAll('SELECT id, name FROM bazar_events ORDER BY id DESC LIMIT 100');

qb_page_start('admin', 'Promotions', 'promos.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Promos &amp; ads</h1>
    <p class="page-subtitle">Admin-uploaded images or MP4 videos — shown to buyers, sellers, and/or organizers (see audience checkboxes). Optional marquee text feeds the portal ticker.</p>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!qb_has_column('event_promotions', 'id')): ?>
  <div class="alert alert-warning">Run <code>install/migrate_2026_qbazaar.php</code> first.</div>
<?php else: ?>

<div class="grid grid-2 gap-3 mb-4">
  <div class="card">
    <h3 class="font-bold mb-3">Add promo</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add"/>
      <div class="form-group mb-2">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" required/>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Event (optional — leave global)</label>
        <select name="event_id" class="form-control">
          <option value="0">All events (global)</option>
          <?php foreach ($events as $ev): ?>
            <option value="<?= (int)$ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Media (JPG, PNG, or MP4)</label>
        <input type="file" name="media" class="form-control" accept="image/jpeg,image/png,video/mp4" required/>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Marquee text (optional)</label>
        <input type="text" name="marquee_text" class="form-control" placeholder="Shown in scrolling banner"/>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Sort order</label>
        <input type="number" name="sort_order" class="form-control" value="0"/>
      </div>
      <?php if (function_exists('qb_has_column') && qb_has_column('event_promotions', 'show_buyers')): ?>
      <div class="form-group mb-2">
        <span class="form-label d-block mb-1">Show to</span>
        <div class="qb-opt-inline" role="group" aria-label="Promo audience">
          <label><input type="checkbox" name="show_buyers" value="1" checked/> Buyers</label>
          <label><input type="checkbox" name="show_sellers" value="1" checked/> Sellers</label>
          <label><input type="checkbox" name="show_organizers" value="1" checked/> Organizers</label>
        </div>
        <p class="text-xs text-muted mt-1 mb-0">Uncheck a group to hide this promo from that portal home.</p>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Add promo</button>
    </form>
  </div>

  <div class="card">
    <h3 class="font-bold mb-3">Active promos</h3>
    <form method="get" class="mb-2" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end">
      <label class="form-group mb-0">
        <span class="form-label">Scope</span>
        <select name="scope" class="form-control">
          <option value="active" <?= $listScope === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="available" <?= $listScope === 'available' ? 'selected' : '' ?>>Available</option>
          <option value="all" <?= $listScope === 'all' ? 'selected' : '' ?>>All</option>
        </select>
      </label>
      <label class="form-group mb-0">
        <span class="form-label">Type</span>
        <select name="type" class="form-control">
          <option value="all" <?= $listType === 'all' ? 'selected' : '' ?>>All types</option>
          <option value="text" <?= $listType === 'text' ? 'selected' : '' ?>>Text</option>
          <option value="image" <?= $listType === 'image' ? 'selected' : '' ?>>Image</option>
          <option value="video" <?= $listType === 'video' ? 'selected' : '' ?>>Video</option>
        </select>
      </label>
      <label class="form-group mb-0">
        <span class="form-label">Source</span>
        <select name="source" class="form-control">
          <option value="all" <?= $listSource === 'all' ? 'selected' : '' ?>>All sources</option>
          <option value="official" <?= $listSource === 'official' ? 'selected' : '' ?>>QR Bazar official</option>
          <option value="community" <?= $listSource === 'community' ? 'selected' : '' ?>>Community</option>
        </select>
      </label>
      <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
      <a href="promos.php" class="btn btn-ghost btn-sm">Clear</a>
    </form>
    <?php if (empty($promos)): ?>
      <p class="text-muted text-sm">No promos yet.</p>
    <?php else: ?>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Owner</th>
              <th>Event</th>
              <th>State</th>
              <th>Audience</th>
              <th>Type</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($promos as $pr): ?>
            <tr>
              <td class="font-bold"><?= qb_esc_html($pr['title'] ?? '') ?></td>
              <td class="text-sm">
                <?php if (($pr['source'] ?? '') === 'official'): ?>
                  <span class="badge badge-amber">QR Bazar Official</span>
                <?php else: ?>
                  <span class="badge badge-gray">Community</span>
                <?php endif; ?>
                <div class="text-xs text-muted mt-1"><?= htmlspecialchars((string) ($pr['owner'] ?? '')) ?></div>
              </td>
              <td class="text-sm"><?= !empty($pr['event_name']) ? htmlspecialchars((string) $pr['event_name']) : '<span class="text-muted">Global</span>' ?></td>
              <td><span class="badge <?= (string) ($pr['status_label'] ?? '') === 'active' ? 'badge-green' : 'badge-gray' ?>"><?= htmlspecialchars((string) ($pr['status_label'] ?? 'unknown')) ?></span></td>
              <td class="text-xs text-muted">
                <?= htmlspecialchars((string) (($pr['audience'] ?? '') !== '' ? $pr['audience'] : '—')) ?>
              </td>
              <td><?= htmlspecialchars((string) ($pr['media_type'] ?? 'unknown')) ?></td>
              <td>
                <?php if (!empty($pr['is_deletable'])): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                    <input type="hidden" name="action" value="delete"/>
                    <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>"/>
                    <button type="submit" class="btn btn-ghost btn-sm text-danger">Remove</button>
                  </form>
                <?php else: ?>
                  <a href="promo_posts_queue.php" class="btn btn-ghost btn-sm">Manage</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php qb_page_end(); ?>
