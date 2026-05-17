<?php
/** @var array<int, array<string, mixed>> $promos */
/** @var string $promoHeading */
/** @var string $promoSpotlightContext Optional: 'public' | 'portal' (default). */
if (empty($promos)) {
    return;
}
$h = isset($promoHeading) && $promoHeading !== '' ? $promoHeading : 'Spotlight';
$ctx = isset($promoSpotlightContext) && $promoSpotlightContext === 'public' ? 'public' : 'portal';
$n = count($promos);
$single = $n === 1;
$stripClass = $ctx === 'public' ? 'qb-public-landing__promo-strip' : 'qb-buyer-home__promo-strip';
$carouselClass = 'qb-promo-carousel ' . $stripClass . ($single ? ' qb-promo-carousel--single' : '');
$sectionClass = $ctx === 'public'
    ? 'qb-promo-spotlight qb-promo-spotlight--public'
    : 'qb-promo-spotlight qb-buyer-home__section qb-buyer-home__section--promos';
$headingClass = $ctx === 'public' ? 'qb-public-landing__promo-heading' : 'qb-buyer-home__section-title';
?>
<section class="<?= htmlspecialchars($sectionClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="Featured promos">
  <h2 class="<?= htmlspecialchars($headingClass, ENT_QUOTES, 'UTF-8') ?>"><?= qb_icon('flash', 'qb-icon', 16) ?> <?= htmlspecialchars($h) ?></h2>

  <div class="<?= htmlspecialchars($carouselClass, ENT_QUOTES, 'UTF-8') ?>"
       data-qb-promo-carousel
       data-interval="5000"
       tabindex="0"
       role="region"
       aria-roledescription="carousel"
       aria-label="<?= htmlspecialchars($h, ENT_QUOTES, 'UTF-8') ?>">

    <div class="qb-promo-carousel__viewport"
         aria-label="Promo slides — use arrows, dots, swipe, or keyboard when the carousel is focused">
      <?php if (!$single): ?>
      <button type="button" class="qb-promo-carousel__arrow qb-promo-carousel__arrow--prev qb-promo-carousel__arrow--overlay" aria-label="Previous slide">
        <span class="qb-promo-carousel__arrow-icon" aria-hidden="true"><?= qb_icon('arrow-right', 'qb-icon', 22) ?></span>
      </button>
      <button type="button" class="qb-promo-carousel__arrow qb-promo-carousel__arrow--next qb-promo-carousel__arrow--overlay" aria-label="Next slide">
        <span class="qb-promo-carousel__arrow-icon" aria-hidden="true"><?= qb_icon('arrow-right', 'qb-icon', 22) ?></span>
      </button>
      <?php endif; ?>
      <div class="qb-promo-carousel__track">
        <?php foreach ($promos as $pi => $pr):
            $resolvedRaw = function_exists('qb_spotlight_resolve_media_url')
                ? qb_spotlight_resolve_media_url($pr)
                : qb_public_upload_url((string) ($pr['media_url'] ?? ''));
            $mediaUrl = htmlspecialchars(is_string($resolvedRaw) ? $resolvedRaw : '', ENT_QUOTES, 'UTF-8');
            $mt = (string) ($pr['media_type'] ?? '');
            $isText = ($mt === 'text');
            $isVideo = ($mt === 'video');
            $title = htmlspecialchars((string) ($pr['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $desc = trim((string) ($pr['description'] ?? ''));
            $descCut = $desc;
            if ($descCut !== '') {
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    $descCut = mb_strlen($descCut) > 400 ? mb_substr($descCut, 0, 397) . '…' : $descCut;
                } elseif (strlen($descCut) > 400) {
                    $descCut = substr($descCut, 0, 397) . '…';
                }
            }
            $descHtml = $descCut !== '' ? nl2br(htmlspecialchars($descCut, ENT_QUOTES, 'UTF-8')) : '';
            $ytId = $isVideo && function_exists('qb_youtube_id_from_url') ? qb_youtube_id_from_url((string) ($pr['media_url'] ?? '')) : null;
            $embedSrc = $ytId !== null && $ytId !== ''
                ? 'https://www.youtube-nocookie.com/embed/' . htmlspecialchars($ytId, ENT_QUOTES, 'UTF-8') . '?rel=0'
                : '';
            $isOfficial = (($pr['_spotlight_source'] ?? '') === 'admin');
            ?>
        <div class="qb-promo-carousel__slide" id="qb-promo-slide-<?= (int) $pi ?>" role="group" aria-roledescription="slide" aria-label="Slide <?= (int) ($pi + 1) ?> of <?= (int) $n ?>"<?= $pi === 0 ? ' aria-hidden="false"' : ' aria-hidden="true"' ?>>
          <div class="qb-promo-carousel__media<?= $isText ? ' qb-promo-carousel__media--text' : '' ?>">
            <?php if ($isOfficial): ?>
              <span class="qb-promo-carousel__owner-badge">QR Bazar Official</span>
            <?php endif; ?>
            <?php if ($isText): ?>
              <div class="qb-promo-carousel__text-slide">
                <h3 class="qb-promo-carousel__text-title"><?= $title !== '' ? $title : 'Promo' ?></h3>
                <?php if ($descHtml !== ''): ?><div class="qb-promo-carousel__text-body"><?= $descHtml ?></div><?php endif; ?>
              </div>
            <?php elseif ($isVideo && $ytId !== null && $embedSrc !== ''): ?>
              <iframe class="qb-promo-carousel__iframe"
                      src="<?= $embedSrc ?>"
                      title="<?= $title !== '' ? $title : 'Promotional video' ?>"
                      loading="<?= $pi === 0 ? 'eager' : 'lazy' ?>"
                      allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                      allowfullscreen></iframe>
            <?php elseif ($isVideo): ?>
              <video src="<?= $mediaUrl ?>"
                     class="qb-promo-carousel__vid"
                     controls
                     playsinline
                     preload="metadata"
                     data-qb-promo-video
                     aria-label="<?= $title !== '' ? $title : 'Promotional video' ?>"></video>
            <?php else: ?>
              <img src="<?= $mediaUrl ?>"
                   alt="<?= $title !== '' ? $title : 'Promo' ?>"
                   loading="<?= $pi === 0 ? 'eager' : 'lazy' ?>"
                   decoding="async"
                   <?= $pi === 0 ? 'fetchpriority="high"' : '' ?>/>
            <?php endif; ?>
          </div>
          <?php if ($title !== '' && !$isText): ?>
          <p class="qb-promo-carousel__title"><?= $title ?></p>
          <?php elseif ($isText): ?>
          <p class="qb-promo-carousel__title qb-promo-carousel__title--muted"><?= qb_icon('flash', 'qb-icon', 14) ?> Text</p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!$single): ?>
    <div class="qb-promo-carousel__chrome qb-promo-carousel__chrome--dots">
      <div class="qb-promo-carousel__dots" role="tablist" aria-label="Choose slide">
        <?php for ($di = 0; $di < $n; $di++): ?>
        <button type="button"
                class="qb-promo-carousel__dot<?= $di === 0 ? ' is-active' : '' ?>"
                role="tab"
                aria-selected="<?= $di === 0 ? 'true' : 'false' ?>"
                aria-controls="qb-promo-slide-<?= (int) $di ?>"
                aria-label="Slide <?= (int) ($di + 1) ?> of <?= (int) $n ?>"></button>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php
static $qbPromoCarouselScriptQueued = false;
if (!$qbPromoCarouselScriptQueued) {
    $qbPromoCarouselScriptQueued = true;
    $js = htmlspecialchars(APP_URL . '/assets/js/promo_carousel.js', ENT_QUOTES, 'UTF-8');
    echo '<script src="' . $js . '" defer></script>';
}
