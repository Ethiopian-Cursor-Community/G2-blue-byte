<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

$seller = getCurrentSeller();
if (!$seller) {
    header('Location: ' . APP_URL . '/login.php?portal=seller&error=session');
    exit;
}
$uid = $seller['uid'];
$sid = (int)$seller['id'];
$events = db()->fetchAll(
  "SELECT DISTINCT e.id, e.name
   FROM bazar_events e
   WHERE e.status IN ('published','live')
     AND (
       EXISTS (SELECT 1 FROM stalls st WHERE st.event_id = e.id AND st.seller_id = ?)
       OR EXISTS (
         SELECT 1 FROM event_participants ep
         INNER JOIN sellers s ON s.app_user_id = ep.app_user_id
         WHERE ep.event_id = e.id AND ep.role_in_event = 'seller' AND ep.status = 'approved' AND s.id = ?
       )
     )
   ORDER BY e.event_start DESC",
  [$sid, $sid]
);
$selectedEventId = (int) ($_GET['event_id'] ?? 0);
if ($selectedEventId <= 0 && $events !== []) {
  $selectedEventId = (int) ($events[0]['id'] ?? 0);
}
$eventOk = false;
foreach ($events as $ev) {
  if ((int) ($ev['id'] ?? 0) === $selectedEventId) {
    $eventOk = true;
    break;
  }
}
if (!$eventOk) {
  $selectedEventId = 0;
}
$sellerPassNo = $selectedEventId > 0 ? qb_seller_gate_pass_no($sid, $selectedEventId) : '';
$sellerEntryPayload = $selectedEventId > 0 ? qb_seller_entry_qr_payload($sid, (string) $uid, $selectedEventId, (string) ($seller['qr_secret'] ?? '')) : '';
$gateUnlocked = $selectedEventId > 0 ? qb_seller_gate_is_unlocked($sid, $selectedEventId) : false;
$qrData = '';
if ($selectedEventId > 0 && $gateUnlocked) {
  $ts = time();
  $signature = signQRPayloadTimed($sid, $uid, $seller['qr_secret'], $ts);
  $qrData = $sid . '|' . $uid . '|' . $ts . '|' . $signature;
}

qb_page_start('seller', 'My QR Codes', 'qr.php', false);
?>
<!-- Include QRCode.js for generating QR -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="page-header">
  <div>
    <h1 class="page-title">MyStallQRCode</h1>
    <p class="page-subtitle">Gatekeeper scans seller entry first; then your buyer QR unlocks for this event.</p>
  </div>
  <?php if ($events !== []): ?>
  <form method="get" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <select name="event_id" class="form-control" onchange="this.form.submit()" style="min-width:220px">
      <?php foreach ($events as $ev): ?>
      <option value="<?= (int) ($ev['id'] ?? 0) ?>" <?= (int) ($ev['id'] ?? 0) === $selectedEventId ? 'selected' : '' ?>>
        <?= htmlspecialchars((string) ($ev['name'] ?? 'Event')) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
    <?php if ($qrData !== ''): ?>
      <button type="button" class="btn btn-primary" id="qr-open-full" aria-haspopup="dialog"><?= qb_icon('maximize', 'qb-icon', 18) ?> Full mode</button>
      <button type="button" class="btn btn-secondary" id="qr-buyer-preview-open" aria-haspopup="dialog"><?= qb_icon('eye', 'qb-icon', 18) ?> Buyer preview</button>
      <button type="button" class="btn btn-secondary" onclick="window.print()"><?= qb_icon('printer', 'qb-icon', 18) ?> Print QR</button>
    <?php endif; ?>
  </div>
</div>

<div id="qr-buyer-preview-layer" class="qb-qr-preview" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="qr-preview-title">
  <div class="qb-qr-preview__backdrop" data-close-preview></div>
  <div class="qb-qr-preview__panel">
    <button type="button" class="qb-qr-preview__close btn btn-ghost" id="qr-buyer-preview-close" aria-label="Close preview"><?= qb_icon('x', 'qb-icon', 22) ?></button>
    <h3 id="qr-preview-title" class="font-bold mb-1">What buyers see</h3>
    <p class="text-xs text-muted mb-2">Live preview of your stall page after someone scans this QR (scroll inside).</p>
    <iframe class="qb-qr-preview__frame" title="Buyer store preview" src="<?= htmlspecialchars(APP_URL . '/buyer/vendor.php?uid=' . rawurlencode($uid)) ?>"></iframe>
  </div>
</div>

