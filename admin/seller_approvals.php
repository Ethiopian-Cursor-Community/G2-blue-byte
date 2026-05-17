<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();
qb_apply_seller_compliance_schema();

$ok = '';
$err = '';
$csrf = qb_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        $err = 'Invalid session token. Refresh and try again.';
    } else {
        $sellerId = (int) ($_POST['seller_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($sellerId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            $err = 'Invalid request.';
        } elseif ($decision === 'reject' && $note === '') {
            $err = 'Add a short reason when rejecting.';
        } else {
            $seller = db()->fetchOne('SELECT id, app_user_id FROM sellers WHERE id = ? LIMIT 1', [$sellerId]);
            if (!$seller) {
                $err = 'Seller not found.';
            } else {
                $status = $decision === 'approve' ? 'approved' : 'rejected';
                db()->execute(
                    'UPDATE sellers SET approval_status = ?, approval_note = ?, approval_reviewed_by = ?, approval_reviewed_at = NOW() WHERE id = ?',
                    [$status, ($note !== '' ? $note : null), (int) ($_SESSION['app_user_id'] ?? 0), $sellerId]
                );
                $ok = $decision === 'approve' ? 'Seller approved successfully.' : 'Seller request rejected.';
            }
        }
    }
}

$rows = db()->fetchAll(
    "SELECT s.*, u.login_uid, u.display_name AS user_name, u.phone AS user_phone
     FROM sellers s
     INNER JOIN app_users u ON u.id = s.app_user_id
     WHERE s.approval_status IN ('pending', 'rejected')
     ORDER BY CASE WHEN s.approval_status = 'pending' THEN 0 ELSE 1 END, s.id DESC"
);

qb_page_start('admin', 'Seller approvals', 'seller_approvals.php', false);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Seller approvals</h1>
    <p class="page-subtitle">Review new seller registrations before portal access is granted.</p>
  </div>
</div>

<?php if ($ok !== ''): ?><div class="alert alert-success mb-2"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert alert-danger mb-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card">
  <?php if ($rows === []): ?>
    <p class="text-muted m-0">No pending/rejected seller approval records.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Seller</th>
            <th>Compliance</th>
            <th>Stall image</th>
            <th>Status</th>
            <th style="min-width:300px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <?php
            $sid = (int) ($r['id'] ?? 0);
            $status = strtolower((string) ($r['approval_status'] ?? 'pending'));
            $img = trim((string) ($r['stall_image'] ?? ''));
          ?>
          <tr>
            <td>
              <div class="font-bold"><?= htmlspecialchars((string) ($r['market_name'] ?? '')) ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars((string) ($r['user_name'] ?? '')) ?> · @<?= htmlspecialchars((string) ($r['login_uid'] ?? '')) ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars((string) ($r['user_phone'] ?? '')) ?></div>
            </td>
            <td class="text-xs">
              <div><strong>TIN:</strong> <?= htmlspecialchars((string) ($r['tin_no'] ?? '—')) ?></div>
              <div><strong>License:</strong> <?= htmlspecialchars((string) ($r['license_no'] ?? '—')) ?></div>
              <div><strong>National ID/FAN:</strong> <?= htmlspecialchars((string) ($r['national_id_fan_no'] ?? '—')) ?></div>
            </td>
            <td>
              <?php if ($img !== ''): ?>
                <a href="<?= htmlspecialchars(qb_public_upload_url($img)) ?>" target="_blank" rel="noopener">
                  <img src="<?= htmlspecialchars(qb_public_upload_url($img)) ?>" alt="Stall image" style="width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">
                </a>
              <?php else: ?>
                <span class="text-xs text-muted">No image</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $status === 'approved' ? 'badge-green' : ($status === 'rejected' ? 'badge-red' : 'badge-amber') ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
              <?php if (!empty($r['approval_note'])): ?>
                <div class="text-xs text-muted mt-1"><?= htmlspecialchars((string) $r['approval_note']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="grid grid-2 gap-2">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="seller_id" value="<?= $sid ?>">
                <div class="form-group" style="grid-column:1/-1;margin-bottom:0;">
                  <input type="text" name="note" class="form-control" maxlength="255" placeholder="Optional note for approval / required for reject">
                </div>
                <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm">Approve</button>
                <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm">Reject</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php qb_page_end(); ?>
