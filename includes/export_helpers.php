<?php
/**
 * Admin data exports: CSV / HTML (print-to-PDF) with optional date range and VAT columns.
 */

require_once __DIR__ . '/qb_features.php';
require_once __DIR__ . '/report_admin_helpers.php';
require_once __DIR__ . '/audit_admin_helpers.php';

/** Default VAT % for compliance reporting (Ethiopia standard VAT often 15%; adjust in UI). */
define('QB_EXPORT_DEFAULT_VAT_PERCENT', 15.0);

/**
 * @return array{net:float,vat:float,gross:float}
 */
function qb_export_vat_split(float $amount, float $vatPercent, string $mode): array {
    $rate = max(0.0, (float) $vatPercent) / 100.0;
    if ($mode === 'exclusive') {
        $net = $amount;
        $vat = round($net * $rate, 2);
        $gross = round($net + $vat, 2);
        return ['net' => round($net, 2), 'vat' => $vat, 'gross' => $gross];
    }
    /* inclusive: amount includes VAT */
    if ($rate <= 0) {
        return ['net' => round($amount, 2), 'vat' => 0.0, 'gross' => round($amount, 2)];
    }
    $gross = $amount;
    $net = $gross / (1 + $rate);
    $vat = $gross - $net;
    return ['net' => round($net, 2), 'vat' => round($vat, 2), 'gross' => round($gross, 2)];
}

