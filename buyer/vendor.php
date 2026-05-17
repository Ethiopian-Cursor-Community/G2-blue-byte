<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyerOrSeller();

$uid = (int)$_SESSION['app_user_id'];
$buyerName = $_SESSION['app_name'];
$shopBackHref = (function_exists('currentRole') && currentRole() === 'seller') ? '../seller/dashboard.php' : 'home.php';

if (qb_table_exists('bazar_events')) {
    db()->execute("
        CREATE TABLE IF NOT EXISTS event_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            seller_id INT NOT NULL,
            product_id INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_product (event_id, seller_id, product_id),
            KEY idx_ep_seller_event (seller_id, event_id),
            KEY idx_ep_product (product_id)
        )
    ");
}

$sellerUid = $_GET['uid'] ?? '';
$qrRaw = $_GET['qr'] ?? '';

// If accessed via QR scan
if ($qrRaw) {
    $parts = explode('|', $qrRaw);
    if (count($parts) >= 4) {
        $sellerId = (int)$parts[0];
        $sellerUid = $parts[1];
        $ts = (int)$parts[2];
        $sig = $parts[3];
        
        // Log analytics scan
        db()->execute("INSERT INTO analytics_events (seller_id, event_type, event_hour, event_date) VALUES (?, 'qr_scan', HOUR(NOW()), CURDATE())", [$sellerId]);
        
        if (!verifyQRTimed($sellerId, $sellerUid, $sig, $ts)) {
            qb_page_start('buyer', 'Invalid QR', 'scan.php');
            echo "<div class='empty-state'><h3>Invalid or expired seller QR</h3><p>Please ask the seller to refresh their QR at the gate.</p></div>";
            qb_page_end();
            exit;
        }
    } else {
        // Legacy fallback
        $sellerUid = $qrRaw;
    }
}

$seller = db()->fetchOne("SELECT * FROM sellers WHERE uid = ?", [$sellerUid]);
if (!$seller) {
    qb_page_start('buyer', 'Store Not Found', 'scan.php');
    echo "<div class='empty-state'><h3>Vendor not found</h3></div>";
    qb_page_end(); exit;
}
$sid = (int)$seller['id'];

if (currentRole() === 'seller') {
    $mySeller = getCurrentSeller();
    if ($mySeller && (int) $mySeller['id'] === $sid) {
        qb_page_start('buyer', 'Your stall', 'vendor.php', false);
        ?>
        <div class="buyer-dashboard"><div class="buyer-main">
          <a href="<?= htmlspecialchars($shopBackHref) ?>" class="btn btn-ghost btn-sm mb-2" style="padding-left:0">&larr; Back</a>
          <div class="empty-state">
            <h3>Your stall</h3>
            <p class="text-sm text-secondary">You can&apos;t purchase from your own store. Open <strong>Shop stalls</strong> and scan another seller&apos;s QR to buy.</p>
          </div>
        </div></div>
        <?php
        qb_page_end();
        exit;
    }
}

$buyerEventId = 0;
$mode = function_exists('qb_event_mode_get') ? qb_event_mode_get($uid) : [];
if (!empty($mode['event_id']) && (string) ($mode['mode_source'] ?? '') === 'ticket_scan') {
    $buyerEventId = (int) $mode['event_id'];
}
if (currentRole() === 'buyer' && $buyerEventId <= 0) {
    qb_page_start('buyer', 'Gate scan required', 'scan.php', false);
    ?>
    <div class="buyer-dashboard"><div class="buyer-main">
      <a href="home.php" class="btn btn-ghost btn-sm mb-2" style="padding-left:0">&larr; Back</a>
      <div class="empty-state">
        <h3>Gate scan required</h3>
        <p class="text-sm text-secondary">Buyer shopping activates only after your ticket is scanned by a gatekeeper.</p>
      </div>
    </div></div>
    <?php
    qb_page_end();
    exit;
}

