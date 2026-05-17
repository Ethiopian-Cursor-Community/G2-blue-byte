<?php
/**
 * Community promo feed (promo_posts) — SSR cards + JS carousel/modal/likes.
 *
 * @var string $promoFeedContext 'public'|'buyer'
 */
declare(strict_types=1);

$promoFeedContext = isset($promoFeedContext) && $promoFeedContext === 'buyer' ? 'buyer' : 'public';
$promoFeedSort = isset($promoFeedSort) && $promoFeedSort === 'fair' ? 'fair' : 'newest';
$ready = qb_promo_posts_ready();
$feedPosts = $ready ? qb_promo_posts_homepage_feed($promoFeedContext === 'public' ? 24 : 12, $promoFeedSort) : [];
$feedApi = htmlspecialchars(APP_URL . '/api/promo_posts.php?feed=homepage&sort=' . rawurlencode($promoFeedSort), ENT_QUOTES, 'UTF-8');
$sortBase = htmlspecialchars((string) ($_SERVER['SCRIPT_NAME'] ?? ''), ENT_QUOTES, 'UTF-8');
$csrf = htmlspecialchars(qb_csrf_token(), ENT_QUOTES, 'UTF-8');
$loggedIn = isLoggedIn();
$wrapClass = $promoFeedContext === 'public'
    ? 'qb-promo-feed qb-promo-feed--public'
    : 'qb-promo-feed qb-promo-feed--buyer';

