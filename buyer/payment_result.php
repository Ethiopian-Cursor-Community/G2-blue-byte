<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireLogin();

$intentId = (string) ($_GET['intent'] ?? '');
$status = (string) ($_GET['status'] ?? 'pending');
$next = (string) ($_GET['next'] ?? (APP_URL . '/buyer/home.php'));
$errorMsg = trim((string) ($_GET['error'] ?? ''));

$intent = $intentId !== '' ? qb_payment_intent_get($intentId) : null;
$canRetry = $intent && in_array((string) ($intent['provider_status'] ?? 'pending'), ['pending', 'failed', 'cancelled'], true);
$intentAmount = (float) ($intent['amount'] ?? 0);
$intentCurrency = (string) ($intent['currency'] ?? 'ETB');
$intentCreatedAt = (string) ($intent['created_at'] ?? '');
$intentProviderStatus = (string) ($intent['provider_status'] ?? 'pending');
$intentTargetType = (string) ($intent['target_type'] ?? 'payment');
$successIconSvg = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 88 88"><circle cx="44" cy="44" r="40" fill="#35B64A"/><path d="M25 45.5l13 13 25-27" fill="none" stroke="#FFFFFF" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/></svg>');

$targetLabel = 'Payment';
if ($intentTargetType === 'ticket_purchase') {
    $targetLabel = 'Ticket purchase';
} elseif ($intentTargetType === 'product_purchase') {
    $targetLabel = 'Product purchase';
} elseif ($intentTargetType === 'promo_paid') {
    $targetLabel = 'Promotion payment';
} elseif ($intentTargetType === 'role_request') {
    $targetLabel = 'Role request payment';
} elseif ($intentTargetType === 'seller_event_apply') {
    $targetLabel = 'Seller event application';
}

qb_page_start(currentRole() === 'buyer' ? 'buyer' : 'seller', 'Payment status', 'home.php', false);
?>
<div class="qb-pay-overlay" role="dialog" aria-modal="true" aria-label="Payment confirmation">
<div class="card qb-pay-overlay__card" style="max-width:460px;margin:0 auto">
  <?php
    $icon = 'clock';
    $iconClass = 'qb-pay-overlay__icon qb-pay-overlay__icon--pending';
    if ($status === 'success') {
        $icon = 'check';
        $iconClass = 'qb-pay-overlay__icon qb-pay-overlay__icon--success';
    } elseif ($status === 'failed') {
        $icon = 'x';
        $iconClass = 'qb-pay-overlay__icon qb-pay-overlay__icon--failed';
    }
  ?>
  <div id="payStatusIcon" class="<?= htmlspecialchars($iconClass) ?>" aria-hidden="true">
    <?php if ($status === 'success'): ?>
      <img src="<?= htmlspecialchars($successIconSvg) ?>" alt="" class="qb-pay-overlay__img-icon"/>
    <?php else: ?>
      <?= qb_icon($icon, 'qb-icon', 52) ?>
    <?php endif; ?>
  </div>
  <h1 class="page-title">Payment status</h1>
  <?php if ($status === 'success'): ?>
    <div id="payStatusAlert" class="alert alert-success">Payment verified and fulfilled.</div>
  <?php elseif ($status === 'failed'): ?>
    <div id="payStatusAlert" class="alert alert-danger">Payment failed or could not be fulfilled. You can retry securely.</div>
    <?php if ($errorMsg !== ''): ?>
      <p class="text-xs text-muted mb-2">Reason: <?= htmlspecialchars($errorMsg) ?></p>
    <?php endif; ?>
  <?php else: ?>
    <div id="payStatusAlert" class="alert alert-warning">Payment is pending verification. This page can re-check your payment.</div>
  <?php endif; ?>

  <div class="text-sm text-muted mb-2">Intent: <code><?= htmlspecialchars($intentId !== '' ? $intentId : 'N/A') ?></code></div>
  <div class="card mb-3" style="border:1px solid #e2e8f0;border-radius:10px;padding:.65rem .7rem;background:#f8fafc">
    <div class="text-xs text-muted mb-1">Confirmation details</div>
    <div class="text-sm" style="display:grid;grid-template-columns:1fr auto;gap:.35rem .6rem">
      <span class="text-muted">Amount</span><strong><?= number_format($intentAmount, 2) ?> <?= htmlspecialchars($intentCurrency) ?></strong>
      <span class="text-muted">Status</span><span><?= htmlspecialchars(ucfirst($intentProviderStatus)) ?></span>
      <span class="text-muted">Type</span><span><?= htmlspecialchars($targetLabel) ?></span>
      <span class="text-muted">Method</span><span>Chapa</span>
      <span class="text-muted">Date</span><span><?= $intentCreatedAt !== '' ? htmlspecialchars(date('j M Y · H:i', strtotime($intentCreatedAt))) : 'N/A' ?></span>
    </div>
  </div>
  <div id="paySavedNote" class="text-xs text-muted mb-2"<?= $status === 'success' ? '' : ' style="display:none"' ?>>Saved in payment history.</div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php if ($canRetry): ?>
      <button id="retryPayBtn" class="btn btn-primary" data-intent="<?= htmlspecialchars($intentId) ?>">Retry payment</button>
    <?php endif; ?>
    <a href="<?= htmlspecialchars($next) ?>" class="btn btn-ghost">Continue</a>
  </div>
  <p id="payStateMsg" class="text-xs text-muted mt-2"></p>