$sellerLiveEvents = db()->fetchAll(
    "SELECT DISTINCT st.event_id
     FROM stalls st
     INNER JOIN bazar_events e ON e.id = st.event_id
     WHERE st.seller_id = ? AND e.status IN ('published','live')",
    [$sid]
);
$sellerEventIds = [];
foreach ($sellerLiveEvents as $se) {
    $eid = (int) ($se['event_id'] ?? 0);
    if ($eid > 0) {
        $sellerEventIds[$eid] = true;
    }
}
if (currentRole() === 'buyer' && $buyerEventId > 0 && !isset($sellerEventIds[$buyerEventId])) {
    qb_page_start('buyer', 'Not available in your event', 'scan.php', false);
    echo "<div class='empty-state'><h3>Seller not available in your scanned event</h3></div>";
    qb_page_end();
    exit;
}
if (currentRole() === 'buyer' && $buyerEventId > 0 && function_exists('qb_seller_gate_is_unlocked') && !qb_seller_gate_is_unlocked($sid, $buyerEventId)) {
    qb_page_start('buyer', 'Seller gate not unlocked', 'scan.php', false);
    echo "<div class='empty-state'><h3>Seller not yet gate-validated</h3><p class='text-sm text-secondary'>Gatekeeper must scan seller entry QR once before buyers can open this stall.</p></div>";
    qb_page_end();
    exit;
}

$ap = qb_sql_product_approved();
$products = db()->fetchAll("SELECT p.* FROM products p WHERE p.seller_id = ? AND p.is_available = 1 AND ($ap)", [$sid]);

$assignedProductIds = [];
if ($buyerEventId > 0 && qb_table_exists('event_products') && isset($sellerEventIds[$buyerEventId])) {
    $assignedRows = db()->fetchAll(
        "SELECT product_id FROM event_products WHERE seller_id = ? AND event_id = ? AND is_active = 1",
        [$sid, $buyerEventId]
    );
    foreach ($assignedRows as $ar) {
        $pid = (int) ($ar['product_id'] ?? 0);
        if ($pid > 0) {
            $assignedProductIds[$pid] = true;
        }
    }
}

if ($assignedProductIds !== []) {
    $products = array_values(array_filter($products, static function (array $p) use ($assignedProductIds): bool {
        return isset($assignedProductIds[(int) ($p['id'] ?? 0)]);
    }));
}

$flashMap = qb_flash_sales_active_map($sid, $buyerEventId);
foreach ($products as $i => $p) {
    $products[$i] = qb_product_with_pricing($p, $flashMap);
}

