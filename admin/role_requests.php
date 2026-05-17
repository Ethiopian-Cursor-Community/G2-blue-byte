<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();
qb_apply_seller_compliance_schema();

$success = '';
$error = '';
$csrf = qb_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && qb_role_request_columns_ready()) {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Invalid session token. Refresh the page.';
    } else {
        $id = (int) ($_POST['user_id'] ?? 0);
        $act = $_POST['decision'] ?? '';
        if ($id && in_array($act, ['approve', 'reject'], true)) {
            if ($act === 'approve') {
                $err = qb_approve_user_role_request($id);
                $success = $err === '' ? 'Request approved. User must sign in again to use the new portal.' : $err;
                if ($err !== '') {
                    $error = $err;
                    $success = '';
                }
            } else {
                qb_reject_user_role_request($id);
                $success = 'Request rejected.';
            }
        }
    }
}

$qRaw = trim((string) ($_GET['q'] ?? ''));
$q = $qRaw !== '' ? sanitize($qRaw) : '';
$reqFilter = isset($_GET['req']) ? (string) $_GET['req'] : 'all';
if (!in_array($reqFilter, ['all', 'seller', 'organizer'], true)) {
    $reqFilter = 'all';
}

$perPage = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = ["role_request_status = 'pending'"];
$params = [];
if ($reqFilter !== 'all') {
    $where[] = 'role_requested = ?';
    $params[] = $reqFilter;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(display_name LIKE ? OR login_uid LIKE ? OR phone LIKE ? OR email LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = 0;
$pending = [];
$totalPages = 1;

if (qb_role_request_columns_ready()) {
    $total = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users $whereSql", $params)['c'] ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $pending = db()->fetchAll(
        "SELECT * FROM app_users $whereSql ORDER BY id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
}

$href = static function (string $q, string $req, int $pg = 1): string {
    $p = [];
    if ($q !== '') {
        $p['q'] = $q;
    }
    if ($req !== 'all') {
        $p['req'] = $req;
    }
    if ($pg > 1) {
        $p['page'] = $pg;
    }
    return 'role_requests.php' . ($p === [] ? '' : '?' . http_build_query($p));
};

$from = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
$to = min($page * $perPage, $total);

qb_page_start('admin', 'Role requests', 'role_requests.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Role upgrade requests</h1>
    <p class="page-subtitle">Buyers who asked to become sellers or organizers — search, filter, paginate.</p>
  </div>
  <a href="users.php" class="btn btn-secondary btn-sm"><?= qb_icon('users', 'qb-icon', 16) ?> All users</a>
</div>

<?php if ($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!qb_role_request_columns_ready()): ?>
  <div class="alert alert-warning">Run <code>install/migrate_2026_role_request.php</code> first.</div>
<?php else: ?>

<form method="get" class="card card--data-roles no-hover-anim mb-3" style="padding:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">
  <div class="form-group mb-0" style="flex:1;min-width:200px">
    <label class="form-label" for="rr-q">Search</label>
    <input type="search" id="rr-q" name="q" class="form-control" value="<?= htmlspecialchars($qRaw) ?>" placeholder="Name, login, phone, email"/>
  </div>
  <div class="form-group mb-0" style="min-width:160px">
    <label class="form-label" for="rr-req">Requested role</label>
    <select id="rr-req" name="req" class="form-control" onchange="this.form.submit()">
      <option value="all" <?= $reqFilter === 'all' ? 'selected' : '' ?>>All</option>
      <option value="seller" <?= $reqFilter === 'seller' ? 'selected' : '' ?>>Seller</option>
      <option value="organizer" <?= $reqFilter === 'organizer' ? 'selected' : '' ?>>Organizer</option>
    </select>
  </div>
  <button type="submit" class="btn btn-secondary">Apply</button>
  <?php if ($qRaw !== '' || $reqFilter !== 'all'): ?>
  <a href="role_requests.php" class="btn btn-ghost">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($pending)): ?>
  <div class="card card--data-roles no-hover-anim"><p class="text-muted m-0">No pending requests match your filters.</p></div>
<?php else: ?>
  <div class="card card--data-roles no-hover-anim">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>Current role</th>
            <th>Requested</th>
            <th>Compliance</th>
            <th style="text-align:right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $u): ?>
          <tr>
            <td>
              <div class="font-bold"><?= htmlspecialchars($u['display_name']) ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars($u['login_uid']) ?> · <?= htmlspecialchars($u['phone'] ?? '') ?></div>
            </td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($u['role_requested'] ?? '') ?></span></td>
            <td class="text-xs">
              <?php if (($u['role_requested'] ?? '') === 'seller'): ?>
                <div><strong>TIN:</strong> <?= htmlspecialchars((string) ($u['role_request_tin_no'] ?? '—')) ?></div>
                <div><strong>License:</strong> <?= htmlspecialchars((string) ($u['role_request_license_no'] ?? '—')) ?></div>
                <div><strong>National ID/FAN:</strong> <?= htmlspecialchars((string) ($u['role_request_national_id_fan_no'] ?? '—')) ?></div>
                <div><strong>Stall image:</strong>
                  <?php if (!empty($u['role_request_stall_image'])): ?>
                    <a href="<?= htmlspecialchars(qb_public_upload_url((string) $u['role_request_stall_image'])) ?>" target="_blank" rel="noopener">View image</a>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </div>
                <div><strong>Legal confirm:</strong> <?= !empty($u['role_request_legal_confirmed']) ? 'Yes' : 'No' ?></div>
              <?php else: ?>
                <span class="text-muted">Not required for organizer request.</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"/>
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"/>
                <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm">Approve</button>
                <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm">Reject</button>
              </form>
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
      <nav class="flex gap-1 align-center" aria-label="Pagination">
        <?php if ($page > 1): ?>
        <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($href($qRaw, $reqFilter, $page - 1)) ?>">Previous</a>
        <?php endif; ?>
        <span class="text-sm text-secondary px-1">Page <?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($href($qRaw, $reqFilter, $page + 1)) ?>">Next</a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <p class="text-xs text-muted mt-2">After approval, the user should log out and sign in again to open the seller or organizer portal.</p>
<?php endif; ?>
<?php endif; ?>

<?php qb_page_end(); ?>
