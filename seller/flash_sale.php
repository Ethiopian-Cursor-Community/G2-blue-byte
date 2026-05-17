<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

$seller = getCurrentSeller();
$sid = (int) $seller['id'];

$tableReady = qb_table_exists('flash_sales');

/** @return list<array<string,mixed>> */
function qb_seller_bazar_events(int $sellerId): array {
    if (!qb_table_exists('stalls') || !qb_table_exists('bazar_events')) {
        return [];
    }
    $hasParticipants = qb_table_exists('event_participants');
    if ($hasParticipants) {
        return db()->fetchAll(
            "SELECT DISTINCT e.id, e.name, e.slug
             FROM bazar_events e
             WHERE e.status IN ('published','live')
               AND (
                   EXISTS (SELECT 1 FROM stalls st WHERE st.event_id = e.id AND st.seller_id = ?)
                   OR EXISTS (
                       SELECT 1 FROM event_participants ep
                       INNER JOIN sellers s ON s.app_user_id = ep.app_user_id
                       WHERE ep.event_id = e.id
                         AND ep.role_in_event = 'seller'
                         AND ep.status = 'approved'
                         AND s.id = ?
                   )
               )
             ORDER BY e.event_start DESC",
            [$sellerId, $sellerId]
        );
    }
    return db()->fetchAll(
        "SELECT DISTINCT e.id, e.name, e.slug
         FROM stalls st
         INNER JOIN bazar_events e ON e.id = st.event_id
         WHERE st.seller_id = ?
           AND e.status IN ('published','live')
         ORDER BY e.event_start DESC",
        [$sellerId]
    );
}

function qb_parse_datetime_local(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

$success = '';
$error = '';
$events = $tableReady ? qb_seller_bazar_events($sid) : [];
$ap = qb_sql_product_approved_plain();
$productRows = $tableReady
    ? db()->fetchAll("SELECT id, name, price, stock, unit FROM products WHERE seller_id = ? AND ($ap) ORDER BY name ASC", [$sid])
    : [];

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token. Refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $pct = (int) ($_POST['discount_pct'] ?? 0);
            $starts = qb_parse_datetime_local((string) ($_POST['starts_at'] ?? ''));
            $ends = qb_parse_datetime_local((string) ($_POST['ends_at'] ?? ''));
            $evRaw = $_POST['event_id'] ?? '';
            $eventId = ($evRaw === '' || $evRaw === '0') ? null : (int) $evRaw;

            $prod = $pid > 0
                ? db()->fetchOne('SELECT * FROM products WHERE id = ? AND seller_id = ?', [$pid, $sid])
                : null;
            if (!$prod) {
                $error = 'Select a valid product.';
            } elseif ($pct < 1 || $pct > 90) {
                $error = 'Discount must be between 1% and 90%.';
            } elseif (!$starts || !$ends) {
                $error = 'Enter valid start and end date/times.';
            } elseif ($starts >= $ends) {
                $error = 'End time must be after start time.';
            } elseif ($eventId !== null && $eventId > 0) {
                $okEv = db()->fetchOne(
                    "SELECT 1
                     FROM bazar_events e
                     WHERE e.id = ?
                       AND e.status IN ('published','live')
                       AND (
                           EXISTS (SELECT 1 FROM stalls st WHERE st.event_id = e.id AND st.seller_id = ?)
                           OR EXISTS (
                               SELECT 1 FROM event_participants ep
                               INNER JOIN sellers s ON s.app_user_id = ep.app_user_id
                               WHERE ep.event_id = e.id
                                 AND ep.role_in_event = 'seller'
                                 AND ep.status = 'approved'
                                 AND s.id = ?
                           )
                       )
                     LIMIT 1",
                    [$eventId, $sid, $sid]
                );
                if (!$okEv) {
                    $error = 'You can only tie a flash sale to a published/live bazar where you are approved or assigned.';
                }
            }
            if ($error === '' && $prod) {
                $orig = (float) $prod['price'];
                if ($orig <= 0) {
                    $error = 'Product must have a positive list price.';
                } else {
                    $sale = round($orig * (1 - $pct / 100), 2);
                    if ($sale <= 0) {
                        $error = 'Sale price would be invalid.';
                    } else {
                        $overlap = db()->fetchOne(
                            "SELECT id FROM flash_sales WHERE product_id = ? AND is_active = 1
                             AND starts_at < ? AND ends_at > ?",
                            [$pid, $ends, $starts]
                        );
                        if ($overlap) {
                            $error = 'This product already has a flash sale in that time window. End or remove it first.';
                        } else {
                            db()->execute(
                                'INSERT INTO flash_sales (product_id, seller_id, event_id, discount_pct, original_price, sale_price, starts_at, ends_at, is_active) VALUES (?,?,?,?,?,?,?,?,1)',
                                [$pid, $sid, $eventId, $pct, $orig, $sale, $starts, $ends]
                            );
                            $success = 'Flash sale scheduled.';
                        }
                    }
                }
            }
        }
    }
}

qb_page_start('seller', 'Flash Sales', 'flash_sale.php', false);
$csrf = qb_csrf_token();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Flash Sales</h1>
    <p class="page-subtitle">Run limited-time discounts on your listings. Buyers see the sale price in your store and at checkout.</p>
  </div>
  <a href="flash_sale_history.php" class="btn btn-secondary btn-sm"><?= qb_icon('list', 'qb-icon', 16) ?> Scheduled &amp; past</a>
</div>

<?php if (!$tableReady): ?>
  <div class="alert alert-warning mb-2">
    The <code>flash_sales</code> table is not installed. Run:
    <code style="display:block;margin-top:0.5rem">php install/migrate_2026_flash_sales.php</code>
  </div>
<?php
  qb_page_end();
  exit;
endif;
?>

<?php if ($success): ?>
  <div class="alert alert-success mb-2"><?= qb_icon('check', 'qb-icon', 16) ?> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger mb-2"><?= qb_icon('alert', 'qb-icon', 16) ?> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:760px;margin-inline:auto">
    <h3 class="font-bold mb-2">New flash sale</h3>
    <?php if (empty($productRows)): ?>
      <p class="text-muted text-sm mb-0">Add approved products first in <a href="products.php">Products</a>.</p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="create">

      <div class="form-group mb-2">
        <label class="form-label">Product</label>
        <select name="product_id" class="form-control" required>
          <?php foreach ($productRows as $pr): ?>
            <option value="<?= (int) $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?> — <?= number_format((float) $pr['price'], 2) ?> ETB (stock <?= (int) $pr['stock'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group mb-2">
        <label class="form-label">Discount (%)</label>
        <input type="number" name="discount_pct" class="form-control" min="1" max="90" value="15" required>
      </div>

      <div class="grid grid-2 gap-2 mb-2">
        <div class="form-group">
          <label class="form-label">Starts</label>
          <input type="datetime-local" name="starts_at" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Ends</label>
          <input type="datetime-local" name="ends_at" class="form-control" required>
        </div>
      </div>

      <div class="form-group mb-2">
        <label class="form-label">Bazar scope (optional)</label>
        <select name="event_id" class="form-control">
          <option value="">All buyers (anywhere)</option>
          <?php foreach ($events as $ev): ?>
            <option value="<?= (int) $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-muted mt-1">If you pick a bazar, only buyers checked into that event get this price. Leave empty for a public flash visible to everyone.</p>
      </div>

      <button type="submit" class="btn btn-primary"><?= qb_icon('flash', 'qb-icon', 18) ?> Schedule</button>
    </form>
    <?php endif; ?>
</div>

<?php qb_page_end(); ?>