$soldTodayMap = function_exists('qb_products_sold_today_map')
    ? qb_products_sold_today_map(array_column($products, 'id'))
    : [];

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'checkout_cart') {
        $method = sanitize($_POST['payment_method'] ?? '');
        $rlKey = 'vendor_checkout_submit_' . $uid . '_' . $sid;
        if (!qb_rate_limit_allow($rlKey, 5, 60)) {
            $error = 'Too many checkout attempts. Please retry in a minute.';
        } else {
            qb_rate_limit_hit($rlKey, 60);
        }
        if ($error === '') {
            $token = (string) ($_POST['purchase_token'] ?? '');
            $sessionToken = (string) ($_SESSION['vendor_purchase_token'] ?? '');
            if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
                $error = 'Purchase session expired. Please try again.';
            } elseif (time() - (int) ($_SESSION['vendor_checkout_last_ts'] ?? 0) < 3) {
                $error = 'Please wait a moment before checkout again.';
            } else {
            $_SESSION['vendor_checkout_last_ts'] = time();
            $_SESSION['vendor_purchase_token'] = bin2hex(random_bytes(16));
            $cartRaw = (string) ($_POST['cart_json'] ?? '[]');
            $cart = json_decode($cartRaw, true);
            if (!is_array($cart) || $cart === []) {
                $error = 'Cart is empty.';
            } elseif ($method !== 'chapa') {
                $error = 'Only Chapa payment is enabled.';
            } elseif (!qb_chapa_ready()) {
                $error = 'Chapa is not configured on this server yet.';
            } else {
                $mapBuy = qb_flash_sales_active_map($sid, $buyerEventId);
                $productRows = db()->fetchAll("SELECT * FROM products WHERE seller_id = ? AND is_available = 1 AND ($ap)", [$sid]);
                $byId = [];
                foreach ($productRows as $pr) {
                    $byId[(int) ($pr['id'] ?? 0)] = $pr;
                }
                $cartItems = [];
                $grandTotal = 0.0;
                foreach ($cart as $line) {
                    $pid = (int) ($line['product_id'] ?? 0);
                    $qty = max(1, (int) ($line['qty'] ?? 0));
                    if ($pid <= 0 || !isset($byId[$pid])) {
                        continue;
                    }
                    if ($assignedProductIds !== [] && !isset($assignedProductIds[$pid])) {
                        continue;
                    }
                    $prod = $byId[$pid];
                    $stock = (int) ($prod['stock'] ?? 0);
                    if ($stock < $qty) {
                        $error = 'Some items are out of stock.';
                        break;
                    }
                    $pricing = qb_resolve_product_pricing($prod, $mapBuy[$pid] ?? null);
                    $unitPrice = (float) ($pricing['unit_price'] ?? $prod['price'] ?? 0);
                    if ($unitPrice <= 0) {
                        continue;
                    }
                    $subtotal = round($unitPrice * $qty, 2);
                    $cartItems[] = [
                        'product_id' => $pid,
                        'product_name' => (string) ($prod['name'] ?? 'Product'),
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                    ];
                    $grandTotal += $subtotal;
                }
                if ($error === '' && $cartItems === []) {
                    $error = 'No valid items in cart.';
                }
                if ($error === '' && $grandTotal > 0) {
                    $txId = generateTxId();
                    $eventId = $buyerEventId > 0 ? $buyerEventId : 0;
                    $intent = qb_payment_intent_create(
                        $uid,
                        'product_purchase',
                        'cart:' . $sid,
                        (float) $grandTotal,
                        [
                            'tx_id' => $txId,
                            'seller_id' => (int) $sid,
                            'event_id' => (int) $eventId,
                            'buyer_name' => (string) $buyerName,
                            'cart_items' => $cartItems,
                            'idempotency_key' => hash('sha256', $uid . '|' . $sid . '|' . $txId . '|' . $grandTotal),
                        ]
                    );
                    $intentId = (string) ($intent['intent_id'] ?? '');
                    qb_track_event('product.checkout.intent_created', [
                        'seller_id' => (int) $sid,
                        'event_id' => (int) $eventId,
                        'items' => count($cartItems),
                        'amount' => (float) $grandTotal,
                    ], $uid, 'payment_intent', $intentId);
                    $intentRow = qb_payment_intent_get((string) $intent['intent_id']);
                    if (!$intentRow) {
                        $error = 'Could not create payment intent.';
                    } else {
                        $u = currentUser() ?? [];
                        $start = qb_chapa_checkout_start($intentRow, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? 'Buyer'), (string) ($u['phone'] ?? ''));
                        if (!$start['ok']) {
                            qb_track_event('product.checkout.start_failed', [
                                'seller_id' => (int) $sid,
                                'intent_id' => $intentId,
                                'error' => (string) ($start['error'] ?? 'unknown'),
                            ], $uid, 'payment_intent', $intentId);
                            qb_audit_log('payment.chapa.init_failed', 'payment_intents', (int) ($intentRow['id'] ?? 0), [
                                'flow' => 'product_purchase_cart',
                                'seller_id' => (int) $sid,
                                'error' => (string) ($start['error'] ?? 'unknown'),
                            ]);
                            $nextUrl = APP_URL . '/buyer/vendor.php?uid=' . rawurlencode((string) $sellerUid);
                            $failUrl = APP_URL . '/buyer/payment_result.php?intent=' . rawurlencode((string) ($intentRow['intent_id'] ?? '')) . '&status=failed&error=' . rawurlencode((string) ($start['error'] ?? 'Could not start Chapa checkout.')) . '&next=' . rawurlencode($nextUrl);
                            header('Location: ' . $failUrl, true, 302);
                            exit;
                        }
                        qb_track_event('product.checkout.started', [
                            'seller_id' => (int) $sid,
                            'event_id' => (int) $eventId,
                            'intent_id' => $intentId,
                        ], $uid, 'payment_intent', $intentId);
                        header('Location: ' . (string) $start['checkout_url'], true, 302);
                        exit;
                    }
                }
            }
        }
        }
    }
}

