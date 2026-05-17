<?php
/**
 * Ethiopian cities, seller categories, and JSON helpers for profile / events.
 */

declare(strict_types=1);

/** Major Ethiopian cities & towns (Addis first; long list, de-duplicated). */
function qb_ethiopian_cities(): array {
    $list = [
        'Addis Ababa', 'Dire Dawa', 'Mekelle', 'Adama (Nazret)', 'Gondar', 'Hawassa (Awassa)', 'Bahir Dar',
        'Dessie', 'Jimma', 'Jijiga', 'Shashamane', 'Arba Minch', 'Hosaena', 'Harar', 'Kombolcha',
        'Debre Berhan', 'Assela', 'Nekemte', 'Debre Mark\'os', 'Weldiya', 'Wolaita Sodo', 'Asosa',
        'Semera', 'Gambela', 'Jinka', 'Dilla', 'Moyale', 'Negele', 'Mega', 'Yabelo', 'Bishoftu (Debre Zeyit)',
        'Sebeta', 'Burayu', 'Legetafo', 'Holeta', 'Ambo', 'Gimbi', 'Dembi Dolo', 'Tepi', 'Mizan Teferi',
        'Bonga', 'Bench Maji', 'Goba', 'Dodola', 'Robe', 'Gode', 'Kibre Mengist', 'Fiche', 'Mojo',
        'Bichena', 'Dejen', 'Debre Tabor', 'Lalibela', 'Werota', 'Finote Selam', 'Injibara', 'Dangila',
        'Chagni', 'Mota', 'Debark', 'Adigrat', 'Wukro', 'Alamata', 'Korem', 'Maychew', 'Abi Adi',
        'Shire (Inda Selassie)', 'Axum', 'Adwa', 'Woldiya', 'Mersa', 'Kobo', 'Kemise', 'Shewa Robit',
        'Debre Sina', 'Ataye', 'Chiro', 'Asebe Teferi', 'Deder', 'Bedesa', 'Metehara', 'Awash', 'Mille',
        'Gewane', 'Logiya', 'Abala', 'Dubti', 'Bure', 'Werder', 'Kebri Dehar', 'Degehabur', 'Kelafo',
        'Negele Borana', 'Konso', 'Turmi', 'Omorate', 'Menge', 'Kurmuk', 'Itang', 'Metekel', 'Bulchi',
        'Gore', 'Butajira', 'Welkite', 'Durame', 'Areka', 'Sawla', 'Yirgalem', 'Aleta Wendo', 'Wondo Genet',
        'Mizan Aman', 'Bedele', 'Agaro', 'Chora', 'Seka', 'Gera', 'Holleta', 'Ejere', 'Ginchi', 'Addis Alem',
        'Sululta', 'Sali', 'Ghion', 'Injibara', 'Debre Woredo', 'Wereta', 'Debre Markos', 'Ferew Ber',
        'Bichena', 'Motta', 'Estifanos', 'Bahir Dar', 'Wollo', 'Dessie Zuria', 'Kobo', 'Lalibela Airport',
        'Mekane Selam', 'Debre Birhan', 'Chancho', 'Ginchi', 'Teji', 'Holeta Genet', 'Tulubolo', 'Koka',
        'Dukem', 'Debre Zeit', 'Bishoftu', 'Meki', 'Ziway', 'Batu', 'Adami Tulu', 'Shashemene', 'Kuyera',
        'Aje', 'Asela', 'Tiyo', 'Hetosa', 'Togochale', 'Wardheer', 'Gode', 'Filtu', 'Dolo Odo', 'Moyale (Eth.)',
        'Mega', 'Yabelo', 'Konso', 'Key Afer', 'Jinka', 'Turmi', 'Omorate', 'Dimeka', 'Weyto', 'Ginka',
        'Bench', 'Sheko', 'Tepi', 'Gore', 'Tongo', 'Bako', 'Metu', 'Dambi Dolo', 'Begi', 'Kumruk',
        'Mankush', 'Gambela', 'Abobo', 'Gog', 'Jor', 'Mizan', 'Bonga', 'Chebera', 'Kaffa', 'Wushwush',
        'Tepi', 'Bedele', 'Chora', 'Dima', 'Gore', 'Saylem', 'Bure', 'Dangla', 'Wereta', 'Farta',
        'Estifanos', 'Dabat', 'Debark', 'Lalibela', 'Woldiya', 'Kobo', 'Mersa', 'Wukro', 'Adigrat',
        'Axum', 'Adwa', 'Shire', 'Sheraro', 'Himora', 'Maychew', 'Korem', 'Alamata', 'Kutaber', 'Ansokia',
        'Kombolcha', 'Kemise', 'Dessie', 'Worebabu', 'Mekaneselam', 'Debre Sina', 'Ataye', 'Shewa Robit',
        'Debre Birhan', 'Fiche', 'Chancho', 'Ginchi', 'Addis Alem', 'Holeta', 'Ambo', 'Nekemte', 'Gimbi',
        'Mendi', 'Dambi Dolo', 'Tongo', 'Assosa', 'Kurmuk', 'Menge', 'Homosha', 'Gambela', 'Itang',
        'Other / not listed',
    ];
    $list = array_values(array_unique($list));
    $other = 'Other / not listed';
    $list = array_values(array_filter($list, static fn ($c) => $c !== $other));
    $top = ['Addis Ababa', 'Dire Dawa', 'Mekelle'];
    $rest = array_values(array_diff($list, $top));
    sort($rest);
    return array_merge($top, $rest, [$other]);
}