<div id="qr-fullscreen-layer" class="qb-qr-full" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="qr-full-title">
  <div class="qb-qr-full__backdrop" data-close-full></div>
  <div class="qb-qr-full__panel">
    <button type="button" class="qb-qr-full__close btn btn-ghost" id="qr-close-full" aria-label="Close full mode"><?= qb_icon('x', 'qb-icon', 22) ?></button>
    <h2 id="qr-full-title" class="qb-qr-full__title"><?= htmlspecialchars($seller['market_name']) ?></h2>
    <p class="qb-qr-full__uid">UID <strong><?= htmlspecialchars($uid) ?></strong></p>
    <div id="qrcode-full" class="qb-qr-full__canvas"></div>
    <p class="qb-qr-full__hint text-muted text-sm">Shows a larger QR for buyers at your stall. Press <kbd>Esc</kbd> or tap the backdrop to exit.</p>
    <button type="button" class="btn btn-secondary btn-sm" id="qr-browser-fullscreen"><?= qb_icon('maximize', 'qb-icon', 16) ?> Browser fullscreen</button>
  </div>
</div>

<div id="qr-print-sheet" class="qb-qr-print-sheet" aria-hidden="true">
  <h2 class="qb-qr-print-sheet__title">MyStallQRCode</h2>
  <p class="qb-qr-print-sheet__sub">Buyers scan this to access your stall products.</p>
  <div id="qrcode-print" class="qb-qr-print-sheet__canvas"></div>
  <h3 class="qb-qr-print-sheet__market"><?= htmlspecialchars($seller['market_name']) ?></h3>
  <p class="qb-qr-print-sheet__uid">UID: <?= htmlspecialchars($uid) ?></p>
  <p class="qb-qr-print-sheet__note">This QR code is cryptographically signed to prevent fraud. It expires in 1 hour.only.</p>
</div>

