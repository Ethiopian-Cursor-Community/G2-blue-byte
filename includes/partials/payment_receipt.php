<?php
/**
 * Ticket-style payment receipt.
 *
 * Expects: $tx (array), $items (array), $role ('buyer'|'seller')
 */
$role = isset($role) && in_array($role, ['buyer', 'seller'], true) ? $role : 'buyer';

$methodRaw = (string) ($tx['payment_method'] ?? 'chapa');
$methodPretty = 'Chapa';

$digits = preg_replace('/\D/', '', (string) ($tx['tx_id'] ?? ''));
$mask4 = strlen($digits) >= 4 ? substr($digits, -4) : strtoupper(substr(hash('sha256', (string) ($tx['tx_id'] ?? '')), 0, 4));

$currency = 'ETB';
$dt = $tx['created_at'] ?? date('Y-m-d H:i:s');
$ts = strtotime((string) $dt) ?: time();
$dateFmt = date('j M Y', $ts);
$timeFmt = date('H:i', $ts);
$amount = number_format((float) ($tx['total_amount'] ?? 0), 2);
$adminFeeAmountRaw = (float) ($tx['admin_fee_amount'] ?? 0);
$sellerNetRaw = (float) ($tx['seller_net_amount'] ?? (($tx['total_amount'] ?? 0) - $adminFeeAmountRaw));
$adminFeePctRaw = (float) ($tx['admin_fee_pct'] ?? 0);
$showAdminFee = $adminFeeAmountRaw > 0;
$txid = htmlspecialchars((string) ($tx['tx_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$buyerName = htmlspecialchars((string) ($tx['buyer_name'] ?? 'Buyer'), ENT_QUOTES, 'UTF-8');
$mask4h = htmlspecialchars($mask4, ENT_QUOTES, 'UTF-8');
$methodPrettyH = htmlspecialchars($methodPretty, ENT_QUOTES, 'UTF-8');
$methodClass = htmlspecialchars(preg_replace('/[^a-z0-9_-]/i', '', $methodRaw), ENT_QUOTES, 'UTF-8');
$market = htmlspecialchars((string) ($tx['market_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$sellerName = htmlspecialchars((string) ($tx['seller_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$eventName = !empty($tx['event_name']) ? htmlspecialchars((string) $tx['event_name'], ENT_QUOTES, 'UTF-8') : '';
$statusMeta = qb_payment_status_meta((string) ($tx['payment_status'] ?? 'completed'));

?>
<link rel="stylesheet" href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/payment-receipt.css"/>

<div class="qb-pay-receipt-wrap">
  <?php if ($role === 'buyer'): ?>
  <p class="qb-pay-receipt-hint">Show this receipt to the seller to confirm payment. Saved in your history.</p>
  <?php else: ?>
  <p class="qb-pay-receipt-hint">Same receipt the buyer sees — use to verify in-person payments.</p>
  <?php endif; ?>

  <article class="qb-pay-receipt" aria-label="Payment receipt">
    <div class="qb-pay-receipt__section qb-pay-receipt__hero">
      <div class="qb-pay-receipt__emoji" aria-hidden="true">🎉</div>
      <h1 class="qb-pay-receipt__title">Thank you!</h1>
      <p class="qb-pay-receipt__subtitle">Payment status: <span class="badge <?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></p>
    </div>

    <?php if ($role === 'buyer' && ($market !== '' || $eventName !== '')): ?>
    <div class="qb-pay-sticker">
      You supported <strong><?= $market !== '' ? $market : $sellerName ?></strong><?php if ($eventName !== ''): ?> at <strong><?= $eventName ?></strong><?php endif; ?>.
      <small>Share this screen — it helps local stalls grow.</small>
    </div>
    <?php endif; ?>

    <div class="qb-pay-receipt__perforation" aria-hidden="true"></div>

    <div class="qb-pay-receipt__section qb-pay-receipt__grid">
      <div class="qb-pay-receipt__cell">
        <span class="qb-pay-receipt__label">Ticket ID</span>
        <span class="qb-pay-receipt__value qb-pay-receipt__value--mono"><?= $txid ?></span>
      </div>
      <div class="qb-pay-receipt__cell qb-pay-receipt__cell--right">
        <span class="qb-pay-receipt__label">Amount</span>
        <span class="qb-pay-receipt__value"><?= $amount ?> <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <?php if ($showAdminFee): ?>
      <div class="qb-pay-receipt__cell">
        <span class="qb-pay-receipt__label">Admin fee</span>
        <span class="qb-pay-receipt__value"><?= number_format($adminFeeAmountRaw, 2) ?> <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?> (<?= number_format($adminFeePctRaw, 2) ?>%)</span>
      </div>
      <div class="qb-pay-receipt__cell qb-pay-receipt__cell--right">
        <span class="qb-pay-receipt__label">Seller net</span>
        <span class="qb-pay-receipt__value"><?= number_format($sellerNetRaw, 2) ?> <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <?php endif; ?>
      <div class="qb-pay-receipt__cell qb-pay-receipt__cell--full">
        <span class="qb-pay-receipt__label">Date &amp; time</span>
        <span class="qb-pay-receipt__value"><?= htmlspecialchars($dateFmt . ' • ' . $timeFmt, ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <?php if ($market !== '' || $sellerName !== ''): ?>
      <div class="qb-pay-receipt__cell qb-pay-receipt__cell--full qb-pay-receipt__merchant">
        <span class="qb-pay-receipt__label">Seller</span>
        <span class="qb-pay-receipt__value"><?= $market !== '' ? $market : $sellerName ?></span>
        <?php if ($market !== '' && $sellerName !== ''): ?>
          <span class="qb-pay-receipt__fine"><?= $sellerName ?></span>
        <?php endif; ?>
        <?php if ($eventName !== ''): ?>
          <span class="qb-pay-badge"><?= $eventName ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="qb-pay-card">
        <div class="qb-pay-card__brand qb-pay-card__brand--<?= $methodClass ?>" aria-hidden="true"></div>
        <div class="qb-pay-card__body">
          <div class="qb-pay-card__name"><?= $buyerName ?></div>
          <div class="qb-pay-card__mask">•••• <?= $mask4h ?></div>
          <div class="qb-pay-card__method"><?= $methodPrettyH ?></div>
        </div>
      </div>
    </div>

    <div class="qb-pay-receipt__perforation" aria-hidden="true"></div>

    <div class="qb-pay-receipt__section qb-pay-receipt__barcode-block">
      <svg id="qb-pay-barcode" class="qb-pay-barcode-svg" role="img" aria-label="Barcode"></svg>
    </div>

    <div class="qb-pay-receipt__scallop" aria-hidden="true"></div>
  </article>

  <?php if (!empty($items)): ?>
  <div class="qb-pay-items card" style="margin-top:1rem;padding:1rem 1.25rem">
    <div class="text-xs font-bold text-muted text-uppercase mb-2">Items</div>
    <?php foreach ($items as $i): ?>
      <div class="qb-pay-line">
        <span><strong><?= (int) ($i['quantity'] ?? 0) ?>×</strong> <?= htmlspecialchars((string) ($i['product_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        <span class="font-bold"><?= number_format((float) ($i['subtotal'] ?? 0), 2) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
(function(){
  var id = <?= json_encode((string) ($tx['tx_id'] ?? '')) ?>;
  function run(){
    var el = document.getElementById('qb-pay-barcode');
    if (!el || !id || typeof JsBarcode === 'undefined') return;
    try {
      JsBarcode(el, id, { format: 'CODE128', displayValue: true, fontSize: 11, height: 44, margin: 6, width: 1.6 });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();
</script>
