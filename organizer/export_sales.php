<?php
/**
 * CSV export of completed transactions for one bazar (organizer-access only).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
requireOrganizer();

$uid = (int) $_SESSION['app_user_id'];
$ew = qb_organizer_event_alias_access_sql('e');
$eb = qb_organizer_event_access_bind($uid);
$events = db()->fetchAll("SELECT e.id, e.name FROM bazar_events e WHERE $ew ORDER BY e.name", $eb);

$eventId = qb_organizer_resolve_event_id(
    isset($_GET['event']) ? (int) $_GET['event'] : null,
    $events
);

if ($eventId <= 0 || !qb_organizer_event_id_allowed($eventId, $events)) {
    header('Location: ' . APP_URL . '/organizer/dashboard.php?notice=event_not_found', true, 302);
    exit;
}

$rows = db()->fetchAll(
    "SELECT t.tx_id, t.total_amount, t.payment_method, t.payment_status, t.buyer_name, t.created_at,
            s.market_name AS seller_market
     FROM transactions t
     LEFT JOIN sellers s ON s.id = t.seller_id
     WHERE t.event_id = ? AND t.payment_status = 'completed'
     ORDER BY t.created_at DESC",
    [$eventId]
);

$ev = db()->fetchOne('SELECT name, slug FROM bazar_events WHERE id = ?', [$eventId]);
$safeSlug = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($ev['slug'] ?? 'event')) . '-' . $eventId;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $safeSlug . '-sales.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['tx_id', 'total_amount_etb', 'payment_method', 'buyer_name', 'seller_market', 'created_at']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['tx_id'] ?? '',
        $r['total_amount'] ?? '',
        $r['payment_method'] ?? '',
        $r['buyer_name'] ?? '',
        $r['seller_market'] ?? '',
        $r['created_at'] ?? '',
    ]);
}
fclose($out);
exit;