/** Stable slugs => English labels for seller stall categories (max 3 per seller). */
function qb_seller_category_catalog(): array {
    return [
        'food_beverages'      => 'Food & beverages (general)',
        'fresh_produce'       => 'Fresh produce & vegetables',
        'bakery_sweets'       => 'Bakery, injera & sweets',
        'meat_poultry'        => 'Meat & poultry',
        'dairy_eggs'          => 'Dairy & eggs',
        'coffee_tea'          => 'Coffee, tea & spices',
        'textiles_fashion'    => 'Textiles & clothing',
        'traditional_wear'    => 'Traditional & cultural wear',
        'footwear'            => 'Footwear',
        'crafts_souvenirs'    => 'Crafts & souvenirs',
        'jewelry_accessories' => 'Jewelry & accessories',
        'bags_leather'        => 'Bags & leather goods',
        'home_kitchen'        => 'Home & kitchen goods',
        'electronics_mobile'  => 'Electronics & mobile accessories',
        'beauty_personal'     => 'Beauty & personal care',
        'health_supplements'    => 'Health & supplements',
        'books_stationery'      => 'Books & stationery',
        'kids_baby'           => 'Kids & baby products',
        'sports_outdoor'      => 'Sports & outdoor',
        'agriculture_inputs'  => 'Agriculture & inputs',
        'flowers_plants'      => 'Flowers & plants',
        'art_antiques'        => 'Art & collectibles',
        'services_repair'     => 'Services & repairs',
        'digital_services'    => 'Digital / top-up services',
        'gifts'               => 'Gifts & packaging',
        'household'           => 'Household essentials',
        'fish_seafood'        => 'Fish & seafood',
        'spices_herbs'        => 'Spices, herbs & seasoning',
        'other'               => 'Other',
    ];
}

function qb_seller_category_slugs(): array {
    return array_keys(qb_seller_category_catalog());
}

function qb_decode_categories_json(?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return [];
    }
    $out = [];
    $catalog = qb_seller_category_catalog();
    foreach ($d as $x) {
        if (!is_string($x)) {
            continue;
        }
        if (isset($catalog[$x])) {
            $out[] = $x;
        }
    }
    return array_values(array_unique($out));
}

/** Merge legacy single `category` string into slugs when JSON empty. */
function qb_seller_categories_from_row(array $seller): array {
    $j = qb_decode_categories_json($seller['categories_json'] ?? null);
    if (!empty($j)) {
        return array_slice($j, 0, 3);
    }
    $legacy = trim((string) ($seller['category'] ?? ''));
    if ($legacy === '' || strcasecmp($legacy, 'General') === 0) {
        return [];
    }
    foreach (qb_seller_category_catalog() as $slug => $label) {
        if (strcasecmp($legacy, $label) === 0) {
            return [$slug];
        }
    }
    return ['other'];
}

function qb_encode_categories_json(array $slugs): string {
    $catalog = qb_seller_category_catalog();
    $clean = [];
    foreach ($slugs as $s) {
        if (is_string($s) && isset($catalog[$s])) {
            $clean[] = $s;
        }
    }
    $clean = array_values(array_unique($clean));
    $clean = array_slice($clean, 0, 3);
    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

function qb_seller_categories_labels(array $slugs): string {
    $cat = qb_seller_category_catalog();
    $labels = [];
    foreach (array_slice($slugs, 0, 3) as $s) {
        if (isset($cat[$s])) {
            $labels[] = $cat[$s];
        }
    }
    return implode(' · ', $labels);
}

function qb_event_eligible_slugs(?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return [];
    }
    $catalog = qb_seller_category_catalog();
    $out = [];
    foreach ($d as $x) {
        if (is_string($x) && isset($catalog[$x])) {
            $out[] = $x;
        }
    }
    return array_values(array_unique($out));
}

/** Human-readable list of all categories allowed at a bazar (not capped at 3). */
function qb_event_eligible_categories_label(?string $eligibleCategoriesJson): string {
    $slugs = qb_event_eligible_slugs($eligibleCategoriesJson);
    if ($slugs === []) {
        return '';
    }
    $cat = qb_seller_category_catalog();
    $labels = [];
    foreach ($slugs as $s) {
        if (isset($cat[$s])) {
            $labels[] = $cat[$s];
        }
    }
    return implode(' · ', $labels);
}

