<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
qb_require_buyer_portal_mobile();
?>
<!DOCTYPE html>
<html lang="en" class="shell-light buyer-shell">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Activity — <?= htmlspecialchars(APP_NAME) ?></title>
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
  <h1 style="font-size:1.25rem">Live activity</h1>
  <p class="text-xs text-muted" style="margin-bottom:1rem">Anonymized recent purchases (demo data grows as transactions are recorded).</p>
  <div id="feed" class="buyer-card" style="min-height:120px">Loading…</div>
</div>

<nav class="buyer-bottom-nav">
  <a href="home.php"><span class="ico">⌂</span>Home</a>
  <a href="scan.php"><span class="ico">▣</span>Scan</a>
  <a href="favorites.php"><span class="ico">★</span>Saved</a>
  <a href="profile.php"><span class="ico">◎</span>Profile</a>
</nav>

<script>
(async () => {
  const el = document.getElementById('feed');
  try {
    const res = await fetch('../api/activity_feed.php?limit=20');
    const data = await res.json();
    const rows = data.feed || [];
    if (!rows.length) { el.textContent = 'No recent activity yet.'; return; }
    el.innerHTML = '<ul style="margin:0;padding-left:1rem;line-height:1.6">' + rows.map(r =>
      '<li><span style="font-weight:600">' + (parseFloat(r.amount_etb).toFixed(0)) + ' ETB</span> — ' +
      escapeHtml(r.text) + '<br><span class="text-xs text-muted">' + escapeHtml(r.at) + '</span></li>'
    ).join('') + '</ul>';
  } catch (_) {
    el.textContent = 'Could not load feed.';
  }
})();
function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
</script>
</body>
</html>
