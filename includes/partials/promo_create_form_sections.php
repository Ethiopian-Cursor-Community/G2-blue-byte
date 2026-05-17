<?php
/**
 * Shared markup: seller + organizer promotion create/edit.
 * Expects in scope: $pvTitle, $pvDesc, $pvType, $pvTarget, $pvExp, $pvVideoUrl, $vidSrc, $editing (array|null), $promoPostedTags (for extras).
 */
declare(strict_types=1);

$imgMaxMb = (int) (QB_UPLOAD_MAX_IMAGE_BYTES / 1024 / 1024);
$vidMaxMb = (int) (QB_UPLOAD_MAX_VIDEO_BYTES / 1024 / 1024);
?>
    <div class="qb-form-stack qb-promo-form-stack">
      <section class="qb-form-panel qb-form-panel--promo-preview" aria-labelledby="promo-sec-preview">
        <h2 id="promo-sec-preview" class="qb-form-panel__legend">Live preview</h2>
        <p class="qb-form-panel__lede text-xs text-muted mb-2">How your card can look on the homepage after approval.</p>
        <div class="qb-promo-live-preview" data-promo-live-preview aria-label="Homepage-style preview">
          <h4 class="font-bold mb-1" data-preview-title><?= qb_esc_html($pvTitle !== '' ? $pvTitle : 'Your title') ?></h4>
          <p class="text-sm text-secondary mb-0" data-preview-desc><?= qb_esc_html($pvDesc !== '' ? (strlen($pvDesc) > 120 ? substr($pvDesc, 0, 117) . '…' : $pvDesc) : 'Description or overlay text appears here.') ?></p>
        </div>
      </section>

      <section class="qb-form-panel" aria-labelledby="promo-sec-basics">
        <h2 id="promo-sec-basics" class="qb-form-panel__legend">Headline</h2>
        <div class="form-group mb-0">
          <label class="form-label" for="promo-title">Title</label>
          <input id="promo-title" name="title" class="form-control form-control-lg" required maxlength="200" placeholder="Short, clear title" value="<?= qb_esc_html($pvTitle) ?>"/>
        </div>
      </section>

      <section class="qb-form-panel" aria-labelledby="promo-sec-format">
        <h2 id="promo-sec-format" class="qb-form-panel__legend">Format &amp; content</h2>
        <fieldset class="qb-form-fieldset mb-3" data-promo-type-group>
          <legend class="form-label mb-2">What are you posting?</legend>
          <div class="qb-segmented" role="radiogroup" aria-label="Content format">
            <label class="qb-segmented__opt">
              <input type="radio" name="content_type" value="text"<?= ($pvType === 'text') ? ' checked' : '' ?>/>
              <span class="qb-segmented__face">
                <span class="qb-segmented__title">Text</span>
                <span class="qb-segmented__hint">Title + description</span>
              </span>
            </label>
            <label class="qb-segmented__opt">
              <input type="radio" name="content_type" value="image"<?= ($pvType === 'image') ? ' checked' : '' ?>/>
              <span class="qb-segmented__face">
                <span class="qb-segmented__title">Image</span>
                <span class="qb-segmented__hint">Photo + overlay</span>
              </span>
            </label>
            <label class="qb-segmented__opt">
              <input type="radio" name="content_type" value="video"<?= ($pvType === 'video') ? ' checked' : '' ?>/>
              <span class="qb-segmented__face">
                <span class="qb-segmented__title">Video</span>
                <span class="qb-segmented__hint">Upload or link</span>
              </span>
            </label>
          </div>
        </fieldset>

        <div class="form-group mb-2" data-promo-panel="text">
          <label class="form-label" for="promo-desc">Promotion text</label>
          <textarea id="promo-desc" name="description" class="form-control" rows="5" placeholder="Write your promotion message here..."><?= qb_esc_html($pvDesc) ?></textarea>
          <p class="text-xs text-muted mt-1 mb-0" data-promo-text-hint>Required for text-only promos.</p>
          <p class="text-xs text-muted mt-1 mb-0" data-promo-overlay-hint hidden>Optional overlay text for your media.</p>
        </div>

        <div class="form-group mb-0" data-promo-panel="image" hidden>
          <label class="form-label">Image <span class="text-muted font-normal">(JPG / PNG · max <?= $imgMaxMb ?>MB)</span></label>
          <div class="qb-promo-dropzone" data-promo-dropzone>
            <input type="file" name="promo_media" class="form-control qb-promo-dropzone__input" accept="image/jpeg,image/png" data-promo-image-input data-promo-drop-input/>
            <p class="qb-promo-dropzone__hint text-xs text-muted mb-0">Drag and drop or tap to choose.</p>
          </div>
          <div class="qb-promo-form__preview mt-2" data-promo-image-preview><?php
            if ($editing && ($editing['content_type'] ?? '') === 'image' && !empty($editing['media_url'])) {
                $mu = (string) $editing['media_url'];
                $pub = htmlspecialchars(qb_promo_external_media_url($mu) ? $mu : qb_public_upload_url($mu), ENT_QUOTES, 'UTF-8');
                echo '<img src="' . $pub . '" alt="" class="qb-promo-form__preview-img"/>';
            }
          ?></div>
        </div>

        <div data-promo-panel="video" hidden>
          <fieldset class="qb-form-fieldset mb-3">
            <legend class="form-label mb-2">Video source</legend>
            <div class="qb-segmented qb-segmented--dual" role="radiogroup" aria-label="Video source">
              <label class="qb-segmented__opt">
                <input type="radio" name="video_source" value="upload"<?= ($vidSrc !== 'url') ? ' checked' : '' ?>/>
                <span class="qb-segmented__face">
                  <span class="qb-segmented__title">Upload MP4</span>
                  <span class="qb-segmented__hint">From your device</span>
                </span>
              </label>
              <label class="qb-segmented__opt">
                <input type="radio" name="video_source" value="url"<?= ($vidSrc === 'url') ? ' checked' : '' ?>/>
                <span class="qb-segmented__face">
                  <span class="qb-segmented__title">YouTube / URL</span>
                  <span class="qb-segmented__hint">Link to clip</span>
                </span>
              </label>
            </div>
          </fieldset>
          <div class="form-group mb-2">
            <label class="form-label">Video file <span class="text-muted font-normal">(MP4 · max <?= $vidMaxMb ?>MB)</span></label>
            <div class="qb-promo-dropzone" data-promo-dropzone>
              <input type="file" name="promo_media" class="form-control qb-promo-dropzone__input" accept="video/mp4" data-promo-video-file data-promo-drop-input/>
              <p class="qb-promo-dropzone__hint text-xs text-muted mb-0">Drag and drop or choose MP4.</p>
            </div>
          </div>
          <div class="form-group mb-2">
            <label class="form-label">Video URL</label>
            <input type="url" name="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=… or https://…/clip.mp4" value="<?= qb_esc_html($pvVideoUrl) ?>" data-promo-video-url/>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Poster / thumbnail <span class="text-muted font-normal">(JPG / PNG)</span></label>
            <div class="qb-promo-dropzone" data-promo-dropzone>
              <input type="file" name="promo_thumbnail" class="form-control qb-promo-dropzone__input" accept="image/jpeg,image/png" data-promo-thumb-input data-promo-drop-input/>
              <p class="qb-promo-dropzone__hint text-xs text-muted mb-0">Required for uploads; optional for YouTube.</p>
            </div>
            <div class="qb-promo-form__preview mt-2" data-promo-thumb-preview></div>
            <div class="qb-promo-form__preview mt-2" data-promo-yt-preview></div>
          </div>
        </div>
      </section>

      <section class="qb-form-panel" aria-labelledby="promo-sec-place">
        <h2 id="promo-sec-place" class="qb-form-panel__legend">Placement &amp; timing</h2>
        <fieldset class="qb-form-fieldset mb-3">
          <legend class="form-label mb-2">Target audience</legend>
          <div class="qb-segmented qb-segmented--stack" role="radiogroup" aria-label="Promo target">
            <label class="qb-segmented__opt">
              <input type="radio" name="target" value="homepage"<?= ($pvTarget === 'homepage') ? ' checked' : '' ?>/>
              <span class="qb-segmented__face">
                <span class="qb-segmented__title">Homepage</span>
                <span class="qb-segmented__hint">Community feed &amp; spotlight pool</span>
              </span>
            </label>
            <label class="qb-segmented__opt">
              <input type="radio" name="target" value="store"<?= ($pvTarget === 'store') ? ' checked' : '' ?>/>
              <span class="qb-segmented__face">
                <span class="qb-segmented__title">Store</span>
                <span class="qb-segmented__hint">Reserved — coming later</span>
              </span>
            </label>
            <label class="qb-segmented__opt">
              <input type="radio" name="target" value="category"<?= ($pvTarget === 'category') ? ' checked' : '' ?>/>
              <span class="qb-segmented__face">
                <span class="qb-segmented__title">Category</span>
                <span class="qb-segmented__hint">Reserved — coming later</span>
              </span>
            </label>
          </div>
        </fieldset>
        <div class="form-group mb-0">
          <label class="form-label" for="promo-exp">Expiry <span class="text-muted font-normal">(optional)</span></label>
          <input id="promo-exp" type="datetime-local" name="expires_at" class="form-control" value="<?= qb_esc_html($pvExp) ?>"/>
        </div>
      </section>

      <section class="qb-form-panel qb-form-panel--tags" aria-label="Optional tags for reviewers">
        <?php require __DIR__ . '/promo_create_extras.php'; ?>
      </section>
    </div>

    <div class="qb-promo-create-actions qb-form-actions d-flex flex-wrap gap-2 align-center mt-3 pt-3">
      <input type="hidden" name="payment_method" value="chapa"/>
      <span class="text-xs text-muted">Payment method: Chapa</span>
      <button type="submit" name="save_as" value="submit" class="btn btn-primary btn-lg qb-promo-create-actions__primary"><?= qb_icon('flash', 'qb-icon', 18) ?> Submit for review</button>
      <button type="submit" name="save_as" value="draft" class="btn btn-ghost"><?= qb_icon('edit', 'qb-icon', 16) ?> Save draft</button>
      <?php if ($editing): ?>
      <span class="text-xs text-muted qb-promo-create-actions__meta">Editing #<?= (int) $editing['id'] ?> · <a href="<?= htmlspecialchars(APP_URL . '/promo.php?id=' . (int) $editing['id'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Public link</a> (after approval)</span>
      <?php endif; ?>
    </div>
