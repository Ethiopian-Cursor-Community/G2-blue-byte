<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_account_migrate.php';
startSession();

if (!qb_user_account_schema_ready()) {
    qb_apply_user_account_schema();
}
qb_apply_seller_compliance_schema();
$requireSellerCompliance = qb_setting_get_bool('require_seller_compliance', true);

if (isLoggedIn()) {
    qb_redirect_after_login();
}

$asBuyer = (isset($_GET['portal']) && $_GET['portal'] === 'buyer')
    || (($_POST['account_type'] ?? '') === 'buyer');

$error   = '';
$success = '';
$catCatalog = qb_seller_category_catalog();
$catCatalog = qb_seller_category_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $asBuyer = (($_POST['account_type'] ?? '') === 'buyer');
    $name    = sanitize($_POST['name'] ?? '');
    $login   = sanitize($_POST['login'] ?? '');
    $phone   = sanitize($_POST['phone'] ?? '');
    $market  = sanitize($_POST['market_name'] ?? '');
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $agreePolicy = !empty($_POST['agree_policy']);
    $tinNo = sanitize($_POST['tin_no'] ?? '');
    $licenseNo = sanitize($_POST['license_no'] ?? '');
    $nationalIdFanNo = sanitize($_POST['national_id_fan_no'] ?? '');
    $legalConfirm = !empty($_POST['legal_confirm']);
    $sellerStallImage = $_FILES['stall_image'] ?? null;
    $sellerCats = [];
    if (!$asBuyer) {
        $rawCats = isset($_POST['seller_categories']) && is_array($_POST['seller_categories']) ? $_POST['seller_categories'] : [];
        foreach ($rawCats as $slug) {
            $slug = sanitize((string) $slug);
            if (isset($catCatalog[$slug])) {
                $sellerCats[] = $slug;
            }
        }
        $sellerCats = array_values(array_unique($sellerCats));
        if (count($sellerCats) > 3) {
            $sellerCats = array_slice($sellerCats, 0, 3);
        }
    }

    if (!$name || !$login || !$phone || !$pass) {
        $error = 'All fields are required.';
    } elseif (!$agreePolicy) {
        $error = 'You must agree to the Terms, Privacy Policy, and system rules.';
    } elseif (!$asBuyer && $market === '') {
        $error = 'Shop or stall name is required for seller accounts.';
    } elseif (!$asBuyer && $requireSellerCompliance && (!$tinNo || !$licenseNo || !$nationalIdFanNo)) {
        $error = 'TIN, license, and National ID/FAN number are required for seller registration.';
    } elseif (!$asBuyer && count($sellerCats) < 1) {
        $error = 'Select at least 1 stall category (up to 3).';
    } elseif (!$asBuyer && $requireSellerCompliance && !$legalConfirm) {
        $error = 'You must confirm legal compliance for seller registration.';
    } elseif (!$asBuyer && (!is_array($sellerStallImage) || (int) ($sellerStallImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
        $error = 'Stall image is required for seller registration.';
    } elseif (strlen($pass) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $existing = db()->fetchOne('SELECT id FROM app_users WHERE login_uid = ? OR phone = ?', [$login, $phone]);
        if ($existing) {
            $error = 'Username or phone number already registered.';
        } else {
            $hash = hashPassword($pass);
            $role = $asBuyer ? 'buyer' : 'seller';
            if (qb_user_account_schema_ready()) {
                $publicId = qb_generate_public_id();
                db()->execute(
                    'INSERT INTO app_users (public_uuid, login_uid, password_hash, display_name, role, phone, seller_tin_no, seller_license_no, seller_national_id_fan_no, seller_legal_confirmed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$publicId, $login, $hash, $name, $role, $phone, ($asBuyer ? null : $tinNo), ($asBuyer ? null : $licenseNo), ($asBuyer ? null : $nationalIdFanNo), ($asBuyer ? 0 : ($legalConfirm ? 1 : 0))]
                );
            } else {
                db()->execute(
                    'INSERT INTO app_users (login_uid, password_hash, display_name, role, phone, seller_tin_no, seller_license_no, seller_national_id_fan_no, seller_legal_confirmed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$login, $hash, $name, $role, $phone, ($asBuyer ? null : $tinNo), ($asBuyer ? null : $licenseNo), ($asBuyer ? null : $nationalIdFanNo), ($asBuyer ? 0 : ($legalConfirm ? 1 : 0))]
                );
            }
            $newId = (int) db()->lastInsertId();

            if (!$asBuyer) {
                $uid = generateUID();
                $marketName = $market !== '' ? $market : ($name . "'s Shop");
                $categoriesJson = qb_encode_categories_json($sellerCats);
                $legacyCategory = $sellerCats !== []
                    ? mb_substr(qb_seller_categories_labels($sellerCats), 0, 50)
                    : 'General';
                db()->execute(
                    "INSERT INTO sellers (app_user_id, uid, full_name, market_name, category, categories_json, phone, email, password_hash, qr_secret, tin_no, license_no, national_id_fan_no, legal_confirmed, approval_status, approval_submitted_at, allow_categories_edit) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)",
                    [$newId, $uid, $name, $marketName, $legacyCategory, $categoriesJson, $phone, '', $hash, 'qr_sec_' . time(), $tinNo, $licenseNo, $nationalIdFanNo, ($legalConfirm ? 1 : 0), 'pending', date('Y-m-d H:i:s')]
                );
                $newSeller = db()->fetchOne('SELECT * FROM sellers WHERE app_user_id = ?', [$newId]);
                $newSellerId = (int) ($newSeller['id'] ?? 0);
                if ($newSellerId > 0 && is_array($sellerStallImage)) {
                    $upload = qb_save_seller_profile_png($sellerStallImage, $newSellerId);
                    if (($upload['error'] ?? null) !== null || empty($upload['path'])) {
                        db()->execute('DELETE FROM sellers WHERE id = ?', [$newSellerId]);
                        db()->execute('DELETE FROM app_users WHERE id = ?', [$newId]);
                        $error = (string) ($upload['error'] ?? 'Could not save stall image.');
                    } else {
                        db()->execute('UPDATE sellers SET stall_image = ?, profile_image = ? WHERE id = ?', [(string) $upload['path'], (string) $upload['path'], $newSellerId]);
                    }
                } else {
                    db()->execute('DELETE FROM app_users WHERE id = ?', [$newId]);
                    $error = 'Could not create seller profile. Please try again.';
                }
            }

            if ($error === '') {
                $success = $asBuyer
                    ? 'Buyer account created! You can now sign in.'
                    : 'Seller account submitted. Wait for admin approval before seller sign-in is enabled.';
            }
        }
    }
}

$loginPortal = $asBuyer ? 'buyer' : 'seller';
$title = $asBuyer ? 'Create Buyer Account' : 'Create Seller Account';
$icon = $asBuyer ? 'user' : 'store';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>
<body>
<div class="qb-auth-sky qb-auth-sky--signup qb-auth-sky--signup-orange">
  <div class="qb-auth-clouds" aria-hidden="true"></div>
  <div class="qb-auth-card qb-auth-card--signup">
    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/" class="qb-auth-top-icon" title="Home"><?= qb_icon('arrow-right', 'qb-icon', 18) ?></a>
    <h1 class="qb-auth-title"><?= $asBuyer ? 'Join QR Bazar as Buyer' : 'Join QR Bazar as Seller' ?></h1>
    <p class="qb-auth-subtitle"><?= $asBuyer ? 'Create your buyer account to discover event products, buy tickets, and pay securely with QR + Chapa.' : 'Create your seller account to publish products, join events, and start selling after admin approval.' ?></p>

    <div class="auth-account-switch">
      <a href="register.php?portal=buyer" class="btn btn-sm <?= $asBuyer ? 'btn-primary' : 'btn-ghost' ?>">Buyer</a>
      <a href="register.php" class="btn btn-sm <?= !$asBuyer ? 'btn-primary' : 'btn-ghost' ?>">Seller</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger auth-alert">
        <?= qb_icon('alert', 'qb-icon', 16) ?> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success auth-alert">
        <?= qb_icon('check', 'qb-icon', 16) ?> <?= htmlspecialchars($success) ?>
        <a href="login.php?portal=<?= htmlspecialchars($loginPortal, ENT_QUOTES, 'UTF-8') ?>" class="auth-alert-link">Sign in →</a>
      </div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" class="qb-auth-form">
      <input type="hidden" name="account_type" value="<?= $asBuyer ? 'buyer' : 'seller' ?>"/>
      <div class="form-gap auth-form-grid auth-form-grid--register qb-auth-form-gap">
        <div class="form-group">
          <label class="form-label" for="name">Full Name</label>
          <input id="name" name="name" class="form-control qb-auth-input" placeholder="e.g. Abebe Kebede"
                 value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required/>
        </div>
        <?php if (!$asBuyer): ?>
        <div class="auth-register-segment">
          <div class="auth-register-segment__title">Seller verification details</div>
          <div class="auth-register-segment__hint">These details are reviewed by admin before seller portal approval.</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="tin_no">TIN Number</label>
          <input id="tin_no" name="tin_no" class="form-control qb-auth-input" placeholder="Tax Identification Number"
                 value="<?= htmlspecialchars($_POST['tin_no'] ?? '') ?>" <?= $requireSellerCompliance ? 'required' : '' ?>/>
        </div>
        <div class="form-group">
          <label class="form-label" for="license_no">Business License Number</label>
          <input id="license_no" name="license_no" class="form-control qb-auth-input" placeholder="License / permit number"
                 value="<?= htmlspecialchars($_POST['license_no'] ?? '') ?>" <?= $requireSellerCompliance ? 'required' : '' ?>/>
        </div>
        <div class="form-group">
          <label class="form-label" for="national_id_fan_no">National ID / FAN Number</label>
          <input id="national_id_fan_no" name="national_id_fan_no" class="form-control qb-auth-input" placeholder="National ID or FAN number"
                 value="<?= htmlspecialchars($_POST['national_id_fan_no'] ?? '') ?>" <?= $requireSellerCompliance ? 'required' : '' ?>/>
        </div>
        <div class="form-group">
          <label class="form-label" for="market_name">Shop / stall name</label>
          <input id="market_name" name="market_name" class="form-control qb-auth-input" placeholder="e.g. Abebe Fresh Corner"
                 value="<?= htmlspecialchars($_POST['market_name'] ?? '') ?>" required/>
        </div>
        <div class="form-group">
          <label class="form-label" for="stall_image">Stall Image</label>
          <input id="stall_image" name="stall_image" class="form-control qb-auth-input" type="file" accept="image/*" required/>
          <div class="text-xs text-muted mt-1">Upload a clear stall/shop image (max 2MB).</div>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Stall Categories (choose 1-3)</label>
          <?php $selectedCats = (array) ($_POST['seller_categories'] ?? []); ?>
          <details class="qb-cat-dropdown">
            <summary class="qb-cat-dropdown__summary">
              <span>Choose categories</span>
              <strong id="qbCatCount"><?= count($selectedCats) ?></strong>
            </summary>
            <div class="qb-cat-pick-grid qb-cat-pick-grid--dropdown">
              <?php foreach ($catCatalog as $slug => $label): ?>
              <label class="qb-cat-pick qb-cat-pick--auth">
                <input type="checkbox" name="seller_categories[]" value="<?= htmlspecialchars($slug) ?>" <?= in_array($slug, $selectedCats, true) ? 'checked' : '' ?>/>
                <span><?= htmlspecialchars($label) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </details>
          <div class="text-xs text-muted mt-1">After sign-in, seller can edit categories only one time. Admin can unlock/edit later.</div>
        </div>
        <div class="form-group">
          <label class="text-sm text-muted auth-policy-check">
            <input type="checkbox" name="legal_confirm" value="1" <?= $requireSellerCompliance ? 'required' : '' ?> <?= !empty($_POST['legal_confirm']) ? 'checked' : '' ?>>
            I confirm this seller registration is legal and compliant with applicable trade/tax rules.
          </label>
        </div>
        <?php endif; ?>
        <div class="auth-register-segment">
          <div class="auth-register-segment__title">Account credentials</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="login">Username</label>
          <input id="login" name="login" class="form-control qb-auth-input" placeholder="e.g. abebe2026"
                 value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required autocomplete="username"/>
        </div>
        <div class="form-group">
          <label class="form-label" for="phone">Phone Number</label>
          <input id="phone" name="phone" class="form-control qb-auth-input" placeholder="e.g. 0911234567"
                 value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required type="tel"/>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="auth-pass-wrap">
            <input id="password" name="password" type="password" class="form-control qb-auth-input"
                   placeholder="Minimum 4 characters" required autocomplete="new-password"/>
            <button type="button" class="auth-pass-toggle" data-pass-toggle="password" aria-label="Show password">Show</button>
          </div>
          <div id="passwordHint" class="auth-pass-hint text-xs text-muted">Use at least 4 characters. For better security, mix letters and numbers.</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="confirm">Confirm Password</label>
          <div class="auth-pass-wrap">
            <input id="confirm" name="confirm" type="password" class="form-control qb-auth-input"
                   placeholder="Repeat your password" required autocomplete="new-password"/>
            <button type="button" class="auth-pass-toggle" data-pass-toggle="confirm" aria-label="Show confirm password">Show</button>
          </div>
        </div>
        <div class="form-group">
          <label class="text-sm text-muted auth-policy-check">
            <input type="checkbox" name="agree_policy" value="1" required <?= !empty($_POST['agree_policy']) ? 'checked' : '' ?>>
            I agree to the Terms, Privacy Policy, and system rules of QR Bazar.
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-full btn-lg auth-register-submit qb-auth-submit">
          <?= qb_icon($icon, 'qb-icon', 18) ?> <?= $asBuyer ? 'Create buyer account' : 'Create seller account' ?>
        </button>
      </div>
    </form>
    <?php endif; ?>

    <p class="auth-muted-links auth-muted-links--clean auth-links-main qb-auth-bottom-link">
      Already have an account?
      <a href="login.php?portal=<?= htmlspecialchars($loginPortal, ENT_QUOTES, 'UTF-8') ?>">Sign in</a>
    </p>

    <p class="auth-muted-links auth-muted-links--small auth-muted-links--clean auth-links-sub">
      <?php if ($asBuyer): ?>
        Want to sell? <a href="register.php">Register as a seller</a>
      <?php else: ?>
        Shopping only? <a href="register.php?portal=buyer">Create a buyer account</a>
        · Organizer accounts are created by admin.
      <?php endif; ?>
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

(function () {
  var p = document.getElementById('password');
  var h = document.getElementById('passwordHint');
  if (!p || !h) return;
  function updateHint() {
    var v = p.value || '';
    var score = 0;
    if (v.length >= 4) score++;
    if (/[A-Za-z]/.test(v) && /\d/.test(v)) score++;
    if (/[^\w]/.test(v) || v.length >= 8) score++;
    if (v.length === 0) {
      h.textContent = 'Use at least 4 characters. For better security, mix letters and numbers.';
      h.className = 'auth-pass-hint text-xs text-muted';
    } else if (score <= 1) {
      h.textContent = 'Weak password — add numbers or make it longer.';
      h.className = 'auth-pass-hint text-xs text-danger';
    } else if (score === 2) {
      h.textContent = 'Good password.';
      h.className = 'auth-pass-hint text-xs text-secondary';
    } else {
      h.textContent = 'Strong password.';
      h.className = 'auth-pass-hint auth-pass-hint--strong text-xs';
    }
  }
  p.addEventListener('input', updateHint);
  updateHint();
})();

(function () {
  var wrap = document.querySelector('.qb-cat-pick-grid--dropdown');
  var count = document.getElementById('qbCatCount');
  if (!wrap || !count) return;
  function syncCount() {
    var checked = wrap.querySelectorAll('input[type="checkbox"]:checked').length;
    count.textContent = String(checked);
  }
  wrap.addEventListener('change', function (e) {
    var target = e.target;
    if (!target || target.type !== 'checkbox') return;
    var checked = wrap.querySelectorAll('input[type="checkbox"]:checked');
    if (checked.length > 3) {
      target.checked = false;
      return;
    }
    syncCount();
  });
  syncCount();
})();
</script>
</body>
</html>
