<?php
/**
 * Fraud / risk signals — velocity checks and manual review queue.
 */

function qb_fraud_table_exists(): bool {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $r = db()->fetchOne(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fraud_signals'"
    );
    $c = (bool)$r;
    return $c;
}

function qb_fraud_record_signal(string $type, string $refType, string $refId, int $score, array $meta = []): void {
    if (!qb_fraud_table_exists()) {
        return;
    }
    $j = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
    db()->insert(
        'INSERT INTO fraud_signals (signal_type, ref_type, ref_id, score, meta) VALUES (?,?,?,?,?)',
        [$type, $refType, $refId, $score, $j],
        'sssis'
    );
}

/** High velocity completed TX for same seller in short window → flag */
function qb_fraud_check_seller_tx_velocity(int $sellerId, float $amount): void {
    if (!qb_fraud_table_exists() || $sellerId <= 0) {
        return;
    }
    $n = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM transactions WHERE seller_id = ? AND payment_status = 'completed' AND created_at >= NOW() - INTERVAL 5 MINUTE",
        [$sellerId],
        'i'
    );
    $c = (int)($n['c'] ?? 0);
    if ($c >= 25) {
        qb_fraud_record_signal('velocity_burst', 'seller', (string)$sellerId, 80, ['tx_5m' => $c, 'amount' => $amount]);
    }
    if ($amount > 50000) {
        qb_fraud_record_signal('large_sale', 'seller', (string)$sellerId, 40, ['amount' => $amount]);
    }
}