if (empty($_SESSION['vendor_purchase_token'])) {
    $_SESSION['vendor_purchase_token'] = bin2hex(random_bytes(16));
}

$trust = getTrustBadge(computeTrustScore($sid));
$sellerRankText = '';
$rankRows = qb_lb_sellers_global(200);
foreach ($rankRows as $rr) {
    if ((int) ($rr['seller_id'] ?? 0) === $sid) {
        $sellerRankText = '#' . (int) ($rr['rank'] ?? 0) . ' seller platform-wide';
        break;
    }
}

// Desktop overrides the mobile bottom nav
qb_page_start('buyer', $seller['market_name'], 'vendor.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main">
  <div style="margin-bottom:1.5rem">
    <a href="<?= htmlspecialchars($shopBackHref) ?>" class="btn btn-ghost btn-sm mb-2" style="padding-left:0">&larr; Back</a>
    <div style="display:flex;justify-content:space-between;align-items:flex-start">
      <div>
        <h1 class="font-black text-2xl"><?= htmlspecialchars($seller['market_name']) ?></h1>
        <p class="text-sm text-secondary"><?= htmlspecialchars($seller['full_name']) ?> • <?= htmlspecialchars($seller['category']) ?><?= $sellerRankText !== '' ? ' • ' . htmlspecialchars($sellerRankText) : '' ?></p>
      </div>
      <span class="badge <?= $trust['class'] ?>"><?= qb_icon($trust['icon_key'], 'qb-icon', 14) ?> <?= $trust['label'] ?></span>
    </div>
  </div>

  <?php if(isset($error)): ?>
     <div class="alert alert-danger mb-2"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($buyerEventId > 0): ?>
    <div class="alert alert-info mb-2">Showing products eligible for your active event purchase flow.</div>
  <?php endif; ?>

  <h3 class="font-bold text-sm text-uppercase text-muted mb-2">Products</h3>
  <div class="grid gap-2">
    <?php foreach($products as $p): ?>
    <div class="card" style="display:flex;justify-content:space-between;align-items:center;padding:1rem">
      <div>
        <div class="font-bold text-lg"><?= htmlspecialchars($p['name']) ?></div>
        <div class="text-xs text-muted mb-1"><?= htmlspecialchars($p['description']) ?></div>
        <div class="font-black">
          <?php if (($p['qb_discount_badge'] ?? '') === 'flash' && !empty($p['qb_flash'])): ?>
            <span class="qb-flash-strike text-muted"><?= number_format($p['qb_list_price'], 2) ?></span>
            <span class="text-emerald"><?= number_format($p['qb_unit_price'], 2) ?> ETB</span>
            <span class="qb-flash-pill"><?= (int)($p['qb_flash']['discount_pct'] ?? 0) ?>% flash</span>
          <?php elseif (($p['qb_discount_badge'] ?? '') === 'regular' && (int)($p['qb_regular_discount_pct'] ?? 0) > 0): ?>
            <span class="qb-flash-strike text-muted"><?= number_format($p['qb_list_price'], 2) ?></span>
            <span class="text-emerald"><?= number_format($p['qb_unit_price'], 2) ?> ETB</span>
            <span class="qb-discount-pill"><?= (int) $p['qb_regular_discount_pct'] ?>% off</span>
          <?php else: ?>
            <span class="text-emerald"><?= number_format($p['qb_unit_price'], 2) ?> ETB</span>
          <?php endif; ?>
          / <?= htmlspecialchars((string) $p['unit']) ?>
        </div>
        <?php
          $soldN = (int) ($soldTodayMap[(int) ($p['id'] ?? 0)] ?? 0);
        if ($soldN > 0):
        ?>
        <div class="qb-vendor-sold-today"><?= (int) $soldN ?> sold today</div>
        <?php endif; ?>
      </div>
      <div>
        <?php if($p['stock'] > 0): ?>
          <button class="btn btn-primary btn-sm" type="button" onclick='addToCart(<?= json_encode([
            'id' => (int) ($p['id'] ?? 0),
            'name' => (string) ($p['name'] ?? ''),
            'price' => (float) ($p['qb_unit_price'] ?? $p['price'] ?? 0),
            'stock' => (int) ($p['stock'] ?? 0),
            'unit' => (string) ($p['unit'] ?? 'unit'),
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>)'>Add to Cart</button>
        <?php else: ?>
          <span class="badge badge-gray text-xs">Out of Stock</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<button id="cartChip" class="btn btn-primary" type="button" style="position:fixed;right:1rem;bottom:5.5rem;z-index:1200;display:none;align-items:center;gap:0.45rem;border-radius:999px">
  <?= qb_icon('cart', 'qb-icon', 16) ?> <strong id="cartChipCount">0</strong> · <span id="cartChipTotal">0.00 ETB</span>
