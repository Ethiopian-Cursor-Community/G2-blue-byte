<?php
/**
 * Admission tiers: Standard (single entry), Premium & VIP (re-entry for full event),
 * Day pass (re-entry on event day only). Organizer gate scan updates rows.
 */
declare(strict_types=1);

/** @return list<string> */
function qb_ticket_tier_values(): array {
    return ['standard', 'premium', 'vip', 'day_pass'];
}

function qb_ticket_normalize_tier(?string $raw): string {
    $t = strtolower(trim((string) ($raw ?? '')));
    return in_array($t, qb_ticket_tier_values(), true) ? $t : 'standard';
}

function qb_ticket_tier_label(string $tier): string {
    return match (qb_ticket_normalize_tier($tier)) {
        'premium' => 'Premium',
        'vip' => 'VIP',
        'day_pass' => 'Day pass',
        default => 'Standard',
    };
}

/**
 * Short hint for buyers (printed / My tickets).
 */
function qb_ticket_tier_rules_hint(string $tier): string {
    return match (qb_ticket_normalize_tier($tier)) {
        'premium', 'vip' => 'Re-entry allowed for the full event.',
        'day_pass' => 'Unlimited scans on the event day only.',
        default => 'Single entry — after one gate scan this ticket is used.',
    };
}

/**
 * Whether a ticket is still usable at the gate right now.
 *
 * @return array{ok:bool,reason:string}
 */
