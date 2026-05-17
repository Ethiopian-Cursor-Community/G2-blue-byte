<?php
/**
 * Friendly 404 — used by Apache ErrorDocument or direct visit.
 */
declare(strict_types=1);

if (!headers_sent()) {
    http_response_code(404);
}

require_once __DIR__ . '/config.php';

$home = rtrim((string) (defined('APP_URL') ? APP_URL : '/'), '/') . '/';
$homeEsc = htmlspecialchars($home, ENT_QUOTES, 'UTF-8');
$name = defined('APP_NAME') ? (string) APP_NAME : 'QR Bazar';
$nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$req = (string) ($_SERVER['REQUEST_URI'] ?? '');
$reqShort = $req !== '' ? htmlspecialchars($req, ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="robots" content="noindex"/>
  <title>Page not found (404) — <?= $nameEsc ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="<?= htmlspecialchars(rtrim((string) APP_URL, '/') . '/assets/css/style.css', ENT_QUOTES, 'UTF-8') ?>"/>
</head>
<body class="qb-error-page">
  <main class="qb-error-page__main">
    <div class="qb-error-page__card card">
      <p class="qb-error-page__code" aria-hidden="true">404</p>
      <h1 class="qb-error-page__title">This page isn’t here</h1>
      <p class="qb-error-page__lead text-secondary">
        The link may be broken or the page was removed. Double-check the URL or go back to the home page.
      </p>
      <?php if ($reqShort !== ''): ?>
      <p class="qb-error-page__path text-xs text-muted mb-0"><code><?= $reqShort ?></code></p>
      <?php endif; ?>
      <div class="qb-error-page__actions">
        <a class="btn btn-primary" href="<?= $homeEsc ?>">Back to <?= $nameEsc ?></a>
        <button type="button" class="btn btn-ghost" onclick="history.length > 1 ? history.back() : (location.href='<?= $homeEsc ?>')">Go back</button>
      </div>
    </div>
  </main>
</body>
</html>
