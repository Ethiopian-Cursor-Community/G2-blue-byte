<?php
/**
 * Optional moderation tags + hidden video duration (promo create forms).
 *
 * @var list<string> $promoPostedTags
 */
declare(strict_types=1);

$promoPostedTags = isset($promoPostedTags) && is_array($promoPostedTags) ? $promoPostedTags : [];
$tagChecked = static function (string $slug) use ($promoPostedTags): string {
    return in_array($slug, $promoPostedTags, true) ? ' checked' : '';
};
$wl = qb_promo_moderation_tag_whitelist();
?>
<input type="hidden" name="video_duration_seconds" value="" data-promo-video-duration/>
<?php if (function_exists('qb_has_column') && qb_has_column('promo_posts', 'video_transcode_status')): ?>
<p class="text-xs text-muted mb-3">Video files are stored as uploaded MP4. Optional server transcoding is not enabled in this deployment (marked “skipped” in the database for tooling hooks).</p>
<?php endif; ?>

<fieldset class="qb-promo-tags form-group mb-0">
  <legend class="qb-form-panel__legend" style="margin-bottom:0.35rem">Moderation tags (optional)</legend>
  <p class="text-xs text-muted mb-2">Help reviewers and buyers see what your promo is about. Labels appear on approved homepage cards.</p>
  <div class="qb-promo-tags__grid">
    <?php if (in_array('family_friendly', $wl, true)): ?>
    <label class="qb-promo-tags__item"><input type="checkbox" name="moderation_tags[]" value="family_friendly"<?= $tagChecked('family_friendly') ?>/> <?= qb_esc_html(qb_promo_moderation_tag_label('family_friendly')) ?></label>
    <?php endif; ?>
    <?php if (in_array('flash_sale', $wl, true)): ?>
    <label class="qb-promo-tags__item"><input type="checkbox" name="moderation_tags[]" value="flash_sale"<?= $tagChecked('flash_sale') ?>/> <?= qb_esc_html(qb_promo_moderation_tag_label('flash_sale')) ?></label>
    <?php endif; ?>
    <?php if (in_array('new_arrival', $wl, true)): ?>
    <label class="qb-promo-tags__item"><input type="checkbox" name="moderation_tags[]" value="new_arrival"<?= $tagChecked('new_arrival') ?>/> <?= qb_esc_html(qb_promo_moderation_tag_label('new_arrival')) ?></label>
    <?php endif; ?>
    <?php if (in_array('event_highlight', $wl, true)): ?>
    <label class="qb-promo-tags__item"><input type="checkbox" name="moderation_tags[]" value="event_highlight"<?= $tagChecked('event_highlight') ?>/> <?= qb_esc_html(qb_promo_moderation_tag_label('event_highlight')) ?></label>
    <?php endif; ?>
    <?php if (in_array('limited_time', $wl, true)): ?>
    <label class="qb-promo-tags__item"><input type="checkbox" name="moderation_tags[]" value="limited_time"<?= $tagChecked('limited_time') ?>/> <?= qb_esc_html(qb_promo_moderation_tag_label('limited_time')) ?></label>
    <?php endif; ?>
    <?php if (in_array('featured_request', $wl, true)): ?>
    <label class="qb-promo-tags__item"><input type="checkbox" name="moderation_tags[]" value="featured_request"<?= $tagChecked('featured_request') ?>/> <?= qb_esc_html(qb_promo_moderation_tag_label('featured_request')) ?></label>
    <?php endif; ?>
  </div>
</fieldset>
