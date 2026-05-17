<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    qb_redirect_after_login();
}

$portal = preg_replace('/[^a-z_]/', '', $_GET['portal'] ?? 'buyer');
$validPortals = ['admin', 'organizer', 'seller', 'buyer', 'gatekeeper'];
if (!in_array($portal, $validPortals)) {
    $portal = 'buyer';
}

$error = '';
$notice = '';
$loginCsrf = qb_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token. Please try again.';
    } elseif (!qb_rate_limit_login_allow_all()) {
        $error = 'Too many sign-in attempts. Please wait a few minutes and try again.';
    }

    if ($error === '' && ($login === '' || $pass === '')) {
        $error = 'Please enter your username and password.';
    } elseif ($error === '') {
        $loginRow = db()->fetchOne(
            'SELECT login_uid, is_banned, is_locked FROM app_users WHERE login_uid = ? OR phone = ? LIMIT 1',
            [$login, $login]
        );
        if ($loginRow && !empty($loginRow['is_banned'])) {
            $error = 'This username is banned. Please contact support.';
            qb_rate_limit_login_fail();
        } elseif ($loginRow && !empty($loginRow['is_locked'])) {
            $error = 'This account is locked. Please contact support.';
            qb_rate_limit_login_fail();
        } elseif (tryUnifiedLogin($login, $pass)) {
            qb_rate_limit_login_ok();
            if (!empty($_SESSION['qb_user_disabled'])) {
                $notice = 'Your account is disabled. You can sign in with read-only access, but buying and selling actions are blocked.';
            }
            if (!empty($_GET['redirect'])) {
                $redir = ltrim((string) $_GET['redirect'], '/');
                $role = $_SESSION['app_role'] ?? '';
                if (qb_login_redirect_allowed($redir, $role)) {
                    header('Location: ' . APP_URL . '/' . $redir);
                    exit;
                }
            }
            qb_redirect_after_login();
        } elseif (function_exists('qb_auth_last_error_get') && qb_auth_last_error_get() !== '') {
            $error = qb_auth_last_error_get();
            qb_rate_limit_login_fail();
        } else {
            $error = 'Invalid username or password.';
            qb_rate_limit_login_fail();
        }
    }
}
if (($_GET['error'] ?? '') === 'readonly') {
    $notice = 'Read-only mode: this account is disabled, so activity actions are blocked.';
} elseif (($_GET['error'] ?? '') === 'seller_pending') {
    $error = 'Seller account is pending admin approval. Please wait for approval.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sign in — <?= htmlspecialchars(APP_NAME) ?></title>
  <meta name="description" content="Sign in to continue."/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>
<body>
<div class="qb-auth-sky qb-auth-sky--signin-blue">
  <div class="qb-auth-clouds" aria-hidden="true"></div>
  <div class="qb-auth-card">
    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>" class="qb-auth-top-icon" title="Home"><?= qb_icon('arrow-right', 'qb-icon', 18) ?></a>
    <h1 class="qb-auth-title">Welcome back to QR Bazar</h1>
    <p class="qb-auth-subtitle">Sign in to manage stalls, discover event products, scan QR codes, and complete secure Chapa payments.</p>

    <?php if ($error): ?>
      <div class="alert alert-danger auth-alert qb-auth-alert">
        <?= qb_icon('alert', 'qb-icon', 16) ?>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>
    <?php if ($notice): ?>
      <div class="alert alert-warning auth-alert qb-auth-alert">
        <?= qb_icon('info', 'qb-icon', 16) ?>
        <?= htmlspecialchars($notice) ?>
      </div>
    <?php endif; ?>

    <form method="post" id="login-form" autocomplete="on" class="qb-auth-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($loginCsrf) ?>"/>
      <div class="form-gap qb-auth-form-gap">
        <div class="form-group qb-auth-field">
          <label class="form-label" for="login">Email</label>
          <input id="login" name="login" class="form-control qb-auth-input"
                 placeholder="Email"
                 value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required autocomplete="username" inputmode="text"/>
        </div>
        <div class="form-group qb-auth-field">
          <label class="form-label" for="password">Password</label>
          <div class="auth-pass-wrap">
            <input id="password" name="password" type="password" class="form-control qb-auth-input"
                   placeholder="Password"
                   required autocomplete="current-password"/>
            <button type="button" class="auth-pass-toggle" data-pass-toggle="password" aria-label="Show password">Show</button>
          </div>
        </div>
        <div class="qb-auth-foot-row">
          <span></span>
          <a class="auth-link-large" href="forgot-password.php">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-primary btn-full btn-lg qb-auth-submit">
          Sign in to QR Bazar
        </button>

        <div class="qb-auth-demo-helpers" style="margin-top:2rem; padding-top:1rem; border-top:1px dashed rgba(0,0,0,0.1)">
          <p style="font-size:0.7rem; color:var(--text-muted); margin-bottom:0.75rem; text-align:center; font-weight:600; text-transform:uppercase; letter-spacing:1px">Demo Accounts</p>
          <div style="display:flex; justify-content:center; gap:0.5rem; flex-wrap:wrap">
            <button type="button" class="btn btn-ghost btn-xs" style="font-size:0.75rem; border:1px solid var(--border)" onclick="fillLogin('admin1', 'password')">Admin</button>
            <button type="button" class="btn btn-ghost btn-xs" style="font-size:0.75rem; border:1px solid var(--border)" onclick="fillLogin('seller1', 'password')">Seller</button>
            <button type="button" class="btn btn-ghost btn-xs" style="font-size:0.75rem; border:1px solid var(--border)" onclick="fillLogin('organizer1', 'password')">Organizer</button>
            <button type="button" class="btn btn-ghost btn-xs" style="font-size:0.75rem; border:1px solid var(--border)" onclick="fillLogin('buyer1', 'password')">Buyer</button>
          </div>
        </div>
      </div>
    </form>

    <p class="auth-muted-links auth-muted-links--clean qb-auth-bottom-link">
      New to QR Bazar? <a class="auth-link-cta" href="register.php">Create an account</a>
    </p>
  </div>
</div>

<script>
document.querySelectorAll('[data-pass-toggle]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-pass-toggle');
    var input = id ? document.getElementById(id) : null;
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
  });
});

function fillLogin(u, p) {
  var uInput = document.getElementById('login');
  var pInput = document.getElementById('password');
  if (uInput) uInput.value = u;
  if (pInput) pInput.value = p;
  // Visual feedback
  uInput.style.backgroundColor = 'var(--bg-elevated)';
  pInput.style.backgroundColor = 'var(--bg-elevated)';
  setTimeout(function() {
    uInput.style.backgroundColor = '';
    pInput.style.backgroundColor = '';
  }, 500);
}
</script>
</body>
</html>