</button>

<div id="cartDrawer" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.45);z-index:1300;">
  <div class="card" style="position:absolute;right:0;top:0;height:100%;width:min(420px,92vw);overflow:auto;border-radius:0;padding:1rem 1rem 5rem 1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem">
      <h3 class="font-bold">Your cart</h3>
      <button class="btn btn-ghost btn-sm" type="button" onclick="closeCartDrawer()"><?= qb_icon('x', 'qb-icon', 16) ?></button>
    </div>
    <div id="cartItemsHost" class="grid gap-2"></div>
    <form method="post" class="js-pay-submit" style="position:sticky;bottom:0;background:var(--bg-card);padding-top:0.75rem;border-top:1px solid var(--border);margin-top:0.75rem">
      <input type="hidden" name="action" value="checkout_cart">
      <input type="hidden" name="payment_method" value="chapa">
      <input type="hidden" name="purchase_token" value="<?= htmlspecialchars((string) ($_SESSION['vendor_purchase_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" id="cartPayload" name="cart_json" value="[]">
      <div class="text-sm mb-2">Total: <strong id="cartDrawerTotal">0.00 ETB</strong></div>
      <button id="cartCheckoutBtn" type="submit" class="btn btn-primary btn-full" disabled>Pay with Chapa</button>
    </form>
  </div>
</div>

<script>
var cart = {};
var cartStoreKey = 'qb_cart_vendor_' + <?= json_encode((string) $sid) ?> + '_' + <?= json_encode((string) $buyerEventId) ?>;
var cartChip = document.getElementById('cartChip');
var cartChipCount = document.getElementById('cartChipCount');
var cartChipTotal = document.getElementById('cartChipTotal');
var cartDrawer = document.getElementById('cartDrawer');
var cartItemsHost = document.getElementById('cartItemsHost');
var cartDrawerTotal = document.getElementById('cartDrawerTotal');
var cartPayload = document.getElementById('cartPayload');
var cartCheckoutBtn = document.getElementById('cartCheckoutBtn');

function addToCart(p) {
  var id = String(p.id || '');
  if (!id) return;
  if (!cart[id]) {
    cart[id] = { product_id: Number(p.id), name: String(p.name || 'Product'), price: Number(p.price || 0), qty: 0, stock: Number(p.stock || 0), unit: String(p.unit || 'unit') };
  }
  cart[id].qty = Math.min(cart[id].stock, cart[id].qty + 1);
  refreshCartUI();
}

