<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
startSession();
qb_require_seller_portal();
$seller = getCurrentSeller();

$rows = db()->fetchAll(
    "SELECT tx_id, buyer_name, buyer_phone, total_amount, payment_method, payment_status, created_at
     FROM transactions WHERE seller_id = ? ORDER BY created_at DESC LIMIT 8000",
    [(int)$seller['id']],
    'i'
);

$fn = 'q-bazaar-sales-' . preg_replace('/[^a-z0-9_-]/i', '-', $seller['uid']) . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['tx_id', 'buyer_name', 'buyer_phone', 'total_amount_etb', 'payment_method', 'payment_status', 'created_at']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['tx_id'],
        $r['buyer_name'],
        $r['buyer_phone'],
        $r['total_amount'],
        $r['payment_method'],
        $r['payment_status'],
        $r['created_at'],
    ]);
}
fclose($out);
exit;
