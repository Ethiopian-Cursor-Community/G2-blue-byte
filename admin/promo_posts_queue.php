<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

startSession();
requireAdmin();

$uid = (int) ($_SESSION['app_user_id'] ?? 0);
$success = '';
$error = '';

$tab = (string) ($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'flagged'], true)) {
    $tab = 'pending';
}
$filterType = (string) ($_GET['type'] ?? '');
if (!in_array($filterType, ['', 'text', 'image', 'video'], true)) {
    $filterType = '';
}
$view = (string) ($_GET['view'] ?? '');
if ($view !== '') {
    $parts = explode(':', $view, 2);
    $vTab = $parts[0] ?? '';
    $vType = $parts[1] ?? '';
    if (in_array($vTab, ['pending', 'flagged'], true)) {
        $tab = $vTab;
    }
    if ($tab === 'pending' && in_array($vType, ['all', 'text', 'image', 'video'], true)) {
        $filterType = $vType === 'all' ? '' : $vType;
    } elseif ($tab === 'flagged') {
        $filterType = '';
    }
}
$filterQ = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && qb_csrf_verify($_POST['csrf'] ?? null)) {
    $act = (string) ($_POST['action'] ?? '');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];

    if ($act === 'bulk_approve' && $ids !== []) {
        $n = 0;
        foreach ($ids as $pid) {
            if ($pid > 0 && qb_promo_post_set_status($pid, 'active', $uid)) {
                $n++;
            }
        }
        $success = $n . ' promo(s) approved.';
    } elseif ($act === 'bulk_reject' && $ids !== []) {
        $code = (string) ($_POST['rejection_code'] ?? 'other');
        $note = (string) ($_POST['rejection_note'] ?? '');
        $n = 0;
        foreach ($ids as $pid) {
            if ($pid > 0 && qb_promo_post_set_status($pid, 'rejected', $uid, $code, $note)) {
                $n++;
            }
        }
        $success = $n . ' promo(s) rejected.';
    } else {
        $pid = (int) ($_POST['id'] ?? 0);
        if ($pid > 0 && in_array($act, ['approve', 'reject', 'pending', 'restore_active', 'dismiss_flag'], true)) {
            if ($act === 'approve') {
                if (qb_promo_post_set_status($pid, 'active', $uid)) {
                    $success = 'Promo approved and live on homepage.';
                } else {
                    $error = 'Could not update status.';
                }
            } elseif ($act === 'reject') {
                $code = (string) ($_POST['rejection_code'] ?? 'other');
                $note = (string) ($_POST['rejection_note'] ?? '');
                if (qb_promo_post_set_status($pid, 'rejected', $uid, $code, $note)) {
                    $success = 'Promo rejected.';
                } else {
                    $error = 'Could not update status.';
                }
            } elseif ($act === 'pending') {
                if (qb_promo_post_set_status($pid, 'pending', $uid)) {
                    $success = 'Promo set to pending.';
                } else {
                    $error = 'Could not update status.';
                }
            } elseif ($act === 'restore_active') {
                if (qb_promo_post_set_status($pid, 'active', $uid)) {
                    $success = 'Promo restored to active.';
                } else {
                    $error = 'Could not restore.';
                }
            } elseif ($act === 'dismiss_flag') {
                if (qb_promo_post_set_status($pid, 'pending', $uid)) {
                    $success = 'Flag cleared — back to pending queue.';
                } else {
                    $error = 'Could not update.';
                }
            }
        }
    }
}

