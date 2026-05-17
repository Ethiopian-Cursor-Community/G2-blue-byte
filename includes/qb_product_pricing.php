<?php
/**
 * List price + optional regular discount (products.discount_pct) + optional flash sale row.
 */

require_once __DIR__ . '/qb_features.php';

function qb_product_discount_pct(array $product): int {
    if (!qb_has_column('products', 'discount_pct')) {
        return 0;
    }
    $p = (int) ($product['discount_pct'] ?? 0);
    return max(0, min(90, $p));
}

/**
 * Best price for the buyer: minimum of list, regular discounted, and active flash (when present).
 *
 * @return array{
 *   unit_price: float,
 *   list_price: float,
 *   badge: 'none'|'regular'|'flash',
 *   flash: ?array,
 *   regular_pct: int
 * }
 */
function qb_resolve_product_pricing(array $product, ?array $flashRow): array {
    $list = (float) ($product['price'] ?? 0);
    $regPct = qb_product_discount_pct($product);
    $regularUnit = $regPct > 0 ? round($list * (1 - $regPct / 100), 2) : $list;

    $flashValid = null;
    if ($flashRow !== null && qb_table_exists('flash_sales')) {
        $sale = (float) ($flashRow['sale_price'] ?? 0);
        if ($sale > 0 && $sale < $list) {
            $flashValid = $flashRow;
        }
    }
    $flashUnit = $flashValid ? (float) $flashValid['sale_price'] : $list;

    $cands = [
        ['price' => $list, 'badge' => 'none', 'flash' => null],
    ];
    if ($regPct > 0 && $regularUnit < $list) {
        $cands[] = ['price' => $regularUnit, 'badge' => 'regular', 'flash' => null];
    }
    if ($flashValid !== null && $flashUnit < $list) {
        $cands[] = ['price' => $flashUnit, 'badge' => 'flash', 'flash' => $flashValid];
    }

    $minP = $list;
    foreach ($cands as $c) {
        if ($c['price'] < $minP) {
            $minP = $c['price'];
        }
    }
    $atMin = array_values(array_filter($cands, static function ($c) use ($minP) {
        return abs($c['price'] - $minP) < 0.0001;
    }));
    $prio = ['flash' => 0, 'regular' => 1, 'none' => 2];
    usort($atMin, static function ($a, $b) use ($prio) {
        return ($prio[$a['badge']] ?? 9) <=> ($prio[$b['badge']] ?? 9);
    });
    $win = $atMin[0];

    return [
        'unit_price' => $win['price'],
        'list_price' => $list,
        'badge' => $win['badge'],
        'flash' => $win['flash'],
        'regular_pct' => $regPct,
    ];
}

/**
 * @param array<int, array<string,mixed>> $flashMap product_id => flash row
 * @return array<string, mixed>
 */
function qb_product_with_pricing(array $product, array $flashMap): array {
    $pid = (int) ($product['id'] ?? 0);
    $flash = $flashMap[$pid] ?? null;
    $r = qb_resolve_product_pricing($product, $flash);
    $product['qb_unit_price'] = $r['unit_price'];
    $product['qb_list_price'] = $r['list_price'];
    $product['qb_discount_badge'] = $r['badge'];
    $product['qb_flash'] = $r['flash'];
    $product['qb_regular_discount_pct'] = $r['regular_pct'];
    return $product;
}