</div>
</div>
<script>
(function(){
  function postForm(url, params){
    var form = document.createElement('form');
    form.method = 'post'; form.action = url;
    Object.keys(params).forEach(function(k){
      var i = document.createElement('input'); i.type='hidden'; i.name=k; i.value=params[k]; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
  }
  var msg = document.getElementById('payStateMsg');
  var statusAlert = document.getElementById('payStatusAlert');
  var statusIcon = document.getElementById('payStatusIcon');
  var savedNote = document.getElementById('paySavedNote');
  var retry = document.getElementById('retryPayBtn');
  var intentId = <?= json_encode($intentId, JSON_UNESCAPED_SLASHES) ?>;
  var verifyInFlight = false;
  function setVisual(state, text){
    if (!statusAlert || !statusIcon) return;
    statusAlert.className = 'alert';
    if (state === 'success') {
      statusAlert.classList.add('alert-success');
      statusAlert.textContent = text || 'Payment verified and fulfilled.';
      statusIcon.className = 'qb-pay-overlay__icon qb-pay-overlay__icon--success';
      statusIcon.innerHTML = '<img src="<?= htmlspecialchars($successIconSvg) ?>" alt="" class="qb-pay-overlay__img-icon"/>';
      if (savedNote) savedNote.style.display = '';
      return;
    }
    if (state === 'paid') {
      statusAlert.classList.add('alert-info');
      statusAlert.textContent = text || 'Payment is received. Finalizing order now...';
      statusIcon.className = 'qb-pay-overlay__icon qb-pay-overlay__icon--pending';
      statusIcon.innerHTML = '<?= addslashes(qb_icon('clock', 'qb-icon', 52)) ?>';
      if (savedNote) savedNote.style.display = 'none';
      return;
    }
    if (state === 'failed') {
      statusAlert.classList.add('alert-danger');
      statusAlert.textContent = text || 'Payment failed or could not be fulfilled. You can retry securely.';
      statusIcon.className = 'qb-pay-overlay__icon qb-pay-overlay__icon--failed';
      statusIcon.innerHTML = '<?= addslashes(qb_icon('x', 'qb-icon', 52)) ?>';
      if (savedNote) savedNote.style.display = 'none';
      return;
    }
    statusAlert.classList.add('alert-warning');
    statusAlert.textContent = text || 'Payment is pending verification. This page can re-check your payment.';
    statusIcon.className = 'qb-pay-overlay__icon qb-pay-overlay__icon--pending';
    statusIcon.innerHTML = '<?= addslashes(qb_icon('clock', 'qb-icon', 52)) ?>';
    if (savedNote) savedNote.style.display = 'none';
  }
  function doRecheck() {
    if (!intentId) return Promise.resolve(null);
    if (verifyInFlight) return Promise.resolve(null);
    verifyInFlight = true;
    return fetch('../api/chapa_verify.php?intent_id='+encodeURIComponent(intentId))
      .then(function(r){return r.json();})
      .then(function(j){
        if (j && j.ok) {
          var st = (j.status || 'paid').toLowerCase();
          if (st === 'fulfilled' || st === 'success' || st === 'completed') {
            setVisual('success');
          } else if (st === 'paid') {
            setVisual('paid');
          } else {
            setVisual('pending');
          }
          msg.textContent = 'Payment status: '+st+'.';
          if (j.redirect) {
            location.href = j.redirect;
            return j;
          }
        } else {
          setVisual('failed');
          msg.textContent = (j && j.error) ? j.error : 'Still pending.';
        }
        return j;
      })
      .catch(function(){ msg.textContent = 'Could not verify right now.'; return null; })
      .finally(function(){ verifyInFlight = false; });
  }
  if (retry) retry.addEventListener('click', function(){
    var intent = retry.getAttribute('data-intent') || '';
    if (!intent) return;
    retry.disabled = true; retry.textContent = 'Loading...'; msg.textContent = 'Starting hosted checkout...';
    fetch('../api/chapa_initialize.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'intent_id='+encodeURIComponent(intent)})
      .then(function(r){return r.json();})
      .then(function(j){ if (j && j.ok && j.checkout_url) { location.href = j.checkout_url; return; } throw new Error((j && j.error) || 'Failed'); })
      .catch(function(e){ retry.disabled = false; retry.textContent = 'Retry payment'; msg.textContent = e.message; });
  });
  var status = <?= json_encode($status, JSON_UNESCAPED_SLASHES) ?>;
  if (status === 'success') {
    setTimeout(function(){
      location.href = <?= json_encode($next, JSON_UNESCAPED_SLASHES) ?>;
    }, 900);
  } else if (status === 'pending' || status === 'paid') {
    msg.textContent = 'Checking payment status automatically...';
    var attempts = 0;
    var maxAttempts = 3; // hard cap around ~3-4s
    var pollMs = 900;
    doRecheck();
    var timer = setInterval(function(){
      attempts += 1;
      doRecheck().then(function(j){
        if (j && j.ok && j.redirect) {
          clearInterval(timer);
          return;
        }
        if (attempts >= maxAttempts) {
          clearInterval(timer);
          msg.textContent = 'Still pending. Tap retry now or continue; verification will keep updating.';
        }
      });
    }, pollMs);
  }
})();
</script>
<?php qb_page_end(); ?>
