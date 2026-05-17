<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

qb_ensure_category_schema();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = sanitize($_POST['admin_note'] ?? '');

    $req = db()->fetchOne("SELECT * FROM category_change_requests WHERE id = ?", [$reqId]);
    if (!$req) {
        $error = 'Request not found.';
    } else {
        if ($action === 'approve') {
            db()->execute("UPDATE category_change_requests SET status = 'approved', admin_note = ? WHERE id = ?", [$note, $reqId]);
            db()->execute("UPDATE sellers SET allow_categories_edit = 1 WHERE id = ?", [$req['seller_id']]);
            $success = 'Request approved. Seller can now edit categories.';
        } elseif ($action === 'reject') {
            db()->execute("UPDATE category_change_requests SET status = 'rejected', admin_note = ? WHERE id = ?", [$note, $reqId]);
            $success = 'Request rejected.';
        }
    }
}

$requests = db()->fetchAll("
    SELECT r.*, s.market_name, u.display_name, s.categories_json
    FROM category_change_requests r
    JOIN sellers s ON s.id = r.seller_id
    JOIN app_users u ON u.id = s.app_user_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC
");

qb_page_start('admin', 'Category Change Requests', 'category_requests.php', true);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Category Change Requests</h1>
    <p class="page-subtitle">Review and approve category unlock requests from sellers.</p>
  </div>
</div>

<?php if($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <?php if (empty($requests)): ?>
        <p class="text-center text-muted py-4">No pending category change requests.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Seller</th>
                        <th>Current Categories</th>
                        <th>Reason for Change</th>
                        <th>Requested At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): 
                        $cats = qb_decode_categories_json($r['categories_json']);
                        $catLabels = qb_seller_categories_labels($cats);
                    ?>
                    <tr>
                        <td>
                            <div class="font-bold"><?= htmlspecialchars($r['market_name']) ?></div>
                            <div class="text-xs text-muted"><?= htmlspecialchars($r['display_name']) ?></div>
                        </td>
                        <td><div class="text-xs"><?= htmlspecialchars($catLabels ?: 'None') ?></div></td>
                        <td><div class="text-sm"><?= nl2br(htmlspecialchars($r['reason'])) ?></div></td>
                        <td class="text-xs"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td>
                            <form method="post" class="qb-admin-action-form">
                                <input type="hidden" name="request_id" value="<?= $r['id'] ?>"/>
                                <textarea name="admin_note" class="form-control form-control-sm mb-1" placeholder="Admin note (optional)"></textarea>
                                <div style="display:flex;gap:0.4rem">
                                    <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                    <button type="submit" name="action" value="reject" class="btn btn-ghost btn-sm text-danger">Reject</button>
                                </div>
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