<div class="grid grid-2 gap-3">
  <div class="card" style="text-align:center;padding:3rem 1.5rem">
    <h3 class="font-extrabold mb-1" style="font-size:1.4rem"><?= htmlspecialchars($seller['market_name']) ?></h3>
    <?php if ($selectedEventId > 0): ?>
      <p class="text-sm text-secondary mb-2">Seller Gate Number: <strong><?= htmlspecialchars($sellerPassNo) ?></strong></p>
      <div class="qb-gate-qr-container">
        <div id="seller-entry-qrcode" class="qb-qr-canvas-wrap">
          <div class="qb-qr-placeholder">
            <?= qb_icon('qr', 'qb-icon', 48) ?>
            <span class="text-xs text-muted mt-2">Generating Entry QR...</span>
          </div>
        </div>
        <p class="text-xs text-muted mt-2 mb-3">Show this entry QR to the gatekeeper for verification.</p>
      </div>
    <?php endif; ?>
    <?php if ($qrData !== ''): ?>
      <p class="text-sm text-secondary mb-2">Buyer QR (unlocked)</p>
      <div id="qrcode" style="display:inline-block;padding:1.5rem;background:#fff;border-radius:1rem;box-shadow:0 12px 32px rgba(0,0,0,0.1);margin-bottom:1rem"></div>
      <p class="text-xs text-muted mb-3">Buyer QR is active only after gatekeeper validates your seller entry.</p>
      <a href="?event_id=<?= (int) $selectedEventId ?>&refresh=1" class="btn btn-secondary btn-sm"><?= qb_icon('refresh', 'qb-icon', 16) ?> Refresh buyer QR</a>
    <?php else: ?>
      <div class="alert alert-warning mb-0">Buyer QR is hidden until gatekeeper scans your seller entry QR for this event.</div>
    <?php endif; ?>
  </div>
  
  <div>
    <div class="card mb-3">
      <h3 class="font-bold mb-2">How it works</h3>
      <ol style="padding-left:1.25rem;font-size:0.9rem;color:var(--text-secondary);display:flex;flex-direction:column;gap:0.5rem">
        <li>Print this QR code or let buyers scan it directly from your device.</li>
        <li>The buyer uses their QR Bazar app to scan.</li>
        <li>They instantly see your storefront and product catalog.</li>
        <li>They can purchase and pay with Chapa only.</li>
        <li>Both of you see the transaction confirmation instantly.</li>
      </ol>
    </div>
    
    <div class="alert alert-info">
      <?= qb_icon('info', 'qb-icon', 24) ?>
      <div>
        <strong>Dynamic QR Security</strong>
        <p class="text-xs mt-1">This uses time-based HMAC signatures. If a buyer screenshots your QR and tries to re-use it tomorrow, it will be rejected, ensuring they are physically at your stall.</p>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  function renderQRCodes() {
    if (typeof QRCode === "undefined") {
      setTimeout(renderQRCodes, 100);
      return;
    }
    var payload = <?= json_encode($qrData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var sellerEntryPayload = <?= json_encode($sellerEntryPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    
    var sellerEntryEl = document.getElementById("seller-entry-qrcode");
    if (sellerEntryEl && sellerEntryPayload) {
      sellerEntryEl.innerHTML = "";
      new QRCode(sellerEntryEl, {
        text: sellerEntryPayload,
        width: 220,
        height: 220,
        colorDark : "#1C1917",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
      });
    }
    
    var buyerQrEl = document.getElementById("qrcode");
    if (buyerQrEl && payload) {
      buyerQrEl.innerHTML = "";
      new QRCode(buyerQrEl, {
        text: payload,
        width: 256,
        height: 256,
        colorDark : "#1C1917",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
      });
    }
  }
  renderQRCodes();

  var layer = document.getElementById("qr-fullscreen-layer");
  var fullEl = document.getElementById("qrcode-full");
  var printEl = document.getElementById("qrcode-print");

  function buildFullQr() {
    if (!fullEl || typeof QRCode === "undefined") return;
    fullEl.innerHTML = "";
    var w = Math.min(520, Math.floor((window.innerWidth || 520) - 48));
    w = Math.max(280, w);
    new QRCode(fullEl, {
      text: payload,
      width: w,
      height: w,
      colorDark: "#0f172a",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.H
    });
  }

  function buildPrintQr() {
    if (!printEl || typeof QRCode === "undefined") return;
    printEl.innerHTML = "";
    new QRCode(printEl, {
      text: payload,
      width: 320,
      height: 320,
      colorDark: "#0f172a",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.H
    });
  }

  function openFull() {
    layer.classList.add("is-open");
    layer.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    buildFullQr();
    document.getElementById("qr-close-full").focus();
  }

  function closeFull() {
    layer.classList.remove("is-open");
    layer.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
    if (document.fullscreenElement && document.exitFullscreen) {
      document.exitFullscreen().catch(function () {});
    }
  }

  var openBtn = document.getElementById("qr-open-full");
  var closeBtn = document.getElementById("qr-close-full");
  if (openBtn) openBtn.addEventListener("click", openFull);
  if (closeBtn) closeBtn.addEventListener("click", closeFull);
  layer.querySelectorAll("[data-close-full]").forEach(function (el) {
    el.addEventListener("click", closeFull);
  });

  var browserFs = document.getElementById("qr-browser-fullscreen");
  if (browserFs) browserFs.addEventListener("click", function () {
    var panel = layer.querySelector(".qb-qr-full__panel");
    if (!panel) return;
    if (!document.fullscreenElement) {
      (panel.requestFullscreen || panel.webkitRequestFullscreen || panel.msRequestFullscreen).call(panel).catch(function () {});
    } else if (document.exitFullscreen) {
      document.exitFullscreen();
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && layer.classList.contains("is-open")) {
      closeFull();
    }
  });

  var prevLayer = document.getElementById("qr-buyer-preview-layer");
  function openPreview() {
    if (!prevLayer) return;
    prevLayer.classList.add("is-open");
    prevLayer.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
  }
  function closePreview() {
    if (!prevLayer) return;
    prevLayer.classList.remove("is-open");
    prevLayer.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  }
  var pOpen = document.getElementById("qr-buyer-preview-open");
  var pClose = document.getElementById("qr-buyer-preview-close");
  if (pOpen) pOpen.addEventListener("click", openPreview);
  if (pClose) pClose.addEventListener("click", closePreview);
  if (prevLayer) {
    prevLayer.querySelectorAll("[data-close-preview]").forEach(function (el) {
      el.addEventListener("click", closePreview);
    });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && prevLayer && prevLayer.classList.contains("is-open")) {
      closePreview();
    }
  });
  buildPrintQr();
})();
</script>

