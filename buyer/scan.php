<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyerOrSeller();

$user = currentUser();
$uid = (int)$user['id'];
$scanCloseHref = (function_exists('currentRole') && currentRole() === 'seller') ? '../seller/dashboard.php' : 'home.php';

if (currentRole() === 'buyer') {
  $mode = function_exists('qb_event_mode_get') ? qb_event_mode_get($uid) : null;
  $hasGateScan = !empty($mode['event_id']) && (string) ($mode['mode_source'] ?? '') === 'ticket_scan';
  if (!$hasGateScan) {
    qb_page_start('buyer', 'Gate scan required', 'scan.php', false);
    ?>
    <div class="buyer-dashboard"><div class="buyer-main">
      <a href="home.php" class="btn btn-ghost btn-sm mb-2" style="padding-left:0">&larr; Back</a>
      <div class="empty-state">
        <h3>Gatekeeper scan required</h3>
        <p class="text-sm text-secondary">Buyer scanning is enabled only after your ticket QR is scanned at the gate.</p>
      </div>
    </div></div>
    <?php
    qb_page_end();
    exit;
  }
}

// Desktop Block
if (!isMobileDevice()) {
    header('Location: ' . $scanCloseHref . '?notice=mobile_scan_only', true, 302);
    exit;
}

// Mobile Scan UI
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
  <title>Scan QR — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <link rel="stylesheet" href="../assets/css/mobile.css"/>
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
</head>
<body class="qb-qr-scan-body">

<div class="qr-scanner-container">
  <header class="qr-scanner-bar qr-scanner-bar--top">
    <a href="<?= htmlspecialchars($scanCloseHref) ?>" class="qr-scanner-icon-btn" aria-label="Close scanner">
      <?= qb_icon('x', 'qb-icon', 24) ?>
    </a>
    <div class="qr-scanner-title">Scan product</div>
    <button type="button" id="torch-btn" class="qr-scanner-icon-btn qr-scanner-icon-btn--torch" aria-label="Toggle flashlight">
      <?= qb_icon('lightning', 'qb-icon', 24) ?>
    </button>
  </header>

  <div class="qr-video-wrap">
    <video id="qr-video" playsinline></video>
    <div class="qr-overlay">
      <div class="qr-frame-stack">
        <div class="qr-frame" aria-hidden="true">
          <span class="qr-frame__corner qr-frame__corner--tl"></span>
          <span class="qr-frame__corner qr-frame__corner--tr"></span>
          <span class="qr-frame__corner qr-frame__corner--bl"></span>
          <span class="qr-frame__corner qr-frame__corner--br"></span>
          <div class="qr-scan-line"></div>
        </div>
        <p class="qr-hint">Position QR code inside the frame</p>
      </div>
    </div>
    <canvas id="qr-canvas" style="display:none;"></canvas>
  </div>

  <footer class="qr-scanner-bar qr-scanner-bar--bottom">
    <div class="qr-scanner-foot-note">Powered by <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></div>
  </footer>
</div>

<script>
const video = document.getElementById("qr-video");
const canvasElement = document.getElementById("qr-canvas");
const canvas = canvasElement.getContext("2d", { willReadFrequently: true });
let scanning = false;
let stream = null;

async function startCamera() {
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
    video.srcObject = stream;
    video.setAttribute("playsinline", true);
    video.play();
    const torchBtn = document.getElementById('torch-btn');
    const track = stream.getVideoTracks()[0];
    if (torchBtn && track && track.getCapabilities) {
      var caps = track.getCapabilities();
      if (!caps.torch) {
        torchBtn.disabled = true;
        torchBtn.classList.add('is-disabled');
      }
    }
    requestAnimationFrame(tick);
    scanning = true;
  } catch (err) {
    alert("Camera error: Please ensure you have granted camera permissions.");
  }
}

function tick() {
  if (!scanning) return;
  if (video.readyState === video.HAVE_ENOUGH_DATA) {
    canvasElement.height = video.videoHeight;
    canvasElement.width = video.videoWidth;
    canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height);
    const imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height);
    const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: "dontInvert" });
    
    if (code) {
      handleScannedData(code.data);
    }
  }
  requestAnimationFrame(tick);
}

function handleScannedData(data) {
  if(!scanning) return;
  scanning = false; // pause scanning
  
  if (stream) {
    stream.getTracks().forEach(track => track.stop());
  }

  if (navigator.vibrate) {
    try { navigator.vibrate(14); } catch (e) {}
  }

  // Basic signature verify call via API
  window.location.href = `vendor.php?qr=${encodeURIComponent(data)}`;
}

document.getElementById('torch-btn')?.addEventListener('click', async function () {
  if (!stream) return;
  const track = stream.getVideoTracks()[0];
  if (!track || !track.getCapabilities || !track.applyConstraints) return;
  const caps = track.getCapabilities();
  if (!caps.torch) return;
  const cur = track.getSettings().torch === true;
  try {
    await track.applyConstraints({ advanced: [{ torch: !cur }] });
    this.classList.toggle('is-on', !cur);
  } catch (e) {}
});

startCamera();
</script>
</body>
</html>