function updateLine(id, delta) {
  if (!cart[id]) return;
  cart[id].qty = Math.max(0, Math.min(cart[id].stock, cart[id].qty + delta));
  if (cart[id].qty <= 0) delete cart[id];
  refreshCartUI();
}

function openCartDrawer() {
  if (!cartDrawer) return;
  cartDrawer.style.display = 'block';
}

function closeCartDrawer() {
  if (!cartDrawer) return;
  cartDrawer.style.display = 'none';
}

function refreshCartUI() {
  var entries = Object.keys(cart).map(function (k) { return cart[k]; }).filter(function (x) { return x.qty > 0; });
  var totalQty = 0;
  var totalCost = 0;
  entries.forEach(function (x) {
    totalQty += x.qty;
    totalCost += x.qty * x.price;
  });
  if (cartChip) {
    cartChip.style.display = entries.length ? 'inline-flex' : 'none';
  }
  if (cartChipCount) cartChipCount.textContent = String(totalQty);
  if (cartChipTotal) cartChipTotal.textContent = totalCost.toFixed(2) + ' ETB';
  if (cartDrawerTotal) cartDrawerTotal.textContent = totalCost.toFixed(2) + ' ETB';
  if (cartPayload) {
    var payload = entries.map(function (x) { return { product_id: x.product_id, qty: x.qty }; });
    cartPayload.value = JSON.stringify(payload);
  }
  try {
    sessionStorage.setItem(cartStoreKey, JSON.stringify(entries.map(function (x) {
      return { product_id: x.product_id, name: x.name, price: x.price, qty: x.qty, stock: x.stock, unit: x.unit };
    })));
  } catch (e) {}
  if (cartCheckoutBtn) cartCheckoutBtn.disabled = entries.length === 0;
  if (cartItemsHost) {
    cartItemsHost.innerHTML = entries.length ? entries.map(function (x) {
      var subtotal = (x.qty * x.price).toFixed(2);
      return '<div class="card" style="padding:0.65rem">' +
        '<div class="font-bold">' + escapeHtml(x.name) + '</div>' +
        '<div class="text-xs text-muted">' + x.price.toFixed(2) + ' ETB / ' + escapeHtml(x.unit) + '</div>' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.35rem">' +
          '<div style="display:flex;gap:0.35rem;align-items:center">' +
            '<button type="button" class="btn btn-ghost btn-sm" onclick="updateLine(\'' + String(x.product_id) + '\',-1)">-</button>' +
            '<strong>' + String(x.qty) + '</strong>' +
            '<button type="button" class="btn btn-ghost btn-sm" onclick="updateLine(\'' + String(x.product_id) + '\',1)">+</button>' +
          '</div>' +
          '<strong>' + subtotal + ' ETB</strong>' +
        '</div>' +
      '</div>';
    }).join('') : '<div class="text-sm text-muted">Cart is empty. Add products to continue.</div>';
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c;
  });
}

if (cartChip) cartChip.addEventListener('click', openCartDrawer);
if (cartDrawer) {
  cartDrawer.addEventListener('click', function (e) {
    if (e.target === cartDrawer) closeCartDrawer();
  });
}
try {
  var raw = sessionStorage.getItem(cartStoreKey);
  if (raw) {
    var parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      parsed.forEach(function (x) {
        var id = String(x.product_id || '');
        if (!id) return;
        cart[id] = {
          product_id: Number(x.product_id || 0),
          name: String(x.name || 'Product'),
          price: Number(x.price || 0),
          qty: Math.max(0, Number(x.qty || 0)),
          stock: Math.max(0, Number(x.stock || 0)),
          unit: String(x.unit || 'unit')
        };
      });
    }
  }
} catch (e) {}
refreshCartUI();
</script>
<script>
document.querySelectorAll('form.js-pay-submit').forEach(function(form){
  form.addEventListener('submit', function(){
    var btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Redirecting...';
    try { sessionStorage.removeItem(cartStoreKey); } catch (e) {}
  });
});
</script>

<?php qb_page_end(); ?>