$trimDesc = static function (string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
    }

    return strlen($text) > 160 ? substr($text, 0, 157) . '…' : $text;
};
?>
<section id="community-promos" class="<?= htmlspecialchars($wrapClass, ENT_QUOTES, 'UTF-8') ?>"
         data-promo-feed
         data-feed-url="<?= $feedApi ?>"
         data-feed-sort="<?= htmlspecialchars($promoFeedSort, ENT_QUOTES, 'UTF-8') ?>"
         data-csrf="<?= $csrf ?>"
         data-logged-in="<?= $loggedIn ? '1' : '0' ?>"
         aria-label="Community promotions">
  <div class="qb-promo-feed__head">
    <h2 class="qb-promo-feed__title"><?= qb_icon('flash', 'qb-icon', 20) ?> Community promos</h2>
    <p class="qb-promo-feed__sub">From sellers and organizers. Submissions are reviewed before they appear here.</p>
    <p class="qb-promo-feed__sort text-xs text-muted mb-0">
      Sort:
      <a href="<?= $sortBase ?>?promo_sort=newest#community-promos" class="<?= $promoFeedSort === 'newest' ? 'font-bold' : '' ?>">Newest</a>
      ·
      <a href="<?= $sortBase ?>?promo_sort=fair#community-promos" class="<?= $promoFeedSort === 'fair' ? 'font-bold' : '' ?>">Fair rotation</a>
      <span class="qb-promo-feed__sort-hint"> — fair mixes creators so one shop doesn’t always appear first.</span>
    </p>
  </div>

  <?php if (!$ready): ?>
  <div class="alert alert-warning qb-promo-feed__migrate">
    Run <code>php install/migrate_promo_posts.php</code> to enable the promo content system.
  </div>
  <?php else: ?>

  <div class="qb-promo-feed__skeleton" data-promo-feed-skeleton hidden aria-hidden="true">
    <?php for ($si = 0; $si < 4; $si++): ?>
    <div class="qb-promo-feed__skeleton-card"></div>
    <?php endfor; ?>
  </div>

  <div class="qb-promo-feed__viewport" data-promo-feed-viewport>
    <div class="qb-promo-feed__track" data-promo-feed-track role="list">
      <?php if ($feedPosts === []): ?>
      <article class="qb-promo-card card qb-promo-card--demo" role="listitem" data-promo-card data-post-id="0" data-content-type="text" data-owner-type="seller" aria-label="Example promo card">
        <div class="qb-promo-card__body qb-promo-card__body--text">
          <span class="qb-promo-card__source-ribbon qb-promo-card__source-ribbon--seller">Seller promo</span>
          <span class="badge mb-1">Example</span>
          <h3 class="qb-promo-card__title">Your promo could look like this</h3>
          <p class="qb-promo-card__offer-text">OFFER: up to 15% off when buyers mention this card</p>
          <p class="qb-promo-card__desc">Community cards appear here after approval. This is a static preview so the section never feels empty on day one.</p>
        </div>
        <footer class="qb-promo-card__meta">
          <span class="qb-promo-card__owner text-muted">Sample seller</span>
          <span class="qb-promo-card__stats text-muted">0 views · 0 likes</span>
        </footer>
      </article>
      <?php endif; ?>
      <?php foreach ($feedPosts as $row):
          $pid = (int) ($row['id'] ?? 0);
          $ctype = (string) ($row['content_type'] ?? 'text');
          $title = qb_esc_html((string) ($row['title'] ?? ''));
          $descRaw = trim((string) ($row['description'] ?? ''));
          $descShort = $descRaw !== '' ? qb_esc_html($trimDesc($descRaw)) : '';
          $media = (string) ($row['media_url'] ?? '');
          $thumb = (string) ($row['thumbnail_url'] ?? '');
          $views = (int) ($row['view_count'] ?? 0);
          $likes = (int) ($row['like_count'] ?? 0);
          $sponsored = !empty($row['is_sponsored']);
          $durSec = isset($row['video_duration_seconds']) ? (int) $row['video_duration_seconds'] : 0;
          $tags = [];
          if (!empty($row['moderation_tags'])) {
              $tags = qb_promo_decode_moderation_tags((string) $row['moderation_tags']);
          }
          $ownerLabel = qb_esc_html((string) ($row['owner_label'] ?? ''));
          $ownerType = (string) ($row['owner_type'] ?? 'seller');
          $ribbonClass = $ownerType === 'organization'
              ? 'qb-promo-card__source-ribbon--org'
              : 'qb-promo-card__source-ribbon--seller';
          $ribbonText = $ownerType === 'organization' ? 'Organizer promo' : 'Seller promo';
          $offerHint = qb_promo_offer_hint((string) ($row['title'] ?? ''), $descRaw);
          $offerHintEsc = $offerHint !== '' ? htmlspecialchars($offerHint, ENT_QUOTES, 'UTF-8') : '';
          $liked = $loggedIn && qb_promo_post_user_liked($pid, (int) ($_SESSION['app_user_id'] ?? 0));
          $mediaPub = $media !== '' ? htmlspecialchars(qb_promo_external_media_url($media) ? $media : qb_public_upload_url($media), ENT_QUOTES, 'UTF-8') : '';
          $thumbPub = $thumb !== '' ? htmlspecialchars(qb_promo_external_media_url($thumb) ? $thumb : qb_public_upload_url($thumb), ENT_QUOTES, 'UTF-8') : '';
          $yt = $ctype === 'video' ? qb_youtube_id_from_url($media) : null;
          $titlePlain = htmlspecialchars(strip_tags((string) ($row['title'] ?? '')), ENT_QUOTES, 'UTF-8');
          $embedHtml = '';
          if ($ctype === 'video') {
              if ($yt !== null) {
                  $embed = 'https://www.youtube-nocookie.com/embed/' . htmlspecialchars($yt, ENT_QUOTES, 'UTF-8') . '?autoplay=1&rel=0';
                  $embedHtml = '<iframe class="qb-promo-modal__iframe" src="' . $embed . '" title="' . $titlePlain . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
              } elseif ($mediaPub !== '') {
                  $embedHtml = '<video class="qb-promo-modal__video" controls playsinline preload="metadata" src="' . $mediaPub . '"></video>';
              }
          }
          ?>
      <article class="qb-promo-card card" role="listitem" data-promo-card data-post-id="<?= $pid ?>" data-content-type="<?= htmlspecialchars($ctype, ENT_QUOTES, 'UTF-8') ?>" data-owner-type="<?= htmlspecialchars($ownerType, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($sponsored): ?>
        <span class="qb-promo-card__badge">Sponsored</span>
        <?php endif; ?>
        <?php if ($ctype === 'text'): ?>
        <div class="qb-promo-card__body qb-promo-card__body--text">
          <span class="qb-promo-card__source-ribbon <?= htmlspecialchars($ribbonClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ribbonText, ENT_QUOTES, 'UTF-8') ?></span>
          <h3 class="qb-promo-card__title"><?= $title ?></h3>
          <?php if ($offerHintEsc !== ''): ?><p class="qb-promo-card__offer-text"><?= $offerHintEsc ?></p><?php endif; ?>
          <?php if ($descShort !== ''): ?><p class="qb-promo-card__desc"><?= $descShort ?></p><?php endif; ?>
          <?php if ($tags !== []): ?>
          <div class="qb-promo-card__tags"><?php foreach (array_slice($tags, 0, 4) as $tg): ?><span class="qb-promo-card__tag"><?= qb_esc_html(qb_promo_moderation_tag_label($tg)) ?></span><?php endforeach; ?></div>
          <?php endif; ?>
        </div>
        <?php elseif ($ctype === 'image'): ?>
        <div class="qb-promo-card__media-wrap">
          <img src="<?= $mediaPub ?>" alt="" class="qb-promo-card__img" loading="lazy" decoding="async"/>
          <div class="qb-promo-card__overlay">
            <h3 class="qb-promo-card__title"><?= $title ?></h3>
            <?php if ($descShort !== ''): ?><p class="qb-promo-card__overlay-desc"><?= $descShort ?></p><?php endif; ?>
            <?php if ($tags !== []): ?>
            <div class="qb-promo-card__tags qb-promo-card__tags--overlay"><?php foreach (array_slice($tags, 0, 4) as $tg): ?><span class="qb-promo-card__tag"><?= qb_esc_html(qb_promo_moderation_tag_label($tg)) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
          </div>
          <span class="qb-promo-card__source-ribbon <?= htmlspecialchars($ribbonClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ribbonText, ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($offerHintEsc !== ''): ?><span class="qb-promo-card__offer-pill"><?= $offerHintEsc ?></span><?php endif; ?>
        </div>
        <?php else: ?>
        <button type="button" class="qb-promo-card__video-hit" data-open-promo-video aria-label="Play video: <?= $titlePlain ?>">
          <?php if ($thumbPub !== ''): ?>
          <img src="<?= $thumbPub ?>" alt="" class="qb-promo-card__thumb" loading="lazy" decoding="async"/>
          <?php else: ?>
          <div class="qb-promo-card__thumb qb-promo-card__thumb--placeholder"></div>
          <?php endif; ?>
          <span class="qb-promo-card__play" aria-hidden="true"><?= qb_icon('play', 'qb-icon', 28) ?></span>
          <?php if ($durSec > 0): ?>
          <span class="qb-promo-card__duration"><?= qb_esc_html(qb_promo_format_duration($durSec)) ?></span>
          <?php endif; ?>
          <span class="qb-promo-card__overlay qb-promo-card__overlay--video">
            <span class="qb-promo-card__title"><?= $title ?></span>
            <?php if ($tags !== []): ?>
            <span class="qb-promo-card__tags qb-promo-card__tags--video"><?php foreach (array_slice($tags, 0, 3) as $tg): ?><span class="qb-promo-card__tag"><?= qb_esc_html(qb_promo_moderation_tag_label($tg)) ?></span><?php endforeach; ?></span>
            <?php endif; ?>
          </span>
          <span class="qb-promo-card__source-ribbon <?= htmlspecialchars($ribbonClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ribbonText, ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($offerHintEsc !== ''): ?><span class="qb-promo-card__offer-pill"><?= $offerHintEsc ?></span><?php endif; ?>
        </button>
        <?php if ($embedHtml !== ''): ?>
        <template data-video-template><?= $embedHtml ?></template>
        <?php endif; ?>
        <?php endif; ?>
        <footer class="qb-promo-card__meta">
          <?php if ($ownerLabel !== ''): ?><span class="qb-promo-card__owner"><?= $ownerLabel ?></span><?php endif; ?>
          <span class="qb-promo-card__stats">
            <span data-stat-views><?= $views ?></span> views
            ·
            <span data-stat-likes><?= $likes ?></span> likes
          </span>
          <button type="button"
                  class="btn btn-ghost btn-sm qb-promo-card__like<?= $liked ? ' is-liked' : '' ?>"
                  data-like-promo
                  data-post-id="<?= $pid ?>"
                  <?= $loggedIn ? '' : ' disabled title="Sign in to like"' ?>>
            <?= qb_icon('heart', 'qb-icon', 16) ?> <span data-like-label><?= $liked ? 'Liked' : 'Like' ?></span>
          </button>
          <a href="<?= htmlspecialchars(APP_URL . '/promo.php?id=' . $pid, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost btn-sm text-xs" rel="bookmark">Share</a>
          <button type="button" class="btn btn-ghost btn-sm text-xs text-danger" data-report-promo data-post-id="<?= $pid ?>">Report</button>
        </footer>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="qb-promo-feed__chrome" data-promo-feed-chrome hidden>
      <button type="button" class="qb-promo-feed__arrow qb-promo-feed__arrow--prev" aria-label="Previous promos"><?= qb_icon('arrow-right', 'qb-icon', 20) ?></button>
      <button type="button" class="qb-promo-feed__arrow qb-promo-feed__arrow--next" aria-label="Next promos"><?= qb_icon('arrow-right', 'qb-icon', 20) ?></button>
    </div>
  </div>

  <?php if ($feedPosts === []): ?>
  <p class="qb-promo-feed__empty text-muted text-sm">No live community promos yet — the example card above shows the layout. <a href="<?= htmlspecialchars(APP_URL . '/seller/promotion_create.php', ENT_QUOTES, 'UTF-8') ?>">Sellers</a> and <a href="<?= htmlspecialchars(APP_URL . '/organizer/promotion_create.php', ENT_QUOTES, 'UTF-8') ?>">organizers</a> can submit one for review.</p>
  <?php endif; ?>

  <dialog class="qb-promo-report" data-promo-report-dialog aria-label="Report promo">
    <form method="dialog" class="qb-promo-report__form">
      <h3 class="font-bold mb-2">Report this promo</h3>
      <p class="text-sm text-muted mb-2">Reports are reviewed by moderators. The promo may be hidden while we check.</p>
      <div class="form-group mb-2">
        <label class="form-label" for="promo-report-reason">Reason</label>
        <select id="promo-report-reason" class="form-control" data-promo-report-reason>
          <option value="spam">Spam</option>
          <option value="inappropriate">Inappropriate</option>
          <option value="misleading">Misleading</option>
          <option value="copyright">Copyright</option>
          <option value="other" selected>Other</option>
        </select>
      </div>
      <div class="form-group mb-2">
        <label class="form-label" for="promo-report-body">Details (optional)</label>
        <textarea id="promo-report-body" class="form-control" rows="3" data-promo-report-body placeholder="What should we know?"></textarea>
      </div>
      <div class="qb-promo-report__actions">
        <button type="button" class="btn btn-ghost btn-sm" data-promo-report-cancel>Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" data-promo-report-submit>Submit report</button>
      </div>
    </form>
  </dialog>

  <dialog class="qb-promo-modal" data-promo-video-modal aria-label="Video">
    <div class="qb-promo-modal__inner">
      <button type="button" class="qb-promo-modal__close btn btn-ghost btn-sm" data-close-promo-modal aria-label="Close"><?= qb_icon('x', 'qb-icon', 18) ?></button>
      <div class="qb-promo-modal__stage" data-promo-modal-stage></div>
    </div>
  </dialog>
  <?php endif; ?>
</section>
<?php
if ($ready) {
    $jsFeed = htmlspecialchars(APP_URL . '/assets/js/promo/PromoFeed.js', ENT_QUOTES, 'UTF-8');
    echo '<script type="module" src="' . $jsFeed . '"></script>';
}
