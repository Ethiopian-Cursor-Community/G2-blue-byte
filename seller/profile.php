<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();

qb_ensure_category_schema();

$user = currentUser();
$uid = (int)$user['id'];
$seller = getCurrentSeller();
$sid = (int)$seller['id'];

$success = '';
$error = '';

$cities = qb_ethiopian_cities();
$catCatalog = qb_seller_category_catalog();
$selectedCats = qb_seller_categories_from_row($seller);
$allowCatEdit = !qb_has_column('sellers', 'allow_categories_edit') || (int) ($seller['allow_categories_edit'] ?? 1) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'remove_profile_photo') {
        foreach (array_unique(array_filter([$seller['profile_image'] ?? '', $user['avatar'] ?? ''])) as $p) {
            qb_delete_upload_file($p);
        }
        db()->execute('UPDATE app_users SET avatar=NULL WHERE id=?', [$uid]);
        db()->execute('UPDATE sellers SET profile_image=NULL WHERE id=?', [$sid]);
        $success = 'Profile photo removed.';
        $user = currentUser();
        $seller = getCurrentSeller();
        $selectedCats = qb_seller_categories_from_row($seller);
    } elseif ($action === 'update_profile') {
        $name = sanitize($_POST['name']);
        $phone = sanitize($_POST['phone']);
        $email = sanitize($_POST['email']);
        $market_name = sanitize($_POST['market_name']);
        $location = sanitize($_POST['location'] ?? '');

        $prevCats = qb_seller_categories_from_row($seller);
        $allowCatEditPost = !qb_has_column('sellers', 'allow_categories_edit') || (int) ($seller['allow_categories_edit'] ?? 1) === 1;
        if ($allowCatEditPost) {
            $rawCats = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];
            $picked = [];
            foreach ($rawCats as $x) {
                $s = sanitize((string) $x);
                if (isset($catCatalog[$s])) {
                    $picked[] = $s;
                }
            }
            $picked = array_values(array_unique($picked));
            $picked = array_slice($picked, 0, 3);
        } else {
            $picked = $prevCats;
        }
        $categoriesJson = qb_encode_categories_json($picked);
        $legacyCategory = !empty($picked)
            ? mb_substr(qb_seller_categories_labels($picked), 0, 50)
            : 'General';

        if (!$name || !$market_name) {
            $error = 'Name and Market Name are required.';
        } elseif ($location === '' || !in_array($location, $cities, true)) {
            $error = 'Please choose a valid city from the list.';
        } elseif (count($picked) < 1) {
            $error = 'Select at least one category (up to three).';
        } else {
            $newAvatarPath = null;
            if (!empty($_FILES['profile_photo']['tmp_name'])) {
                $up = qb_save_seller_profile_png($_FILES['profile_photo'], $sid);
                if ($up['error']) {
                    $error = $up['error'];
                } else {
                    foreach (array_unique(array_filter([$seller['profile_image'] ?? '', $user['avatar'] ?? ''])) as $p) {
                        qb_delete_upload_file($p);
                    }
                    $newAvatarPath = $up['path'];
                }
            }

            if ($error === '') {
                if (qb_has_column('sellers', 'categories_json')) {
                    if ($newAvatarPath !== null) {
                        db()->execute(
                            "UPDATE app_users SET display_name=?, phone=?, email=?, avatar=? WHERE id=?",
                            [$name, $phone, $email, $newAvatarPath, $uid]
                        );
                        db()->execute(
                            "UPDATE sellers SET full_name=?, market_name=?, category=?, categories_json=?, location=?, phone=?, email=?, profile_image=? WHERE id=?",
                            [$name, $market_name, $legacyCategory, $categoriesJson, $location, $phone, $email, $newAvatarPath, $sid]
                        );
                    } else {
                        db()->execute("UPDATE app_users SET display_name=?, phone=?, email=? WHERE id=?", [$name, $phone, $email, $uid]);
                        db()->execute(
                            "UPDATE sellers SET full_name=?, market_name=?, category=?, categories_json=?, location=?, phone=?, email=? WHERE id=?",
                            [$name, $market_name, $legacyCategory, $categoriesJson, $location, $phone, $email, $sid]
                        );
                    }
                } else {
                    if ($newAvatarPath !== null) {
                        db()->execute(
                            "UPDATE app_users SET display_name=?, phone=?, email=?, avatar=? WHERE id=?",
                            [$name, $phone, $email, $newAvatarPath, $uid]
                        );
                        db()->execute(
                            "UPDATE sellers SET full_name=?, market_name=?, category=?, location=?, phone=?, email=?, profile_image=? WHERE id=?",
                            [$name, $market_name, $legacyCategory, $location, $phone, $email, $newAvatarPath, $sid]
                        );
                    } else {
                        db()->execute("UPDATE app_users SET display_name=?, phone=?, email=? WHERE id=?", [$name, $phone, $email, $uid]);
                        db()->execute(
                            "UPDATE sellers SET full_name=?, market_name=?, category=?, location=?, phone=?, email=? WHERE id=?",
                            [$name, $market_name, $legacyCategory, $location, $phone, $email, $sid]
                        );
                    }
                }

                $_SESSION['app_name'] = $name;
                $success = 'Profile updated successfully!';

                if (function_exists('qb_has_column') && qb_has_column('sellers', 'stall_tagline')) {
                    $stag = qb_sanitize_plain_text((string) ($_POST['stall_tagline'] ?? ''), 160);
                    db()->execute('UPDATE sellers SET stall_tagline = ? WHERE id = ?', [$stag === '' ? null : $stag, $sid]);
                }

                if ($allowCatEditPost && qb_has_column('sellers', 'allow_categories_edit')) {
                    $changedCats = qb_encode_categories_json($picked) !== qb_encode_categories_json($prevCats);
                    if ($changedCats) {
                        db()->execute('UPDATE sellers SET allow_categories_edit = 0 WHERE id = ?', [$sid]);
                    }
                }

                $user = currentUser();
                $seller = getCurrentSeller();
                $selectedCats = qb_seller_categories_from_row($seller);
                $allowCatEdit = !qb_has_column('sellers', 'allow_categories_edit') || (int) ($seller['allow_categories_edit'] ?? 1) === 1;
            }
        }
    } elseif ($action === 'request_category_change') {
        $reason = sanitize($_POST['reason'] ?? '');
        if ($reason === '') {
            $error = 'Please provide a reason for the change request.';
        } else {
            $existing = db()->fetchOne("SELECT id FROM category_change_requests WHERE seller_id = ? AND status = 'pending'", [$sid]);
            if ($existing) {
                $error = 'You already have a pending category change request.';
            } else {
                db()->execute("INSERT INTO category_change_requests (seller_id, reason) VALUES (?, ?)", [$sid, $reason]);
                $success = 'Your category change request has been sent to the admin.';
            }
        }
    }
}

