<?php
declare(strict_types=1);

function qb_chapa_ready(): bool {
    if (!(defined('CHAPA_ENABLED') && CHAPA_ENABLED)) return false;
    if (!(defined('CHAPA_SECRET_KEY') && CHAPA_SECRET_KEY !== '')) return false;
    if (!(defined('CHAPA_BASE_URL') && CHAPA_BASE_URL !== '')) return false;
    $mode = qb_chapa_mode();
    $secret = (string) CHAPA_SECRET_KEY;
    if ($mode === 'test' && stripos($secret, '_TEST-') === false) return false;
    if ($mode === 'live' && stripos($secret, '_TEST-') !== false) return false;
    return true;
}

function qb_chapa_mode(): string {
    $mode = defined('CHAPA_MODE') ? strtolower(trim((string) CHAPA_MODE)) : 'test';
    return $mode === 'live' ? 'live' : 'test';
}

function qb_chapa_apply_schema(): void {
    db()->execute("
        CREATE TABLE IF NOT EXISTS payment_intents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            intent_id VARCHAR(64) NOT NULL UNIQUE,
            app_user_id INT NOT NULL,
            target_type VARCHAR(40) NOT NULL,
            target_ref VARCHAR(64) NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'ETB',
            provider VARCHAR(20) NOT NULL DEFAULT 'chapa',
            provider_status VARCHAR(32) NOT NULL DEFAULT 'pending',
            provider_tx_ref VARCHAR(128) NULL,
            provider_reference VARCHAR(128) NULL,
            checkout_url VARCHAR(600) NULL,
            metadata_json TEXT NULL,
            paid_at DATETIME NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    if (!function_exists('qb_chapa_try_add_column')) {
        function qb_chapa_try_add_column(string $table, string $column, string $sql): void {
            if (function_exists('qb_has_column') && qb_has_column($table, $column)) return;
            try {
                db()->execute($sql);
            } catch (Throwable $e) {
                $m = strtolower($e->getMessage());
                if (str_contains($m, 'duplicate column') || str_contains($m, 'already exists')) return;
                throw $e;
            }
        }
    }
    qb_chapa_try_add_column('payment_intents', 'consumed_at', "ALTER TABLE payment_intents ADD COLUMN consumed_at DATETIME NULL AFTER paid_at");
    qb_chapa_try_add_column('payment_intents', 'verified_at', "ALTER TABLE payment_intents ADD COLUMN verified_at DATETIME NULL AFTER paid_at");
    qb_chapa_try_add_column('payment_intents', 'last_verify_payload', "ALTER TABLE payment_intents ADD COLUMN last_verify_payload LONGTEXT NULL AFTER metadata_json");
    qb_chapa_try_add_column('payment_intents', 'failure_reason', "ALTER TABLE payment_intents ADD COLUMN failure_reason VARCHAR(255) NULL AFTER provider_status");
    db()->execute("
        CREATE TABLE IF NOT EXISTS payment_verification_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            intent_id VARCHAR(64) NOT NULL,
            tx_ref VARCHAR(128) NULL,
            result_status VARCHAR(32) NOT NULL,
            source VARCHAR(32) NOT NULL,
            response_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pvl_intent (intent_id),
            KEY idx_pvl_tx_ref (tx_ref)
        )
    ");
    db()->execute("
        CREATE TABLE IF NOT EXISTS payment_webhook_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_hash CHAR(64) NOT NULL UNIQUE,
            tx_ref VARCHAR(128) NULL,
            signature_valid TINYINT(1) NOT NULL DEFAULT 0,
            payload_json LONGTEXT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pwe_tx_ref (tx_ref)
        )
    ");
}

function qb_chapa_signature_headers(): array {
    return [
        $_SERVER['HTTP_CHAPA_SIGNATURE'] ?? '',
        $_SERVER['HTTP_X_CHAPA_SIGNATURE'] ?? '',
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
    ];
}

function qb_chapa_validate_webhook_signature(string $raw): bool {
    $key = defined('CHAPA_ENCRYPTION_KEY') ? trim((string) CHAPA_ENCRYPTION_KEY) : '';
    if ($key === '') return false;
    foreach (qb_chapa_signature_headers() as $sig) {
        $sig = trim((string) $sig);
        if ($sig === '') continue;
        $calc = hash_hmac('sha256', $raw, $key);
        if (hash_equals(strtolower($calc), strtolower($sig))) return true;
    }
    return false;
}

function qb_chapa_log_verification(string $intentId, ?string $txRef, string $resultStatus, string $source, array $response): void {
    try {
        db()->execute(
            "INSERT INTO payment_verification_logs (intent_id, tx_ref, result_status, source, response_json) VALUES (?, ?, ?, ?, ?)",
            [$intentId, $txRef, $resultStatus, $source, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    } catch (Throwable $e) {
    }
}

function qb_chapa_http_post(string $path, array $payload): array {
    $url = rtrim((string) CHAPA_BASE_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . CHAPA_SECRET_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => $code, 'error' => $err ?: 'cURL error'];
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) return ['ok' => false, 'status' => $code, 'error' => 'Invalid Chapa response'];
    $ok = ($code >= 200 && $code < 300) && (($json['status'] ?? '') === 'success');
    $err = null;
    if (!$ok) {
        $m = $json['message'] ?? ('HTTP ' . $code);
        $err = is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $m;
    }
    return ['ok' => $ok, 'status' => $code, 'json' => $json, 'error' => $err];
}

function qb_chapa_http_get(string $path): array {
    $url = rtrim((string) CHAPA_BASE_URL, '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . CHAPA_SECRET_KEY, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => $code, 'error' => $err ?: 'cURL error'];
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) return ['ok' => false, 'status' => $code, 'error' => 'Invalid Chapa response'];
    $ok = ($code >= 200 && $code < 300) && (($json['status'] ?? '') === 'success');
    $err = null;
    if (!$ok) {
        $m = $json['message'] ?? ('HTTP ' . $code);
        $err = is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $m;
    }
    return ['ok' => $ok, 'status' => $code, 'json' => $json, 'error' => $err];
}

function qb_payment_intent_create(int $appUserId, string $targetType, string $targetRef, float $amount, array $meta = []): array {
    qb_chapa_apply_schema();
    $intentId = 'CHP_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
    $txRef = 'QB_' . $intentId;
    db()->execute(
        "INSERT INTO payment_intents (intent_id, app_user_id, target_type, target_ref, amount, metadata_json, provider_tx_ref, provider_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
        [$intentId, $appUserId, $targetType, $targetRef, $amount, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $txRef]
    );
    return ['intent_id' => $intentId, 'tx_ref' => $txRef];
}

function qb_payment_intent_get(string $intentId): ?array {
    qb_chapa_apply_schema();
    $r = db()->fetchOne('SELECT * FROM payment_intents WHERE intent_id = ?', [$intentId]);
    return $r ?: null;
}

function qb_chapa_log_transaction(string $txId, int $buyerId, ?int $sellerId, ?int $eventId, string $buyerName, string $buyerPhone, float $amount, string $context): void {
    try {
        if (function_exists('qb_apply_transaction_fee_schema')) {
            qb_apply_transaction_fee_schema();
        }
        $feePct = 0.0;
        $feeAmount = 0.0;
        $sellerNet = round(max(0.0, $amount), 2);
        $isBuyerSellerFlow = $context === 'product_purchase' && $buyerId > 0 && $sellerId !== null && $sellerId > 0;
        if ($isBuyerSellerFlow && function_exists('qb_tx_fee_breakdown')) {
            $fee = qb_tx_fee_breakdown((float) $amount);
            $feePct = (float) ($fee['fee_pct'] ?? 0.0);
            $feeAmount = (float) ($fee['fee_amount'] ?? 0.0);
            $sellerNet = (float) ($fee['seller_net'] ?? $sellerNet);
        }
        db()->execute(
            "INSERT INTO transactions (tx_id, seller_id, buyer_id, event_id, buyer_name, buyer_phone, total_amount, admin_fee_pct, admin_fee_amount, seller_net_amount, payment_method, payment_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'chapa', 'completed')",
            [
                $txId,
                $sellerId !== null && $sellerId > 0 ? $sellerId : null,
                $buyerId > 0 ? $buyerId : null,
                $eventId !== null && $eventId > 0 ? $eventId : null,
                $buyerName !== '' ? $buyerName : 'QR Bazar User',
                $buyerPhone !== '' ? $buyerPhone : null,
                $amount,
                $feePct,
                $feeAmount,
                $sellerNet,
            ]
        );
    } catch (Throwable $e) {
        qb_audit_log('payment.chapa.tx_log_failed', 'transactions', 0, ['tx_id' => $txId, 'context' => $context, 'error' => $e->getMessage()]);
    }
}

function qb_chapa_checkout_start(array $intent, string $email, string $name, string $phone): array {
    $intentId = (string) ($intent['intent_id'] ?? '');
    $amount = (float) ($intent['amount'] ?? 0);
    $targetType = (string) ($intent['target_type'] ?? 'payment');
    $txRef = (string) ($intent['provider_tx_ref'] ?? ('QB_' . $intentId));
    $emailSafe = filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : 'test@gmail.com';
    if (qb_chapa_mode() === 'test') {
        $emailSafe = 'test@gmail.com';
    }
    $nameSafe = trim($name) !== '' ? trim($name) : 'QR Bazar';
    $first = $nameSafe;
    $last = 'Customer';
    if (str_contains($nameSafe, ' ')) {
        $parts = preg_split('/\s+/', $nameSafe, 2);
        $first = trim((string) ($parts[0] ?? 'QR'));
        $last = trim((string) ($parts[1] ?? 'Customer'));
    }
    if ($first === '') $first = 'QR';
    if ($last === '') $last = 'Customer';
    $phoneSafe = preg_replace('/\D+/', '', $phone ?? '');
    if ($phoneSafe === null || strlen($phoneSafe) < 9) $phoneSafe = '0911000000';

    $res = qb_chapa_http_post('/transaction/initialize', [
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => 'ETB',
        'email' => $emailSafe,
        'first_name' => $first,
        'last_name' => $last,
        'phone_number' => $phoneSafe,
        'tx_ref' => $txRef,
        'callback_url' => rtrim((string) APP_URL, '/') . '/api/chapa_callback.php',
        'return_url' => rtrim((string) APP_URL, '/') . '/chapa_return.php?intent=' . rawurlencode($intentId),
        'customization' => ['title' => 'QR Bazar', 'description' => 'Payment for ' . $targetType],
        'meta' => ['mode' => qb_chapa_mode()],
    ]);
    if (!$res['ok']) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Failed to initialize Chapa'), 'response' => (array) ($res['json'] ?? [])];
    $j = (array) ($res['json'] ?? []);
    $checkout = (string) ($j['data']['checkout_url'] ?? '');
    $reference = (string) ($j['data']['reference'] ?? '');
    if ($checkout === '') return ['ok' => false, 'error' => 'No checkout URL returned'];
    db()->execute("UPDATE payment_intents SET checkout_url = ?, provider_reference = ?, provider_status = 'pending' WHERE intent_id = ?", [$checkout, $reference, $intentId]);
    return ['ok' => true, 'checkout_url' => $checkout];
}

function qb_chapa_verify_intent(string $intentId): array {
    $intent = qb_payment_intent_get($intentId);
    if (!$intent) return ['ok' => false, 'error' => 'Intent not found'];
    $currentStatus = (string) ($intent['provider_status'] ?? 'pending');
    if (in_array($currentStatus, ['paid', 'completed', 'fulfilled'], true)) {
        return ['ok' => true, 'intent' => $intent];
    }
    $txRef = (string) ($intent['provider_tx_ref'] ?? '');
    if ($txRef === '') return ['ok' => false, 'error' => 'Missing tx_ref'];
    $res = qb_chapa_http_get('/transaction/verify/' . rawurlencode($txRef));
    if (!$res['ok']) {
        qb_chapa_log_verification($intentId, $txRef, 'verify_failed', 'verify_endpoint', ['ok' => false, 'error' => (string) ($res['error'] ?? 'Verify failed')]);
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Verify failed')];
    }
    $j = (array) ($res['json'] ?? []);
    $remoteStatus = strtolower((string) ($j['data']['status'] ?? ''));
    $paid = $remoteStatus === 'success';
    qb_chapa_log_verification($intentId, $txRef, $remoteStatus !== '' ? $remoteStatus : 'unknown', 'verify_endpoint', $j);
    if (!$paid) {
        $failureReason = (string) ($j['message'] ?? 'not_success');
        db()->execute("UPDATE payment_intents SET provider_status = ?, failure_reason = ?, last_verify_payload = ? WHERE intent_id = ?", [$remoteStatus !== '' ? $remoteStatus : 'pending', $failureReason, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $intentId]);
        return ['ok' => false, 'error' => 'Payment not successful yet'];
    }
    db()->execute("UPDATE payment_intents SET provider_status = 'paid', paid_at = NOW(), verified_at = NOW(), last_verify_payload = ? WHERE intent_id = ? AND provider_status NOT IN ('fulfilled')", [json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $intentId]);
    return ['ok' => true, 'intent' => qb_payment_intent_get($intentId)];
}

function qb_chapa_process_webhook(string $raw): array {
    qb_chapa_apply_schema();
    $eventHash = hash('sha256', $raw);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'http' => 400, 'error' => 'Invalid JSON payload'];
    }
    $txRef = (string) ($payload['tx_ref'] ?? ($payload['trx_ref'] ?? ''));
    if ($txRef === '') {
        return ['ok' => false, 'http' => 400, 'error' => 'Missing tx_ref'];
    }
    $signatureValid = qb_chapa_validate_webhook_signature($raw) ? 1 : 0;
    try {
        db()->execute(
            "INSERT INTO payment_webhook_events (event_hash, tx_ref, signature_valid, payload_json) VALUES (?, ?, ?, ?)",
            [$eventHash, $txRef, $signatureValid, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), 'UNIQUE') !== false) {
            return ['ok' => true, 'http' => 200, 'message' => 'duplicate_ignored'];
        }
        return ['ok' => false, 'http' => 500, 'error' => 'Could not persist webhook event'];
    }
    if ($signatureValid !== 1) {
        return ['ok' => false, 'http' => 401, 'error' => 'Invalid webhook signature'];
    }
    $intent = db()->fetchOne('SELECT * FROM payment_intents WHERE provider_tx_ref = ? LIMIT 1', [$txRef]);
    if (!$intent) {
        return ['ok' => false, 'http' => 404, 'error' => 'Intent not found'];
    }
    $verify = qb_chapa_verify_intent((string) ($intent['intent_id'] ?? ''));
    if (!$verify['ok']) {
        return ['ok' => false, 'http' => 202, 'error' => (string) ($verify['error'] ?? 'Verification pending')];
    }
    $consume = qb_payment_intent_consume((array) ($verify['intent'] ?? $intent));
    if (!$consume['ok']) {
        qb_audit_log('payment.chapa.consume_failed', 'payment_intents', (int) ($intent['id'] ?? 0), ['error' => (string) ($consume['error'] ?? 'unknown')]);
        return ['ok' => false, 'http' => 500, 'error' => (string) ($consume['error'] ?? 'Failed to fulfill')];
    }
    qb_audit_log('payment.chapa.fulfilled', 'payment_intents', (int) ($intent['id'] ?? 0), ['target_type' => (string) ($intent['target_type'] ?? '')]);
    return ['ok' => true, 'http' => 200, 'message' => 'processed'];
}

