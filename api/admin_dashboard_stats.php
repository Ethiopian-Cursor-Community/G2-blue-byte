<?php
/**
 * JSON metrics for admin dashboard live refresh (auth: super_admin).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

startSession();
if (!function_exists('currentRole') || currentRole() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $users = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM app_users')['c'] ?? 0);
    $events = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM bazar_events')['c'] ?? 0);
    $eventsLive = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status IN ('published','live')")['c'] ?? 0);
    $eventsDraft = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status = 'draft'")['c'] ?? 0);
    $products = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0);
    $revenue = (float) (db()->fetchOne("SELECT COALESCE(SUM(total_amount), 0) AS t FROM transactions WHERE payment_status = 'completed'")['t'] ?? 0);
    $txN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status = 'completed'")['c'] ?? 0);
    $revToday = (float) (db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS t FROM transactions WHERE payment_status='completed' AND DATE(created_at)=CURDATE()")['t'] ?? 0);
    $txToday = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status='completed' AND DATE(created_at)=CURDATE()")['c'] ?? 0);
    $txWeek = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE payment_status='completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")['c'] ?? 0);
    $ticketsN = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM tickets')['c'] ?? 0);
    $scansN = 0;
    $ratingsN = 0;
    try {
        $scansN = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM analytics_events WHERE event_type='qr_scan'")['c'] ?? 0);
    } catch (Throwable $e) { /* */ }
    try {
        $ratingsN = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM ratings')['c'] ?? 0);
    } catch (Throwable $e) { /* */ }

    $pendingProducts = function_exists('qb_pending_product_count') ? qb_pending_product_count() : 0;
    $pendingRoles = 0;
    if (function_exists('qb_role_request_columns_ready') && qb_role_request_columns_ready()) {
        try {
            $pendingRoles = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role_request_status = 'pending'")['c'] ?? 0);
        } catch (Throwable $e) { /* */ }
    }

    $sellerProfiles = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM sellers WHERE is_active = 1')['c'] ?? 0);
    $avgOrder = (float) (db()->fetchOne("SELECT COALESCE(AVG(total_amount), 0) AS a FROM transactions WHERE payment_status = 'completed'")['a'] ?? 0);
    $newUsersWeek = (int) (db()->fetchOne('SELECT COUNT(*) AS c FROM app_users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')['c'] ?? 0);

    $buyers = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role = 'buyer'")['c'] ?? 0);
    $sellers = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role = 'seller'")['c'] ?? 0);
    $organizers = (int) (db()->fetchOne("SELECT COUNT(*) AS c FROM app_users WHERE role = 'organizer'")['c'] ?? 0);

    $chartData = db()->fetchAll('
        SELECT DATE(created_at) AS dt, SUM(total_amount) AS rev
        FROM transactions
        WHERE payment_status = \'completed\' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY dt ORDER BY dt ASC
    ');
    $dates = [];
    $revs = [];
    foreach ($chartData as $d) {
        $dates[] = $d['dt'];
        $revs[] = (float) $d['rev'];
    }

    $txCountData = db()->fetchAll('
        SELECT DATE(created_at) AS dt, COUNT(*) AS cnt
        FROM transactions
        WHERE payment_status = \'completed\' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY dt ORDER BY dt ASC
    ');
    $txDates = [];
    $txCounts = [];
    foreach ($txCountData as $r) {
        $txDates[] = $r['dt'];
        $txCounts[] = (int) $r['cnt'];
    }

    $eventStatusRows = db()->fetchAll('SELECT status, COUNT(*) AS c FROM bazar_events GROUP BY status');
    $evLabels = [];
    $evCounts = [];
    foreach ($eventStatusRows as $row) {
        $evLabels[] = $row['status'];
        $evCounts[] = (int) $row['c'];
    }
    if (empty($evLabels)) {
        $evLabels = ['—'];
        $evCounts = [0];
    }

    echo json_encode([
        'ok' => true,
        'ts' => time(),
        'stats' => [
            'users' => $users,
            'events' => $events,
            'eventsLive' => $eventsLive,
            'eventsDraft' => $eventsDraft,
            'products' => $products,
            'revenue' => $revenue,
            'txN' => $txN,
            'revToday' => $revToday,
            'txToday' => $txToday,
            'txWeek' => $txWeek,
            'ticketsN' => $ticketsN,
            'scansN' => $scansN,
            'ratingsN' => $ratingsN,
            'pendingProducts' => $pendingProducts,
            'pendingRoles' => $pendingRoles,
            'sellerProfiles' => $sellerProfiles,
            'avgOrder' => $avgOrder,
            'newUsersWeek' => $newUsersWeek,
            'buyers' => $buyers,
            'sellers' => $sellers,
            'organizers' => $organizers,
        ],
        'charts' => [
            'rev' => ['labels' => $dates, 'values' => $revs],
            'tx' => ['labels' => $txDates, 'values' => $txCounts],
            'roles' => [$buyers, $sellers, $organizers],
            'eventsByStatus' => ['labels' => $evLabels, 'counts' => $evCounts],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
