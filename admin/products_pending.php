<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && qb_has_column('products', 'approval_status')) {
    $id = (int) ($_POST['product_id'] ?? 0);
    $act = $_POST['decision'] ?? '';
    if ($id && in_array($act, ['approve', 'reject'], true)) {
        $st = $act === 'approve' ? 'approved' : 'rejected';
        db()->execute('UPDATE products SET approval_status=? WHERE id=?', [$st, $id]);
        $success = 'Product ' . ($st === 'approved' ? 'approved' : 'rejected') . '.';
    }
}

$pending = [];
if (qb_has_column('products', 'approval_status')) {
    $pending = db()->fetchAll(
        "SELECT p.*, s.market_name, s.uid AS seller_uid
         FROM products p
         JOIN sellers s ON s.id = p.seller_id
         WHERE p.approval_status = 'pending'
         ORDER BY p.id DESC"
    );
}

qb_page_start('admin', 'Product approvals', 'products_pending.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Pending products</h1>
    <p class="page-subtitle">Approve seller listings before they appear to buyers.</p>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-3"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if (!qb_has_column('products', 'approval_status')): ?>
  <div class="alert alert-warning">Migration not applied — approval column missing.</div>
<?php elseif (empty($pending)): ?>
  <div class="card"><p class="text-muted m-0">No pending products.</p></div>
<?php else: ?>
  <div class="card">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Product</th>
            <th>Seller</th>
            <th>Price</th>
            <th style="text-align:right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $p): ?>
          <tr>
            <td>
              <div class="font-bold"><?= htmlspecialchars($p['name']) ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars(mb_substr($p['description'] ?? '', 0, 80)) ?></div>
            </td>
            <td><?= htmlspecialchars($p['market_name']) ?></td>
            <td><?= number_format((float)$p['price'], 2) ?> ETB</td>
            <td style="text-align:right">
              <form method="post" style="display:inline">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"/>
                <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm">Approve</button>
                <button type="submit" name="decision" value="reject" class="btn btn-danger btn-sm">Reject</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php qb_page_end(); ?>
