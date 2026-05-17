<?php
/**
 * Public share page for a single approved community promo (Open Graph / Twitter cards).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

startSession();

$id = (int) ($_GET['id'] ?? 0);
$row = null;
if ($id > 0 && qb_promo_posts_ready()) {
    $row = db()->fetchOne(
        'SELECT p.*,
        CASE p.owner_type
          WHEN \'seller\' THEN (SELECT s.market_name FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
          WHEN \'organization\' THEN (SELECT u.display_name FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
        END AS owner_label
        FROM promo_posts p WHERE p.id = ? AND p.status = \'active\' AND p.target = \'homepage\'
          AND (p.expires_at IS NULL OR p.expires_at > NOW())',
        [$id]
    );
}

$title = $row ? (string) ($row['title'] ?? 'Promo') : 'Promo — ' . APP_NAME;
$desc = $row ? trim(strip_tags((string) ($row['description'] ?? ''))) : 'Community promotion on ' . APP_NAME;
if (strlen($desc) > 200) {
    $desc = (function_exists('mb_substr') ? mb_substr($desc, 0, 197) : substr($desc, 0, 197)) . '…';
}
if ($desc === '') {
    $desc = 'View this community promo on ' . APP_NAME;
}

$ogImage = '';
if ($row) {
    $ctype = (string) ($row['content_type'] ?? 'text');
    $media = trim((string) ($row['media_url'] ?? ''));
    $thumb = trim((string) ($row['thumbnail_url'] ?? ''));
    if ($ctype === 'video') {
        $yt = qb_youtube_id_from_url($media);
        if ($yt !== null) {
            $ogImage = qb_youtube_thumb($yt);
        } elseif ($thumb !== '') {
            $ogImage = qb_promo_external_media_url($thumb) ? $thumb : qb_public_upload_url($thumb);
        }
    } elseif ($ctype === 'image' && $media !== '') {
        $ogImage = qb_promo_external_media_url($media) ? $media : qb_public_upload_url($media);
    }
}

$canonical = rtrim((string) APP_URL, '/') . '/promo.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>"/>
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta property="og:type" content="article"/>
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta property="og:description" content="<?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>"/>
  <?php if ($ogImage !== ''): ?>
  <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="twitter:card" content="summary_large_image"/>
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"/>
  <?php else: ?>
  <meta name="twitter:card" content="summary"/>
  <?php endif; ?>
  <meta name="twitter:title" content="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"/>
  <meta name="twitter:description" content="<?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>"/>
  <link rel="stylesheet" href="<?= htmlspecialchars(APP_URL . '/assets/css/style.css', ENT_QUOTES, 'UTF-8') ?>"/>
</head>
<body class="p-4" style="max-width:42rem;margin:0 auto;font-family:system-ui,sans-serif">
  <?php if (!$row): ?>
  <h1>Promo not found</h1>
  <p class="text-muted">It may have expired or been removed.</p>
  <p><a href="<?= htmlspecialchars(APP_URL . '/public_home.php', ENT_QUOTES, 'UTF-8') ?>">← Home</a></p>
  <?php else: ?>
  <p class="text-xs text-muted mb-2"><a href="<?= htmlspecialchars(APP_URL . '/public_home.php', ENT_QUOTES, 'UTF-8') ?>">← <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></a></p>
  <h1 style="font-size:1.35rem;margin:0 0 0.5rem"><?= qb_esc_html((string) ($row['title'] ?? '')) ?></h1>
  <?php if (($row['owner_label'] ?? '') !== ''): ?>
  <p class="text-sm text-muted mb-3"><?= qb_esc_html((string) ($row['owner_label'] ?? '')) ?></p>
  <?php endif; ?>
  <?php
    $ctype = (string) ($row['content_type'] ?? 'text');
    $media = (string) ($row['media_url'] ?? '');
    $mediaPub = $media !== '' ? htmlspecialchars(qb_promo_external_media_url($media) ? $media : qb_public_upload_url($media), ENT_QUOTES, 'UTF-8') : '';
    $thumb = (string) ($row['thumbnail_url'] ?? '');
    $thumbPub = $thumb !== '' ? htmlspecialchars(qb_promo_external_media_url($thumb) ? $thumb : qb_public_upload_url($thumb), ENT_QUOTES, 'UTF-8') : '';
    $yt = $ctype === 'video' ? qb_youtube_id_from_url($media) : null;
  if ($ctype === 'text'): ?>
  <div class="card p-3"><?= $row['description'] !== null && $row['description'] !== '' ? nl2br(qb_esc_html((string) $row['description'])) : '' ?></div>
  <?php elseif ($ctype === 'image' && $mediaPub !== ''): ?>
  <img src="<?= $mediaPub ?>" alt="" style="max-width:100%;border-radius:8px"/>
  <?php if (!empty($row['description'])): ?><p class="mt-3"><?= nl2br(qb_esc_html((string) $row['description'])) ?></p><?php endif; ?>
  <?php elseif ($ctype === 'video' && $yt !== null): ?>
  <div style="aspect-ratio:16/9;max-width:100%">
    <iframe src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($yt, ENT_QUOTES, 'UTF-8') ?>?rel=0" style="width:100%;height:100%;border:0;border-radius:8px" allowfullscreen title="Video"></iframe>
  </div>
  <?php elseif ($ctype === 'video' && $mediaPub !== ''): ?>
  <video src="<?= $mediaPub ?>" controls style="max-width:100%;border-radius:8px"></video>
  <?php if (!empty($row['description'])): ?><p class="mt-3"><?= nl2br(qb_esc_html((string) $row['description'])) ?></p><?php endif; ?>
  <?php else: ?>
  <p class="text-muted">No preview available.</p>
  <?php endif; ?>
  <p class="mt-4 text-xs text-muted">Shared from <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?> community promos.</p>
  <?php endif; ?>
</body>
</html>
