<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/export_helpers.php';
startSession();
requireAdmin();

$catalog = qb_export_dataset_catalog();
$csrf = qb_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['export_run'] ?? '') === '1') {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        header('Location: exports.php?err=csrf');
        exit;
    }

    $format = (string) ($_POST['format'] ?? 'csv_zip');
    if (!in_array($format, ['csv_single', 'csv_zip', 'html'], true)) {
        $format = 'csv_zip';
    }

    $datasets = $_POST['datasets'] ?? [];
    if (!is_array($datasets)) {
        $datasets = [];
    }
    $datasets = array_values(array_intersect(array_keys($catalog), array_map('strval', $datasets)));
    if ($datasets === []) {
        header('Location: exports.php?err=none');
        exit;
    }

    $df = trim((string) ($_POST['date_from'] ?? ''));
    $dt = trim((string) ($_POST['date_to'] ?? ''));
    if ($df !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
        $df = '';
    }
    if ($dt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        $dt = '';
    }

    $vatPct = (float) ($_POST['vat_rate'] ?? QB_EXPORT_DEFAULT_VAT_PERCENT);
    if ($vatPct < 0 || $vatPct > 100) {
        $vatPct = QB_EXPORT_DEFAULT_VAT_PERCENT;
    }
    $vatMode = (string) ($_POST['vat_mode'] ?? 'inclusive');
    if (!in_array($vatMode, ['inclusive', 'exclusive'], true)) {
        $vatMode = 'inclusive';
    }

    $includePii = isset($_POST['include_pii']) && $_POST['include_pii'] === '1';

    $admin = currentUser();
    $genName = ($admin['display_name'] ?? 'Admin') . ' (id ' . (int) ($admin['id'] ?? 0) . ')';

    /* —— HTML (print to PDF) —— */
    if ($format === 'html') {
        $style = '
          body{font-family:system-ui,Segoe UI,Roboto,sans-serif;margin:1.25rem;color:#111;background:#fff}
          .qb-export-meta{background:#f4f4f5;padding:0.85rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.85rem;line-height:1.5}
          .qb-export-meta strong{color:#0a0a0a}
          h1{font-size:1.35rem;margin:0 0 0.5rem}
          h2{font-size:1.05rem;margin:1.5rem 0 0.5rem;color:#1e293b}
          .qb-export-table{border-collapse:collapse;width:100%;font-size:0.72rem;margin-bottom:1rem}
          .qb-export-table caption{text-align:left;font-weight:700;padding:0.35rem 0;font-size:0.8rem}
          .qb-export-table th,.qb-export-table td{border:1px solid #cbd5e1;padding:0.35rem 0.45rem;text-align:left;vertical-align:top}
          .qb-export-table th{background:#e2e8f0}
          @media print{ body{margin:0.5cm} .qb-export-meta{break-inside:avoid} }
        ';
        $parts = [];
        $parts[] = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"/><title>QR Bazar — data export</title><style>' . $style . '</style></head><body>';
        $parts[] = '<h1>' . qb_export_html_escape(APP_NAME) . ' — compliance export</h1>';
        $parts[] = '<div class="qb-export-meta"><strong>Generated:</strong> ' . qb_export_html_escape(date('Y-m-d H:i:s')) . ' (Africa/Addis_Ababa)<br/>';
        $parts[] = '<strong>Prepared by:</strong> ' . qb_export_html_escape($genName) . '<br/>';
        $parts[] = '<strong>Currency:</strong> ETB<br/>';
        $parts[] = '<strong>Date filter:</strong> ' . qb_export_html_escape($df !== '' || $dt !== '' ? (($df ?: '…') . ' → ' . ($dt ?: '…')) : 'Full history (no date filter)') . '<br/>';
        $parts[] = '<strong>VAT / tax assumptions:</strong> Rate ' . qb_export_html_escape((string) $vatPct) . '% — ';
        $parts[] = $vatMode === 'inclusive'
            ? 'Amounts in transactions and line items are treated as <strong>VAT-inclusive</strong>; taxable base and VAT are derived for reporting.'
            : 'Amounts are treated as <strong>VAT-exclusive</strong>; VAT is calculated on top for reporting.';
        $parts[] = '<br/><strong>PII:</strong> ' . ($includePii ? 'Included (phones/emails where applicable).' : 'Reduced (phones/emails omitted where optional).') . '<br/>';
        $parts[] = '<em>Disclaimer: Tax figures are indicative for disclosure workflows — confirm with your accountant or tax authority.</em></div>';

        foreach ($datasets as $key) {
            $pack = qb_export_dataset_rows($key, $df !== '' ? $df : null, $dt !== '' ? $dt : null, $includePii, $vatPct, $vatMode);
            $title = $catalog[$key]['label'] ?? $key;
            $parts[] = '<h2>' . qb_export_html_escape($title) . '</h2>';
            if ($pack['rows'] === []) {
                $parts[] = '<p class="text-muted">No rows in this range.</p>';
                continue;
            }
            $parts[] = qb_export_html_table($title, $pack['headers'], $pack['rows']);
        }
        $parts[] = '</body></html>';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="qrbazar_export_' . date('Y-m-d') . '.html"');
        echo implode('', $parts);
        exit;
    }

    /* —— CSV (single or ZIP) —— */
    $built = [];
    foreach ($datasets as $key) {
        $pack = qb_export_dataset_rows($key, $df !== '' ? $df : null, $dt !== '' ? $dt : null, $includePii, $vatPct, $vatMode);
        $fn = 'qrbazar_' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '_' . date('Y-m-d') . '.csv';
        $meta = 'export_generated_at,' . date('c') . "\n"
            . 'vat_rate_percent,' . $vatPct . "\n"
            . 'vat_mode,' . $vatMode . "\n"
            . 'date_from,' . ($df ?: '') . "\n"
            . 'date_to,' . ($dt ?: '') . "\n"
            . 'currency,ETB' . "\n"
            . 'prepared_by,' . str_replace(["\n", "\r"], ' ', $genName) . "\n\n";
        $built[$fn] = $meta . qb_export_csv_string($pack['headers'], $pack['rows']);
    }

    if ($format === 'csv_single') {
        if (count($built) !== 1) {
            header('Location: exports.php?err=single');
            exit;
        }
        $csv = reset($built);
        $name = key($built);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo $csv;
        exit;
    }

    /* csv_zip */
    if (!class_exists('ZipArchive')) {
        /* fallback: concatenate with separators */
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="qrbazar_export_' . date('Y-m-d') . '.txt"');
        foreach ($built as $name => $content) {
            echo "===== FILE: $name =====\n\n";
            echo $content;
            echo "\n\n";
        }
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'qbzexp');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        header('Location: exports.php?err=zip');
        exit;
    }
    foreach ($built as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->addFromString(
        'README_export_metadata.txt',
        "QR Bazar export bundle\n"
        . 'Generated: ' . date('Y-m-d H:i:s') . "\n"
        . "VAT rate %: $vatPct\nMode: $vatMode\n"
        . 'Date from: ' . ($df ?: '(none)') . ' to: ' . ($dt ?: '(none)') . "\n"
        . "Currency: ETB\n"
        . 'Prepared by: ' . $genName . "\n"
        . "Each CSV starts with metadata lines, then column headers.\n"
        . "Transaction exports include tax columns (taxable_base_etb, vat_amount_etb) for compliance review.\n"
    );
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="qrbazar_export_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

$err = $_GET['err'] ?? '';
$errMsg = [
    'csrf'   => 'Session expired — try again.',
    'none'   => 'Select at least one dataset.',
    'single' => 'Single CSV mode requires exactly one dataset.',
    'zip'    => 'Could not create ZIP — try again or choose HTML export.',
][$err] ?? '';

require_once __DIR__ . '/../includes/layout.php';

qb_page_start('admin', 'Data exports', 'exports.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Data exports</h1>
    <p class="page-subtitle">Choose datasets, tax (VAT) assumptions, and format before downloading — for analysis, users, or authority disclosure.</p>
  </div>
</div>

<?php if ($errMsg): ?>
<div class="alert alert-danger mb-3"><?= htmlspecialchars($errMsg) ?></div>
<?php endif; ?>

<form method="post" class="card card--data-moderation no-hover-anim mb-3" style="padding:1.25rem">
  <input type="hidden" name="export_run" value="1"/>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>

  <h3 class="font-bold mb-2" style="font-size:1rem">1. Select datasets</h3>
  <p class="text-sm text-muted mb-2">Include only what you need. Sensitive credentials are never exported.</p>
  <div class="qb-export-grid">
    <?php foreach ($catalog as $key => $info): ?>
    <label class="qb-export-opt">
      <input type="checkbox" name="datasets[]" value="<?= htmlspecialchars($key) ?>"/>
      <span>
        <span class="qb-export-opt__title"><?= htmlspecialchars($info['label']) ?></span>
        <span class="qb-export-opt__desc"><?= htmlspecialchars($info['desc']) ?></span>
      </span>
    </label>
    <?php endforeach; ?>
  </div>

  <h3 class="font-bold mt-4 mb-2" style="font-size:1rem">2. Filters &amp; tax (VAT) measurements</h3>
  <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;margin-bottom:1rem">
    <div class="form-group mb-0">
      <label class="form-label" for="exp-df">Date from</label>
      <input type="date" id="exp-df" name="date_from" class="form-control" value=""/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label" for="exp-dt">Date to</label>
      <input type="date" id="exp-dt" name="date_to" class="form-control" value=""/>
    </div>
    <div class="form-group mb-0">
      <label class="form-label" for="exp-vat">VAT rate (%)</label>
      <input type="number" id="exp-vat" name="vat_rate" class="form-control" style="max-width:7rem" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) QB_EXPORT_DEFAULT_VAT_PERCENT) ?>"/>
    </div>
    <div class="form-group mb-0">
      <span class="form-label d-block">Amount treatment</span>
      <div class="qb-opt-segment" role="radiogroup" aria-label="VAT amount treatment">
        <label title="Totals include VAT"><input type="radio" name="vat_mode" value="inclusive" checked/> Inclusive</label>
        <label title="Totals exclude VAT"><input type="radio" name="vat_mode" value="exclusive"/> Exclusive</label>
      </div>
    </div>
  </div>
  <p class="text-xs text-muted mb-3">
    <strong>ETB</strong> amounts in <strong>transactions</strong> and <strong>transaction line items</strong> get extra columns:
    <code>taxable_base_etb</code>, <code>vat_amount_etb</code> (and related labels). Adjust the rate to match your reporting period; confirm with your tax advisor.
  </p>

  <h3 class="font-bold mb-2" style="font-size:1rem">3. Privacy</h3>
  <label class="qb-form-check-row mb-3">
    <input type="checkbox" name="include_pii" value="1" checked/>
    <span>Include personally identifiable fields (phone, email) where the dataset supports it</span>
  </label>

  <h3 class="font-bold mb-2" style="font-size:1rem">4. Format</h3>
  <div class="form-group mb-3 qb-opt-radio-col" role="radiogroup" aria-label="Export format">
    <label><input type="radio" name="format" value="csv_zip" checked/> <strong>ZIP</strong> — one CSV per dataset (recommended for multiple tables)</label>
    <label><input type="radio" name="format" value="csv_single"/> <strong>Single CSV</strong> — only when exactly one dataset is checked</label>
    <label><input type="radio" name="format" value="html"/> <strong>HTML report</strong> — open in browser, then <em>Print → Save as PDF</em> for a PDF file</label>
  </div>

  <button type="submit" class="btn btn-primary"><?= qb_icon('download', 'qb-icon', 18) ?> Build &amp; download</button>
</form>

<div class="card no-hover-anim" style="padding:1rem">
  <p class="text-sm text-muted m-0">
    Older direct links are replaced by this form. Exports are generated on demand and include metadata headers in each CSV describing VAT settings and date range.
  </p>
</div>

<?php qb_page_end(); ?>
