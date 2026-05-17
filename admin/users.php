<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_account_migrate.php';
require_once __DIR__ . '/../includes/report_admin_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

if (!qb_user_account_schema_ready() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    qb_apply_user_account_schema();
}
qb_apply_seller_compliance_schema();
if (function_exists('qb_apply_seller_downgrade_schema')) {
    qb_apply_seller_downgrade_schema();
}

$adminSelfId = (int) ($_SESSION['app_user_id'] ?? 0);
$flashOk = '';
$flashErr = '';
$moderationOps = ['lock', 'ban', 'downgrade_seller', 'reject_seller'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $role = sanitize($_POST['role']);
        $login = sanitize($_POST['login_uid']);
        $pass = $_POST['password'];
        $name = sanitize($_POST['display_name']);
        $phone = sanitize($_POST['phone']);
        $email = sanitize($_POST['email']);

        $exists = db()->fetchOne('SELECT id FROM app_users WHERE login_uid = ?', [$login]);
        if (!$exists) {
            $pwd = hashPassword($pass);
            $publicId = qb_user_account_schema_ready() ? qb_generate_public_id() : null;
            if ($publicId !== null) {
                db()->execute(
                    'INSERT INTO app_users (public_uuid, login_uid, password_hash, display_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$publicId, $login, $pwd, $name, $role, $phone, $email]
                );
            } else {
                db()->execute(
                    'INSERT INTO app_users (login_uid, password_hash, display_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?)',
                    [$login, $pwd, $name, $role, $phone, $email]
                );
            }
            $newId = (int) db()->lastInsertId();

            if ($role === 'seller') {
                $uid = generateUID();
                db()->execute(
                    'INSERT INTO sellers (app_user_id, uid, full_name, market_name, phone, email, password_hash, qr_secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$newId, $uid, $name, "$name's Shop", $phone, $email, $pwd, 'qr_sec_' . time()]
                );
            }
            $flashOk = 'User created.';
        } else {
            $flashErr = 'Login already exists.';
        }
    } elseif ($act === 'mod_user' && qb_csrf_verify($_POST['csrf'] ?? null)) {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $op = (string) ($_POST['op'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            $note = mb_substr($note, 0, 500);
        }
        if (in_array($op, $moderationOps, true) && $note === '') {
            $flashErr = 'Reason is required for this moderation action.';
        }

        if ($flashErr === '' && ($uid <= 0 || $uid === $adminSelfId)) {
            $flashErr = 'You cannot change your own account this way.';
        } elseif ($flashErr === '') {
            $target = db()->fetchOne('SELECT id, role FROM app_users WHERE id = ?', [$uid]);
            if (!$target) {
                $flashErr = 'User not found.';
            } else {
                if (!qb_user_account_schema_ready()) {
                    $flashErr = 'Database columns missing — refresh the page to apply updates.';
                } else {
                    switch ($op) {
                        case 'toggle_active':
                            db()->execute(
                                'UPDATE app_users SET is_active = 1 - is_active WHERE id = ?',
                                [$uid]
                            );
                            $flashOk = 'Active status updated.';
                            break;
                        case 'lock':
                            db()->execute(
                                'UPDATE app_users SET is_locked = 1, moderation_note = ? WHERE id = ?',
                                [$note, $uid]
                            );
                            qb_moderation_history_add($uid, 'lock', $note);
                            qb_audit_log('admin.lock_user', 'app_user', (string) $uid, ['reason' => $note]);
                            $flashOk = 'Account locked.';
                            break;
                        case 'unlock':
                            db()->execute('UPDATE app_users SET is_locked = 0 WHERE id = ?', [$uid]);
                            $flashOk = 'Lock removed.';
                            break;
                        case 'ban':
                            db()->execute(
                                'UPDATE app_users SET is_banned = 1, moderation_note = ? WHERE id = ?',
                                [$note, $uid]
                            );
                            qb_moderation_history_add($uid, 'ban', $note);
                            qb_audit_log('admin.ban_user', 'app_user', (string) $uid, ['reason' => $note]);
                            $flashOk = 'Account banned.';
                            break;
                        case 'unban':
                            db()->execute('UPDATE app_users SET is_banned = 0 WHERE id = ?', [$uid]);
                            $flashOk = 'Ban removed.';
                            break;
                        case 'set_note':
                            db()->execute('UPDATE app_users SET moderation_note = ? WHERE id = ?', [$note !== '' ? $note : null, $uid]);
                            $flashOk = 'Note saved.';
                            break;
                        case 'downgrade_seller':
                            if ((string) ($target['role'] ?? '') !== 'seller') {
                                $flashErr = 'Only seller accounts can be downgraded.';
                                break;
                            }
                            if ($note === '') {
                                $flashErr = 'Downgrade reason is required.';
                                break;
                            }
                            if (function_exists('qb_seller_downgrade_schema_ready') && qb_seller_downgrade_schema_ready()) {
                                db()->execute(
                                    "UPDATE app_users
                                     SET role = 'buyer',
                                         seller_downgrade_notice_pending = 1,
                                         moderation_note = ?
                                     WHERE id = ? AND role = 'seller'",
                                    [$note, $uid]
                                );
                            } else {
                                db()->execute(
                                    "UPDATE app_users SET role = 'buyer', moderation_note = ? WHERE id = ? AND role = 'seller'",
                                    [$note, $uid]
                                );
                            }
                            qb_moderation_history_add($uid, 'downgrade_seller', $note);
                            qb_audit_log('admin.downgrade_seller', 'app_user', (string) $uid, ['reason' => $note]);
                            $flashOk = 'Seller downgraded to buyer.';
                            break;
                        case 'approve_seller':
                            if ((string) ($target['role'] ?? '') !== 'seller') {
                                $flashErr = 'Only seller accounts can be approved.';
                                break;
                            }
                            if (!qb_has_column('sellers', 'approval_status')) {
                                $flashErr = 'Seller approval columns are missing.';
                                break;
                            }
                            db()->execute(
                                "UPDATE sellers
                                 SET approval_status = 'approved',
                                     approval_note = NULL,
                                     approval_reviewed_at = NOW(),
                                     approval_reviewed_by = ?
                                 WHERE app_user_id = ?",
                                [$adminSelfId, $uid]
                            );
                            qb_audit_log('admin.approve_seller', 'app_user', (string) $uid, ['reason' => 'seller approved']);
                            $flashOk = 'Seller approved.';
                            break;
                        case 'reject_seller':
                            if ((string) ($target['role'] ?? '') !== 'seller') {
                                $flashErr = 'Only seller accounts can be rejected.';
                                break;
                            }
                            if (!qb_has_column('sellers', 'approval_status')) {
                                $flashErr = 'Seller approval columns are missing.';
                                break;
                            }
                            db()->execute(
                                "UPDATE sellers
                                 SET approval_status = 'rejected',
                                     approval_note = ?,
                                     approval_reviewed_at = NOW(),
                                     approval_reviewed_by = ?
                                 WHERE app_user_id = ?",
                                [$note, $adminSelfId, $uid]
                            );
                            qb_audit_log('admin.reject_seller', 'app_user', (string) $uid, ['reason' => $note]);
                            $flashOk = 'Seller rejected.';
                            break;
                        default:
                            $flashErr = 'Unknown action.';
                    }
                }
            }
        }
    } elseif ($act === 'mod_user') {
        $flashErr = 'Invalid session token. Refresh and try again.';
    } elseif ($act === 'unlock_seller_categories' && qb_csrf_verify($_POST['csrf'] ?? null)) {
        if (function_exists('qb_ensure_category_schema')) {
            qb_ensure_category_schema();
        }
        $sid = (int) ($_POST['seller_id'] ?? 0);
        if ($sid > 0 && function_exists('qb_has_column') && qb_has_column('sellers', 'allow_categories_edit')) {
            db()->execute('UPDATE sellers SET allow_categories_edit = 1 WHERE id = ?', [$sid]);
            $flashOk = 'Seller can edit stall categories again on the Profile page.';
        } else {
            $flashErr = 'Could not unlock categories (run DB migration or invalid seller).';
        }
    }
}

