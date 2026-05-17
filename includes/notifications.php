<?php
/**
 * Notification helpers for QR Bazar
 */

function createNotification(int $userId, string $type, string $title, string $body = '', string $link = ''): void {
    try {
        db()->execute(
            'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, $title, $body, $link]
        );
    } catch (Exception $e) {
        // Silently fail — notifications are non-critical
    }
}

function getUnreadCount(int $userId): int {
    try {
        $row = db()->fetchOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function getNotifications(int $userId, int $limit = 20): array {
    try {
        return db()->fetchAll(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?',
            [$userId, $limit]
        );
    } catch (Exception $e) {
        return [];
    }
}

function markAllRead(int $userId): void {
    try {
        db()->execute(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ?',
            [$userId]
        );
    } catch (Exception $e) {}
}

function markRead(int $notifId, int $userId): void {
    try {
        db()->execute(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
            [$notifId, $userId]
        );
    } catch (Exception $e) {}
}

/**
 * Broadcast a notification to all buyers in an event
 */
function broadcastToEventBuyers(int $eventId, string $type, string $title, string $body = '', string $link = ''): void {
    try {
        $buyers = db()->fetchAll(
            "SELECT app_user_id FROM event_participants WHERE event_id = ? AND role_in_event = 'buyer'",
            [$eventId]
        );
        foreach ($buyers as $b) {
            createNotification((int)$b['app_user_id'], $type, $title, $body, $link);
        }
    } catch (Exception $e) {}
}

/**
 * Issue one admission ticket to a buyer (join bazar + notification). Returns ['ok'=>bool, 'error'=>string, 'ticket_id'=>int].
 */
function qb_issue_buyer_ticket(int $buyerId, int $eventId, string $ticketType = 'standard'): array {
    qb_apply_event_ticket_pricing_schema();
    $ev = db()->fetchOne('SELECT * FROM bazar_events WHERE id = ?', [$eventId]);
    if (!$ev) {
        return ['ok' => false, 'error' => 'Bazar not found.', 'ticket_id' => 0];
    }
    $st = (string) ($ev['status'] ?? '');
    if ($st === 'canceled') {
        return ['ok' => false, 'error' => 'This bazar was canceled.', 'ticket_id' => 0];
    }
    if (!in_array($st, ['published', 'live'], true)) {
        return ['ok' => false, 'error' => 'Tickets are not available for this bazar yet.', 'ticket_id' => 0];
    }

    $dup = db()->fetchOne(
        "SELECT id FROM tickets WHERE buyer_id = ? AND event_id = ? AND status = 'active'",
        [$buyerId, $eventId]
    );
    if ($dup) {
        return ['ok' => false, 'error' => 'You already have a ticket for this bazar.', 'ticket_id' => (int) $dup['id']];
    }

    $tsStart = $ev['ticket_sales_start'] ?? null;
    $tsEnd = $ev['ticket_sales_end'] ?? null;
    if ($tsStart && $tsEnd) {
        $now = time();
        $a = strtotime((string) $tsStart);
        $b = strtotime((string) $tsEnd);
        if ($a && $b && ($now < $a || $now > $b)) {
            return ['ok' => false, 'error' => 'Ticket sales are not open for this bazar right now.', 'ticket_id' => 0];
        }
    }

    $code = generateTicketCode();
    $tier = in_array($ticketType, ['standard', 'premium'], true) ? $ticketType : 'standard';
    $face = $tier === 'premium'
        ? (float) ($ev['premium_ticket_price_etb'] ?? 0)
        : (float) ($ev['standard_ticket_price_etb'] ?? 0);

    $cols = ['buyer_id', 'event_id', 'ticket_code', 'qr_data', 'status'];
    $vals = [$buyerId, $eventId, $code, $code, 'active'];
    if (function_exists('qb_has_column') && qb_has_column('tickets', 'ticket_tier')) {
        $cols[] = 'ticket_tier';
        $vals[] = $tier;
    }
    if (function_exists('qb_has_column') && qb_has_column('tickets', 'ticket_type')) {
        $cols[] = 'ticket_type';
        $vals[] = $tier;
    }
    if (function_exists('qb_has_column') && qb_has_column('tickets', 'face_value_etb')) {
        $cols[] = 'face_value_etb';
        $vals[] = $face;
    }
    if (function_exists('qb_has_column') && qb_has_column('tickets', 'ticket_price_etb')) {
        $cols[] = 'ticket_price_etb';
        $vals[] = $face;
    }

    $ph = implode(',', array_fill(0, count($cols), '?'));
    db()->execute('INSERT INTO tickets (' . implode(',', $cols) . ") VALUES ($ph)", $vals);
    $tid = (int) db()->lastInsertId();

    if (function_exists('qb_has_column') && qb_has_column('tickets', 'display_no')) {
        try {
            db()->execute(
                'UPDATE tickets SET display_no = CONCAT(\'QB-\', LPAD(?, 6, \'0\')) WHERE id = ? AND (display_no IS NULL OR display_no = \'\')',
                [(string) $tid, $tid]
            );
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    try {
        db()->execute(
            "INSERT IGNORE INTO event_participants (event_id, app_user_id, role_in_event, status) VALUES (?, ?, 'buyer', 'approved')",
            [$eventId, $buyerId]
        );
    } catch (Throwable $e) {
        /* non-fatal */
    }

    $ename = (string) ($ev['name'] ?? 'Bazar');
    createNotification($buyerId, 'ticket', 'Ticket confirmed — ' . $ename, 'Your admission ticket is ready. Print it from Tickets before you arrive.', 'tickets.php');

    return ['ok' => true, 'error' => '', 'ticket_id' => $tid];
}

/**
 * Notify all buyers registered for the event + optional audit row in event_announcements.
 */
function qb_broadcast_event_announcement(int $organizerAppUserId, int $eventId, string $title, string $body, string $link = ''): int {
    broadcastToEventBuyers($eventId, 'announcement', $title, $body, $link);
    if (!function_exists('qb_table_exists') || !qb_table_exists('event_announcements')) {
        return 0;
    }
    try {
        db()->execute(
            'INSERT INTO event_announcements (event_id, organizer_id, title, body) VALUES (?,?,?,?)',
            [$eventId, $organizerAppUserId, $title, $body]
        );
        return db()->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}
