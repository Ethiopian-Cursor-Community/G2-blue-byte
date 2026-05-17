<?php
/** @var array $ticket joined row tickets + event cols */
/** @var array $user */
$tid = (int) ($ticket['id'] ?? 0);
$tier = strtolower((string) ($ticket['ticket_tier'] ?? 'standard'));
if (!in_array($tier, ['standard', 'premium', 'vip', 'day_pass'], true)) {
    $tier = 'standard';
}
$faceValue = isset($ticket['face_value_etb']) ? (float) $ticket['face_value_etb'] : 0.0;
$ticketNo = trim((string) ($ticket['display_no'] ?? ''));
if ($ticketNo === '') {
    $ticketNo = 'QB-' . str_pad((string) $tid, 6, '0', STR_PAD_LEFT);
}
$qrRaw = $ticket['qr_data'] ?? null;
$qrText = (string) (($qrRaw !== null && $qrRaw !== '') ? $qrRaw : $ticket['ticket_code']);
$when = !empty($ticket['event_start']) ? date('M j, Y · g:i A', strtotime($ticket['event_start'])) : '';
$placeParts = array_filter([trim((string) ($ticket['venue'] ?? '')), trim((string) ($ticket['city'] ?? ''))]);
$placeLine = implode(' · ', $placeParts);
if ($placeLine === '') {
    $placeLine = '—';
}
$coverUrl = '';
if (!empty($ticket['cover_image'])) {
    $coverUrl = qb_public_upload_url($ticket['cover_image']);
}
$codeSpaced = trim(chunk_split(preg_replace('/\s+/', '', (string) $ticket['ticket_code']), 4, ' '));
$barcodeValue = (string) $ticket['ticket_code'];
$tierLabel = match ($tier) {
    'premium' => 'Premium',
    'vip' => 'VIP',
    'day_pass' => 'Day pass',
    default => 'Standard',
};
$priceLabel = $faceValue > 0.005
    ? number_format($faceValue, 2) . ' ETB'
    : 'Complimentary';
?>
  <div class="ticket ticket--<?= htmlspecialchars($tier) ?>" data-tier="<?= htmlspecialchars($tier) ?>" data-ticket-id="<?= $tid ?>">
    <div class="ticket__wash" aria-hidden="true">
      <?php if ($coverUrl): ?>
      <div class="ticket__watermark" style="background-image:url('<?= htmlspecialchars($coverUrl) ?>')"></div>
      <?php endif; ?>
      <div class="ticket__veil"></div>
    </div>

    <div class="ticket__main">
      <div class="ticket__row-top">
        <span class="pill-tier"><?= htmlspecialchars($tierLabel) ?></span>
        <div class="ticket__nums" role="group" aria-label="Ticket details">
          <div class="ticket__pair">
            <span class="ticket__k">Ticket no.</span>
            <span class="ticket__v"><?= htmlspecialchars($ticketNo) ?></span>
          </div>
          <div class="ticket__pair">
            <span class="ticket__k">Price</span>
            <span class="ticket__v"><?= htmlspecialchars($priceLabel) ?></span>
          </div>
        </div>
      </div>

      <h1 class="ticket__title"><?= htmlspecialchars($ticket['event_name']) ?></h1>
      <p class="ticket__meta"><?= htmlspecialchars($placeLine) ?></p>
      <?php if ($when): ?>
        <p class="ticket__when"><?= htmlspecialchars($when) ?></p>
      <?php endif; ?>

      <p class="ticket__holder">Admits: <strong><?= htmlspecialchars($user['display_name'] ?? 'Guest') ?></strong></p>
      <span class="ticket__valid">✓ Valid · Admit one</span>

      <div class="ticket__qrrow">
        <div class="qrbox">
          <div id="qrcode-main-<?= $tid ?>"></div>
          <small>Scan at gate</small>
        </div>
      </div>
    </div>

    <div class="ticket__perf-wrap" aria-hidden="true">
      <span class="ticket__perf-line"></span>
    </div>

    <div class="ticket__stub">
      <span class="stub-label">Schedule</span>
      <div class="stub-dates">
        <?php if (!empty($ticket['event_start'])): ?>
          <div class="stub-date-row"><span class="stub-date-k">Starts</span><span class="stub-date-v"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($ticket['event_start']))) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($ticket['event_end'])): ?>
          <div class="stub-date-row"><span class="stub-date-k">Ends</span><span class="stub-date-v"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($ticket['event_end']))) ?></span></div>
        <?php endif; ?>
        <?php if (empty($ticket['event_start']) && empty($ticket['event_end'])): ?>
          <div class="stub-date-row"><span class="stub-date-v">Schedule TBA</span></div>
        <?php endif; ?>
      </div>
      <span class="stub-label">Barcode</span>
      <div class="stub-barcode-wrap">
        <svg id="barcode-<?= $tid ?>" aria-label="Barcode"></svg>
      </div>
      <div class="stub-code"><?= htmlspecialchars($codeSpaced) ?></div>
    </div>
  </div>
<?php
// Expose for batch script
if (!isset($GLOBALS['qb_ticket_print_batch'])) {
    $GLOBALS['qb_ticket_print_batch'] = [];
}
$GLOBALS['qb_ticket_print_batch'][] = [
    'id' => $tid,
    'qrText' => $qrText,
    'barcode' => $barcodeValue,
    'vip' => $tier === 'vip',
];
