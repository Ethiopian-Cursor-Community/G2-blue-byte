<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
qb_require_buyer_portal_mobile();
$u = appCurrentUser();
$ok = isset($_GET['ok']);
?>
<!DOCTYPE html>
<html lang="en" class="shell-light buyer-shell">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Report — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <link rel="stylesheet" href="../assets/css/shell-light.css"/>
  <link rel="stylesheet" href="../assets/css/buyer-mobile.css"/>
  <link rel="manifest" href="manifest.json"/>
</head>
<body>

<div class="buyer-topbar">
  <a href="home.php" style="font-weight:800;text-decoration:none;color:inherit">← Home</a>
</div>

<div class="buyer-page">
  <h1 style="font-size:1.25rem">Report an issue</h1>
  <p class="text-sm text-secondary" style="margin-bottom:1rem">Moderators review reports by priority. Include what happened and when.</p>
  <?php if ($ok): ?>
    <div class="buyer-card" style="margin-bottom:1rem">Thank you — your report was submitted.</div>
  <?php endif; ?>

  <form class="buyer-card" id="report-form">
    <div class="form-group">
      <label class="form-label">Type</label>
      <select name="target_type" class="form-control" id="rt">
        <option value="seller">Seller</option>
        <option value="product">Product</option>
        <option value="behavior">Behavior / safety</option>
        <option value="event">Event</option>
        <option value="promo">Community promo</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Target ID (UID, product id, or describe)</label>
      <input type="text" name="target_id" class="form-control" id="tid" placeholder="e.g. SEL001ABEBE" required/>
    </div>
    <div class="form-group">
      <label class="form-label">Details</label>
      <textarea name="body" class="form-control" rows="4" required placeholder="What should we know?"></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-full">Submit report</button>
  </form>
</div>

<nav class="buyer-bottom-nav">
  <a href="home.php"><span class="ico">⌂</span>Home</a>
  <a href="scan.php"><span class="ico">▣</span>Scan</a>
  <a href="favorites.php"><span class="ico">★</span>Saved</a>
  <a href="profile.php"><span class="ico">◎</span>Profile</a>
</nav>

<script>
document.getElementById('report-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = {
    target_type: fd.get('target_type'),
    target_id: fd.get('target_id'),
    body: fd.get('body')
  };
  try {
    const res = await fetch('../api/reports.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const data = await res.json();
    if (data.success) {
      window.location.href = 'report.php?ok=1';
    } else {
      alert(data.error || 'Failed');
    }
  } catch (_) {
    alert('Network error');
  }
});
</script>
</body>
</html>