/**
 * If event has no eligibility list, any seller may join (backward compatible).
 * Otherwise seller must have at least one selected category in the event list.
 */
function qb_seller_eligible_for_event_categories(array $sellerRow, array $eventRow): bool {
    $eligible = qb_event_eligible_slugs($eventRow['eligible_categories_json'] ?? null);
    if (empty($eligible)) {
        return true;
    }
    $mine = qb_seller_categories_from_row($sellerRow);
    foreach ($mine as $slug) {
        if (in_array($slug, $eligible, true)) {
            return true;
        }
    }
    return false;
}

function qb_ensure_category_schema(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    if (!function_exists('qb_has_column')) {
        return;
    }
    try {
        if (!qb_has_column('sellers', 'categories_json')) {
            db()->execute('ALTER TABLE sellers ADD COLUMN categories_json TEXT NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    try {
        if (!qb_has_column('bazar_events', 'eligible_categories_json')) {
            db()->execute('ALTER TABLE bazar_events ADD COLUMN eligible_categories_json TEXT NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    qb_ensure_seller_event_application_schema();
    qb_ensure_seller_verification_schema();
    qb_ensure_category_change_request_schema();
}

/**
 * Sellers request category unlock from admin.
 */
function qb_ensure_category_change_request_schema(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try {
        db()->execute("CREATE TABLE IF NOT EXISTS category_change_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seller_id INT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            reason TEXT,
            admin_note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_seller (seller_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Stall categories snapshot on event applications + admin unlock for profile category edits.
 */
function qb_ensure_seller_event_application_schema(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    if (!function_exists('qb_has_column')) {
        return;
    }
    try {
        if (!qb_has_column('event_participants', 'application_categories_json')) {
            db()->execute('ALTER TABLE event_participants ADD COLUMN application_categories_json TEXT NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    try {
        if (!qb_has_column('event_participants', 'application_products_json')) {
            db()->execute('ALTER TABLE event_participants ADD COLUMN application_products_json LONGTEXT NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    try {
        if (!qb_has_column('sellers', 'allow_categories_edit')) {
            db()->execute('ALTER TABLE sellers ADD COLUMN allow_categories_edit TINYINT(1) NOT NULL DEFAULT 1');
            try {
                db()->execute(
                    "UPDATE sellers SET allow_categories_edit = 0 WHERE categories_json IS NOT NULL AND TRIM(categories_json) NOT IN ('', '[]', 'null')"
                );
            } catch (Throwable $e2) {
                /* ignore */
            }
        }
    } catch (Throwable $e) {
        /* ignore */
    }
}

function qb_application_products_label(?string $applicationProductsJson): string {
    $raw = trim((string) $applicationProductsJson);
    if ($raw === '') {
        return '';
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr) || $arr === []) {
        return '';
    }
    $names = [];
    foreach ($arr as $row) {
        if (is_array($row)) {
            $nm = trim((string) ($row['name'] ?? ''));
            if ($nm !== '') {
                $names[] = $nm;
            }
        } elseif (is_string($row)) {
            $nm = trim($row);
            if ($nm !== '') {
                $names[] = $nm;
            }
        }
    }
    $names = array_values(array_unique($names));
    if ($names === []) {
        return '';
    }
    if (count($names) > 6) {
        return implode(', ', array_slice($names, 0, 6)) . ' +' . (count($names) - 6);
    }
    return implode(', ', $names);
}

/** Distinct sellers with stalls for this bazar (active assignments). */
function qb_event_assigned_seller_count(int $eventId): int {
    if ($eventId <= 0 || !function_exists('qb_table_exists') || !qb_table_exists('stalls')) {
        return 0;
    }
    try {
        $r = db()->fetchOne('SELECT COUNT(DISTINCT seller_id) AS c FROM stalls WHERE event_id = ?', [$eventId]);

        return (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Human-readable labels from JSON snapshot stored on event_participants. */
function qb_application_categories_label(?string $applicationCategoriesJson): string {
    $slugs = qb_decode_categories_json($applicationCategoriesJson);
    if ($slugs === []) {
        return '';
    }

    return qb_seller_categories_labels($slugs);
}

/** Admin verification gate for sellers (organizers may assign only verified sellers). */
function qb_ensure_seller_verification_schema(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    if (!function_exists('qb_has_column')) {
        return;
    }
    try {
        if (!qb_has_column('sellers', 'verification_status')) {
            db()->execute(
                "ALTER TABLE sellers ADD COLUMN verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified'"
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    try {
        if (!qb_has_column('sellers', 'verified_at')) {
            db()->execute('ALTER TABLE sellers ADD COLUMN verified_at DATETIME NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    try {
        if (!qb_has_column('sellers', 'verified_by_app_user_id')) {
            db()->execute('ALTER TABLE sellers ADD COLUMN verified_by_app_user_id INT NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    try {
        if (!qb_has_column('sellers', 'verification_note')) {
            db()->execute('ALTER TABLE sellers ADD COLUMN verification_note VARCHAR(500) NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }
}