function qb_chapa_list_banks(?string $currency = null): array {
    if (!qb_chapa_ready()) {
        return ['ok' => false, 'error' => 'Chapa is not configured'];
    }
    $res = qb_chapa_http_get('/banks');
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not fetch banks')];
    }
    $json = (array) ($res['json'] ?? []);
    $banks = $json['data'] ?? [];
    if (!is_array($banks)) {
        $banks = [];
    }

    $cur = strtoupper(trim((string) ($currency ?? '')));
    if ($cur !== '') {
        $banks = array_values(array_filter($banks, static function ($row) use ($cur): bool {
            if (!is_array($row)) return false;
            $rowCurrency = strtoupper(trim((string) ($row['currency'] ?? '')));
            return $rowCurrency === '' || $rowCurrency === $cur;
        }));
    }

    return ['ok' => true, 'banks' => $banks, 'raw' => $json];
}

function qb_payment_intent_consume(array $intent): array {
    $intentId = (string) ($intent['intent_id'] ?? '');
    $txRefForHistory = (string) ($intent['provider_tx_ref'] ?? '');
    $intentType = (string) ($intent['target_type'] ?? '');
    $intentUser = (int) ($intent['app_user_id'] ?? 0);
    if ($intentId !== '') {
        $fresh = qb_payment_intent_get($intentId);
        if ($fresh && !empty($fresh['consumed_at'])) {
            return ['ok' => true, 'redirect' => (string) (($fresh['target_type'] ?? '') === 'role_request' ? 'profile.php?role_req_ok=1' : 'home.php?pay_ok=1')];
        }
    }
    $type = (string) ($intent['target_type'] ?? '');
    $meta = json_decode((string) ($intent['metadata_json'] ?? '{}'), true);
    if (!is_array($meta)) $meta = [];

    if ($type === 'ticket_purchase') {
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $eventId = (int) ($meta['event_id'] ?? 0);
        $ret = (string) ($meta['redirect'] ?? 'home.php');
        $ticketType = (string) ($meta['ticket_type'] ?? 'standard');
        $r = qb_issue_buyer_ticket($uid, $eventId, $ticketType);
        if (!$r['ok']) {
            $err = (string) ($r['error'] ?? 'Ticket issue failed');
            $existingTicketId = (int) ($r['ticket_id'] ?? 0);
            // Idempotent fulfill: if buyer already has active ticket for this event, treat as successful.
            if ($existingTicketId > 0 && stripos($err, 'already have a ticket') !== false) {
                $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
                qb_chapa_log_transaction(
                    generateTxId(),
                    $uid,
                    null,
                    $eventId,
                    (string) ($u['display_name'] ?? 'Buyer'),
                    (string) ($u['phone'] ?? ''),
                    (float) ($intent['amount'] ?? 0),
                    'ticket_purchase_existing'
                );
                if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled_existing_ticket', 'fulfill', ['target_type' => 'ticket_purchase', 'ticket_id' => $existingTicketId]);
                if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
                qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType, 'mode' => 'existing_ticket'], $intentUser, 'payment_intent', $intentId);
                return ['ok' => true, 'redirect' => $ret . '?ticket_ok=1&ticket_exists=1'];
            }
            return ['ok' => false, 'error' => $err];
        }
        $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
        qb_chapa_log_transaction(
            generateTxId(),
            $uid,
            null,
            $eventId,
            (string) ($u['display_name'] ?? 'Buyer'),
            (string) ($u['phone'] ?? ''),
            (float) ($intent['amount'] ?? 0),
            'ticket_purchase'
        );
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'ticket_purchase']);
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType], $intentUser, 'payment_intent', $intentId);
        return ['ok' => true, 'redirect' => $ret . '?ticket_ok=1'];
    }

    if ($type === 'role_request') {
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $want = (string) ($meta['want'] ?? '');
        if (!in_array($want, ['seller', 'organizer'], true)) return ['ok' => false, 'error' => 'Invalid role request'];
        db()->execute('UPDATE app_users SET role_requested = ?, role_request_status = ? WHERE id = ?', [$want, 'pending', $uid]);
        $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
        qb_chapa_log_transaction(
            generateTxId(),
            $uid,
            null,
            null,
            (string) ($u['display_name'] ?? 'Buyer'),
            (string) ($u['phone'] ?? ''),
            (float) ($intent['amount'] ?? 0),
            'role_request'
        );
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'role_request']);
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType], $intentUser, 'payment_intent', $intentId);
        return ['ok' => true, 'redirect' => 'profile.php?role_req_ok=1'];
    }

    if ($type === 'seller_event_apply') {
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $eventId = (int) ($meta['event_id'] ?? 0);
        if ($uid <= 0 || $eventId <= 0) return ['ok' => false, 'error' => 'Invalid seller application intent'];
        $exists = db()->fetchOne("SELECT id FROM event_participants WHERE event_id = ? AND app_user_id = ? AND role_in_event = 'seller' LIMIT 1", [$eventId, $uid]);
        if (!$exists) {
            $seller = db()->fetchOne("SELECT * FROM sellers WHERE app_user_id = ? LIMIT 1", [$uid]);
            $snapJson = qb_encode_categories_json(qb_seller_categories_from_row((array) ($seller ?: [])));
            $visibilityMode = (string) ($meta['application_visibility_mode'] ?? 'selected');
            if (!in_array($visibilityMode, ['selected', 'all'], true)) {
                $visibilityMode = 'selected';
            }
            $applicationProducts = [];
            if (isset($meta['application_products']) && is_array($meta['application_products'])) {
                foreach ($meta['application_products'] as $pr) {
                    if (!is_array($pr)) {
                        continue;
                    }
                    $pid = (int) ($pr['id'] ?? 0);
                    $pname = trim((string) ($pr['name'] ?? ''));
                    $pcat = trim((string) ($pr['category'] ?? ''));
                    if ($pid <= 0 || $pname === '') {
                        continue;
                    }
                    $applicationProducts[] = ['id' => $pid, 'name' => $pname, 'category' => $pcat];
                }
            }
            $productsJson = json_encode($applicationProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hasSnapCat = qb_has_column('event_participants', 'application_categories_json');
            $hasSnapProducts = qb_has_column('event_participants', 'application_products_json');
            $hasVisibilityMode = qb_has_column('event_participants', 'application_visibility_mode');
            if ($hasSnapCat && $hasSnapProducts && $hasVisibilityMode) {
                db()->execute(
                    "INSERT INTO event_participants (event_id, app_user_id, role_in_event, status, application_categories_json, application_products_json, application_visibility_mode) VALUES (?, ?, 'seller', 'pending', ?, ?, ?)",
                    [$eventId, $uid, $snapJson, $productsJson, $visibilityMode]
                );
            } elseif ($hasSnapCat && $hasSnapProducts) {
                db()->execute(
                    "INSERT INTO event_participants (event_id, app_user_id, role_in_event, status, application_categories_json, application_products_json) VALUES (?, ?, 'seller', 'pending', ?, ?)",
                    [$eventId, $uid, $snapJson, $productsJson]
                );
            } elseif ($hasSnapCat) {
                db()->execute("INSERT INTO event_participants (event_id, app_user_id, role_in_event, status, application_categories_json) VALUES (?, ?, 'seller', 'pending', ?)", [$eventId, $uid, $snapJson]);
            } else {
                db()->execute("INSERT INTO event_participants (event_id, app_user_id, role_in_event, status) VALUES (?, ?, 'seller', 'pending')", [$eventId, $uid]);
            }
        }
        $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
        $seller = db()->fetchOne('SELECT id FROM sellers WHERE app_user_id = ? LIMIT 1', [$uid]) ?: [];
        qb_chapa_log_transaction(
            generateTxId(),
            $uid,
            isset($seller['id']) ? (int) $seller['id'] : null,
            $eventId,
            (string) ($u['display_name'] ?? 'Seller'),
            (string) ($u['phone'] ?? ''),
            (float) ($intent['amount'] ?? 0),
            'seller_event_apply'
        );
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'seller_event_apply']);
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType], $intentUser, 'payment_intent', $intentId);
        return ['ok' => true, 'redirect' => APP_URL . '/seller/events.php?pay_ok=1'];
    }

    if ($type === 'promo_paid') {
        $postId = (int) ($meta['promo_post_id'] ?? 0);
        if ($postId <= 0) return ['ok' => false, 'error' => 'Invalid promo payment intent'];
        $autoPublish = qb_setting_get_bool('promo_auto_publish_paid', true);
        if ($autoPublish) {
            $ok = qb_promo_post_set_status($postId, 'active', 1, null, null);
            if (!$ok) return ['ok' => false, 'error' => 'Could not auto-activate paid promo'];
        } else {
            $ok = qb_promo_post_set_status($postId, 'pending', 1, null, null);
            if (!$ok) return ['ok' => false, 'error' => 'Could not mark paid promo pending'];
        }
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
        qb_chapa_log_transaction(
            generateTxId(),
            $uid,
            null,
            null,
            (string) ($u['display_name'] ?? 'Owner'),
            (string) ($u['phone'] ?? ''),
            (float) ($intent['amount'] ?? 0),
            'promo_paid'
        );
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'promo_paid']);
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType], $intentUser, 'payment_intent', $intentId);
        $ownerType = (string) ($meta['owner_type'] ?? 'seller');
        $redirect = $ownerType === 'organization' ? APP_URL . '/organizer/promotion_create.php?ok=1' : APP_URL . '/seller/promotion_create.php?ok=1';
        return ['ok' => true, 'redirect' => $redirect];
    }

    if ($type === 'promo_extend') {
        $postId = (int) ($meta['promo_post_id'] ?? 0);
        $days = (int) ($meta['extension_days'] ?? 1);
        if ($postId <= 0 || $days < 1) return ['ok' => false, 'error' => 'Invalid promo extension intent'];
        $promo = qb_promo_post_get($postId);
        if (!$promo) return ['ok' => false, 'error' => 'Promo not found'];
        $currentExpiry = $promo['expires_at'] ? strtotime($promo['expires_at']) : time();
        if ($currentExpiry < time()) {
            $currentExpiry = time();
        }
        $newExpiry = date('Y-m-d H:i:s', $currentExpiry + ($days * 86400));
        db()->execute("UPDATE promo_posts SET expires_at = ?, status = 'active' WHERE id = ?", [$newExpiry, $postId]);
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
        qb_chapa_log_transaction(generateTxId(), $uid, null, null, (string) ($u['display_name'] ?? 'Owner'), (string) ($u['phone'] ?? ''), (float) ($intent['amount'] ?? 0), 'promo_extend');
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'promo_extend', 'extension_days' => $days]);
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        $ownerType = (string) ($meta['owner_type'] ?? 'seller');
        $redirect = $ownerType === 'organization' ? APP_URL . '/organizer/promotion_create.php?ext_ok=1' : APP_URL . '/seller/promotion_create.php?ext_ok=1';
        return ['ok' => true, 'redirect' => $redirect];
    }

    if ($type === 'seller_product_slots') {
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $sellerId = (int) ($meta['seller_id'] ?? 0);
        $slotsQty = max(1, (int) ($meta['slots_qty'] ?? 1));
        $unitFee = max(0.0, (float) ($meta['unit_fee_etb'] ?? 0));
        if ($uid <= 0 || $sellerId <= 0 || $intentId === '') {
            return ['ok' => false, 'error' => 'Invalid slot purchase intent'];
        }
        $seller = db()->fetchOne('SELECT id FROM sellers WHERE id = ? AND app_user_id = ? LIMIT 1', [$sellerId, $uid]);
        if (!$seller) {
            return ['ok' => false, 'error' => 'Seller account not found for slot purchase'];
        }
        if (function_exists('qb_apply_seller_product_slot_schema')) {
            qb_apply_seller_product_slot_schema();
        }
        db()->execute(
            "INSERT INTO seller_product_slot_payments (intent_id, app_user_id, seller_id, slots_qty, unit_fee_etb, total_amount, status, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
             ON DUPLICATE KEY UPDATE status = 'paid', paid_at = NOW(), slots_qty = VALUES(slots_qty), unit_fee_etb = VALUES(unit_fee_etb), total_amount = VALUES(total_amount)",
            [$intentId, $uid, $sellerId, $slotsQty, $unitFee, (float) ($intent['amount'] ?? ($slotsQty * $unitFee))]
        );
        $u = db()->fetchOne('SELECT display_name, phone FROM app_users WHERE id = ? LIMIT 1', [$uid]) ?: [];
        qb_chapa_log_transaction(
            generateTxId(),
            $uid,
            $sellerId,
            null,
            (string) ($u['display_name'] ?? 'Seller'),
            (string) ($u['phone'] ?? ''),
            (float) ($intent['amount'] ?? 0),
            'seller_product_slots'
        );
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'seller_product_slots', 'slots_qty' => $slotsQty]);
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType, 'slots_qty' => $slotsQty], $intentUser, 'payment_intent', $intentId);
        $redirectBase = (string) ($meta['redirect'] ?? (APP_URL . '/seller/products.php'));
        $sep = str_contains($redirectBase, '?') ? '&' : '?';
        return ['ok' => true, 'redirect' => $redirectBase . $sep . 'slots_paid=1&slots_qty=' . $slotsQty];
    }

    if ($type === 'product_purchase') {
        $uid = (int) ($intent['app_user_id'] ?? 0);
        $sellerId = (int) ($meta['seller_id'] ?? 0);
        $productId = (int) ($meta['product_id'] ?? 0);
        $qty = max(1, (int) ($meta['qty'] ?? 1));
        $unitPrice = (float) ($meta['unit_price'] ?? 0);
        $eventId = (int) ($meta['event_id'] ?? 0);
        $buyerName = (string) ($meta['buyer_name'] ?? 'Buyer');
        $productName = (string) ($meta['product_name'] ?? 'Product');
        $txId = (string) ($meta['tx_id'] ?? generateTxId());
        $cartItems = isset($meta['cart_items']) && is_array($meta['cart_items']) ? $meta['cart_items'] : [];
        if ($uid <= 0 || $sellerId <= 0 || ($productId <= 0 && $cartItems === [])) return ['ok' => false, 'error' => 'Invalid product purchase intent'];
        $mode = function_exists('qb_event_mode_get') ? qb_event_mode_get($uid) : null;
        $gateEventId = ((string) ($mode['mode_source'] ?? '') === 'ticket_scan') ? (int) ($mode['event_id'] ?? 0) : 0;
        if ($gateEventId <= 0) {
            return ['ok' => false, 'error' => 'Gate scan required before checkout fulfillment'];
        }
        if (function_exists('qb_seller_gate_is_unlocked') && !qb_seller_gate_is_unlocked($sellerId, $gateEventId)) {
            return ['ok' => false, 'error' => 'Seller gate validation missing for this event'];
        }
        if ($eventId > 0 && $gateEventId > 0 && $eventId !== $gateEventId) {
            return ['ok' => false, 'error' => 'Checkout event mismatch with gate-scanned event'];
        }

        $already = db()->fetchOne('SELECT id FROM transactions WHERE tx_id = ? LIMIT 1', [$txId]);
        if (!$already) {
            $lines = [];
            $total = 0.0;
            if ($cartItems !== []) {
                foreach ($cartItems as $line) {
                    $lid = (int) ($line['product_id'] ?? 0);
                    $lq = max(1, (int) ($line['qty'] ?? 1));
                    $lu = (float) ($line['unit_price'] ?? 0);
                    $ln = (string) ($line['product_name'] ?? 'Product');
                    if ($lid <= 0) {
                        continue;
                    }
                    $prod = db()->fetchOne("SELECT id, stock, name FROM products WHERE id = ? AND seller_id = ? LIMIT 1", [$lid, $sellerId]);
                    if (!$prod || (int) ($prod['stock'] ?? 0) < $lq) {
                        return ['ok' => false, 'error' => 'One or more cart items are no longer available'];
                    }
                    if ($lu <= 0) {
                        return ['ok' => false, 'error' => 'Invalid cart pricing'];
                    }
                    $sub = round($lu * $lq, 2);
                    $lines[] = ['product_id' => $lid, 'product_name' => $ln !== '' ? $ln : (string) ($prod['name'] ?? 'Product'), 'qty' => $lq, 'unit_price' => $lu, 'subtotal' => $sub];
                    $total += $sub;
                }
            } else {
                $prod = db()->fetchOne("SELECT id, stock, name FROM products WHERE id = ? AND seller_id = ? LIMIT 1", [$productId, $sellerId]);
                if (!$prod || (int) ($prod['stock'] ?? 0) < $qty) return ['ok' => false, 'error' => 'Product is no longer available in requested quantity'];
                $total = round($unitPrice * $qty, 2);
                $lines[] = ['product_id' => $productId, 'product_name' => $productName !== '' ? $productName : (string) ($prod['name'] ?? 'Product'), 'qty' => $qty, 'unit_price' => $unitPrice, 'subtotal' => $total];
            }
            if ($lines === []) {
                return ['ok' => false, 'error' => 'Cart has no valid items'];
            }
            if (function_exists('qb_apply_transaction_fee_schema')) {
                qb_apply_transaction_fee_schema();
            }
            $fee = function_exists('qb_tx_fee_breakdown')
                ? qb_tx_fee_breakdown((float) $total)
                : ['fee_pct' => 0.0, 'fee_amount' => 0.0, 'seller_net' => (float) $total];
            db()->execute(
                "INSERT INTO transactions (tx_id, seller_id, buyer_id, event_id, buyer_name, total_amount, admin_fee_pct, admin_fee_amount, seller_net_amount, payment_method, payment_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'chapa', 'completed')",
                [
                    $txId,
                    $sellerId,
                    $uid,
                    $eventId > 0 ? $eventId : null,
                    $buyerName,
                    $total,
                    (float) ($fee['fee_pct'] ?? 0.0),
                    (float) ($fee['fee_amount'] ?? 0.0),
                    (float) ($fee['seller_net'] ?? $total),
                ]
            );
            $newTx = (int) db()->lastInsertId();
            foreach ($lines as $line) {
                db()->execute(
                    "INSERT INTO transaction_items (transaction_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)",
                    [$newTx, (int) $line['product_id'], (string) $line['product_name'], (float) $line['unit_price'], (int) $line['qty'], (float) $line['subtotal']]
                );
                db()->execute("UPDATE products SET stock = stock - ? WHERE id = ?", [(int) $line['qty'], (int) $line['product_id']]);
                db()->execute("INSERT INTO analytics_events (seller_id, event_type, product_id, event_hour, event_date) VALUES (?, 'purchase', ?, HOUR(NOW()), CURDATE())", [$sellerId, (int) $line['product_id']]);
            }
            createNotification($uid, 'purchase', 'Purchase Successful', "Order paid successfully. Reference: $txId", "receipt.php?tx=$txId");
        }
        if ($intentId !== '') db()->execute("UPDATE payment_intents SET consumed_at = NOW(), provider_status = 'fulfilled' WHERE intent_id = ? AND consumed_at IS NULL", [$intentId]);
        if ($intentId !== '') qb_chapa_log_verification($intentId, $txRefForHistory, 'fulfilled', 'fulfill', ['target_type' => 'product_purchase', 'tx_id' => $txId]);
        qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType, 'tx_id' => $txId], $intentUser, 'payment_intent', $intentId);
        return ['ok' => true, 'redirect' => APP_URL . '/buyer/receipt.php?tx=' . rawurlencode($txId) . '&paid_ok=1&items=' . count($lines)];
    }

    qb_track_event('payment.intent.fulfilled', ['intent_id' => $intentId, 'type' => $intentType, 'mode' => 'default_redirect'], $intentUser, 'payment_intent', $intentId);
    return ['ok' => true, 'redirect' => 'home.php?pay_ok=1'];
}
