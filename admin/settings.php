<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

startSession();
requireAdmin();
qb_apply_system_settings_schema();

$success = '';
$error = '';
$csrf = qb_csrf_token();

$defaults = [
    'promo_fee_etb' => (float) CHAPA_PROMO_FEE_ETB,
    'ticket_fee_etb' => (float) CHAPA_TICKET_FEE_ETB,
    'role_request_fee_etb' => (float) CHAPA_ROLE_REQUEST_FEE_ETB,
    'seller_event_fee_etb' => (float) CHAPA_SELLER_EVENT_FEE_ETB,
    'tx_admin_fee_pct' => 5.0,
    'seller_product_slot_fee_etb' => 25.0,
    'seller_product_free_limit' => 7,
    'promo_daily_submission_limit' => 10,
    'promo_auto_publish_paid' => 1,
    'require_seller_compliance' => 1,
    'promo_expiry_days' => 7,
    'promo_extension_cost_per_day' => 10.0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token. Refresh and try again.';
    } else {
        $promoFee = max(0.0, (float) ($_POST['promo_fee_etb'] ?? $defaults['promo_fee_etb']));
        $ticketFee = max(0.0, (float) ($_POST['ticket_fee_etb'] ?? $defaults['ticket_fee_etb']));
        $roleFee = max(0.0, (float) ($_POST['role_request_fee_etb'] ?? $defaults['role_request_fee_etb']));
        $sellerEventFee = max(0.0, (float) ($_POST['seller_event_fee_etb'] ?? $defaults['seller_event_fee_etb']));
        $txAdminFeePct = max(0.0, (float) ($_POST['tx_admin_fee_pct'] ?? $defaults['tx_admin_fee_pct']));
        if ($txAdminFeePct > 100.0) {
            $txAdminFeePct = 100.0;
        }
        $sellerProductSlotFee = max(0.0, (float) ($_POST['seller_product_slot_fee_etb'] ?? $defaults['seller_product_slot_fee_etb']));
        $sellerProductFreeLimit = (int) ($_POST['seller_product_free_limit'] ?? $defaults['seller_product_free_limit']);
        if ($sellerProductFreeLimit < 0) {
            $sellerProductFreeLimit = 0;
        } elseif ($sellerProductFreeLimit > 500) {
            $sellerProductFreeLimit = 500;
        }
        $promoDailyLimit = (int) ($_POST['promo_daily_submission_limit'] ?? $defaults['promo_daily_submission_limit']);
        if ($promoDailyLimit < 1) {
            $promoDailyLimit = 1;
        } elseif ($promoDailyLimit > 100) {
            $promoDailyLimit = 100;
        }
        $promoAutoPublish = !empty($_POST['promo_auto_publish_paid']) ? 1 : 0;
        $sellerComplianceRequired = !empty($_POST['require_seller_compliance']) ? 1 : 0;
        $promoExpiryDays = max(1, (int) ($_POST['promo_expiry_days'] ?? $defaults['promo_expiry_days']));
        $promoExtCost = max(0.0, (float) ($_POST['promo_extension_cost_per_day'] ?? $defaults['promo_extension_cost_per_day']));

        qb_setting_set('promo_fee_etb', (string) $promoFee);
        qb_setting_set('ticket_fee_etb', (string) $ticketFee);
        qb_setting_set('role_request_fee_etb', (string) $roleFee);
        qb_setting_set('seller_event_fee_etb', (string) $sellerEventFee);
        qb_setting_set('tx_admin_fee_pct', (string) $txAdminFeePct);
        qb_setting_set('seller_product_slot_fee_etb', (string) $sellerProductSlotFee);
        qb_setting_set('seller_product_free_limit', (string) $sellerProductFreeLimit);
        qb_setting_set('promo_daily_submission_limit', (string) $promoDailyLimit);
        qb_setting_set('promo_auto_publish_paid', (string) $promoAutoPublish);
        qb_setting_set('require_seller_compliance', (string) $sellerComplianceRequired);
        qb_setting_set('promo_expiry_days', (string) $promoExpiryDays);
        qb_setting_set('promo_extension_cost_per_day', (string) $promoExtCost);
        $success = 'System settings updated.';
    }
}

