<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$method = $_SERVER['REQUEST_METHOD'];
$data   = getJson();

// ── POST: Record transaction ────────────────
if ($method === 'POST') {
    startSession();
    if (!isLoggedIn()) jsonError('Unauthorized', 401);
    if (function_exists('qb_sync_disabled_session_state')) qb_sync_disabled_session_state();
    if (function_exists('qb_is_disabled_session') && qb_is_disabled_session()) {
        jsonError('Your account is disabled. Read-only access only.', 403);
    }

    $sellerUid    = sanitize($data['seller_uid'] ?? '');
    $sellerId     = intval($data['seller_id'] ?? 0);
    $buyerName    = sanitize($data['buyer_name'] ?? 'Anonymous');
    $buyerPhone   = sanitize($data['buyer_phone'] ?? '');
    $totalAmount  = floatval($data['total_amount'] ?? 0);
    $payMethod    = sanitize($data['payment_method'] ?? 'chapa');
    $items        = $data['items'] ?? [];
    $offlineSync  = (bool)($data['offline_sync'] ?? false);
    $eventId      = (int)($data['event_id'] ?? 0);

    if (!$sellerId && $sellerUid) {
        $s = db()->fetchOne("SELECT id FROM sellers WHERE uid = ?", [$sellerUid]);
        $sellerId = $s ? (int)$s['id'] : 0;
    }

    if (!$sellerId || $totalAmount <= 0) jsonError('Invalid transaction data');
    if ($payMethod !== 'chapa') jsonError('Only Chapa payment is enabled');

    $enforceBazar = false;
    if (function_exists('qb_app_users_table_exists') && qb_app_users_table_exists()) {
        $col = db()->fetchOne(
            "SELECT 1 AS o FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sellers' AND COLUMN_NAME = 'allow_direct_sales'"
        );
        $enforceBazar = (bool)$col;
    }
    $evForCheck = $eventId > 0 ? $eventId : null;
    if ($enforceBazar && function_exists('qb_seller_may_complete_sale') && !qb_seller_may_complete_sale((int)$sellerId, $evForCheck)) {
        jsonError(
            'Sales require an active bazar: pass event_id while tickets are live and you are assigned as seller. ' .
            'You can still add products anytime.',
            403
        );
    }

    $txId = generateTxId();

    $hasEventCol = db()->fetchOne(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'event_id'"
    );
    $initialStatus = 'completed';
    if ($hasEventCol && $eventId > 0) {
        $txDbId = db()->insert(
            "INSERT INTO transactions (tx_id, seller_id, buyer_name, buyer_phone, total_amount, payment_method, payment_status, event_id) VALUES (?,?,?,?,?,?,?,?)",
            [$txId, $sellerId, $buyerName, $buyerPhone, $totalAmount, $payMethod, $initialStatus, $eventId],
            'sissdssi'
        );
    } else {
        $txDbId = db()->insert(
            "INSERT INTO transactions (tx_id, seller_id, buyer_name, buyer_phone, total_amount, payment_method, payment_status) VALUES (?,?,?,?,?,?,?)",
            [$txId, $sellerId, $buyerName, $buyerPhone, $totalAmount, $payMethod, $initialStatus],
            'sissdss'
        );
    }
    if (!$txDbId) jsonError('Failed to record transaction', 500);

    if (function_exists('qb_audit_log')) {
        qb_audit_log('transaction.completed', 'transaction', (string)$txDbId, [
            'seller_id' => $sellerId,
            'amount'    => $totalAmount,
            'event_id'  => $eventId,
        ]);
    }
    if (function_exists('qb_fraud_check_seller_tx_velocity')) {
        qb_fraud_check_seller_tx_velocity((int)$sellerId, $totalAmount);
    }

    // Insert items + decrement stock
    foreach ($items as $item) {
        $productId   = intval($item['product_id'] ?? 0);
        $productName = sanitize($item['product_name'] ?? '');
        $unitPrice   = floatval($item['unit_price'] ?? 0);
        $qty         = intval($item['quantity'] ?? 1);
        $subtotal    = floatval($item['subtotal'] ?? $unitPrice * $qty);

        db()->insert(
            "INSERT INTO transaction_items (transaction_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (?,?,?,?,?,?)",
            [$txDbId, $productId ?: null, $productName, $unitPrice, $qty, $subtotal],
            'iisdid'
        );

        if ($productId) {
            db()->execute("UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?", [$qty, $productId], 'ii');
            db()->execute("UPDATE products SET is_available = 0 WHERE id = ? AND stock <= 0", [$productId], 'i');
            db()->execute("UPDATE products SET view_count = view_count + 1 WHERE id = ?", [$productId], 'i');
        }
    }

    // Log analytics event
    db()->insert(
        "INSERT INTO analytics_events (seller_id, event_type, event_hour, event_date) VALUES (?, 'purchase', ?, CURDATE())",
        [$sellerId, (int)date('G')], 'ii'
    );

    jsonSuccess([
        'transaction' => [
            'tx_id'          => $txId,
            'seller_id'      => $sellerId,
            'buyer_name'     => $buyerName,
            'total_amount'   => $totalAmount,
            'payment_method' => $payMethod,
            'items'          => $items,
            'created_at'     => date('Y-m-d H:i:s'),
            'payment_status' => $initialStatus,
        ]
    ], 'Transaction recorded');
}

// ── GET: Seller's transaction history ────────
if ($method === 'GET') {
    startSession();
    if (!isLoggedIn()) jsonError('Unauthorized', 401);
    $sellerId = (int)$_SESSION['seller_id'];
    $limit    = min(intval($_GET['limit'] ?? 20), 100);

    $transactions = db()->fetchAll(
        "SELECT t.*, GROUP_CONCAT(CONCAT(ti.product_name,'×',ti.quantity) SEPARATOR ', ') AS items_summary
         FROM transactions t
         LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
         WHERE t.seller_id = ?
         GROUP BY t.id
         ORDER BY t.created_at DESC LIMIT ?",
        [$sellerId, $limit], 'ii'
    );
    jsonSuccess(['transactions' => $transactions]);
}

jsonError('Invalid method', 405);
