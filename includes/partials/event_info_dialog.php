<?php
declare(strict_types=1);
/** One dialog per page — safe to require_once from multiple call sites. */
if (!empty($GLOBALS['qb_event_info_dialog_included'])) {
    return;
}
$GLOBALS['qb_event_info_dialog_included'] = true;
$js = htmlspecialchars(rtrim((string) APP_URL, '/') . '/assets/js/qb-event-info-dialog.js', ENT_QUOTES, 'UTF-8');
?>
<dialog class="qb-event-info-dialog" id="qb-event-info-dialog" aria-labelledby="qb-event-info-dialog-title">
  <div class="qb-event-info-dialog__panel card">
    <div class="qb-event-info-dialog__head">
      <h2 class="qb-event-info-dialog__title" id="qb-event-info-dialog-title"></h2>
      <button type="button" class="btn btn-ghost btn-sm qb-event-info-dialog__close" data-qb-event-dialog-close aria-label="Close">×</button>
    </div>
    <div class="qb-event-info-dialog__body" id="qb-event-info-dialog-body"></div>
    <p class="qb-event-info-dialog__hint text-xs text-muted">Double-click an event card to open · Escape to close</p>
  </div>
</dialog>
<script src="<?= $js ?>" defer></script>