$current = [
    'promo_fee_etb' => qb_setting_get_float('promo_fee_etb', $defaults['promo_fee_etb']),
    'ticket_fee_etb' => qb_setting_get_float('ticket_fee_etb', $defaults['ticket_fee_etb']),
    'role_request_fee_etb' => qb_setting_get_float('role_request_fee_etb', $defaults['role_request_fee_etb']),
    'seller_event_fee_etb' => qb_setting_get_float('seller_event_fee_etb', $defaults['seller_event_fee_etb']),
    'tx_admin_fee_pct' => qb_setting_get_float('tx_admin_fee_pct', $defaults['tx_admin_fee_pct']),
    'seller_product_slot_fee_etb' => qb_setting_get_float('seller_product_slot_fee_etb', $defaults['seller_product_slot_fee_etb']),
    'seller_product_free_limit' => qb_setting_get_int('seller_product_free_limit', (int) $defaults['seller_product_free_limit']),
    'promo_daily_submission_limit' => qb_setting_get_int('promo_daily_submission_limit', (int) $defaults['promo_daily_submission_limit']),
    'promo_auto_publish_paid' => qb_setting_get_bool('promo_auto_publish_paid', true),
    'require_seller_compliance' => qb_setting_get_bool('require_seller_compliance', true),
    'promo_expiry_days' => qb_setting_get_int('promo_expiry_days', (int) $defaults['promo_expiry_days']),
    'promo_extension_cost_per_day' => qb_setting_get_float('promo_extension_cost_per_day', (float) $defaults['promo_extension_cost_per_day']),
];

qb_page_start('admin', 'System settings', 'settings.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">System settings</h1>
    <p class="page-subtitle">Control promotion pricing and key platform behavior.</p>
  </div>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post" class="card" style="max-width:920px">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>

  <h3 class="font-bold mb-2">Payment pricing (ETB)</h3>
  <div class="grid grid-2 gap-2 mb-3">
    <div class="form-group mb-0">
      <label class="form-label">Promotion publish fee</label>
      <input type="number" step="0.01" min="0" name="promo_fee_etb" class="form-control" value="<?= htmlspecialchars((string) $current['promo_fee_etb']) ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Ticket default fee</label>
      <input type="number" step="0.01" min="0" name="ticket_fee_etb" class="form-control" value="<?= htmlspecialchars((string) $current['ticket_fee_etb']) ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Role request fee</label>
      <input type="number" step="0.01" min="0" name="role_request_fee_etb" class="form-control" value="<?= htmlspecialchars((string) $current['role_request_fee_etb']) ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Seller event application fee</label>
      <input type="number" step="0.01" min="0" name="seller_event_fee_etb" class="form-control" value="<?= htmlspecialchars((string) $current['seller_event_fee_etb']) ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Buyer→Seller transaction admin fee (%)</label>
      <input type="number" step="0.01" min="0" max="100" name="tx_admin_fee_pct" class="form-control" value="<?= htmlspecialchars((string) $current['tx_admin_fee_pct']) ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Seller extra commodity slot fee (per product)</label>
      <input type="number" step="0.01" min="0" name="seller_product_slot_fee_etb" class="form-control" value="<?= htmlspecialchars((string) $current['seller_product_slot_fee_etb']) ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Free commodity slots per seller</label>
      <input type="number" min="0" max="500" name="seller_product_free_limit" class="form-control" value="<?= (int) $current['seller_product_free_limit'] ?>"/>
    </div>
  </div>

  <h3 class="font-bold mb-2">Promotion controls</h3>
  <div class="grid grid-2 gap-2 mb-3">
    <div class="form-group mb-0">
      <label class="form-label">Promo daily submission limit</label>
      <input type="number" min="1" max="100" name="promo_daily_submission_limit" class="form-control" value="<?= (int) $current['promo_daily_submission_limit'] ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Behavior toggles</label>
      <label class="text-sm text-muted d-block"><input type="checkbox" name="promo_auto_publish_paid" value="1" <?= $current['promo_auto_publish_paid'] ? 'checked' : '' ?>/> Auto-publish paid promotions</label>
      <label class="text-sm text-muted d-block"><input type="checkbox" name="require_seller_compliance" value="1" <?= $current['require_seller_compliance'] ? 'checked' : '' ?>/> Require seller compliance fields on upgrade</label>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Default promo expiry (days)</label>
      <input type="number" min="1" name="promo_expiry_days" class="form-control" value="<?= (int) $current['promo_expiry_days'] ?>"/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label">Promo extension cost (per day, ETB)</label>
      <input type="number" step="0.01" min="0" name="promo_extension_cost_per_day" class="form-control" value="<?= htmlspecialchars((string) $current['promo_extension_cost_per_day']) ?>"/>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Save settings</button>
</form>
<?php qb_page_end(); ?>
