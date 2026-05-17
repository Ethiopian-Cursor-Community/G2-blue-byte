<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Not available for your device — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
</head>
<body style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;background:var(--bg-base)">
  <div class="card" style="max-width:440px;text-align:center;padding:2rem 1.5rem">
    <div style="font-size:2.5rem;margin-bottom:0.75rem" aria-hidden="true">📱</div>
    <h1 style="font-size:1.35rem;font-weight:900;margin-bottom:0.5rem">Not available for your device</h1>
    <p class="text-secondary" style="font-size:0.95rem;line-height:1.6;margin-bottom:1.25rem">
      This part of <?= htmlspecialchars(APP_NAME) ?> is built for <strong>phones</strong> only. Open the same link on your mobile browser to continue.
    </p>
    <a href="../index.php" class="btn btn-secondary btn-full" style="text-decoration:none;display:inline-block">Back to home</a>
  </div>
</body>
</html>
