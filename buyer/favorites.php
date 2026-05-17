<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
qb_require_buyer_portal_mobile();
$u = appCurrentUser();
$saved = qb_favorites_table_exists() ? qb_buyer_saved_shops((int)$u['id']) : [];
$here = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" class="shell-light buyer-shell">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <title>Saved shops — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <link rel="stylesheet" href="../assets/css/shell-light.css"/>
  <link rel="stylesheet" href="../assets/css/buyer-mobile.css"/>
</head>
<body>

<div class="buyer-topbar">
  <a href="home.php" style="text-decoration:none;color:inherit;font-weight:800">← Home</a>
  <div class="buyer-nav-simple">
    <a href="home.php">Home</a>
    <a href="favorites.php" class="active">Saved</a>
    <a href="../logout.php">Out</a>
  </div>
</div>

<div class="buyer-page">
  <h1>Saved shops</h1>
  <p class="text-secondary" style="font-size:0.9rem;margin-bottom:1rem">Vendors you bookmarked while signed in.</p>

  <?php if (!qb_favorites_table_exists()): ?>
    <div class="buyer-card">
      <p class="text-sm text-muted" style="margin:0">Enable this feature by running <code>sql/marketplace_features.sql</code> in MySQL.</p>
    </div>
  <?php elseif (empty($saved)): ?>
    <div class="buyer-card">
      <p class="text-sm" style="margin:0">No saved shops yet. Open a vendor page and tap <strong>Save</strong> or <strong>★ Saved</strong>.</p>
      <a href="../discover.php" class="btn btn-primary btn-full" style="margin-top:1rem;text-align:center;text-decoration:none">Discover vendors</a>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:0.65rem">
      <?php foreach ($saved as $s): ?>
        <a href="vendor.php?uid=<?= urlencode($s['uid']) ?>"
           class="buyer-card" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:0.75rem;margin:0">
          <div style="width:48px;height:48px;border-radius:var(--radius-md);background:var(--accent-soft);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800">
            <?php if (!empty($s['profile_image'])): ?>
              <img src="<?= htmlspecialchars(qb_public_upload_url($s['profile_image'])) ?>" alt="" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover"/>
            <?php else: ?>
              <?= strtoupper(substr($s['market_name'], 0, 1)) ?>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800"><?= htmlspecialchars($s['market_name']) ?></div>
            <div class="text-xs text-muted"><?= htmlspecialchars($s['category']) ?><?= !empty($s['location']) ? ' · ' . htmlspecialchars($s['location']) : '' ?></div>
          </div>
          <span style="color:var(--accent)">→</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<nav class="buyer-bottom-nav" aria-label="Buyer">
  <a href="home.php"><span class="ico">⌂</span>Home</a>
  <a href="scan.php"><span class="ico">▣</span>Scan</a>
  <a href="favorites.php" class="active"><span class="ico">★</span>Saved</a>
  <a href="profile.php"><span class="ico">◎</span>Profile</a>
</nav>

</body>
</html>
