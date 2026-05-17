<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit_admin_helpers.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$actionQ = trim((string)($_GET['action'] ?? ''));
$entityQ = trim((string)($_GET['entity'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 40;

$result = qb_audit_admin_fetch($actionQ, $entityQ, $page, $perPage);
$rows = $result['rows'];
$total = $result['total'];
$pages = max(1, (int)ceil($total / $perPage));
$schema = qb_audit_admin_schema();

qb_page_start('admin', 'Audit Log', 'audit.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Security audit log</h1>
    <p class="page-subtitle">Append-only record of sensitive actions and API events.</p>
  </div>
</div>

<?php if (!$schema): ?>
<div class="card">
  <p class="text-muted">No audit table found. Run the SQL migration to create <code>audit_logs</code>, or import a schema that includes <code>audit_log</code>.</p>
</div>
<?php else: ?>

<form method="get" class="card mb-3" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end">
  <div style="flex:1;min-width:160px">
    <label class="text-xs text-muted" for="f_action">Action contains</label>
    <input id="f_action" type="text" name="action" class="form-control" value="<?= htmlspecialchars($actionQ) ?>" placeholder="e.g. transaction"/>
  </div>
  <div style="flex:1;min-width:160px">
    <label class="text-xs text-muted" for="f_entity">Entity type / id</label>
    <input id="f_entity" type="text" name="entity" class="form-control" value="<?= htmlspecialchars($entityQ) ?>" placeholder="seller, report…"/>
  </div>
  <button type="submit" class="btn btn-primary"><?= qb_icon('eye', 'qb-icon', 16) ?> Filter</button>
  <a href="audit.php" class="btn btn-ghost">Clear</a>
</form>

<p class="text-xs text-muted mb-2">Showing <?= count($rows) ?> of <?= (int)$total ?> · table: <code><?= htmlspecialchars($schema['table']) ?></code></p>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>When</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Actor</th>
          <th>IP / meta</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-muted">No rows match.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $metaRaw = $r['meta'] ?? null;
            if (is_array($metaRaw)) {
                $metaShow = json_encode($metaRaw, JSON_UNESCAPED_UNICODE);
            } elseif ($metaRaw !== null && $metaRaw !== '') {
                $metaShow = (string)$metaRaw;
            } else {
                $metaShow = '';
            }
            $when = $r['created_at'] ?? '';
        ?>
        <tr>
          <td class="text-xs"><?= htmlspecialchars((string)$when) ?></td>
          <td>
            <div class="font-bold" style="font-size:0.8rem"><?= htmlspecialchars(qb_shorten_audit_action((string)($r['action'] ?? ''))) ?></div>
            <code class="text-muted" style="font-size:0.7rem"><?= htmlspecialchars((string)($r['action'] ?? '')) ?></code>
          </td>
          <td class="text-xs">
            <?= htmlspecialchars((string)($r['entity_type'] ?? '')) ?>
            <?php if (!empty($r['entity_id'])): ?>
              <br/><span class="text-muted">#<?= htmlspecialchars((string)$r['entity_id']) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-xs">
            <?php if (!empty($r['actor_login'])): ?>
              <?= htmlspecialchars((string)$r['actor_name']) ?> <span class="text-muted">(<?= htmlspecialchars((string)$r['actor_login']) ?>)</span>
            <?php elseif (!empty($r['actor_app_user_id'])): ?>
              user #<?= (int)$r['actor_app_user_id'] ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-xs" style="max-width:280px;word-break:break-word">
            <?= htmlspecialchars((string)($r['ip'] ?? '')) ?>
            <?php if ($metaShow !== ''): ?>
              <div class="text-muted" style="margin-top:4px"><?= htmlspecialchars(mb_substr($metaShow, 0, 400)) ?><?= mb_strlen($metaShow) > 400 ? '…' : '' ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$baseQ = array_filter(['action' => $actionQ !== '' ? $actionQ : null, 'entity' => $entityQ !== '' ? $entityQ : null]);
if ($pages > 1):
    $prevQ = $baseQ + ['p' => max(1, $page - 1)];
    $nextQ = $baseQ + ['p' => min($pages, $page + 1)];
?>
<nav class="auth-portal-tabs" style="margin-top:1rem;flex-wrap:wrap;align-items:center">
  <a href="audit.php?<?= htmlspecialchars(http_build_query($prevQ)) ?>" class="auth-tab <?= $page <= 1 ? 'text-muted' : '' ?>" <?= $page <= 1 ? 'style="pointer-events:none;opacity:0.5"' : '' ?>>← Prev</a>
  <span class="text-xs text-muted" style="padding:0.5rem">Page <?= (int)$page ?> / <?= (int)$pages ?></span>
  <a href="audit.php?<?= htmlspecialchars(http_build_query($nextQ)) ?>" class="auth-tab <?= $page >= $pages ? 'text-muted' : '' ?>" <?= $page >= $pages ? 'style="pointer-events:none;opacity:0.5"' : '' ?>>Next →</a>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php qb_page_end(); ?>
