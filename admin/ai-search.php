<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireAdmin();

$act = $_POST['action'] ?? '';
$res = null;
if ($act === 'chat') {
    $q = sanitize($_POST['query']);
    // Mocking an AI response for demonstration that integrates nicely.
    $res = "I have analyzed the database for: <strong>$q</strong>. <br><br>The system currently reports strong activity in the seller portals. There are no major fraud flags detected today. Would you like me to generate a detailed report block?";
}

qb_page_start('admin', 'AI Search', 'ai-search.php', false);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">AI System Assistant</h1>
    <p class="page-subtitle">Ask questions about logs, system performance, or user flags.</p>
  </div>
</div>

<div class="card mb-3">
    <form method="post" style="display:flex;gap:0.5rem">
        <input type="hidden" name="action" value="chat">
        <div style="position:relative;flex:1">
            <span style="position:absolute;left:12px;top:10px;color:var(--text-muted)"><?= qb_icon('search') ?></span>
            <input type="text" name="query" class="form-control" style="padding-left:40px" placeholder="e.g. Find sellers with high refund rates..." required>
        </div>
        <button type="submit" class="btn btn-primary">Ask AI</button>
    </form>
</div>

<?php if ($res): ?>
<div class="card" style="background:var(--accent-soft);border-color:var(--accent-light)">
    <div style="display:flex;gap:1rem;align-items:flex-start">
        <div style="background:var(--accent);color:#fff;padding:8px;border-radius:12px">
            <?= qb_icon('zap', 'qb-icon', 24) ?>
        </div>
        <div>
            <h4 class="font-bold mb-1 text-accent">AI Response</h4>
            <div class="text-secondary text-sm" style="line-height:1.6">
                <?= $res ?>
            </div>
            
            <div style="margin-top:1rem;display:flex;gap:0.5rem">
                <button class="btn btn-primary btn-sm">Generate Report</button>
                <button class="btn btn-secondary btn-sm">Clear</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-2 gap-3 mt-4">
    <div>
        <h4 class="font-bold text-sm text-muted text-uppercase mb-2">Suggested Queries</h4>
        <ul style="padding-left:1.5rem;font-size:0.85rem;color:var(--accent)">
            <li>"Show me the top 5 highest grossing sellers"</li>
            <li>"Did any buyer purchase more than 10 products today?"</li>
            <li>"Find recent system anomalies for role Organizer"</li>
        </ul>
    </div>
</div>

<?php qb_page_end(); ?>
