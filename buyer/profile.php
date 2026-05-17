<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireBuyer();
qb_apply_seller_compliance_schema();
$requireSellerCompliance = qb_setting_get_bool('require_seller_compliance', true);

$user = currentUser();
$uid = (int)$user['id'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'remove_profile_photo') {
        if (!empty($user['avatar'])) {
            qb_delete_upload_file($user['avatar']);
        }
        db()->execute('UPDATE app_users SET avatar=NULL WHERE id=?', [$uid]);
        $success = 'Profile photo removed.';
        $user = currentUser();
    } elseif ($action === 'request_role' && qb_role_request_columns_ready()) {
        $want = sanitize($_POST['want'] ?? '');
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'chapa');
        $agreePolicy = !empty($_POST['agree_policy']);
        $rqTinNo = sanitize($_POST['tin_no'] ?? '');
        $rqLicenseNo = sanitize($_POST['license_no'] ?? '');
        $rqNationalIdFanNo = sanitize($_POST['national_id_fan_no'] ?? '');
        $rqLegalConfirm = !empty($_POST['legal_confirm']);
        $rqStallImage = $_FILES['stall_image'] ?? null;
        if (!in_array($want, ['seller', 'organizer'], true)) {
            $error = 'Invalid request.';
        } elseif (!$agreePolicy) {
            $error = 'You must agree to the system policy before requesting a role change.';
        } elseif ($want === 'seller' && $requireSellerCompliance && (!$rqTinNo || !$rqLicenseNo || !$rqNationalIdFanNo)) {
            $error = 'TIN, license, and National ID/FAN number are required for seller role request.';
        } elseif ($want === 'seller' && (!is_array($rqStallImage) || (int) ($rqStallImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
            $error = 'Stall image is required for seller role request.';
        } elseif ($want === 'seller' && $requireSellerCompliance && !$rqLegalConfirm) {
            $error = 'You must confirm legal compliance before requesting seller role.';
        } elseif (($user['role'] ?? '') !== 'buyer') {
            $error = 'Only buyer accounts can use this form.';
        } elseif (($user['role_request_status'] ?? 'none') === 'pending') {
            $error = 'You already have a pending request.';
        } else {
            $roleReqStallImagePath = null;
            if ($want === 'seller') {
                $up = qb_save_user_avatar_png((array) $rqStallImage, $uid);
                if (($up['error'] ?? null) !== null || empty($up['path'])) {
                    $error = (string) ($up['error'] ?? 'Could not save stall image.');
                } else {
                    $prevReqImage = (string) ($user['role_request_stall_image'] ?? '');
                    if ($prevReqImage !== '' && $prevReqImage !== (string) $up['path']) {
                        qb_delete_upload_file($prevReqImage);
                    }
                    $roleReqStallImagePath = (string) $up['path'];
                }
            }
            if ($error !== '') {
                // keep existing error
            } elseif ($paymentMethod === 'chapa') {
                if (!qb_chapa_ready()) {
                    $error = 'Chapa is not configured yet.';
                } else {
                    $roleReqFeeEtb = qb_setting_get_float('role_request_fee_etb', (float) CHAPA_ROLE_REQUEST_FEE_ETB);
                    $newIntent = qb_payment_intent_create($uid, 'role_request', 'role:' . $want, $roleReqFeeEtb, ['want' => $want]);
                    if ($want === 'seller') {
                        db()->execute(
                            'UPDATE app_users SET role_request_tin_no = ?, role_request_license_no = ?, role_request_national_id_fan_no = ?, role_request_legal_confirmed = ?, role_request_stall_image = ? WHERE id = ?',
                            [$rqTinNo, $rqLicenseNo, $rqNationalIdFanNo, ($rqLegalConfirm ? 1 : 0), $roleReqStallImagePath, $uid]
                        );
                    }
                    $intentRow = qb_payment_intent_get((string) $newIntent['intent_id']);
                    if (!$intentRow) {
                        $error = 'Could not create payment intent.';
                    } else {
                        $start = qb_chapa_checkout_start(
                            $intentRow,
                            (string) ($user['email'] ?? ''),
                            (string) ($user['display_name'] ?? 'Buyer'),
                            (string) ($user['phone'] ?? '')
                        );
                        if (!$start['ok']) {
                            $error = (string) ($start['error'] ?? 'Failed to start Chapa checkout.');
                        } else {
                            header('Location: ' . (string) $start['checkout_url'], true, 302);
                            exit;
                        }
                    }
                }
            } else {
                db()->execute(
                    'UPDATE app_users SET role_requested = ?, role_request_status = ?, role_request_tin_no = ?, role_request_license_no = ?, role_request_national_id_fan_no = ?, role_request_legal_confirmed = ?, role_request_stall_image = ? WHERE id = ?',
                    [$want, 'pending', ($want === 'seller' ? $rqTinNo : null), ($want === 'seller' ? $rqLicenseNo : null), ($want === 'seller' ? $rqNationalIdFanNo : null), ($want === 'seller' && $rqLegalConfirm ? 1 : 0), ($want === 'seller' ? $roleReqStallImagePath : null), $uid]
                );
                $success = 'Request submitted. An admin will review it.';
                $user = currentUser();
            }
        }
    } elseif ($action === 'update_profile') {
        $name = sanitize($_POST['name']);
        $phone = sanitize($_POST['phone']);
        $email = sanitize($_POST['email']);

        if (!$name) {
            $error = "Name is required.";
        } else {
            $newPath = null;
            if (!empty($_FILES['profile_photo']['tmp_name'])) {
                $up = qb_save_user_avatar_png($_FILES['profile_photo'], $uid);
                if ($up['error']) {
                    $error = $up['error'];
                } else {
                    if (!empty($user['avatar'])) {
                        qb_delete_upload_file($user['avatar']);
                    }
                    $newPath = $up['path'];
                }
            }

            if ($error === '') {
                if ($newPath !== null) {
                    db()->execute(
                        'UPDATE app_users SET display_name=?, phone=?, email=?, avatar=? WHERE id=?',
                        [$name, $phone, $email, $newPath, $uid]
                    );
                } else {
                    db()->execute('UPDATE app_users SET display_name=?, phone=?, email=? WHERE id=?', [$name, $phone, $email, $uid]);
                }
                $_SESSION['app_name'] = $name;
                $success = 'Profile updated successfully!';
                $user = currentUser();
            }
        }
    }
}