qb_page_start('seller', 'Edit Profile', 'profile.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Seller Profile</h1>
    <p class="page-subtitle">Manage your personal and market information.</p>
  </div>
</div>

<?php if($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid grid-2 gap-3">
    <div class="card">
        <h3 class="font-bold mb-3">Edit Information</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-group mb-3">
                <label class="form-label">Profile photo</label>
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                    <div class="qb-profile-preview">
                        <?php
                        $pimg = $user['avatar'] ?? $seller['profile_image'] ?? '';
                        if ($pimg): ?>
                            <img src="<?= htmlspecialchars(qb_public_upload_url($pimg)) ?>" alt="" class="qb-profile-preview__img"/>
                        <?php else: ?>
                            <span class="qb-profile-preview__ph" aria-hidden="true"><?= strtoupper(mb_substr($user['display_name'], 0, 1)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <input type="file" name="profile_photo" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif"/>
                        <p class="text-xs text-muted mt-1 mb-0">PNG, max 2MB.</p>
                    </div>
                </div>
            </div>

            <h4 class="text-sm font-bold text-muted text-uppercase mb-2">Personal Info</h4>
            <div class="form-group mb-2">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" value="<?= qb_esc_html($user['display_name']) ?>" required>
            </div>
            <div class="grid grid-2 gap-2 mb-3">
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= qb_esc_html($user['phone']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= qb_esc_html($user['email']) ?>">
                </div>
            </div>

            <h4 class="text-sm font-bold text-muted text-uppercase mb-2 mt-3">Market Info</h4>
            <div class="form-group mb-2">
                <label class="form-label">Market / Stall Name</label>
                <input type="text" name="market_name" class="form-control" value="<?= qb_esc_html($seller['market_name']) ?>" required>
            </div>
            <?php if (function_exists('qb_has_column') && qb_has_column('sellers', 'stall_tagline')): ?>
            <div class="form-group mb-2">
                <label class="form-label">Stall story (short line)</label>
                <input type="text" name="stall_tagline" class="form-control" maxlength="160" placeholder="e.g. Fresh injera · honey · coffee — today only"
                  value="<?= qb_esc_html((string) ($seller['stall_tagline'] ?? '')) ?>"/>
                <p class="text-xs text-muted mt-1 mb-0">Shown on Discover and the live map — one catchy line.</p>
            </div>
            <?php endif; ?>
            <div class="form-group mb-2">
                <label class="form-label">City / Location</label>
                <select name="location" class="form-control" required>
                    <option value="">— Select city —</option>
                    <?php foreach ($cities as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= ($seller['location'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-3">
                <label class="form-label">Categories (choose 1–3)</label>
                <?php if (!$allowCatEdit): ?>
                <div class="alert alert-info mb-2 text-sm" role="status">
                  <p class="mb-1 qb-alert-prose">Your stall categories are <strong>locked</strong> after your first save. An admin can unlock them from <strong>Users</strong> if you need to change them again.</p>
                  <button type="button" class="btn btn-secondary btn-xs" onclick="document.getElementById('qb-cat-request-modal').hidden = false">Request Category Change</button>
                </div>
                <div id="qb-cat-request-modal" class="qb-overlay-webpage" hidden>
                   <div class="qb-overlay-webpage__backdrop" onclick="document.getElementById('qb-cat-request-modal').hidden = true"></div>
                   <div class="qb-overlay-webpage__content card">
                      <div class="qb-overlay-webpage__header">
                        <h3 class="font-bold">Request Category Change</h3>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('qb-cat-request-modal').hidden = true"><?= qb_icon('x', 'qb-icon', 22) ?></button>
                      </div>
                      <div class="qb-overlay-webpage__body">
                        <p class="text-sm text-secondary mb-4">Your categories are locked after the first bazar to ensure market consistency. Tell the admin why you need a change (e.g. pivoting business, error in setup).</p>
                        <form method="post">
                           <input type="hidden" name="action" value="request_category_change"/>
                           <div class="form-group mb-4">
                              <label class="form-label">Reason for change</label>
                              <textarea name="reason" class="form-control" rows="5" placeholder="e.g. Switched from clothing to food... My new focus is bakery items." required></textarea>
                           </div>
                           <div style="display:flex;gap:0.75rem">
                              <button type="submit" class="btn btn-primary btn-full">Send Request to Admin</button>
                              <button type="button" class="btn btn-secondary btn-full" onclick="document.getElementById('qb-cat-request-modal').hidden = true">Cancel</button>
                           </div>
                        </form>
                      </div>
                   </div>
                </div>
                <div class="qb-cat-pick-grid qb-cat-pick-grid--readonly" aria-readonly="true">
                  <?php foreach ($selectedCats as $slug): ?>
                    <?php if (isset($catCatalog[$slug])): ?>
                    <span class="qb-cat-pick qb-cat-pick--readonly"><span><?= htmlspecialchars($catCatalog[$slug]) ?></span></span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-xs text-muted mb-2">Pick carefully — after you save, categories lock until an admin unlocks your account.</p>
                <details class="qb-select-compact" open>
                  <summary>
                    <span>Select categories</span>
                    <span class="text-xs text-muted" id="qb-seller-cat-count"><?= count($selectedCats) ?> selected</span>
                  </summary>
                  <div class="qb-cat-pick-grid mt-2">
                      <?php foreach ($catCatalog as $slug => $label): ?>
                      <label class="qb-cat-pick">
                          <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($slug) ?>" data-qb-cat-cb
                              <?= in_array($slug, $selectedCats, true) ? 'checked' : '' ?>>
                          <span><?= htmlspecialchars($label) ?></span>
                      </label>
                      <?php endforeach; ?>
                  </div>
                </details>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-full mt-2">Update Profile</button>
        </form>
        <?php if (!empty($user['avatar']) || !empty($seller['profile_image'])): ?>
        <form method="post" class="mt-2">
            <input type="hidden" name="action" value="remove_profile_photo"/>
            <button type="submit" class="btn btn-ghost btn-sm">Remove profile photo</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="qb-profile-side-stack">
        <div class="card mb-3" style="background:linear-gradient(135deg, #1C1917, #3D1A07); color:#fff">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.25rem">
                <div>
                    <h3 class="font-bold" style="color:var(--goldenrod-soft);margin-bottom:0.25rem"><?= htmlspecialchars($seller['market_name']) ?></h3>
                    <p class="text-sm" style="color:rgba(255,255,255,0.7)"><?= htmlspecialchars(qb_seller_categories_labels($selectedCats) ?: 'Uncategorized') ?></p>
                </div>
                <span class="badge" style="background:rgba(218, 165, 32, 0.2); color:#DAA520; border:1px solid #DAA520">Verified Vendor</span>
            </div>
            <div style="margin-bottom:1rem">
                <div class="text-xs text-uppercase" style="color:rgba(255,255,255,0.5);letter-spacing:1px;margin-bottom:2px">Vendor UID</div>
                <div class="font-bold" style="font-family:monospace;font-size:1.1rem;letter-spacing:2px"><?= htmlspecialchars($seller['uid']) ?></div>
            </div>
            <div style="margin-bottom:0.75rem">
                <div class="text-xs text-uppercase" style="color:rgba(255,255,255,0.5)">City</div>
                <div class="font-bold text-sm"><?= htmlspecialchars($seller['location'] ?: '—') ?></div>
            </div>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap">
                <div>
                    <div class="text-xs text-uppercase" style="color:rgba(255,255,255,0.5)">Owner</div>
                    <div class="font-bold text-sm"><?= htmlspecialchars($user['display_name']) ?></div>
                </div>
                <div>
                    <div class="text-xs text-uppercase" style="color:rgba(255,255,255,0.5)">Joined</div>
                    <div class="font-bold text-sm"><?= date('M Y', strtotime($user['created_at'])) ?></div>
                </div>
            </div>
        </div>

        <div class="grid grid-2 gap-2 mb-3">
            <div class="stat-card" style="padding:1rem">
                <div class="stat-label mb-1">Products Listed</div>
                <div class="stat-value text-xl">
                    <?= db()->fetchOne("SELECT COUNT(*) as c FROM products WHERE seller_id = ?", [$sid])['c'] ?>
                </div>
            </div>
            <div class="stat-card" style="padding:1rem">
                <div class="stat-label mb-1">Completed Sales</div>
                <div class="stat-value text-xl text-emerald">
                    <?= db()->fetchOne("SELECT COUNT(*) as c FROM transactions WHERE seller_id = ? AND payment_status='completed'", [$sid])['c'] ?>
                </div>
            </div>
        </div>

        <div class="card qb-profile-side-links" style="padding:1rem 1.1rem">
            <h3 class="font-bold mb-2" style="font-size:1rem">Quick links</h3>
            <p class="text-xs text-muted mb-2">Jump to the tools you use most — balances the layout next to the long edit form.</p>
            <ul class="qb-profile-side-links__list">
                <li><a href="<?= htmlspecialchars(APP_URL . '/seller/dashboard.php') ?>">Dashboard</a></li>
                <li><a href="<?= htmlspecialchars(APP_URL . '/seller/products.php') ?>">Products &amp; stock</a></li>
                <li><a href="<?= htmlspecialchars(APP_URL . '/seller/qr.php') ?>">My stall QR</a></li>
                <li><a href="<?= htmlspecialchars(APP_URL . '/seller/events.php') ?>">Apply to bazars</a></li>
                <li><a href="<?= htmlspecialchars(APP_URL . '/seller/payments.php') ?>">Payments &amp; receipts</a></li>
            </ul>
        </div>
    </div>
</div>

<script>
window.__qbSellerCatLocked = <?= $allowCatEdit ? 'false' : 'true' ?>;
(function () {
  if (window.__qbSellerCatLocked) return;
  var max = 3;
  var cbs = document.querySelectorAll('[data-qb-cat-cb]');
  var countEl = document.getElementById('qb-seller-cat-count');
  function syncCount() {
    if (!countEl) return;
    var n = document.querySelectorAll('[data-qb-cat-cb]:checked').length;
    countEl.textContent = n + ' selected';
  }
  cbs.forEach(function (cb) {
    cb.addEventListener('change', function () {
      var n = document.querySelectorAll('[data-qb-cat-cb]:checked').length;
      if (n > max) {
        cb.checked = false;
        alert('You can select at most ' + max + ' categories.');
      }
      syncCount();
    });
  });
  syncCount();
})();
</script>

<style>
.qb-select-compact {
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--bg-soft);
  padding: 0.65rem 0.75rem;
}
.qb-select-compact summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  list-style: none;
  font-weight: 600;
}
.qb-select-compact summary::-webkit-details-marker { display: none; }
.qb-cat-pick-grid .qb-cat-pick {
  background: #ffffff !important;
  border-color: #d9e1ee !important;
  color: #1f2a44 !important;
}
.qb-cat-pick-grid .qb-cat-pick:hover {
  background: #f8fbff !important;
  border-color: #2a3582 !important;
}
.qb-cat-pick-grid .qb-cat-pick input[type="checkbox"] {
  accent-color: #eb670e;
}
.qb-cat-pick-grid .qb-cat-pick--readonly {
  background: #f6f9ff !important;
}
.qb-profile-side-stack { display: flex; flex-direction: column; gap: 0; }
.qb-profile-side-links__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.qb-profile-side-links__list a {
  display: block;
  padding: 0.45rem 0.55rem;
  border-radius: var(--radius-md);
  font-weight: 600;
  font-size: 0.9rem;
  color: var(--text);
  text-decoration: none;
  border: 1px solid transparent;
}
.qb-profile-side-links__list a:hover {
  background: var(--bg-elevated);
  border-color: var(--border);
  color: var(--accent-hover);
}
.qb-mini-modal {
  position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4);
  display:none; align-items:center; justify-content:center; z-index:9999;
}
.qb-mini-modal:not([hidden]) {
  display: flex;
}
.qb-mini-modal__content { width:90%; max-width:400px; padding:1.5rem; }
</style>

<?php qb_page_end(); ?>