$roleFilter = isset($_GET['role']) ? sanitize((string) $_GET['role']) : '';
$validRoleTabs = ['buyer', 'seller', 'organizer', 'co_organizer', 'super_admin', 'gatekeeper'];
if ($roleFilter !== '' && !in_array($roleFilter, $validRoleTabs, true)) {
    $roleFilter = '';
}
$searchRaw = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$search = $searchRaw !== '' ? sanitize($searchRaw) : '';
$statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
$validStatus = ['all', 'active', 'disabled', 'locked', 'banned'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
if (!qb_user_account_schema_ready() && in_array($statusFilter, ['locked', 'banned'], true)) {
    $statusFilter = 'all';
}

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($roleFilter !== '') {
    $where[] = 'u.role = ?';
    $params[] = $roleFilter;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    if (qb_user_account_schema_ready()) {
        $where[] = '(u.display_name LIKE ? OR u.login_uid LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) LIKE ? OR u.public_uuid LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    } else {
        $where[] = '(u.display_name LIKE ? OR u.login_uid LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
}
if ($statusFilter === 'active') {
    $where[] = 'u.is_active = 1 AND COALESCE(u.is_locked,0) = 0 AND COALESCE(u.is_banned,0) = 0';
} elseif ($statusFilter === 'disabled') {
    $where[] = 'u.is_active = 0';
} elseif ($statusFilter === 'locked' && qb_user_account_schema_ready()) {
    $where[] = 'u.is_locked = 1';
} elseif ($statusFilter === 'banned' && qb_user_account_schema_ready()) {
    $where[] = 'u.is_banned = 1';
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$total = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users u LEFT JOIN sellers s ON s.app_user_id = u.id $whereSql", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$users = db()->fetchAll(
    'SELECT u.*, s.id AS seller_linked_id, s.allow_categories_edit AS seller_allow_cat_edit, s.approval_status AS seller_approval_status, s.approval_note AS seller_approval_note, s.stall_image AS seller_stall_image '
    . 'FROM app_users u LEFT JOIN sellers s ON s.app_user_id = u.id '
    . $whereSql
    . ' ORDER BY u.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    $params
);

$organizerPrimaryCount = [];
$organizerCoCount = [];
$organizerUserIds = [];
foreach ($users as $row) {
    $r = (string) ($row['role'] ?? '');
    if ($r === 'organizer' || $r === 'co_organizer') {
        $organizerUserIds[] = (int) ($row['id'] ?? 0);
    }
}
$organizerUserIds = array_values(array_unique(array_filter($organizerUserIds, static fn ($v) => $v > 0)));
if ($organizerUserIds !== []) {
    $idCsv = implode(',', array_map('intval', $organizerUserIds));
    if ($idCsv !== '') {
        $primaryRows = db()->fetchAll(
            "SELECT organizer_app_user_id AS uid, COUNT(*) AS c
             FROM bazar_events
             WHERE organizer_app_user_id IN ($idCsv)
             GROUP BY organizer_app_user_id"
        );
        foreach ($primaryRows as $pr) {
            $organizerPrimaryCount[(int) ($pr['uid'] ?? 0)] = (int) ($pr['c'] ?? 0);
        }
        if (qb_table_exists('bazar_event_organizers')) {
            $coRows = db()->fetchAll(
                "SELECT app_user_id AS uid, COUNT(*) AS c
                 FROM bazar_event_organizers
                 WHERE app_user_id IN ($idCsv)
                 GROUP BY app_user_id"
            );
            foreach ($coRows as $cr) {
                $organizerCoCount[(int) ($cr['uid'] ?? 0)] = (int) ($cr['c'] ?? 0);
            }
        }
    }
}

$reportWarnings = qb_admin_user_report_warnings(array_map(static fn ($row) => (int) ($row['id'] ?? 0), $users));
$moderationRows = qb_moderation_history_recent_for_users(array_map(static fn ($row) => (int) ($row['id'] ?? 0), $users), 5);
$moderationByUser = [];
foreach ($moderationRows as $mr) {
    $tid = (int) ($mr['target_app_user_id'] ?? 0);
    if ($tid <= 0) {
        continue;
    }
    if (!isset($moderationByUser[$tid])) {
        $moderationByUser[$tid] = [];
    }
    if (count($moderationByUser[$tid]) < 3) {
        $moderationByUser[$tid][] = $mr;
    }
}

$from = $total > 0 ? $offset + 1 : 0;
$to = min($offset + $perPage, $total);

/** @param array<string, scalar|null> $extra */
$usersHref = static function (?string $role, string $q, string $status, int $pg = 1, array $extra = []): string {
    $p = $extra;
    if ($role !== null && $role !== '') {
        $p['role'] = $role;
    }
    if ($q !== '') {
        $p['q'] = $q;
    }
    if ($status !== '' && $status !== 'all') {
        $p['status'] = $status;
    }
    if ($pg > 1) {
        $p['page'] = $pg;
    }
    $p = array_filter($p, static fn ($v) => $v !== null && $v !== '');
    return 'users.php' . ($p === [] ? '' : '?' . http_build_query($p));
};

$csrf = qb_csrf_token();

qb_page_start('admin', 'Users', 'users.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Manage Users</h1>
    <p class="page-subtitle">Create accounts, search by public ID or name, and moderate access (lock, ban, disable).</p>
  </div>
  <button type="button" class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='flex'"><?= qb_icon('plus') ?> Create User</button>
</div>

<?php if ($flashOk || $flashErr): ?>
<div id="qb-user-flash" class="qb-toast qb-toast--<?= $flashErr ? 'error' : 'success' ?>" role="status" aria-live="polite" data-auto-dismiss="2200">
  <span class="qb-toast__icon" aria-hidden="true"><?= qb_icon($flashErr ? 'alert' : 'check', 'qb-icon qb-toast__glyph', 18) ?></span>
  <span class="qb-toast__body">
    <span class="qb-toast__title"><?= $flashErr ? 'Something went wrong' : 'Done' ?></span>
    <span class="qb-toast__msg"><?= htmlspecialchars($flashErr ?: $flashOk) ?></span>
  </span>
</div>
<script>
(function(){
  var el = document.getElementById('qb-user-flash');
  if (!el) return;
  requestAnimationFrame(function(){
    el.classList.add('qb-toast--visible');
  });
  var ms = parseInt(el.getAttribute('data-auto-dismiss'), 10) || 2200;
  setTimeout(function(){
    el.classList.remove('qb-toast--visible');
    el.classList.add('qb-toast--out');
    setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 200);
  }, ms);
})();
</script>
<?php endif; ?>

<?php if (!qb_user_account_schema_ready()): ?>
<div class="alert alert-warning mb-3">Database columns for public IDs and moderation are being applied. Refresh if this persists.</div>
<?php endif; ?>

<div class="auth-portal-tabs" style="max-width:560px">
    <a href="<?= htmlspecialchars($usersHref(null, $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === '' ? 'active' : '' ?>">All</a>
    <a href="<?= htmlspecialchars($usersHref('buyer', $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === 'buyer' ? 'active' : '' ?>">Buyers</a>
    <a href="<?= htmlspecialchars($usersHref('seller', $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === 'seller' ? 'active' : '' ?>">Sellers</a>
    <a href="<?= htmlspecialchars($usersHref('organizer', $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === 'organizer' ? 'active' : '' ?>">Organizers</a>
    <a href="<?= htmlspecialchars($usersHref('co_organizer', $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === 'co_organizer' ? 'active' : '' ?>">Co-organizers</a>
    <a href="<?= htmlspecialchars($usersHref('super_admin', $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === 'super_admin' ? 'active' : '' ?>">Admins</a>
    <a href="<?= htmlspecialchars($usersHref('gatekeeper', $searchRaw, $statusFilter)) ?>" class="auth-tab <?= $roleFilter === 'gatekeeper' ? 'active' : '' ?>">Gatekeepers</a>
</div>

<form method="get" action="users.php" class="card card--data-users no-hover-anim mb-3" style="padding:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">
    <?php if ($roleFilter !== ''): ?>
    <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>"/>
    <?php endif; ?>
    <div class="form-group mb-0" style="flex:1;min-width:180px">
        <label class="form-label" for="user-search-q">Search</label>
        <input type="search" id="user-search-q" name="q" class="form-control" value="<?= htmlspecialchars($searchRaw) ?>" placeholder="Name, login, phone, email, ID, or public ID" autocomplete="off"/>
    </div>
    <div class="form-group mb-0" style="min-width:140px">
        <label class="form-label" for="user-status-f">Status</label>
        <select id="user-status-f" name="status" class="form-control">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
            <?php if (qb_user_account_schema_ready()): ?>
            <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Locked</option>
            <option value="banned" <?= $statusFilter === 'banned' ? 'selected' : '' ?>>Banned</option>
            <?php endif; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary">Apply</button>
    <?php if ($searchRaw !== '' || $statusFilter !== 'all'): ?>
    <a href="<?= htmlspecialchars($usersHref($roleFilter !== '' ? $roleFilter : null, '', 'all')) ?>" class="btn btn-ghost">Clear</a>
    <?php endif; ?>
</form>

<div class="card card--data-users no-hover-anim">
    <div class="table-wrapper">
        <table class="admin-users-table">
            <thead>
                <tr>
                    <th>User</th>
                    <?php if (qb_user_account_schema_ready()): ?>
                    <th>Public ID</th>
                    <?php endif; ?>
                    <th>Role</th>
                    <th>Login</th>
                    <th>Contact</th>
                    <th>Flags</th>
                    <th style="min-width:220px">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="<?= qb_user_account_schema_ready() ? '7' : '6' ?>" class="text-center text-muted py-4">
                        <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== 'all'): ?>
                            No users match your filters.
                        <?php else: ?>
                            No users yet.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($users as $u):
                    $rid = (int) $u['id'];
                    $isSelf = $rid === $adminSelfId;
                    $rw = $reportWarnings[$rid] ?? ['warn' => false, 'label' => ''];
                    ?>
                <tr class="<?= !empty($u['is_banned']) ? 'admin-user-row--banned' : (!empty($u['is_locked']) ? 'admin-user-row--locked' : '') ?>">
                    <td>
                        <div class="qb-user-name-row">
                            <?php if (!empty($rw['warn'])): ?>
                            <span class="qb-report-warn-icon" title="<?= htmlspecialchars($rw['label']) ?>" aria-label="High report volume: <?= htmlspecialchars($rw['label']) ?>"><?= qb_icon('alert', 'qb-icon', 16) ?></span>
                            <?php endif; ?>
                            <span class="font-bold"><?= htmlspecialchars($u['display_name']) ?></span>
                            <?php if (!empty($rw['warn'])): ?>
                            <span class="qb-report-warn-label"><?= htmlspecialchars($rw['label']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-muted">Internal ID: <?= $rid ?></div>
                    </td>
                    <?php if (qb_user_account_schema_ready()): ?>
                    <td class="text-xs font-mono"><?= htmlspecialchars((string) ($u['public_uuid'] ?? '—')) ?></td>
                    <?php endif; ?>
                    <td>
                        <?php
                        $roleRaw = (string) ($u['role'] ?? '');
                        $roleLabel = match ($roleRaw) {
                            'co_organizer' => 'Co-organizer',
                            'super_admin' => 'Admin',
                            'gatekeeper' => 'Gatekeeper',
                            default => ucfirst(str_replace('_', ' ', $roleRaw)),
                        };
                        $primaryN = (int) ($organizerPrimaryCount[$rid] ?? 0);
                        $coN = (int) ($organizerCoCount[$rid] ?? 0);
                        ?>
                        <span class="nav-badge-role badge-<?= htmlspecialchars($roleRaw) ?>"><?= htmlspecialchars($roleLabel) ?></span>
                        <?php if ($roleRaw === 'seller' && qb_has_column('sellers', 'approval_status')): ?>
                        <?php $sellerApproval = strtolower((string) ($u['seller_approval_status'] ?? 'approved')); ?>
                        <div class="qb-role-identity-stack">
                          <?php if ($sellerApproval === 'approved'): ?>
                          <span class="badge badge-green">Seller approval: Approved</span>
                          <?php elseif ($sellerApproval === 'rejected'): ?>
                          <span class="badge badge-red">Seller approval: Rejected</span>
                          <?php else: ?>
                          <span class="badge badge-amber">Seller approval: Pending</span>
                          <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($roleRaw === 'organizer' || $roleRaw === 'co_organizer'): ?>
                        <div class="qb-role-identity-stack">
                          <?php if ($primaryN > 0): ?>
                          <span class="badge badge-blue">Event ownership: Primary (<?= $primaryN ?>)</span>
                          <?php endif; ?>
                          <?php if ($coN > 0): ?>
                          <span class="badge badge-violet">Event ownership: Assigned Co-organizer (<?= $coN ?>)</span>
                          <?php endif; ?>
                          <?php if ($primaryN === 0 && $coN === 0): ?>
                          <span class="badge badge-gray">No assigned events</span>
                          <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($u['login_uid']) ?></code></td>
                    <td class="text-xs text-secondary"><?= htmlspecialchars((string) ($u['phone'] ?? '')) ?><br/><?= htmlspecialchars((string) ($u['email'] ?? '')) ?></td>
                    <td class="text-xs">
                        <span class="badge <?= !empty($u['is_active']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($u['is_active']) ? 'Active' : 'Off' ?></span>
                        <?php if (qb_user_account_schema_ready()): ?>
                        <?php if (!empty($u['is_locked'])): ?><span class="badge badge-amber">Locked</span><?php endif; ?>
                        <?php if (!empty($u['is_banned'])): ?><span class="badge badge-red">Banned</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isSelf): ?>
                        <span class="text-xs text-muted">This is you</span>
                        <?php else: ?>
                        <div class="admin-user-actions">
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="toggle_active"/>
                                <button type="submit" class="btn btn-icon btn-sm <?= !empty($u['is_active']) ? 'btn-admin-deactivate' : 'btn-success' ?>" title="<?= !empty($u['is_active']) ? 'Deactivate account' : 'Activate account' ?>" aria-label="<?= !empty($u['is_active']) ? 'Deactivate account' : 'Activate account' ?>"><?= !empty($u['is_active']) ? qb_icon('x', 'qb-icon', 14) : qb_icon('check', 'qb-icon', 14) ?></button>
                            </form>
                            <?php if (qb_user_account_schema_ready()): ?>
                            <?php if (empty($u['is_locked'])): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="lock"/>
                                <input type="hidden" name="note" value=""/>
                                <button type="button" class="btn btn-icon btn-admin-lock btn-sm js-open-moderation-modal" data-op="lock" data-user-id="<?= $rid ?>" data-user-name="<?= htmlspecialchars((string) $u['display_name'], ENT_QUOTES, 'UTF-8') ?>" title="Lock sign-in (reason required)" aria-label="Lock account sign-in"><?= qb_icon('lock', 'qb-icon', 14) ?></button>
                            </form>
                            <?php else: ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="unlock"/>
                                <button type="submit" class="btn btn-icon btn-success btn-sm" title="Allow sign-in" aria-label="Unlock account sign-in"><?= qb_icon('unlock', 'qb-icon', 14) ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if (empty($u['is_banned'])): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="ban"/>
                                <input type="hidden" name="note" value=""/>
                                <button type="button" class="btn btn-icon btn-danger btn-sm js-open-moderation-modal" data-op="ban" data-user-id="<?= $rid ?>" data-user-name="<?= htmlspecialchars((string) $u['display_name'], ENT_QUOTES, 'UTF-8') ?>" title="Ban account (reason required)" aria-label="Ban account"><?= qb_icon('ban', 'qb-icon', 14) ?></button>
                            </form>
                            <?php else: ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="unban"/>
                                <button type="submit" class="btn btn-icon btn-success btn-sm" title="Remove ban" aria-label="Remove ban"><?= qb_icon('check', 'qb-icon', 14) ?></button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php
                            $sellId = (int) ($u['seller_linked_id'] ?? 0);
                            $catUnlocked = (int) ($u['seller_allow_cat_edit'] ?? 1) === 1;
                            if (($u['role'] ?? '') === 'seller'):
                            ?>
                            <?php if (qb_has_column('sellers', 'approval_status')): ?>
                            <?php $sellerApprovalStatus = strtolower((string) ($u['seller_approval_status'] ?? 'approved')); ?>
                            <?php if ($sellerApprovalStatus !== 'approved'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="approve_seller"/>
                                <button type="submit" class="btn btn-icon btn-success btn-sm" title="Approve seller account" aria-label="Approve seller account"><?= qb_icon('check', 'qb-icon', 14) ?></button>
                            </form>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="reject_seller"/>
                                <input type="hidden" name="note" value=""/>
                                <button type="button" class="btn btn-icon btn-danger btn-sm js-open-moderation-modal" data-op="reject_seller" data-user-id="<?= $rid ?>" data-user-name="<?= htmlspecialchars((string) $u['display_name'], ENT_QUOTES, 'UTF-8') ?>" title="Reject seller registration (reason required)" aria-label="Reject seller registration"><?= qb_icon('x', 'qb-icon', 14) ?></button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mod_user"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="user_id" value="<?= $rid ?>"/>
                                <input type="hidden" name="op" value="downgrade_seller"/>
                                <input type="hidden" name="note" value=""/>
                                <button type="button" class="btn btn-icon btn-admin-downgrade btn-sm js-open-moderation-modal" data-op="downgrade_seller" data-user-id="<?= $rid ?>" data-user-name="<?= htmlspecialchars((string) $u['display_name'], ENT_QUOTES, 'UTF-8') ?>" title="Downgrade seller to buyer (reason required)" aria-label="Downgrade seller to buyer"><?= qb_icon('arrow-right', 'qb-icon', 14) ?></button>
                            </form>
                            <?php endif; ?>
                            <?php
                            if (($u['role'] ?? '') === 'seller' && $sellId > 0 && function_exists('qb_has_column') && qb_has_column('sellers', 'allow_categories_edit') && !$catUnlocked):
                            ?>
                            <form method="post" class="inline-form" title="Seller stall categories are locked until you allow edits">
                                <input type="hidden" name="action" value="unlock_seller_categories"/>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                                <input type="hidden" name="seller_id" value="<?= $sellId ?>"/>
                                <button type="submit" class="btn btn-secondary btn-sm" aria-label="Unlock seller stall categories">Unlock categories</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php if (qb_user_account_schema_ready() && !empty($u['moderation_note'])): ?>
                        <div class="text-xs text-muted mt-1" title="Moderation note"><?= htmlspecialchars((string) $u['moderation_note']) ?></div>
                        <?php endif; ?>
                        <?php if (($u['role'] ?? '') === 'seller' && qb_has_column('sellers', 'stall_image') && !empty($u['seller_stall_image'])): ?>
                        <div class="mt-1">
                          <a href="<?= htmlspecialchars(qb_public_upload_url((string) $u['seller_stall_image'])) ?>" target="_blank" rel="noopener" class="text-xs">View seller stall image</a>
                        </div>
                        <?php endif; ?>
                        <?php if (($u['role'] ?? '') === 'seller' && qb_has_column('sellers', 'approval_note') && !empty($u['seller_approval_note'])): ?>
                        <div class="text-xs text-muted mt-1" title="Seller approval note"><?= htmlspecialchars((string) $u['seller_approval_note']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($moderationByUser[$rid])): ?>
                        <details class="mt-1">
                          <summary class="text-xs text-muted" style="cursor:pointer">Reason history</summary>
                          <div class="text-xs mt-1" style="display:grid;gap:0.35rem">
                            <?php foreach ($moderationByUser[$rid] as $hr): ?>
                            <div style="padding:0.35rem 0.5rem;border:1px solid var(--border);border-radius:10px;background:var(--bg-elevated)">
                              <div><strong><?= htmlspecialchars((string) ($hr['action'] ?? 'action')) ?></strong> · <?= htmlspecialchars((string) ($hr['created_at'] ?? '')) ?></div>
                              <div class="text-muted"><?= htmlspecialchars((string) ($hr['reason'] ?? '')) ?></div>
                              <div class="text-muted">
                                by <?= htmlspecialchars((string) (($hr['actor_name'] ?? '') !== '' ? $hr['actor_name'] : ('#' . (string) ($hr['actor_app_user_id'] ?? '—')))) ?>
                              </div>
                            </div>
                            <?php endforeach; ?>
                          </div>
                        </details>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > 0): ?>
    <div class="admin-pagination" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.75rem 1rem;border-top:1px solid var(--border)">
        <p class="text-sm text-muted mb-0">
            Showing <strong><?= (int) $from ?></strong>–<strong><?= (int) $to ?></strong> of <strong><?= (int) $total ?></strong>
        </p>
        <?php if ($totalPages > 1): ?>
        <nav class="flex gap-1 align-center" aria-label="Users pagination">
            <?php if ($page > 1): ?>
            <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($usersHref($roleFilter !== '' ? $roleFilter : null, $searchRaw, $statusFilter, $page - 1)) ?>">Previous</a>
            <?php endif; ?>
            <span class="text-sm text-secondary px-1">Page <?= (int) $page ?> / <?= (int) $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
            <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($usersHref($roleFilter !== '' ? $roleFilter : null, $searchRaw, $statusFilter, $page + 1)) ?>">Next</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div id="qbModerationModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1400; align-items:center; justify-content:center; padding:1rem;">
  <div class="card" style="width:100%; max-width:460px; background:var(--bg-card);">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem; margin-bottom:1rem;">
      <h3 class="font-bold" id="qbModerationModalTitle">Moderation action</h3>
      <button type="button" class="btn btn-ghost btn-sm" id="qbModerationModalClose" aria-label="Close"><?= qb_icon('x', 'qb-icon', 16) ?></button>
    </div>
    <p class="text-sm text-muted mb-2" id="qbModerationModalMeta"></p>
    <form method="post" id="qbModerationForm">
      <input type="hidden" name="action" value="mod_user"/>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="user_id" id="qbModerationUserId" value="0"/>
      <input type="hidden" name="op" id="qbModerationOp" value=""/>
      <div class="form-group mb-3">
        <label class="form-label" for="qbModerationReason">Reason <span class="text-danger">*</span></label>
        <textarea id="qbModerationReason" name="note" class="form-control" rows="4" maxlength="500" required placeholder="Write clear reason for this moderation action"></textarea>
      </div>
      <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
        <button type="button" class="btn btn-ghost" id="qbModerationCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit action</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('qbModerationModal');
  var openers = document.querySelectorAll('.js-open-moderation-modal');
  if (!modal || !openers.length) return;
  var title = document.getElementById('qbModerationModalTitle');
  var meta = document.getElementById('qbModerationModalMeta');
  var userId = document.getElementById('qbModerationUserId');
  var op = document.getElementById('qbModerationOp');
  var reason = document.getElementById('qbModerationReason');
  var closeBtn = document.getElementById('qbModerationModalClose');
  var cancelBtn = document.getElementById('qbModerationCancel');
  var opLabel = {
    lock: 'Lock account',
    ban: 'Ban account',
    downgrade_seller: 'Downgrade seller to buyer',
    reject_seller: 'Reject seller registration'
  };
  function closeModal() {
    modal.style.display = 'none';
    if (reason) reason.value = '';
  }
  openers.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var opVal = btn.getAttribute('data-op') || '';
      var uid = btn.getAttribute('data-user-id') || '0';
      var name = btn.getAttribute('data-user-name') || 'User';
      if (title) title.textContent = opLabel[opVal] || 'Moderation action';
      if (meta) meta.textContent = name + ' (ID ' + uid + ')';
      if (userId) userId.value = uid;
      if (op) op.value = opVal;
      modal.style.display = 'flex';
      setTimeout(function () { if (reason) reason.focus(); }, 30);
    });
  });
  [closeBtn, cancelBtn].forEach(function (el) {
    if (!el) return;
    el.addEventListener('click', closeModal);
  });
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });
})();
</script>

<div id="addUserModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
  <div class="card" style="width:100%; max-width:400px; background:var(--bg-card);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
      <h3 class="font-bold">Create System User</h3>
      <button type="button" onclick="document.getElementById('addUserModal').style.display='none'" class="btn btn-ghost" style="padding:4px"><?= qb_icon('x') ?></button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <div class="form-group mb-2">
        <label class="form-label">Role</label>
        <select name="role" class="form-control" required>
            <option value="buyer">Buyer</option>
            <option value="seller">Seller</option>
            <option value="organizer">Organizer</option>
            <option value="co_organizer">Co-organizer</option>
            <option value="gatekeeper">Gatekeeper</option>
            <option value="super_admin">Admin</option>
        </select>
        <p class="text-xs text-muted mt-1">Seller auto-creates a stall profile. Gatekeeper is for gate-only accounts (usually assign via Gate staff after).</p>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Login Username</label>
        <input type="text" name="login_uid" class="form-control" required>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Display Name</label>
        <input type="text" name="display_name" class="form-control" required>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" required>
      </div>
      <div class="form-group mb-2">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="">
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Password</label>
        <input type="text" name="password" class="form-control" value="password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Create User</button>
    </form>
  </div>
</div>

<?php qb_page_end(); ?>