<!-- Hide navs when printing -->
<style>
.qb-qr-full {
  position: fixed;
  inset: 0;
  z-index: 10050;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
  pointer-events: none;
  visibility: hidden;
  opacity: 0;
  transition: opacity 0.25s ease, visibility 0.25s;
}
.qb-qr-full.is-open {
  pointer-events: auto;
  visibility: visible;
  opacity: 1;
}
.qb-qr-full__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(6px);
  cursor: pointer;
}
.qb-qr-full__panel {
  position: relative;
  z-index: 1;
  max-width: min(100vw - 2rem, 36rem);
  width: 100%;
  margin: 1rem;
  padding: 1.75rem 1.5rem 1.5rem;
  background: var(--bg-card, #fff);
  color: var(--text, #0f172a);
  border-radius: 1.25rem;
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35);
  text-align: center;
}
html[data-theme="dark"] .qb-qr-full__panel {
  background: #1e293b;
  color: #f1f5f9;
}
.qb-qr-full__close {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  padding: 0.35rem !important;
  min-width: 2.5rem;
  border-radius: 0.75rem;
}
.qb-qr-full__title {
  font-size: 1.35rem;
  font-weight: 800;
  margin: 0 2rem 0.35rem 0;
  line-height: 1.2;
}
.qb-qr-full__uid {
  font-size: 0.9rem;
  margin-bottom: 1rem;
  color: var(--text-secondary, #64748b);
}
.qb-qr-full__canvas {
  display: inline-block;
  padding: 1rem;
  background: #fff;
  border-radius: 1rem;
  margin-bottom: 0.75rem;
  box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.06);
}
.qb-qr-full__canvas img,
.qb-qr-full__canvas canvas {
  display: block !important;
  max-width: 100%;
  height: auto !important;
}
.qb-qr-full__canvas img + canvas,
.qb-qr-full__canvas canvas + img {
  display: none !important;
}
.qb-qr-full__hint {
  margin-bottom: 0.75rem;
}
.qb-qr-full__hint kbd {
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
  background: var(--bg-elevated, #f1f5f9);
  font-size: 0.75rem;
}
@media print {
  .qb-qr-full { display: none !important; }
}
@media print {
  body { background: #fff; }
  .navbar, .sidebar, .page-header button, .btn { display: none !important; }
  .main-content { margin: 0; padding: 0; }
  .card { border: none; box-shadow: none; }
}
.qb-qr-preview {
  position: fixed;
  inset: 0;
  z-index: 10040;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
  pointer-events: none;
  visibility: hidden;
  opacity: 0;
  transition: opacity 0.2s ease, visibility 0.2s;
}
.qb-qr-preview.is-open {
  pointer-events: auto;
  visibility: visible;
  opacity: 1;
}
.qb-qr-preview__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(4px);
  cursor: pointer;
}
.qb-qr-preview__panel {
  position: relative;
  z-index: 1;
  width: min(100vw - 2rem, 28rem);
  max-height: min(88vh, 720px);
  background: var(--bg-card);
  border-radius: 1rem;
  padding: 1rem 1rem 0.75rem;
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35);
  display: flex;
  flex-direction: column;
}
.qb-qr-preview__close {
  position: absolute;
  top: 0.35rem;
  right: 0.35rem;
}
.qb-qr-preview__frame {
  width: 100%;
  flex: 1;
  min-height: 360px;
  border: 0;
  border-radius: 0.65rem;
  background: var(--bg);
}
.qb-qr-print-sheet {
  display: none;
  text-align: center;
}
.qb-qr-print-sheet__title {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  font-weight: 800;
}
.qb-qr-print-sheet__sub {
  margin: 0 0 0.9rem;
  font-size: 0.9rem;
}
.qb-qr-print-sheet__canvas {
  display: inline-block;
  padding: 0.65rem;
  background: #fff;
  border-radius: 0.5rem;
  border: 1px solid #e5e7eb;
  margin-bottom: 0.85rem;
}
.qb-qr-print-sheet__canvas img,
.qb-qr-print-sheet__canvas canvas {
  display: block !important;
}
.qb-qr-print-sheet__canvas img + canvas,
.qb-qr-print-sheet__canvas canvas + img {
  display: none !important;
}
.qb-qr-print-sheet__market {
  margin: 0.25rem 0;
  font-size: 1.1rem;
  font-weight: 700;
}
.qb-qr-print-sheet__uid {
  margin: 0.2rem 0 0.65rem;
}
.qb-qr-print-sheet__note {
  margin: 0;
  font-size: 0.76rem;
  color: #4b5563;
}
@media print {
  .page-header,
  .grid,
  .qb-qr-full,
  .qb-qr-preview,
  #qrcode,
  #qrcode-full,
  .navbar,
  .topbar,
  .sidebar,
  .status-bar,
  .mobile-nav,
  .mobile-nav--seller,
  .mobile-nav-item,
  .skip-link,
  #qb-portal-marquee-root,
  .qb-marquee-wrap,
  .qb-marquee-pop--bottom {
    display: none !important;
  }
  .app-layout,
  .main-content {
    display: block !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  .main-content > * {
    display: none !important;
  }
  @page {
    margin: 10mm;
  }
  .qb-qr-print-sheet {
    display: block !important;
    page-break-inside: avoid;
    margin-top: 10mm;
  }
}
</style>

<?php qb_page_end(); ?>