/** @return array{sql:string,params:list<mixed>} */
function qb_export_date_range_sql(string $column, ?string $dateFrom, ?string $dateTo): array {
    $w = [];
    $p = [];
    if ($dateFrom !== null && $dateFrom !== '') {
        $w[] = $column . ' >= ?';
        $p[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $w[] = $column . ' <= ?';
        $p[] = $dateTo . ' 23:59:59';
    }
    if ($w === []) {
        return ['sql' => '', 'params' => []];
    }
    return ['sql' => ' AND ' . implode(' AND ', $w), 'params' => $p];
}

/**
 * @param list<list<string|int|float>> $rows
 */
function qb_export_csv_string(array $headers, array $rows): string {
    $buf = fopen('php://temp', 'r+');
    fwrite($buf, "\xEF\xBB\xBF");
    fputcsv($buf, $headers);
    foreach ($rows as $row) {
        fputcsv($buf, $row);
    }
    rewind($buf);
    $s = stream_get_contents($buf);
    fclose($buf);
    return $s;
}

/**
 * @return array<string, array{label:string,desc:string}>
 */
function qb_export_dataset_catalog(): array {
    return [
        'users' => [
            'label' => 'Users (app_users)',
            'desc'  => 'Accounts, roles, public UUID, moderation flags — no passwords.',
        ],
        'sellers' => [
            'label' => 'Sellers',
            'desc'  => 'Seller profiles and stalls linkage — secrets excluded.',
        ],
        'products' => [
            'label' => 'Products',
            'desc'  => 'Listings, prices, stock.',
        ],
        'transactions' => [
            'label' => 'Transactions',
            'desc'  => 'Sales with tax/VAT breakdown columns (ETB).',
        ],
        'transaction_items' => [
            'label' => 'Transaction line items',
            'desc'  => 'Per-line product detail with proportional VAT.',
        ],
        'events' => [
            'label' => 'Events (bazars)',
            'desc'  => 'Schedules, venues, status.',
        ],
        'stalls' => [
            'label' => 'Stalls',
            'desc'  => 'Seller assignments per event.',
        ],
        'tickets' => [
            'label' => 'Tickets',
            'desc'  => 'Buyer entry codes per event.',
        ],
        'event_participants' => [
            'label' => 'Event participants',
            'desc'  => 'Buyer/seller participation records.',
        ],
        'event_coorganizers' => [
            'label' => 'Co-organizers',
            'desc'  => 'Secondary organizers per event (if table exists).',
        ],
        'ratings' => [
            'label' => 'Ratings',
            'desc'  => 'Seller feedback.',
        ],
        'reports' => [
            'label' => 'Moderation reports',
            'desc'  => 'Buyer/staff reports queue (if table exists).',
        ],
        'analytics_events' => [
            'label' => 'Analytics events',
            'desc'  => 'QR scans, views, etc. (if table exists).',
        ],
        'notifications' => [
            'label' => 'Notifications',
            'desc'  => 'In-app notifications log (if table exists).',
        ],
        'event_announcements' => [
            'label' => 'Event announcements',
            'desc'  => 'Organizer announcements (if table exists).',
        ],
        'flash_sales' => [
            'label' => 'Flash sales',
            'desc'  => 'Promotional flash records (if table exists).',
        ],
        'audit_log' => [
            'label' => 'Audit log',
            'desc'  => 'Security / admin audit trail (if table exists).',
        ],
    ];
}

/**
 * @return array{headers:list<string>,rows:list<list<string|int|float>>}
 */
function qb_export_dataset_rows(string $key, ?string $df, ?string $dt, bool $includePii, float $vatPct, string $vatMode): array {
    $dr = qb_export_date_range_sql('created_at', $df, $dt);

    switch ($key) {
        case 'users':
            $cols = ['id', 'login_uid', 'display_name', 'role', 'created_at'];
            if (qb_has_column('app_users', 'public_uuid')) {
                array_splice($cols, 1, 0, ['public_id']);
            }
            $cols[] = 'is_active';
            if (qb_has_column('app_users', 'is_locked')) {
                $cols[] = 'is_locked';
            }
            if (qb_has_column('app_users', 'is_banned')) {
                $cols[] = 'is_banned';
            }
            if (qb_has_column('app_users', 'moderation_note')) {
                $cols[] = 'moderation_note';
            }
            if ($includePii) {
                $cols[] = 'phone';
                $cols[] = 'email';
            }
            /* Build SELECT list: public_id comes from public_uuid AS public_id */
            if (qb_has_column('app_users', 'public_uuid')) {
                $rest = array_values(array_diff($cols, ['id', 'public_id']));
                $selectParts = array_merge(['id', 'public_uuid AS public_id'], $rest);
                $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM app_users WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            } else {
                $sql = 'SELECT ' . implode(', ', $cols) . ' FROM app_users WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            }
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'sellers':
            $cols = ['id', 'app_user_id', 'uid', 'full_name', 'market_name', 'location', 'category', 'is_active', 'is_flagged', 'created_at'];
            if ($includePii) {
                $cols[] = 'phone';
                $cols[] = 'email';
            }
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM sellers WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'products':
            $cols = ['id', 'seller_id', 'event_id', 'name', 'price', 'unit', 'stock', 'category', 'is_available', 'created_at'];
            if (qb_has_column('products', 'approval_status')) {
                $cols[] = 'approval_status';
            }
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM products WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'transactions':
            $cols = [
                'id', 'tx_id', 'seller_id', 'buyer_id', 'event_id', 'buyer_name', 'total_amount',
                'payment_method', 'payment_status', 'created_at',
            ];
            if ($includePii) {
                $cols[] = 'buyer_phone';
            }
            $cols[] = 'notes';
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM transactions WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $taxHeaders = [
                'taxable_base_etb', 'vat_rate_percent', 'vat_amount_etb', 'total_amount_etb',
                'vat_treatment_note',
            ];
            $headers = array_merge($cols, $taxHeaders);
            $out = [];
            foreach ($rows as $r) {
                $amt = (float) ($r['total_amount'] ?? 0);
                $t = qb_export_vat_split($amt, $vatPct, $vatMode);
                $note = $vatMode === 'inclusive'
                    ? 'Total treated as VAT-inclusive; base and VAT derived.'
                    : 'Line amount treated as VAT-exclusive; VAT added for display.';
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $line[] = $t['net'];
                $line[] = $vatPct;
                $line[] = $t['vat'];
                $line[] = $t['gross'];
                $line[] = $note;
                $out[] = $line;
            }
            return ['headers' => $headers, 'rows' => $out];

        case 'transaction_items':
            $drT = qb_export_date_range_sql('t.created_at', $df, $dt);
            $sql = '
                SELECT ti.id, ti.transaction_id, t.tx_id, ti.product_id, ti.product_name, ti.unit_price, ti.quantity, ti.subtotal,
                       t.total_amount AS order_total, t.payment_status, t.created_at AS tx_created
                FROM transaction_items ti
                INNER JOIN transactions t ON t.id = ti.transaction_id
                WHERE 1=1' . $drT['sql'] . '
                ORDER BY ti.id ASC';
            $rows = db()->fetchAll($sql, $drT['params']);
            $headers = [
                'line_id', 'transaction_id', 'tx_id', 'product_id', 'product_name', 'unit_price', 'quantity', 'subtotal_etb',
                'line_taxable_base_etb', 'line_vat_amount_etb', 'vat_rate_percent', 'order_total_etb', 'payment_status', 'tx_created',
                'vat_treatment_note',
            ];
            $out = [];
            foreach ($rows as $r) {
                $sub = (float) ($r['subtotal'] ?? 0);
                $t = qb_export_vat_split($sub, $vatPct, $vatMode);
                $note = $vatMode === 'inclusive'
                    ? 'Subtotal VAT-inclusive; proportional split for reporting.'
                    : 'Subtotal VAT-exclusive; VAT shown for reporting.';
                $out[] = [
                    $r['id'] ?? '',
                    $r['transaction_id'] ?? '',
                    $r['tx_id'] ?? '',
                    $r['product_id'] ?? '',
                    $r['product_name'] ?? '',
                    $r['unit_price'] ?? '',
                    $r['quantity'] ?? '',
                    $sub,
                    $t['net'],
                    $t['vat'],
                    $vatPct,
                    $r['order_total'] ?? '',
                    $r['payment_status'] ?? '',
                    $r['tx_created'] ?? '',
                    $note,
                ];
            }
            return ['headers' => $headers, 'rows' => $out];

        case 'events':
            $cols = ['id', 'slug', 'name', 'venue', 'city', 'organizer_app_user_id', 'status', 'max_sellers',
                'ticket_sales_start', 'ticket_sales_end', 'event_start', 'event_end', 'created_at'];
            if (qb_has_column('bazar_events', 'lifecycle_note')) {
                $cols[] = 'lifecycle_note';
            }
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM bazar_events WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'stalls':
            $cols = ['id', 'event_id', 'seller_id', 'stall_number', 'lat', 'lng', 'created_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM stalls WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'tickets':
            $drT = qb_export_date_range_sql('issued_at', $df, $dt);
            $cols = ['id', 'buyer_id', 'event_id', 'ticket_code', 'status', 'issued_at', 'used_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM tickets WHERE 1=1' . $drT['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $drT['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'event_participants':
            $drT = qb_export_date_range_sql('assigned_at', $df, $dt);
            $cols = ['id', 'event_id', 'app_user_id', 'role_in_event', 'status', 'assigned_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM event_participants WHERE 1=1' . $drT['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $drT['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'event_coorganizers':
            if (!qb_table_exists('bazar_event_organizers')) {
                return ['headers' => ['message'], 'rows' => [['Table not present in database']]];
            }
            $dr = qb_export_date_range_sql('assigned_at', $df, $dt);
            $cols = ['event_id', 'app_user_id', 'assigned_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM bazar_event_organizers WHERE 1=1' . $dr['sql'] . ' ORDER BY event_id, app_user_id';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['event_id'] ?? '', $r['app_user_id'] ?? '', $r['assigned_at'] ?? ''];
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'ratings':
            $cols = ['id', 'seller_id', 'buyer_id', 'transaction_id', 'buyer_name', 'stars', 'comment', 'created_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM ratings WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'reports':
            if (!qb_table_exists('reports')) {
                return ['headers' => ['message'], 'rows' => [['Table not present']]];
            }
            $cols = qb_reports_table_columns();
            if ($cols === []) {
                return ['headers' => ['message'], 'rows' => [['No columns']]];
            }
            /* safe: no raw password in reports */
            $sql = 'SELECT * FROM reports WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            try {
                $rows = db()->fetchAll($sql, $dr['params']);
            } catch (Throwable $e) {
                return ['headers' => ['error'], 'rows' => [[$e->getMessage()]]];
            }
            if (empty($rows)) {
                return ['headers' => $cols, 'rows' => []];
            }
            $headers = array_keys($rows[0]);
            if (!$includePii) {
                $headers = array_values(array_filter($headers, static fn ($h) => !in_array(strtolower((string) $h), ['email', 'phone', 'reporter_phone'], true)));
            }
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($headers as $h) {
                    $v = $r[$h] ?? '';
                    if (is_array($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    $line[] = $v;
                }
                $out[] = $line;
            }
            return ['headers' => $headers, 'rows' => $out];

        case 'analytics_events':
            if (!qb_table_exists('analytics_events')) {
                return ['headers' => ['message'], 'rows' => [['Table not present']]];
            }
            $cols = ['id', 'seller_id', 'event_type', 'product_id', 'metadata', 'event_hour', 'event_date', 'created_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM analytics_events WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $meta = $r['metadata'] ?? '';
                if (is_array($meta)) {
                    $meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
                }
                $out[] = [
                    $r['id'] ?? '',
                    $r['seller_id'] ?? '',
                    $r['event_type'] ?? '',
                    $r['product_id'] ?? '',
                    $meta,
                    $r['event_hour'] ?? '',
                    $r['event_date'] ?? '',
                    $r['created_at'] ?? '',
                ];
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'notifications':
            if (!qb_table_exists('notifications')) {
                return ['headers' => ['message'], 'rows' => [['Table not present']]];
            }
            $cols = ['id', 'user_id', 'type', 'title', 'body', 'link', 'is_read', 'created_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM notifications WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'event_announcements':
            if (!qb_table_exists('event_announcements')) {
                return ['headers' => ['message'], 'rows' => [['Table not present']]];
            }
            $cols = ['id', 'event_id', 'organizer_id', 'title', 'body', 'created_at'];
            $sql = 'SELECT ' . implode(', ', $cols) . ' FROM event_announcements WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            $rows = db()->fetchAll($sql, $dr['params']);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? '';
                }
                $out[] = $line;
            }
            return ['headers' => $cols, 'rows' => $out];

        case 'flash_sales':
            if (!qb_table_exists('flash_sales')) {
                return ['headers' => ['message'], 'rows' => [['Table not present']]];
            }
            $row = db()->fetchOne('SELECT * FROM flash_sales LIMIT 1');
            $cols = $row ? array_keys($row) : ['id'];
            $sql = 'SELECT * FROM flash_sales WHERE 1=1' . $dr['sql'] . ' ORDER BY id ASC';
            try {
                $rows = db()->fetchAll($sql, $dr['params']);
            } catch (Throwable $e) {
                return ['headers' => ['error'], 'rows' => [[$e->getMessage()]]];
            }
            if (empty($rows)) {
                return ['headers' => $cols, 'rows' => []];
            }
            $headers = array_keys($rows[0]);
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($headers as $h) {
                    $v = $r[$h] ?? '';
                    if (is_array($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    $line[] = $v;
                }
                $out[] = $line;
            }
            return ['headers' => $headers, 'rows' => $out];

        case 'audit_log':
            $schema = qb_audit_admin_schema();
            if (!$schema) {
                return ['headers' => ['message'], 'rows' => [['No audit table']]];
            }
            $t = $schema['table'];
            $row = db()->fetchOne("SELECT * FROM `$t` LIMIT 1");
            if (!$row) {
                return ['headers' => ['empty'], 'rows' => []];
            }
            $headers = array_keys($row);
            $orderCol = in_array('created_at', $headers, true) ? 'created_at' : (in_array('id', $headers, true) ? 'id' : $headers[0]);
            $sql = "SELECT * FROM `$t` WHERE 1=1" . $dr['sql'] . " ORDER BY `$orderCol` DESC LIMIT 50000";
            try {
                $rows = db()->fetchAll($sql, $dr['params']);
            } catch (Throwable $e) {
                $rows = db()->fetchAll("SELECT * FROM `$t` WHERE 1=1" . $dr['sql'] . ' LIMIT 50000', $dr['params']);
            }
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($headers as $h) {
                    $v = $r[$h] ?? '';
                    if (is_array($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    $line[] = $v;
                }
                $out[] = $line;
            }
            return ['headers' => $headers, 'rows' => $out];

        default:
            return ['headers' => ['error'], 'rows' => [['Unknown dataset']]];
    }
}

function qb_export_html_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param list<string> $headers
 * @param list<list<string|int|float>> $rows
 */
function qb_export_html_table(string $caption, array $headers, array $rows): string {
    $h = '<table class="qb-export-table"><caption>' . qb_export_html_escape($caption) . '</caption><thead><tr>';
    foreach ($headers as $x) {
        $h .= '<th>' . qb_export_html_escape((string) $x) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $h .= '<tr>';
        foreach ($row as $cell) {
            $h .= '<td>' . qb_export_html_escape((string) $cell) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}
