<?php
/**
 * Schema-aware features: event theming, promos, product approval, organizer access window.
 */

function qb_has_column(string $table, string $column): bool {
    static $cache = [];
    $k = $table . '.' . $column;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    try {
        $r = db()->fetchOne(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        $cache[$k] = ((int) ($r['n'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

function qb_table_exists(string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $r = db()->fetchOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $cache[$table] = ((int) ($r['n'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/** Remove co-organizer rows for an event (e.g. when status is canceled). */
function qb_event_coorganizers_clear(int $eventId): void {
    if ($eventId <= 0 || !qb_table_exists('bazar_event_organizers')) {
        return;
    }
    try {
        db()->execute('DELETE FROM bazar_event_organizers WHERE event_id = ?', [$eventId]);
    } catch (Throwable $e) {
        /* ignore */
    }
}

/**
 * SQL condition: bazar_events row is manageable by this organizer (primary or co-organizer).
 * Bind params from qb_organizer_event_access_bind($uid) in order.
 */
function qb_organizer_event_alias_access_sql(string $alias = 'e'): string {
    if (!qb_table_exists('bazar_event_organizers')) {
        return $alias . '.organizer_app_user_id = ?';
    }
    return '(' . $alias . '.organizer_app_user_id = ? OR EXISTS (SELECT 1 FROM bazar_event_organizers eo WHERE eo.event_id = ' . $alias . '.id AND eo.app_user_id = ?))';
}

/** True when organizer may edit, notify, or assign sellers (not admin-canceled). */
function qb_organizer_may_manage_event(?array $event): bool {
    if (!$event || !isset($event['status'])) {
        return false;
    }

    return ($event['status'] ?? '') !== 'canceled';
}

/** @return list<int> */
function qb_organizer_event_access_bind(int $uid): array {
    if (!qb_table_exists('bazar_event_organizers')) {
        return [$uid];
    }
    return [$uid, $uid];
}

/**
 * Organizer assignment stats for account roleing.
 *
 * @return array{primary:int,co:int}
 */
function qb_organizer_assignment_counts(int $appUserId): array {
    if ($appUserId <= 0) {
        return ['primary' => 0, 'co' => 0];
    }
    try {
        $primary = (int) (db()->fetchOne(
            'SELECT COUNT(*) AS c FROM bazar_events WHERE organizer_app_user_id = ?',
            [$appUserId]
        )['c'] ?? 0);
        $co = 0;
        if (qb_table_exists('bazar_event_organizers')) {
            $co = (int) (db()->fetchOne(
                'SELECT COUNT(*) AS c FROM bazar_event_organizers WHERE app_user_id = ?',
                [$appUserId]
            )['c'] ?? 0);
        }
        return ['primary' => $primary, 'co' => $co];
    } catch (Throwable $e) {
        return ['primary' => 0, 'co' => 0];
    }
}

/**
 * Co-only account: can operate assigned events but should not create/edit core event settings.
 */
function qb_organizer_is_co_only(int $appUserId): bool {
    $counts = qb_organizer_assignment_counts($appUserId);
    return $counts['co'] > 0 && $counts['primary'] === 0;
}

/**
 * Can edit core event configuration (name/slug/location/status/etc).
 */
function qb_organizer_can_edit_event_core(int $appUserId, ?array $event): bool {
    if ($appUserId <= 0 || !$event) {
        return false;
    }
    if ((int) ($event['organizer_app_user_id'] ?? 0) === $appUserId) {
        return true;
    }
    return (currentRole() === 'super_admin');
}

/** SQL fragment for WHERE on bazar_events (no alias). */
function qb_organizer_bazar_events_access_sql(): string {
    if (!qb_table_exists('bazar_event_organizers')) {
        return 'organizer_app_user_id = ?';
    }
    return '(organizer_app_user_id = ? OR EXISTS (SELECT 1 FROM bazar_event_organizers eo WHERE eo.event_id = bazar_events.id AND eo.app_user_id = ?))';
}

/** SQL fragment: approved products only (alias p). */
function qb_sql_product_approved(): string {
    if (!qb_has_column('products', 'approval_status')) {
        return '1=1';
    }
    return "p.approval_status = 'approved'";
}

/** SQL fragment: approved products (no table alias). */
function qb_sql_product_approved_plain(): string {
    if (!qb_has_column('products', 'approval_status')) {
        return '1=1';
    }
    return "approval_status = 'approved'";
}

/**
 * Organizer may use the portal when at least one owned event is within the admin-defined
 * or fallback event window (not strictly ended).
 */
function qb_organizer_portal_open(int $appUserId): bool {
    if (!qb_has_column('bazar_events', 'organizer_active_start')) {
        return true;
    }
    try {
        $w = qb_organizer_bazar_events_access_sql();
        $bind = qb_organizer_event_access_bind($appUserId);
        $rows = db()->fetchAll(
            "SELECT event_start, event_end, organizer_active_start, organizer_active_end, status FROM bazar_events WHERE $w",
            $bind
        );
    } catch (Throwable $e) {
        return true;
    }
    if (empty($rows)) {
        return true;
    }
    $now = time();
    foreach ($rows as $e) {
        if (($e['status'] ?? '') === 'ended') {
            continue;
        }
        $a = null;
        $b = null;
        if (!empty($e['organizer_active_start'])) {
            $a = strtotime((string) $e['organizer_active_start']);
        }
        if (!empty($e['organizer_active_end'])) {
            $b = strtotime((string) $e['organizer_active_end']);
        }
        if ($a === null && $b === null) {
            if (!empty($e['event_start'])) {
                $a = strtotime((string) $e['event_start']);
            }
            if (!empty($e['event_end'])) {
                $b = strtotime((string) $e['event_end']);
            }
        }
        if ($a && $now < $a) {
            continue;
        }
        if ($b && $now > $b) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * Active promos for a portal: buyer | seller | organizer.
 * Admin uploads media in admin/promos.php and checks who should see each row.
 * Global + per live event; same rules as before when audience columns are missing (everyone sees all promos).
 *
 * @param 'buyer'|'seller'|'organizer' $portal
 */
function qb_fetch_active_promos_for(string $portal): array {
    if (!qb_has_column('event_promotions', 'id')) {
        return [];
    }
    $col = 'show_buyers';
    if ($portal === 'seller') {
        $col = 'show_sellers';
    } elseif ($portal === 'organizer') {
        $col = 'show_organizers';
    }
    $audienceSql = '';
    if (qb_has_column('event_promotions', $col)) {
        $audienceSql = ' AND pr.`' . str_replace('`', '', $col) . '` = 1';
    }
    try {
        return db()->fetchAll(
            "SELECT pr.* FROM event_promotions pr
             WHERE pr.is_active = 1 $audienceSql
               AND (pr.event_id IS NULL OR pr.event_id IN (SELECT id FROM bazar_events WHERE status IN ('published','live')))
             ORDER BY pr.sort_order ASC, pr.id DESC"
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** Active promos for buyer home (global + per live event). */
function qb_fetch_active_promos(): array {
    return qb_fetch_active_promos_for('buyer');
}

/** Lines for marquee: promos (buyer audience) + event marquees — legacy helper. */
function qb_fetch_marquee_lines(): array {
    return qb_fetch_portal_marquee_lines('buyer');
}

/** Build one marquee segment with spaced, readable items. */
function qb_marquee_segment_html(array $lines): string {
    $items = [];
    foreach ($lines as $line) {
        $text = trim((string) $line);
        if ($text === '') {
            continue;
        }
        $items[] = '<span class="qb-marquee-msg">' . qb_esc_html($text) . '</span>';
    }
    if ($items === []) {
        $items[] = '<span class="qb-marquee-msg">Discover live bazars, flash deals, and seller promotions on QR Bazar.</span>';
    }

    return implode('', $items);
}

/**
 * Marquee lines for buyer/seller portal strip: promos visible to that portal, event copy, active bazars.
 *
 * @param 'buyer'|'seller'|'organizer' $forPortal
 */
function qb_fetch_portal_marquee_lines(string $forPortal = 'buyer'): array {
    $lines = [];
    foreach (qb_fetch_active_promos_for($forPortal) as $p) {
        if (!empty($p['marquee_text'])) {
            $lines[] = trim((string) $p['marquee_text']);
        }
    }
    if (qb_has_column('bazar_events', 'marquee_text')) {
        try {
            $evs = db()->fetchAll(
                "SELECT marquee_text FROM bazar_events WHERE status IN ('published','live') AND marquee_text IS NOT NULL AND marquee_text != ''"
            );
            foreach ($evs as $e) {
                $lines[] = trim((string) $e['marquee_text']);
            }
        } catch (Throwable $e) {
        }
    }
    $lines = array_values(array_filter($lines));
    $seen = [];
    foreach ($lines as $x) {
        $seen[$x] = true;
    }

    if (function_exists('getActiveEvents')) {
        $n = 0;
        foreach (getActiveEvents() as $ev) {
            if ($n >= 12) {
                break;
            }
            $name = trim((string) ($ev['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $city = trim((string) ($ev['city'] ?? ''));
            $venue = trim((string) ($ev['venue'] ?? ''));
            $parts = array_filter([$name, $city !== '' ? $city : null, $venue !== '' ? $venue : null]);
            $line = 'Active bazar — ' . implode(' · ', $parts);
            if (!isset($seen[$line])) {
                $lines[] = $line;
                $seen[$line] = true;
                $n++;
            }
        }
    }

    foreach (qb_fetch_active_promos_for($forPortal) as $p) {
        $t = trim((string) ($p['title'] ?? ''));
        if ($t === '') {
            continue;
        }
        $line = 'Promotion — ' . $t;
        if (!isset($seen[$line])) {
            $lines[] = $line;
            $seen[$line] = true;
        }
    }

    if ($forPortal === 'buyer' && function_exists('qb_promo_posts_ready') && qb_promo_posts_ready()) {
        try {
            $ppLines = db()->fetchAll(
                "SELECT title FROM promo_posts
                 WHERE status = 'active' AND target = 'homepage'
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY is_sponsored DESC, created_at DESC
                 LIMIT 16"
            );
            foreach ($ppLines as $row) {
                $t = trim((string) ($row['title'] ?? ''));
                if ($t === '') {
                    continue;
                }
                $line = 'Community promo — ' . $t;
                if (!isset($seen[$line])) {
                    $lines[] = $line;
                    $seen[$line] = true;
                }
            }
        } catch (Throwable $e) {
        }
    }

    return array_values(array_filter(array_unique($lines)));
}

/**
 * Scrolling promo strip for buyer + seller: shows on a timer (see layout script).
 */
function qb_render_portal_marquee(string $portal): void {
    if (!in_array($portal, ['buyer', 'seller', 'organizer', 'gatekeeper'], true)) {
        return;
    }
    $lines = qb_fetch_portal_marquee_lines($portal);
    if ($lines === []) {
        $lines = ['Discover live bazars, flash deals, and seller promotions on QR Bazar.'];
    }
    $segmentHtml = qb_marquee_segment_html($lines);
    $slug = in_array($portal, ['buyer', 'seller', 'organizer', 'gatekeeper'], true) ? $portal : 'buyer';
    ?>
    <div id="qb-portal-marquee-root" class="qb-marquee-pop qb-marquee-pop--bottom qb-marquee-pop--<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?> is-visible"
      aria-hidden="false">
      <div class="qb-marquee-wrap qb-marquee-wrap--portal">
        <div class="qb-marquee qb-marquee--medium" aria-live="polite">
          <div class="qb-marquee__loop">
            <span class="qb-marquee__inner"><?= $segmentHtml ?></span><span class="qb-marquee__inner" aria-hidden="true"><?= $segmentHtml ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php
}

/**
 * Scrolling ticker for the public home page (guests) — same copy as buyer portal, not fixed to viewport.
 */
function qb_render_guest_marquee(): void {
    $lines = qb_fetch_portal_marquee_lines('buyer');
    if ($lines === []) {
        $lines = ['Discover live bazars, flash deals, and seller promotions on QR Bazar.'];
    }
    $segmentHtml = qb_marquee_segment_html($lines);
    ?>
    <div class="qb-guest-marquee" aria-hidden="false">
      <div class="qb-marquee-wrap qb-marquee-wrap--portal qb-marquee-wrap--guest qb-marquee-wrap--guest-top">
        <div class="qb-marquee qb-marquee--medium qb-marquee--guest-pace" aria-live="polite">
          <div class="qb-marquee__loop">
            <span class="qb-marquee__inner"><?= $segmentHtml ?></span><span class="qb-marquee__inner" aria-hidden="true"><?= $segmentHtml ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php
}

function qb_seller_verification_ready(): bool {
    return function_exists('qb_has_column') && qb_has_column('sellers', 'verification_status');
}

/** True if seller may be assigned to bazars / shown as trusted; missing column = allow all (legacy). */
function qb_seller_is_verified(array $seller): bool {
    if (!qb_seller_verification_ready()) {
        return true;
    }

    return (($seller['verification_status'] ?? '') === 'verified');
}

function qb_pending_seller_verification_count(): int {
    if (!qb_seller_verification_ready()) {
        return 0;
    }
    try {
        $r = db()->fetchOne("SELECT COUNT(*) AS c FROM sellers WHERE verification_status = 'pending' AND is_active = 1");

        return (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function qb_pending_product_count(): int {
    if (!qb_has_column('products', 'approval_status')) {
        return 0;
    }
    try {
        $r = db()->fetchOne("SELECT COUNT(*) AS c FROM products WHERE approval_status = 'pending'");
        return (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Pick a valid event id from the organizer's allowed list (ignores tampered ?event= IDs).
 *
 * @param list<array{id?:int|string,...}> $allowedEventRows
 */
function qb_organizer_resolve_event_id(?int $requestedId, array $allowedEventRows): int {
    $ids = [];
    foreach ($allowedEventRows as $e) {
        $id = (int) ($e['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return 0;
    }
    if ($requestedId !== null && $requestedId > 0 && in_array($requestedId, $ids, true)) {
        return $requestedId;
    }
    return (int) $ids[0];
}

/**
 * @param list<array{id?:int|string,...}> $allowedEventRows
 */
function qb_organizer_event_id_allowed(int $eventId, array $allowedEventRows): bool {
    if ($eventId <= 0) {
        return false;
    }
    foreach ($allowedEventRows as $e) {
        if ((int) ($e['id'] ?? 0) === $eventId) {
            return true;
        }
    }
    return false;
}
