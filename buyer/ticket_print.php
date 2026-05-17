<?php
/**
 * Printable admission ticket — watermark art, tier (standard / premium / vip), # and price.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireBuyer();

$user = currentUser();
$uid = (int) $user['id'];
$ticketId = (int) ($_GET['id'] ?? 0);

$eventCols = 'e.name AS event_name, e.city, e.venue, e.event_start, e.event_end';
if (function_exists('qb_has_column') && qb_has_column('bazar_events', 'cover_image')) {
    $eventCols .= ', e.cover_image';
}

$ticket = db()->fetchOne(
    "SELECT t.*, $eventCols
     FROM tickets t
     INNER JOIN bazar_events e ON e.id = t.event_id
     WHERE t.id = ? AND t.buyer_id = ? AND t.status = ?",
    [$ticketId, $uid, 'active']
);

if (!$ticket) {
    http_response_code(404);
    exit('Ticket not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Ticket — <?= htmlspecialchars($ticket['event_name']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="../assets/css/ticket-print.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
</head>
<body>

<div class="shell">
  <div class="shell__brand no-print">
    <span><?= htmlspecialchars(APP_NAME) ?> · Digital admission</span>
    <div class="shell__actions">
      <button type="button" class="btn btn--primary" onclick="window.print()">Print / Save as PDF</button>
      <a href="tickets.php" class="btn btn--ghost">Back</a>
    </div>
  </div>

  <div class="ticket-print-wrap">
    <?php
    $GLOBALS['qb_ticket_print_batch'] = [];
    require __DIR__ . '/../includes/partials/ticket_print_single.php';
    $batch = $GLOBALS['qb_ticket_print_batch'] ?? [];
    ?>
  </div>

  <p class="foot no-print">
    <?php
    $coverUrl = '';
    if (!empty($ticket['cover_image'])) {
        $coverUrl = qb_public_upload_url($ticket['cover_image']);
    }
    ?>
    <?php if (!$coverUrl): ?>
      Add a <strong>cover image</strong> under Admin → Event branding — it appears faintly as a watermark on this ticket.
    <?php else: ?>
      Event artwork is shown as a subtle watermark; codes stay sharp for scanning.
    <?php endif; ?>
  </p>
</div>

<script>
(function () {
  var batch = <?= json_encode($batch ?? [], JSON_UNESCAPED_UNICODE) ?>;

  function renderQr(el, size, text) {
    if (typeof QRCode === 'undefined' || !el) return;
    el.innerHTML = '';
    new QRCode(el, { text: text, width: size, height: size, correctLevel: QRCode.CorrectLevel.H });
  }

  document.addEventListener('DOMContentLoaded', function () {
    batch.forEach(function (row) {
      var id = row.id;
      var qrText = row.qrText;
      var barcodeVal = row.barcode;
      var isVip = row.vip;

      renderQr(document.getElementById('qrcode-main-' + id), 168, qrText);

      if (typeof JsBarcode !== 'undefined') {
        var sel = '#barcode-' + id;
        try {
          JsBarcode(sel, barcodeVal, {
            format: 'CODE128',
            displayValue: false,
            height: 52,
            width: 1.55,
            margin: 2,
            background: 'transparent',
            lineColor: isVip ? '#e2e8f0' : '#0f172a'
          });
        } catch (e) {
          try {
            JsBarcode(sel, barcodeVal, { format: 'CODE39', displayValue: false, height: 52, width: 1.2, margin: 2 });
          } catch (e2) {
            var node = document.getElementById('barcode-' + id);
            if (node && node.parentNode) node.parentNode.removeChild(node);
          }
        }
      }
    });
  });
})();
</script>
</body>
</html>
