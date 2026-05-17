<?php

function qb_cursor_enabled(): bool {
    if (strtolower((string) qb_env('CURSOR_ENABLED', 'true')) === 'false') return false;
    return trim((string) qb_env('CURSOR_API_KEY', '')) !== '';
}

function qb_cursor_build_context(?array $user, string $page = ''): array {
    $ctx = ['app' => APP_NAME, 'page' => $page, 'events' => [], 'products_sample' => []];
    if ($user) {
        $ctx['user'] = ['name' => (string) ($user['full_name'] ?? 'Guest'), 'role' => (string) ($user['role'] ?? 'buyer')];
    }
    if (function_exists('getActiveEvents')) {
        foreach (array_slice(getActiveEvents(), 0, 8) as $ev) {
            $ctx['events'][] = ['name' => (string) ($ev['name'] ?? ''), 'status' => (string) ($ev['status'] ?? '')];
        }
    }
    if (qb_table_exists('products') && qb_table_exists('sellers')) {
        try {
            $ap = function_exists('qb_sql_product_approved') ? qb_sql_product_approved() : '1=1';
            foreach (db()->fetchAll(
                "SELECT p.name, p.price, s.market_name FROM products p JOIN sellers s ON s.id = p.seller_id
                 WHERE p.is_available = 1 AND p.stock > 0 AND s.is_active = 1 AND ($ap) ORDER BY p.updated_at DESC LIMIT 15"
            ) as $r) {
                $ctx['products_sample'][] = ['name' => (string) $r['name'], 'price_etb' => (float) $r['price'], 'seller' => (string) $r['market_name']];
            }
        } catch (Throwable $e) {}
    }
    return $ctx;
}

function qb_cursor_ask(string $message, array $context): array {
    if (!qb_cursor_enabled()) return ['ok' => false, 'error' => 'AI assist not configured'];
    $script = dirname(__DIR__) . '/services/cursor/run-assist.mjs';
    if (!is_readable($script)) return ['ok' => false, 'error' => 'Assist service missing'];

    $payload = json_encode([
        'prompt' => "You are QR Bazar assistant (Ethiopia). Answer only from context JSON. Be brief (2-4 sentences).\nContext:\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE) . "\nQuestion:\n" . $message,
        'model' => (string) qb_env('CURSOR_MODEL', 'composer-2'),
        'cwd' => dirname(__DIR__),
    ]);
    if ($payload === false) return ['ok' => false, 'error' => 'Request build failed'];

    $cmd = escapeshellarg(trim((string) qb_env('NODE_BIN', 'node')) ?: 'node') . ' ' . escapeshellarg($script);
    $env = array_merge($_ENV, ['CURSOR_API_KEY' => (string) qb_env('CURSOR_API_KEY', '')]);
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'Could not start Node assist (install Node 18+)' ];

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return ['ok' => false, 'error' => (string) ($decoded['error'] ?? 'Assist failed')];
    }
    return ['ok' => true, 'reply' => (string) ($decoded['reply'] ?? '')];
}

function qb_cursor_assist_render(string $portal): void {
    if (!qb_cursor_enabled() || !in_array($portal, ['buyer', 'seller'], true)) return;
    if (!function_exists('currentUser') || !currentUser()) return;
    $app = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
    echo '<link rel="stylesheet" href="' . $app . '/assets/css/cursor-assist.css"/>';
    echo '<div id="qb-cursor-assist" class="qb-cursor-assist" data-api="' . $app . '/api/cursor_assist.php">';
    echo '<button type="button" class="qb-cursor-assist__fab" id="qb-cursor-fab" aria-label="AI assist">✦</button>';
    echo '<section id="qb-cursor-panel" class="qb-cursor-assist__panel" hidden><header class="qb-cursor-assist__head"><strong>QR Bazar Assist</strong><span>Cursor SDK</span><button type="button" id="qb-cursor-close">×</button></header>';
    echo '<div id="qb-cursor-messages" class="qb-cursor-assist__messages"><p class="qb-cursor-assist__msg qb-cursor-assist__msg--bot">Ask about events, products, or tickets.</p></div>';
    echo '<form id="qb-cursor-form" class="qb-cursor-assist__form"><input id="qb-cursor-input" maxlength="500" placeholder="Ask…"/><button type="submit" class="btn btn-primary btn-sm">Send</button></form></section></div>';
    echo '<script src="' . $app . '/assets/js/cursor-assist.js" defer></script>';
}