qb_page_start('buyer', 'My Profile', 'profile.php', false);
?>

<div class="buyer-dashboard">
<div class="buyer-main">
    <div class="page-header qb-dash-header">
        <div>
            <h1 class="page-title qb-dash-title">Profile &amp; settings</h1>
            <p class="page-subtitle qb-dash-subtitle">Manage your account details and preferences.</p>
        </div>
    </div>

    <?php if($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- User Information Card -->
    <div class="card mb-4" style="background:var(--accent);color:#fff;border:none">
        <div style="display:flex;align-items:center;gap:1rem">
            <div class="qb-profile-preview qb-profile-preview--hero" style="background:rgba(255,255,255,0.95);color:var(--accent)">
                <?php $buyAv = qb_avatar_url($user); if ($buyAv): ?>
                    <img src="<?= htmlspecialchars($buyAv) ?>" alt="" class="qb-profile-preview__img"/>
                <?php else: ?>
                    <?= strtoupper(mb_substr($user['display_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="font-black text-xl m-0"><?= htmlspecialchars($user['display_name']) ?></h3>
                <p class="text-sm m-0" style="color:rgba(255,255,255,0.8)"><?= htmlspecialchars($user['phone'] ?: 'No phone added') ?></p>
                <div class="badge mt-1" style="background:rgba(0,0,0,0.2);color:#fff;border:none">Active Buyer</div>
            </div>
        </div>
    </div>

    <div class="grid grid-2 gap-3">
        <!-- Edit Profile Form -->
        <div class="card">
            <h3 class="font-bold mb-3">Edit Profile</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                <div class="form-group mb-3">
                    <label class="form-label">Profile photo</label>
                    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                        <div class="qb-profile-preview">
                            <?php if (!empty($user['avatar'])): ?>
                                <img src="<?= htmlspecialchars(qb_public_upload_url($user['avatar'])) ?>" alt="" class="qb-profile-preview__img"/>
                            <?php else: ?>
                                <span class="qb-profile-preview__ph"><?= strtoupper(mb_substr($user['display_name'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:200px">
                            <input type="file" name="profile_photo" class="form-control" accept="image/png,image/jpeg,image/jpg,image/webp,image/gif,image/bmp,image/avif"/>
                            <p class="text-xs text-muted mt-1 mb-0">JPG, JPEG, PNG, WEBP, GIF, BMP, or AVIF (max 2MB).</p>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-2">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-control" value="<?= qb_esc_html($user['display_name']) ?>" required>
                </div>
                <div class="form-group mb-2">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-control" value="<?= qb_esc_html($user['phone']) ?>">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?= qb_esc_html($user['email']) ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Save Changes</button>
            </form>
            <?php if (!empty($user['avatar'])): ?>
            <form method="post" class="mt-2">
                <input type="hidden" name="action" value="remove_profile_photo"/>
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--text-secondary)">Remove profile photo</button>
            </form>
            <?php endif; ?>

            <?php if (qb_role_request_columns_ready() && ($user['role'] ?? '') === 'buyer'): ?>
            <hr class="divider" style="margin:1.5rem 0"/>
            <h4 class="font-bold mb-2">Become a seller or organizer</h4>
            <p class="text-xs text-secondary mb-2">Request an upgrade. An administrator must approve it before your account changes.</p>
            <?php
            $rqs = $user['role_request_status'] ?? 'none';
            $rqd = $user['role_requested'] ?? '';
            ?>
            <?php if ($rqs === 'pending'): ?>
              <p class="text-sm mb-0">Status: <span class="badge badge-amber">Pending</span> — you asked to become a <strong><?= htmlspecialchars($rqd) ?></strong>.</p>
            <?php elseif ($rqs === 'rejected'): ?>
              <p class="text-sm text-muted mb-2">Your last request was not approved. You can submit again.</p>
              <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="request_role"/><input type="hidden" name="want" value="seller"/><input type="hidden" name="payment_method" value="chapa"/>
                  <input type="text" name="tin_no" class="form-control mb-1" placeholder="TIN Number" <?= $requireSellerCompliance ? 'required' : '' ?>>
                  <input type="text" name="license_no" class="form-control mb-1" placeholder="Business License Number" <?= $requireSellerCompliance ? 'required' : '' ?>>
                  <input type="text" name="national_id_fan_no" class="form-control mb-1" placeholder="National ID / FAN Number" <?= $requireSellerCompliance ? 'required' : '' ?>>
                  <input type="file" name="stall_image" class="form-control mb-1" accept="image/*" required>
                  <label class="text-xs text-muted" style="display:block;margin-bottom:6px"><input type="checkbox" name="legal_confirm" value="1" <?= $requireSellerCompliance ? 'required' : '' ?>> I confirm legal compliance for seller registration.</label>
                  <label class="text-xs text-muted" style="display:block;margin-bottom:6px"><input type="checkbox" name="agree_policy" value="1" required> I agree to Terms & Privacy Policy.</label>
                  <button type="submit" class="btn btn-secondary btn-sm">Request seller</button></form>
                <form method="post"><input type="hidden" name="action" value="request_role"/><input type="hidden" name="want" value="organizer"/><input type="hidden" name="payment_method" value="chapa"/>
                  <label class="text-xs text-muted" style="display:block;margin-bottom:6px"><input type="checkbox" name="agree_policy" value="1" required> I agree to Terms & Privacy Policy.</label>
                  <button type="submit" class="btn btn-secondary btn-sm">Request organizer</button></form>
              </div>
            <?php elseif ($rqs === 'approved'): ?>
              <p class="text-sm text-emerald mb-0">Your upgrade was approved. Sign out and sign in again to use the new portal.</p>
            <?php else: ?>
              <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="request_role"/><input type="hidden" name="want" value="seller"/><input type="hidden" name="payment_method" value="chapa"/>
                  <input type="text" name="tin_no" class="form-control mb-1" placeholder="TIN Number" <?= $requireSellerCompliance ? 'required' : '' ?>>
                  <input type="text" name="license_no" class="form-control mb-1" placeholder="Business License Number" <?= $requireSellerCompliance ? 'required' : '' ?>>
                  <input type="text" name="national_id_fan_no" class="form-control mb-1" placeholder="National ID / FAN Number" <?= $requireSellerCompliance ? 'required' : '' ?>>
                  <input type="file" name="stall_image" class="form-control mb-1" accept="image/*" required>
                  <label class="text-xs text-muted" style="display:block;margin-bottom:6px"><input type="checkbox" name="legal_confirm" value="1" <?= $requireSellerCompliance ? 'required' : '' ?>> I confirm legal compliance for seller registration.</label>
                  <label class="text-xs text-muted" style="display:block;margin-bottom:6px"><input type="checkbox" name="agree_policy" value="1" required> I agree to Terms & Privacy Policy.</label>
                  <button type="submit" class="btn btn-secondary btn-sm">Request seller account</button></form>
                <form method="post"><input type="hidden" name="action" value="request_role"/><input type="hidden" name="want" value="organizer"/><input type="hidden" name="payment_method" value="chapa"/>
                  <label class="text-xs text-muted" style="display:block;margin-bottom:6px"><input type="checkbox" name="agree_policy" value="1" required> I agree to Terms & Privacy Policy.</label>
                  <button type="submit" class="btn btn-secondary btn-sm">Request organizer account</button></form>
              </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- System Stats / Features -->
        <div style="display:flex;flex-direction:column;gap:1rem">
            <div class="card" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem">
                <div style="background:var(--emerald-soft);color:var(--emerald);padding:10px;border-radius:10px">
                    <?= qb_icon('cart', 'qb-icon', 24) ?>
                </div>
                <div style="flex:1">
                    <div class="font-bold text-lg">
                        <?= db()->fetchOne("SELECT COUNT(*) as c FROM transactions WHERE buyer_id = ? AND payment_status='completed'", [$uid])['c'] ?>
                    </div>
                    <div class="text-xs text-muted">Total Purchases Made</div>
                </div>
            </div>
            <div class="card" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem">
                <div style="background:var(--blue-soft);color:var(--blue);padding:10px;border-radius:10px">
                    <?= qb_icon('ticket', 'qb-icon', 24) ?>
                </div>
                <div style="flex:1">
                    <div class="font-bold text-lg">
                        <?= db()->fetchOne("SELECT COUNT(*) as c FROM tickets WHERE buyer_id = ?", [$uid])['c'] ?>
                    </div>
                    <div class="text-xs text-muted">Tickets Acquired</div>
                </div>
            </div>
            
            <a href="../logout.php" class="card card-hover" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;text-decoration:none;color:inherit">
                <div style="background:var(--danger-soft);color:var(--danger);padding:10px;border-radius:10px">
                    <?= qb_icon('logout', 'qb-icon', 24) ?>
                </div>
                <div class="font-bold text-danger text-sm">Sign Out</div>
            </a>
        </div>
    </div>
</div>
</div>

<?php qb_page_end(); ?>