function qb_ticket_gate_eligibility(array $ticket, array $event): array {
    $st = (string) ($ticket['status'] ?? '');
    if ($st === 'cancelled') {
        return ['ok' => false, 'reason' => 'Ticket cancelled.'];
    }
    if ($st === 'used') {
        return ['ok' => false, 'reason' => 'Ticket already used.'];
    }
    if ($st !== 'active') {
        return ['ok' => false, 'reason' => 'Invalid ticket.'];
    }

    $tier = qb_ticket_normalize_tier($ticket['ticket_tier'] ?? 'standard');
    $now = time();
    $es = !empty($event['event_start']) ? strtotime((string) $event['event_start']) : 0;
    $ee = !empty($event['event_end']) ? strtotime((string) $event['event_end']) : 0;
    if ($es && $now < $es) {
        return ['ok' => false, 'reason' => 'Event has not started yet.'];
    }
    if ($ee && $now > $ee) {
        return ['ok' => false, 'reason' => 'Event has ended.'];
    }

    $scans = (int) ($ticket['gate_scan_count'] ?? 0);
    if ($tier === 'standard') {
        if ($scans >= 1) {
            return ['ok' => false, 'reason' => 'Standard ticket already scanned.'];
        }

        return ['ok' => true, 'reason' => ''];
    }
    if ($tier === 'premium' || $tier === 'vip') {
        return ['ok' => true, 'reason' => ''];
    }
    if ($tier === 'day_pass') {
        // Valid on any calendar day within the event window (start date → end date inclusive).
        $nowDate   = date('Y-m-d', $now);
        $startDate = $es ? date('Y-m-d', $es) : null;
        $endDate   = $ee ? date('Y-m-d', $ee) : $startDate;

        // If no event dates set, allow through.
        if ($startDate === null) {
            return ['ok' => true, 'reason' => ''];
        }
        if ($nowDate < $startDate || $nowDate > $endDate) {
            return ['ok' => false, 'reason' => 'Day pass is only valid on a bazar calendar day (' . $startDate . ($startDate !== $endDate ? ' – ' . $endDate : '') . ').'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    return ['ok' => false, 'reason' => 'Unknown ticket type.'];
}

/**
 * Record a successful gate scan. Caller must verify organizer access to the event.
 *
 * @return array{ok:bool,message:string}
 */
function qb_ticket_record_gate_scan(array $ticket, array $event): array {
    $check = qb_ticket_gate_eligibility($ticket, $event);
    if (!$check['ok']) {
        return ['ok' => false, 'message' => $check['reason']];
    }

    $id = (int) ($ticket['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Invalid ticket.'];
    }

    $tier = qb_ticket_normalize_tier($ticket['ticket_tier'] ?? 'standard');
    $prev = (int) ($ticket['gate_scan_count'] ?? 0);
    $next = $prev + 1;

    if (qb_has_column('tickets', 'gate_scan_count')) {
        if ($tier === 'standard') {
            db()->execute(
                "UPDATE tickets SET gate_scan_count = ?, status = 'used', used_at = COALESCE(used_at, NOW()) WHERE id = ? AND status = 'active'",
                [$next, $id]
            );

            qb_ticket_after_gate_mode_activate($ticket, $event);
            return ['ok' => true, 'message' => 'Admitted — standard (single use). Event mode activated for buyer.'];
        }
        db()->execute(
            'UPDATE tickets SET gate_scan_count = ? WHERE id = ? AND status = ?',
            [$next, $id, 'active']
        );
        qb_ticket_after_gate_mode_activate($ticket, $event);
        return ['ok' => true, 'message' => 'Admitted — ' . qb_ticket_tier_label($tier) . '. Event mode activated for buyer.'];
    }

    // Legacy DB without gate_scan_count: only standard consumes the ticket.
    if ($tier === 'standard') {
        db()->execute("UPDATE tickets SET status = 'used', used_at = NOW() WHERE id = ? AND status = 'active'", [$id]);
        qb_ticket_after_gate_mode_activate($ticket, $event);
        return ['ok' => true, 'message' => 'Admitted — standard (single use). Event mode activated for buyer.'];
    }
    qb_ticket_after_gate_mode_activate($ticket, $event);
    return ['ok' => true, 'message' => 'Admitted — ' . qb_ticket_tier_label($tier) . ' (run migrate_ticket_gate_day_pass.php for scan counts). Event mode activated for buyer.'];
}

function qb_ticket_after_gate_mode_activate(array $ticket, array $event): void {
    $buyerId = (int) ($ticket['buyer_id'] ?? 0);
    $eventId = (int) ($event['id'] ?? 0);
    if ($buyerId > 0 && $eventId > 0 && function_exists('qb_event_mode_set')) {
        qb_event_mode_set($buyerId, $eventId, 'ticket_scan');
    }
}

/**
 * Buyer-facing status label for an active ticket row.
 */
function qb_ticket_buyer_status_label(array $ticket, array $event): string {
    if (($ticket['status'] ?? '') === 'used') {
        return 'Used';
    }
    if (($ticket['status'] ?? '') === 'cancelled') {
        return 'Cancelled';
    }
    $tier = qb_ticket_normalize_tier($ticket['ticket_tier'] ?? 'standard');
    $now = time();
    if (!empty($event['event_end'])) {
        $ee = strtotime((string) $event['event_end']);
        if ($ee && $now > $ee) {
            return 'Expired';
        }
    }
    if ($tier === 'day_pass' && !empty($event['event_start'])) {
        $es = strtotime((string) $event['event_start']);
        $dayEnd = strtotime(date('Y-m-d 23:59:59', $es));
        if ($now > $dayEnd) {
            return 'Expired';
        }
    }

    return 'Valid';
}

/**
 * Dashboard numbers + ticket snippets for buyer home.
 *
 * @return array{
 *   live_bazars:int,
 *   active_tickets:int,
 *   used_tickets:int,
 *   ticket_snippets:list<array{event_name:string,tier_label:string,status:string,href:string}>
 * }
 */
function qb_buyer_home_dashboard(int $buyerId): array {
    $out = [
        'live_bazars' => 0,
        'active_tickets' => 0,
        'used_tickets' => 0,
        'ticket_snippets' => [],
    ];
    try {
        $r = db()->fetchOne("SELECT COUNT(*) AS c FROM bazar_events WHERE status IN ('published','live')");
        $out['live_bazars'] = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        $out['live_bazars'] = 0;
    }

    try {
        $r = db()->fetchOne(
            "SELECT COUNT(*) AS c FROM tickets WHERE buyer_id = ? AND status <> 'cancelled'",
            [$buyerId]
        );
        // Keep legacy key name for compatibility, but value now means "owned tickets".
        $out['active_tickets'] = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        $out['active_tickets'] = 0;
    }

    try {
        $r = db()->fetchOne(
            "SELECT COUNT(*) AS c FROM tickets WHERE buyer_id = ? AND status = 'used'",
            [$buyerId]
        );
        $out['used_tickets'] = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        $out['used_tickets'] = 0;
    }

    $tierCol = qb_has_column('tickets', 'ticket_tier');
    $tierSel = $tierCol ? 't.ticket_tier' : "'standard' AS ticket_tier";

    try {
        $rows = db()->fetchAll(
            "SELECT t.id, t.status, $tierSel, e.name AS event_name, e.event_start, e.event_end
             FROM tickets t
             INNER JOIN bazar_events e ON e.id = t.event_id
             WHERE t.buyer_id = ?
             ORDER BY (t.status = 'active') DESC, t.issued_at DESC
             LIMIT 4",
            [$buyerId]
        );
        foreach ($rows as $row) {
            $tier = qb_ticket_normalize_tier($row['ticket_tier'] ?? 'standard');
            $ev = [
                'event_start' => $row['event_start'] ?? null,
                'event_end' => $row['event_end'] ?? null,
            ];
            $label = qb_ticket_buyer_status_label($row, $ev);
            $out['ticket_snippets'][] = [
                'event_name' => (string) ($row['event_name'] ?? 'Bazar'),
                'tier_label' => qb_ticket_tier_label($tier),
                'status' => $label,
                'href' => APP_URL . '/buyer/tickets.php',
            ];
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    return $out;
}
