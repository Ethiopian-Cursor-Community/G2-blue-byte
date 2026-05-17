<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promo_http_create.php';
require_once __DIR__ . '/../includes/layout.php';

startSession();
requireSeller();
qb_promo_sync_expiry_notifications();

$seller = getCurrentSeller();
if (!$seller) {
    header('Location: ' . APP_URL . '/login.php?portal=seller&error=session');
    exit;
}
$sellerId = (int) ($seller['id'] ?? 0);
$uploadKey = 'seller_' . $sellerId;
$error = '';
$ok = isset($_GET['ok']);
$okDraft = isset($_GET['draft']);
$okAppeal = isset($_GET['appeal']);
$okWithdraw = isset($_GET['withdraw']);
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $editing = qb_promo_post_get_for_owner($editId, 'seller', $sellerId);
    if (!$editing) {
        header('Location: ' . APP_URL . '/seller/promotion_create.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = qb_promo_process_promo_form('seller', $sellerId, $uploadKey);
    if (!empty($_POST['xhr'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($r['ok']) {
            $saveAs = (string) ($_POST['save_as'] ?? 'submit');
            $pid = (int) ($r['id'] ?? (int) ($_POST['promo_post_id'] ?? 0));
            $msg = 'Promotion saved. Complete Chapa payment to publish automatically.';
            $red = APP_URL . '/seller/promotion_create.php?ok=1';
            if (($r['ok'] ?? false) && isset($_POST['promo_action']) && $_POST['promo_action'] === 'appeal') {
                $msg = 'Appeal submitted — your promo is back in the review queue.';
                $red = APP_URL . '/seller/promotion_create.php?appeal=1';
            } elseif (($r['ok'] ?? false) && isset($_POST['promo_action']) && $_POST['promo_action'] === 'withdraw') {
                $msg = 'Withdrawn from review.';
                $red = APP_URL . '/seller/promotion_create.php?withdraw=1';
            } elseif ($saveAs === 'draft') {
                $msg = 'Draft saved.';
                $red = APP_URL . '/seller/promotion_create.php?draft=1' . ($pid > 0 ? '&edit=' . $pid : '');
            }
            echo json_encode([
                'success' => true,
                'message' => $msg,
                'redirect' => $red,
            ], JSON_UNESCAPED_SLASHES);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Error'], JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
    if ($r['ok']) {
        $saveAs = (string) ($_POST['save_as'] ?? 'submit');
        $pid = (int) ($r['id'] ?? (int) ($_POST['promo_post_id'] ?? 0));
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'chapa');
        if (isset($_POST['promo_action']) && $_POST['promo_action'] === 'appeal') {
            header('Location: ' . APP_URL . '/seller/promotion_create.php?appeal=1');
            exit;
        }
        if (isset($_POST['promo_action']) && $_POST['promo_action'] === 'withdraw') {
            header('Location: ' . APP_URL . '/seller/promotion_create.php?withdraw=1');
            exit;
        }
        if (isset($_POST['promo_action']) && $_POST['promo_action'] === 'extend') {
            $pid = (int)($_POST['promo_post_id'] ?? 0);
            $days = (int)($_POST['extension_days'] ?? 1);
            if ($days < 1) $days = 1;
            $promoPost = qb_promo_post_get_for_owner($pid, 'seller', $sellerId);
            if ($promoPost) {
                $costPerDay = qb_setting_get_float('promo_extension_cost_per_day', 10.0);
                $totalCost = $days * $costPerDay;
                if (!qb_chapa_ready()) {
                    $error = 'Chapa is not configured yet.';
                } else {
                    $intent = qb_payment_intent_create((int)($seller['app_user_id'] ?? 0), 'promo_extend', 'promo_ext:' . $pid, (float)$totalCost, ['promo_post_id' => $pid, 'owner_type' => 'seller', 'extension_days' => $days]);
                    $intentRow = qb_payment_intent_get((string)$intent['intent_id']);
                    if ($intentRow) {
                        $u = currentUser() ?? [];
                        $start = qb_chapa_checkout_start($intentRow, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? 'Seller'), (string) ($u['phone'] ?? ''));
                        if (($start['ok'] ?? false) && !empty($start['checkout_url'])) {
                            header('Location: ' . (string) $start['checkout_url']);
                            exit;
                        }
                    }
                    $error = 'Extension payment failed.';
                }
            } else {
                $error = 'Promo not found.';
            }
        }
        if ($saveAs === 'draft') {
            header('Location: ' . APP_URL . '/seller/promotion_create.php?draft=1' . ($pid > 0 ? '&edit=' . $pid : ''));
            exit;
        }
        if ($paymentMethod !== 'chapa') {
            $error = 'Only Chapa payment is enabled.';
        } elseif ($pid > 0) {
            if (!qb_chapa_ready()) {
                $error = 'Chapa is not configured yet.';
            } else {
                qb_promo_post_set_status($pid, 'draft', 0, null, null);
                $intent = qb_payment_intent_create((int) ($seller['app_user_id'] ?? 0), 'promo_paid', 'promo:' . $pid, (float) $promoFeeEtb, ['promo_post_id' => $pid, 'owner_type' => 'seller']);
                $intentRow = qb_payment_intent_get((string) $intent['intent_id']);
                if ($intentRow) {
                    $u = currentUser() ?? [];
                    $start = qb_chapa_checkout_start($intentRow, (string) ($u['email'] ?? ''), (string) ($u['display_name'] ?? 'Seller'), (string) ($u['phone'] ?? ''));
                    if (($start['ok'] ?? false) && !empty($start['checkout_url'])) {
                        header('Location: ' . (string) $start['checkout_url']);
                        exit;
                    }
                    qb_audit_log('payment.chapa.init_failed', 'payment_intents', (int) ($intentRow['id'] ?? 0), [
                        'flow' => 'seller_promo_paid',
                        'promo_post_id' => (int) $pid,
                        'error' => (string) ($start['error'] ?? 'unknown'),
                    ]);
                    $nextUrl = APP_URL . '/seller/promotion_create.php';
                    $failUrl = APP_URL . '/buyer/payment_result.php?intent=' . rawurlencode((string) ($intentRow['intent_id'] ?? '')) . '&status=failed&error=' . rawurlencode((string) ($start['error'] ?? 'Could not start Chapa checkout.')) . '&next=' . rawurlencode($nextUrl);
                    header('Location: ' . $failUrl, true, 302);
                    exit;
                } else {
                    $error = 'Could not create payment intent.';
                }
            }
        }
        if ($error === '') {
            header('Location: ' . APP_URL . '/seller/promotion_create.php?ok=1');
            exit;
        }
    }
    $error = $r['error'] ?? 'Could not save.';
}

$csrf = qb_csrf_token();
$promoPostedTags = [];
if (isset($_POST['moderation_tags']) && is_array($_POST['moderation_tags'])) {
    foreach ($_POST['moderation_tags'] as $t) {
        if (is_string($t)) {
            $promoPostedTags[] = $t;
        }
    }
} elseif ($editing && !empty($editing['moderation_tags'])) {
    $promoPostedTags = qb_promo_decode_moderation_tags((string) $editing['moderation_tags']);
}
$myPosts = qb_promo_posts_by_owner('seller', $sellerId);
$promoFeeEtb = qb_setting_get_float('promo_fee_etb', (float) CHAPA_PROMO_FEE_ETB);
$promoAutoPublish = qb_setting_get_bool('promo_auto_publish_paid', true);

qb_page_start('seller', 'Create promotion', 'promotion_create.php', false);
?>

<div class="seller-dashboard qb-promo-create-page qb-promo-create-page--seller">
<header class="qb-promo-create-hero card mb-3">
  <div class="qb-promo-create-hero__icon" aria-hidden="true"><?= qb_icon('flash', 'qb-icon', 28) ?></div>
  <div class="qb-promo-create-hero__text">
    <h1 class="qb-promo-create-hero__title"><?= $editing ? 'Edit promotion' : 'Create promotion' ?></h1>
    <p class="qb-promo-create-hero__sub">Text, image, or video — pay with Chapa and it posts automatically on success.</p>
  </div>
</header>

<?php if ($ok): ?>
<div class="alert alert-success mb-3"><?= $promoAutoPublish ? 'Promotion payment completed. Your post is now published.' : 'Promotion payment completed. It is now queued for admin review.' ?></div>
<?php endif; ?>
<?php if ($okDraft): ?>
<div class="alert alert-success mb-3">Draft saved. You can continue editing or submit when ready.</div>
<?php endif; ?>
<?php if ($okAppeal): ?>
<div class="alert alert-success mb-3">Appeal sent — your promo is back in the review queue.</div>
<?php endif; ?>
<?php if ($okWithdraw): ?>
<div class="alert alert-success mb-3">That submission was withdrawn from review.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-danger mb-3"><?= qb_esc_html($error) ?></div>
<?php endif; ?>

<div class="qb-promo-create-layout">
<div class="qb-promo-create-main">
<div class="qb-promo-create card qb-promo-create--enhanced" data-promo-create>
  <div class="alert mt-3" data-promo-feedback hidden></div>
  <div class="qb-promo-form__progress mb-3" data-upload-progress hidden>
    <div class="qb-promo-form__progress-label text-sm text-muted mb-1">Uploading…</div>
    <div class="qb-promo-form__progress-bar"><span data-upload-progress-fill></span></div>
  </div>

  <form method="post" enctype="multipart/form-data" action="" class="qb-promo-form" data-promo-form>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
    <input type="hidden" name="xhr" value="0" data-promo-xhr-flag/>
    <input type="hidden" name="promo_action" value="save"/>
    <input type="hidden" name="promo_post_id" value="<?= $editing ? (int) ($editing['id'] ?? 0) : 0 ?>"/>

    <?php
        $pvTitle = (string) ($_POST['title'] ?? ($editing['title'] ?? ''));
        $pvDesc = (string) ($_POST['description'] ?? ($editing['description'] ?? ''));
        $pvType = (string) ($_POST['content_type'] ?? ($editing['content_type'] ?? 'text'));
        $pvTarget = (string) ($_POST['target'] ?? ($editing['target'] ?? 'homepage'));
        $pvExp = (string) ($_POST['expires_at'] ?? '');
        if ($pvExp === '' && !empty($editing['expires_at'])) {
            $pvExp = date('Y-m-d\TH:i', strtotime((string) $editing['expires_at']));
        }
        $pvVideoUrl = (string) ($_POST['video_url'] ?? '');
        if ($pvVideoUrl === '' && $editing && ($editing['content_type'] ?? '') === 'video') {
            $m = (string) ($editing['media_url'] ?? '');
            if ($m !== '' && qb_promo_external_media_url($m)) {
                $pvVideoUrl = $m;
            }
        }
        $vidSrc = (string) ($_POST['video_source'] ?? '');
        if ($vidSrc === '' && $editing && ($editing['content_type'] ?? '') === 'video') {
            $m = (string) ($editing['media_url'] ?? '');
            $vidSrc = ($m !== '' && strpos($m, 'uploads/') === 0) ? 'upload' : 'url';
        }
        require __DIR__ . '/../includes/partials/promo_create_form_sections.php';
    ?>
  </form>
</div>
</div>

<aside class="qb-promo-create-aside card" aria-label="Tips">
  <h2 class="qb-promo-create-aside__title">Review checklist</h2>
  <ul class="qb-promo-create-aside__list text-sm text-secondary">
    <li>Use a clear title buyers will recognize.</li>
    <li>Keep images under about <?= (int) (QB_UPLOAD_MAX_IMAGE_BYTES / 1024 / 1024) ?>MB for smooth uploads.</li>
    <li>Tags help moderators route your promo faster.</li>
  </ul>
</aside>
</div>

<?php if ($myPosts !== []): ?>
<section class="qb-promo-submissions card mt-4" aria-labelledby="promo-submissions-heading">
  <div class="qb-promo-submissions__head">
    <h2 id="promo-submissions-heading" class="qb-promo-submissions__title">Your submissions</h2>
    <p class="qb-promo-submissions__lede text-xs text-muted mb-0">Track status, edit drafts, or appeal if needed.</p>
  </div>
  <div class="qb-promo-submissions__grid">
    <?php foreach ($myPosts as $p): ?>
    <?php
        $pst = (string) ($p['status'] ?? '');
        $pid = (int) ($p['id'] ?? 0);
        $tl = 'Created ' . (string) ($p['created_at'] ?? '');
        if ($pst === 'pending' || $pst === 'flagged') {
            $tl .= ' → In review';
        } elseif ($pst === 'active') {
            $tl .= ' → Approved';
        } elseif ($pst === 'rejected') {
            $tl .= ' → Rejected';
            if (!empty($p['rejection_code'])) {
                $codes = qb_promo_rejection_codes();
                $c = (string) $p['rejection_code'];
                $tl .= ' (' . ($codes[$c] ?? $c) . ')';
            }
        } elseif ($pst === 'draft') {
            $tl .= ' → Draft';
        } elseif ($pst === 'withdrawn') {
            $tl .= ' → Withdrawn';
        }
        $stClass = 'qb-promo-status-badge--' . preg_replace('/[^a-z]/', '', strtolower($pst));
        if ($stClass === 'qb-promo-status-badge--') {
            $stClass = 'qb-promo-status-badge--draft';
        }
    ?>
    <article class="qb-promo-sub-card">
      <div class="qb-promo-sub-card__top">
        <span class="qb-promo-status-badge <?= htmlspecialchars($stClass, ENT_QUOTES, 'UTF-8') ?>"><?= qb_esc_html($pst) ?></span>
        <span class="qb-promo-sub-card__metrics text-xs text-muted"><?= (int) ($p['view_count'] ?? 0) ?> views · <?= (int) ($p['like_count'] ?? 0) ?> likes</span>
      </div>
      <h3 class="qb-promo-sub-card__title"><?= qb_esc_html((string) ($p['title'] ?? '')) ?></h3>
      <p class="qb-promo-sub-card__timeline text-xs text-muted"><?= qb_esc_html($tl) ?></p>
      <div class="qb-promo-sub-card__actions">
        <?php if (in_array($pst, ['draft', 'pending'], true)): ?>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(APP_URL . '/seller/promotion_create.php?edit=' . $pid, ENT_QUOTES, 'UTF-8') ?>"><?= qb_icon('edit', 'qb-icon', 14) ?> Edit</a>
        <?php endif; ?>
        <?php if ($pst === 'pending'): ?>
        <form method="post" class="qb-promo-sub-card__inline-form" onsubmit="return confirm('Withdraw this submission from the review queue?');">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
          <input type="hidden" name="promo_post_id" value="<?= $pid ?>"/>
          <input type="hidden" name="promo_action" value="withdraw"/>
          <button type="submit" class="btn btn-ghost btn-sm text-danger"><?= qb_icon('ban', 'qb-icon', 14) ?> Withdraw</button>
        </form>
        <?php endif; ?>
        <?php if ($pst === 'rejected'): ?>
        <form method="post" class="qb-promo-sub-card__appeal">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
          <input type="hidden" name="promo_post_id" value="<?= $pid ?>"/>
          <input type="hidden" name="promo_action" value="appeal"/>
          <label class="form-label text-xs">Appeal to reviewers</label>
          <textarea name="appeal_message" class="form-control form-control-sm mb-1" rows="2" placeholder="Why should we reconsider?" required></textarea>
          <button type="submit" class="btn btn-primary btn-sm"><?= qb_icon('announce', 'qb-icon', 14) ?> Appeal</button>
        </form>
        <?php endif; ?>
        <?php if (in_array($pst, ['active', 'ended'], true)): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="this.nextElementSibling.hidden = !this.nextElementSibling.hidden"><?= qb_icon('plus', 'qb-icon', 14) ?> Extend</button>
        <form method="post" class="qb-promo-sub-card__extend-form mt-2 p-2 bg-light rounded" hidden>
           <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
           <input type="hidden" name="promo_post_id" value="<?= $pid ?>"/>
           <input type="hidden" name="promo_action" value="extend"/>
           <label class="form-label text-xs">Days to add:</label>
           <div style="display:flex;gap:0.5rem">
             <input type="number" name="extension_days" value="7" min="1" class="form-control form-control-sm" style="width:60px"/>
             <button type="submit" class="btn btn-success btn-sm">Pay &amp; Extend</button>
           </div>
           <p class="text-xs text-muted mt-1">Cost: <?= qb_setting_get_float('promo_extension_cost_per_day', 10.0) ?> ETB / day</p>
        </form>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

</div>

<script type="module">
import { initPromoForm } from <?= json_encode(APP_URL . '/assets/js/promo/PromoForm.js', JSON_UNESCAPED_SLASHES) ?>;
const root = document.querySelector('[data-promo-create]');
const xhr = root?.querySelector('[data-promo-xhr-flag]');
if (xhr) xhr.value = '1';
initPromoForm(root);
</script>
<?php
qb_page_end();