$queue = [];
$flagged = [];
if (qb_promo_posts_ready()) {
    $queue = qb_promo_posts_pending_queue_filtered(['type' => $filterType, 'q' => $filterQ]);
    $flagged = qb_promo_posts_flagged_queue();
}
$recent = [];
if (qb_promo_posts_ready()) {
    try {
        $recent = db()->fetchAll(
            "SELECT p.*,
            CASE p.owner_type
              WHEN 'seller' THEN (SELECT s.market_name FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
              WHEN 'organization' THEN (SELECT u.display_name FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
            END AS owner_label
            FROM promo_posts p
            WHERE p.status IN ('active','rejected')
            ORDER BY COALESCE(p.reviewed_at, p.created_at) DESC, p.id DESC
            LIMIT 40"
        );
    } catch (Throwable $e) {
        $recent = [];
    }
}

$csrf = qb_csrf_token();
$rejectOpts = qb_promo_rejection_codes();

qb_page_start('admin', 'Promo queue', 'promo_posts_queue.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Community promo queue</h1>
    <p class="page-subtitle">Approve submissions, handle reports, and manage the homepage feed.</p>
  </div>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success mb-3"><?= qb_esc_html($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger mb-3"><?= qb_esc_html($error) ?></div><?php endif; ?>

<?php if (!qb_promo_posts_ready()): ?>
<div class="alert alert-warning">Run <code>php install/migrate_promo_posts.php</code> first.</div>
<?php else: ?>
<?php if (!qb_has_column('promo_posts', 'rejection_code')): ?>
<div class="alert alert-info mb-3">Run <code>php install/migrate_promo_posts_v3.php</code> for drafts, reports, rejection reasons, and bulk actions.</div>
<?php endif; ?>

<form method="get" class="qb-admin-promo-toolbar mb-3">
  <div class="qb-admin-promo-toolbar__left">
    <label class="qb-admin-promo-toolbar__search" for="promo-view">
      <span class="qb-admin-promo-toolbar__search-label">View</span>
      <select id="promo-view" name="view" class="form-control form-control-sm">
        <optgroup label="Pending review">
          <option value="pending:all" <?= ($tab === 'pending' && $filterType === '') ? 'selected' : '' ?>>All pending promos</option>
          <option value="pending:text" <?= ($tab === 'pending' && $filterType === 'text') ? 'selected' : '' ?>>Pending text promos</option>
          <option value="pending:image" <?= ($tab === 'pending' && $filterType === 'image') ? 'selected' : '' ?>>Pending image promos</option>
          <option value="pending:video" <?= ($tab === 'pending' && $filterType === 'video') ? 'selected' : '' ?>>Pending video promos</option>
        </optgroup>
        <optgroup label="Moderation">
          <option value="flagged:all" <?= $tab === 'flagged' ? 'selected' : '' ?>>Flagged / reported promos (<?= count($flagged) ?>)</option>
        </optgroup>
      </select>
    </label>
    <label class="qb-admin-promo-toolbar__search" for="promo-q">
      <span class="qb-admin-promo-toolbar__search-label">Search</span>
      <input id="promo-q" type="search" name="q" class="form-control form-control-sm" value="<?= qb_esc_html($filterQ) ?>" placeholder="Title or description"/>
    </label>
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
    <a href="?view=pending:all" class="btn btn-ghost btn-sm">Clear</a>
  </div>
</form>

<?php if ($tab === 'flagged'): ?>
<div class="card mb-4">
  <h3 class="font-bold mb-2">Flagged / reported</h3>
  <?php if ($flagged === []): ?>
    <p class="text-muted text-sm">Nothing flagged.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Preview</th><th>Title</th><th>Reports</th><th>Owner</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($flagged as $row): ?>
          <tr>
            <td class="qb-admin-promo-preview-cell"><?= qb_promo_admin_media_preview_html($row) ?></td>
            <td class="font-bold"><?= qb_esc_html((string) ($row['title'] ?? '')) ?></td>
            <td><?= (int) ($row['report_count'] ?? 0) ?></td>
            <td class="text-sm"><?= qb_esc_html((string) ($row['owner_label'] ?? '')) ?></td>
            <td>
              <form method="post" class="qb-inline-actions" style="display:inline-flex;gap:0.35rem;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
                <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>"/>
                <button type="submit" name="action" value="restore_active" class="btn btn-primary btn-sm">Restore active</button>
                <button type="submit" name="action" value="dismiss_flag" class="btn btn-ghost btn-sm">Move to pending</button>
                <button type="submit" name="action" value="reject" class="btn btn-ghost btn-sm text-danger">Reject</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-muted mt-2">Restore active if the report was mistaken. Move to pending to re-review. Reject uses default reason unless you use the pending table.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'pending'): ?>
<div class="qb-admin-promo-queue card mb-4">
  <div class="qb-admin-promo-queue__head">
    <h2 class="qb-admin-promo-queue__title">Pending review</h2>
    <p class="text-xs text-muted mb-0"><?= count($queue) ?> in queue</p>
  </div>

  <?php if ($queue === []): ?>
    <p class="text-muted text-sm mb-0">No pending promos for this filter.</p>
  <?php else: ?>
    <form method="post" id="bulk-promo-form" class="qb-admin-promo-bulk-bar">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
      <div class="qb-admin-promo-bulk-bar__inner">
        <button type="submit" name="action" value="bulk_approve" class="btn btn-primary btn-sm" onclick="return document.querySelectorAll('input[form=&quot;bulk-promo-form&quot;][name=&quot;ids[]&quot;]:checked').length>0">Approve selected</button>
        <label class="qb-admin-promo-bulk-bar__field text-xs text-muted mb-0">Reject as
          <select name="rejection_code" class="form-control form-control-sm mt-1" style="min-width:10rem">
            <?php foreach ($rejectOpts as $k => $lab): ?>
            <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"><?= qb_esc_html($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <input type="text" name="rejection_note" class="form-control form-control-sm" style="min-width:10rem;max-width:16rem" placeholder="Note (optional)"/>
        <button type="submit" name="action" value="bulk_reject" class="btn btn-ghost btn-sm text-danger" onclick="return document.querySelectorAll('input[form=&quot;bulk-promo-form&quot;][name=&quot;ids[]&quot;]:checked').length>0">Reject selected</button>
      </div>
    </form>
    <div class="qb-admin-promo-review-grid">
      <?php foreach ($queue as $row): ?>
      <?php
          $tags = qb_promo_decode_moderation_tags(isset($row['moderation_tags']) ? (string) $row['moderation_tags'] : null);
          $dur = (int) ($row['video_duration_seconds'] ?? 0);
          $rid = (int) ($row['id'] ?? 0);
          $ctype = (string) ($row['content_type'] ?? '');
      ?>
      <article class="qb-admin-promo-review-card">
        <header class="qb-admin-promo-review-card__head">
          <label class="qb-admin-promo-review-card__pick">
            <input type="checkbox" name="ids[]" value="<?= $rid ?>" form="bulk-promo-form" aria-label="Select promo #<?= $rid ?>"/>
            <span class="text-xs text-muted">Select</span>
          </label>
          <span class="qb-admin-promo-review-card__type"><?= qb_esc_html($ctype) ?><?php if ($dur > 0): ?> · <?= qb_esc_html(qb_promo_format_duration($dur)) ?><?php endif; ?></span>
        </header>
        <div class="qb-admin-promo-review-card__preview"><?= qb_promo_admin_media_preview_html($row) ?></div>
        <div class="qb-admin-promo-review-card__body">
          <h3 class="qb-admin-promo-review-card__title"><?= qb_esc_html((string) ($row['title'] ?? '')) ?></h3>
          <?php if (!empty($row['description'])): ?>
          <p class="qb-admin-promo-review-card__desc text-sm text-secondary"><?= qb_esc_html(mb_strlen((string) $row['description']) > 220 ? mb_substr((string) $row['description'], 0, 217) . '…' : (string) $row['description']) ?></p>
          <?php endif; ?>
          <div class="qb-admin-promo-review-card__meta text-xs text-muted">
            <span><strong>Owner</strong> <?= qb_esc_html((string) ($row['owner_label'] ?? '')) ?></span>
            <span><strong>Target</strong> <?= qb_esc_html((string) ($row['target'] ?? '')) ?></span>
            <span><strong>Submitted</strong> <?= qb_esc_html((string) ($row['created_at'] ?? '')) ?></span>
          </div>
          <?php if ($tags !== []): ?>
          <div class="qb-admin-promo-review-card__tags">
            <?php foreach ($tags as $tg): ?>
            <span class="qb-admin-promo-review-card__tag"><?= qb_esc_html(qb_promo_moderation_tag_label($tg)) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <footer class="qb-admin-promo-review-card__foot">
          <form method="post" class="qb-admin-promo-review-card__actions">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"/>
            <input type="hidden" name="id" value="<?= $rid ?>"/>
            <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm"><?= qb_icon('check', 'qb-icon', 16) ?> Approve</button>
            <button type="submit" name="action" value="reject" class="btn btn-ghost btn-sm text-danger"><?= qb_icon('ban', 'qb-icon', 16) ?> Reject</button>
            <select name="rejection_code" class="form-control form-control-sm qb-admin-promo-review-card__reason" title="Reason if rejecting" aria-label="Rejection reason">
              <?php foreach ($rejectOpts as $k => $lab): ?>
              <option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"><?= qb_esc_html($lab) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="rejection_note" class="form-control form-control-sm" placeholder="Note"/>
          </form>
        </footer>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3 class="font-bold mb-2">Recent decisions</h3>
  <?php if ($recent === []): ?>
    <p class="text-muted text-sm">No history yet.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Title</th><th>Status</th><th>Owner</th><th>Views</th><th>Likes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row): ?>
          <tr>
            <td><?= qb_esc_html((string) ($row['title'] ?? '')) ?></td>
            <td><span class="badge"><?= qb_esc_html((string) ($row['status'] ?? '')) ?></span></td>
            <td class="text-sm"><?= qb_esc_html((string) ($row['owner_label'] ?? '')) ?></td>
            <td><?= (int) ($row['view_count'] ?? 0) ?></td>
            <td><?= (int) ($row['like_count'] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php
qb_page_end();
