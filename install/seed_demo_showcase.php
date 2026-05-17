<?php
/**
 * Demo showcase seeder
 * Run: php install/seed_demo_showcase.php
 *
 * Creates:
 * - many users (admin/organizer/seller/buyer)
 * - seller shops, products, commodity images
 * - past/live/future events + participants + stalls + tickets
 * - transactions + transaction items
 * - flash sales (if table exists)
 * - promo posts (image/video/text marquee-style)
 * - event staff gatekeepers (if table exists)
 * - credentials file at install/demo_credentials.txt
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function tExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute([DB_NAME, $table]);
    return (int) $st->fetchColumn() > 0;
}

function cExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([DB_NAME, $table, $col]);
    return (int) $st->fetchColumn() > 0;
}

function one(PDO $pdo, string $sql, array $bind = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function all(PDO $pdo, string $sql, array $bind = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function execQ(PDO $pdo, string $sql, array $bind = []): void {
    $st = $pdo->prepare($sql);
    $st->execute($bind);
}

function ins(PDO $pdo, string $table, array $data): int {
    $cols = array_keys($data);
    $vals = array_values($data);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
    execQ($pdo, $sql, $vals);
    return (int) $pdo->lastInsertId();
}

function upsertBy(PDO $pdo, string $table, array $where, array $data): int {
    $wCols = array_keys($where);
    $wVals = array_values($where);
    $wSql = implode(' AND ', array_map(static fn($k) => $k . ' = ?', $wCols));
    $row = one($pdo, 'SELECT id FROM ' . $table . ' WHERE ' . $wSql . ' LIMIT 1', $wVals);
    if ($row) {
        $setCols = array_keys($data);
        $setVals = array_values($data);
        if (!empty($setCols)) {
            $setSql = implode(',', array_map(static fn($k) => $k . ' = ?', $setCols));
            execQ($pdo, 'UPDATE ' . $table . ' SET ' . $setSql . ' WHERE id = ?', array_merge($setVals, [(int)$row['id']]));
        }
        return (int) $row['id'];
    }
    return ins($pdo, $table, array_merge($where, $data));
}

function randPick(array $arr) {
    return $arr[array_rand($arr)];
}

/**
 * Stable Unsplash image for demo products — used on insert and to backfill NULL/empty image_url.
 */
function seed_product_image_url(string $name, string $category): string {
    $hay = strtolower($name . ' ' . $category);
    $pairs = [
        'tomato' => 'https://images.unsplash.com/photo-1546094096-0df4bcaaa337?auto=format&fit=crop&w=900&q=70',
        'potato' => 'https://images.unsplash.com/photo-1518977676601-b53f82aba655?auto=format&fit=crop&w=900&q=70',
        'carrot' => 'https://images.unsplash.com/photo-1447175008436-170170753d51?auto=format&fit=crop&w=900&q=70',
        'onion' => 'https://images.unsplash.com/photo-1518977959857-f760c2880529?auto=format&fit=crop&w=900&q=70',
        'pepper' => 'https://images.unsplash.com/photo-1563565375-f3fdfdbefa83?auto=format&fit=crop&w=900&q=70',
        'banana' => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=900&q=70',
        'avocado' => 'https://images.unsplash.com/photo-1601039641847-7857b994d704?auto=format&fit=crop&w=900&q=70',
        'strawberry' => 'https://images.unsplash.com/photo-1464965911861-746a04b4bca6?auto=format&fit=crop&w=900&q=70',
        'orange' => 'https://images.unsplash.com/photo-1547514701-42782101795e?auto=format&fit=crop&w=900&q=70',
        'apple' => 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=900&q=70',
        'mango' => 'https://images.unsplash.com/photo-1605027990121-cbae4b1bbc57?auto=format&fit=crop&w=900&q=70',
        'coffee' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=900&q=70',
        'tea' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=900&q=70',
        'milk' => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?auto=format&fit=crop&w=900&q=70',
        'cheese' => 'https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?auto=format&fit=crop&w=900&q=70',
        'yogurt' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=900&q=70',
        'honey' => 'https://images.unsplash.com/photo-1587049633312-d628ae50a8ae?auto=format&fit=crop&w=900&q=70',
        'egg' => 'https://images.unsplash.com/photo-1582722872445-44dc5f07e124?auto=format&fit=crop&w=900&q=70',
        'bread' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=900&q=70',
        'cake' => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?auto=format&fit=crop&w=900&q=70',
        'cookie' => 'https://images.unsplash.com/photo-1499636136210-6f66ee425819?auto=format&fit=crop&w=900&q=70',
        'rice' => 'https://images.unsplash.com/photo-1584270354949-c66b0d3770f9?auto=format&fit=crop&w=900&q=70',
        'pasta' => 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?auto=format&fit=crop&w=900&q=70',
        'chicken' => 'https://images.unsplash.com/photo-1604503468506-a8da13d82791?auto=format&fit=crop&w=900&q=70',
        'fish' => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=900&q=70',
        'spice' => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=900&q=70',
        'herb' => 'https://images.unsplash.com/photo-1618375529961-c5036a66ed88?auto=format&fit=crop&w=900&q=70',
        'smartphone' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=70',
        'laptop' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=70',
        'headphone' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=900&q=70',
        'sofa' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=900&q=70',
        'chair' => 'https://images.unsplash.com/photo-1503602642458-232111445657?auto=format&fit=crop&w=900&q=70',
        'table' => 'https://images.unsplash.com/photo-1530018607912-effad9753d90?auto=format&fit=crop&w=900&q=70',
        'bed' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=70',
        'lamp' => 'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=900&q=70',
        'paint' => 'https://images.unsplash.com/photo-1562259949-e8e7689d7828?auto=format&fit=crop&w=900&q=70',
        'tool' => 'https://images.unsplash.com/photo-1504148455328-c376907d081c?auto=format&fit=crop&w=900&q=70',
        'stove' => 'https://images.unsplash.com/photo-1556911220-bff31c812dba?auto=format&fit=crop&w=900&q=70',
        'shirt' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=70',
        'dress' => 'https://images.unsplash.com/photo-1595777457583-95e059d581b8?auto=format&fit=crop&w=900&q=70',
        'shoe' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=70',
        'water' => 'https://images.unsplash.com/photo-1564419320408-38e24e038c1d?auto=format&fit=crop&w=900&q=70',
        'juice' => 'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=900&q=70',
        'soda' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?auto=format&fit=crop&w=900&q=70',
        'flour' => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=900&q=70',
        'oil' => 'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?auto=format&fit=crop&w=900&q=70',
        'curtain' => 'https://images.unsplash.com/photo-1585128798170-8375d9f88308?auto=format&fit=crop&w=900&q=70',
        'rug' => 'https://images.unsplash.com/photo-1600166898405-6e4b6f66fb82?auto=format&fit=crop&w=900&q=70',
    ];
    foreach ($pairs as $needle => $url) {
        if (str_contains($hay, $needle)) {
            return $url;
        }
    }
    $byCat = [
        'vegetables' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=900&q=70',
        'fruits' => 'https://images.unsplash.com/photo-1619566636858-adf3ef46443b?auto=format&fit=crop&w=900&q=70',
        'spices' => 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=900&q=70',
        'dairy' => 'https://images.unsplash.com/photo-1550583724-b2692b85b150?auto=format&fit=crop&w=900&q=70',
        'coffee' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=900&q=70',
        'bakery' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=900&q=70',
        'herbs' => 'https://images.unsplash.com/photo-1618375529961-c5036a66ed88?auto=format&fit=crop&w=900&q=70',
        'beverages' => 'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=900&q=70',
        'mobiles' => 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=70',
        'furniture' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=900&q=70',
        'home materials' => 'https://images.unsplash.com/photo-1562259949-e8e7689d7828?auto=format&fit=crop&w=900&q=70',
        'stoves' => 'https://images.unsplash.com/photo-1556911220-bff31c812dba?auto=format&fit=crop&w=900&q=70',
        'clothes' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=900&q=70',
        'general' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=900&q=70',
        'foods' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=900&q=70',
    ];
    $ck = strtolower(trim($category));
    foreach ($byCat as $k => $url) {
        if ($ck === $k || str_contains($ck, $k)) {
            return $url;
        }
    }

    return 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=900&q=70';
}

echo "--- seed_demo_showcase ---\n";

$required = ['app_users', 'sellers', 'bazar_events', 'products', 'transactions', 'transaction_items', 'tickets', 'event_participants', 'stalls'];
foreach ($required as $t) {
    if (!tExists($pdo, $t)) {
        throw new RuntimeException("Missing table: {$t}. Run base migrations first.");
    }
}

$now = time();
$defaultPass = 'Demo@12345';
$hash = password_hash($defaultPass, PASSWORD_DEFAULT);
$credentials = [];

// 1) Users
$adminId = upsertBy($pdo, 'app_users', ['login_uid' => 'admin.demo'], [
    'password_hash' => $hash,
    'display_name' => 'Demo Super Admin',
    'role' => 'super_admin',
    'phone' => '0911000001',
    'email' => 'admin.demo@qrbazar.local',
    'residence_city' => 'Addis Ababa',
    'is_active' => 1,
]);
$credentials[] = "super_admin | admin.demo | {$defaultPass}";

$organizerNames = ['Aster Events', 'Blue Nile Organizer', 'Merkato Programs'];
$organizerIds = [];
foreach ($organizerNames as $i => $name) {
    $uid = 'org.demo' . ($i + 1);
    $id = upsertBy($pdo, 'app_users', ['login_uid' => $uid], [
        'password_hash' => $hash,
        'display_name' => $name,
        'role' => 'organizer',
        'phone' => '09110000' . str_pad((string)($i + 10), 2, '0', STR_PAD_LEFT),
        'email' => str_replace(' ', '.', strtolower($name)) . '@qrbazar.local',
        'residence_city' => randPick(['Addis Ababa', 'Adama', 'Bahir Dar']),
        'is_active' => 1,
    ]);
    $organizerIds[] = $id;
    $credentials[] = "organizer | {$uid} | {$defaultPass}";
}

$sellerProfiles = [];
$sellerCategoryProfiles = [
    ['category' => 'Bakery', 'market' => ['Golden Bakery', 'Fresh Oven', 'Morning Bread'], 'commodities' => ['Bakery', 'Beverages', 'Foods']],
    ['category' => 'Tech', 'market' => ['Tech Hub', 'Smart World', 'Digital Point'], 'commodities' => ['Mobiles', 'Home Materials']],
    ['category' => 'Vegetables', 'market' => ['Green Basket', 'Farm Leaf', 'Fresh Harvest'], 'commodities' => ['Vegetables', 'Herbs']],
    ['category' => 'Fruits', 'market' => ['Fruit Garden', 'Juicy Corner', 'Sweet Basket'], 'commodities' => ['Fruits', 'Beverages']],
    ['category' => 'Dairy', 'market' => ['Milk House', 'Dairy Valley', 'Fresh Cream'], 'commodities' => ['Dairy', 'Foods']],
    ['category' => 'Coffee', 'market' => ['Buna House', 'Coffee Craft', 'Roast Market'], 'commodities' => ['Coffee', 'Spices', 'Beverages']],
    ['category' => 'Furniture', 'market' => ['Urban Furniture', 'Home Wood', 'Living Space'], 'commodities' => ['Furniture', 'Home Materials']],
    ['category' => 'Fashion', 'market' => ['Style Market', 'Trend Wear', 'Cotton Corner'], 'commodities' => ['Clothes']],
];
for ($i = 1; $i <= 24; $i++) {
    $login = 'seller.demo' . $i;
    $display = 'Seller Demo ' . $i;
    $phone = '091200' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $appUserId = upsertBy($pdo, 'app_users', ['login_uid' => $login], [
        'password_hash' => $hash,
        'display_name' => $display,
        'role' => 'seller',
        'phone' => $phone,
        'email' => "seller{$i}@qrbazar.local",
        'residence_city' => randPick(['Addis Ababa', 'Adama', 'Hawassa', 'Mekelle']),
        'is_active' => 1,
    ]);
    $sellerUid = 'SEL' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $profile = $sellerCategoryProfiles[($i - 1) % count($sellerCategoryProfiles)];
    $market = randPick($profile['market']) . ' ' . $i;
    $sellerData = [
        'full_name' => $display,
        'market_name' => $market,
        'phone' => $phone,
        'email' => "seller{$i}@qrbazar.local",
        'password_hash' => $hash,
        'location' => randPick(['Merkato', 'Bole', 'Piassa', 'CMC', 'Mexico']),
        'category' => $profile['category'],
        'qr_secret' => bin2hex(random_bytes(16)),
        'allow_direct_sales' => 1,
        'is_active' => 1,
    ];
    if (cExists($pdo, 'sellers', 'stall_tagline')) {
        $sellerData['stall_tagline'] = randPick([
            'Farm-picked this morning',
            'Trusted quality, fair prices',
            'Fresh every day',
            'Local produce, better taste',
        ]);
    }
    $sellerId = upsertBy($pdo, 'sellers', ['app_user_id' => $appUserId], array_merge(['uid' => $sellerUid], $sellerData));
    $sellerProfiles[] = [
        'seller_id' => $sellerId,
        'app_user_id' => $appUserId,
        'market_name' => $market,
        'phone' => $phone,
        'seller_category' => $profile['category'],
        'commodity_categories' => $profile['commodities'],
    ];
    $credentials[] = "seller | {$login} | {$defaultPass}";
}

$buyerIds = [];
for ($i = 1; $i <= 26; $i++) {
    $login = 'buyer.demo' . $i;
    $phone = '091300' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
    $id = upsertBy($pdo, 'app_users', ['login_uid' => $login], [
        'password_hash' => $hash,
        'display_name' => 'Buyer Demo ' . $i,
        'role' => 'buyer',
        'phone' => $phone,
        'email' => "buyer{$i}@qrbazar.local",
        'residence_city' => randPick(['Addis Ababa', 'Adama', 'Hawassa', 'Bahir Dar']),
        'is_active' => 1,
    ]);
    $buyerIds[] = $id;
    $credentials[] = "buyer | {$login} | {$defaultPass}";
}

// 2) Events (past / present / future)
$events = [
    ['slug' => 'spring-harvest-2026', 'name' => 'Spring Harvest Expo', 'status' => 'ended', 'start' => strtotime('-45 days'), 'days' => 2],
    ['slug' => 'coffee-spice-fair-2026', 'name' => 'Coffee & Spice Fair', 'status' => 'ended', 'start' => strtotime('-20 days'), 'days' => 3],
    ['slug' => 'city-fresh-live-2026', 'name' => 'City Fresh Live Market', 'status' => 'live', 'start' => strtotime('-1 day'), 'days' => 3],
    ['slug' => 'family-food-bazar-2026', 'name' => 'Family Food Bazar', 'status' => 'published', 'start' => strtotime('+5 days'), 'days' => 2],
    ['slug' => 'organic-weekend-2026', 'name' => 'Organic Weekend Pop-up', 'status' => 'published', 'start' => strtotime('+14 days'), 'days' => 2],
    ['slug' => 'future-farm-festival-2026', 'name' => 'Future Farm Festival', 'status' => 'draft', 'start' => strtotime('+35 days'), 'days' => 4],
    ['slug' => 'diredawa-tech-market-2026', 'name' => 'Dire Dawa Tech Market (Thu-Fri-Sat)', 'status' => 'published', 'start' => strtotime('next thursday 09:00'), 'days' => 3],
    ['slug' => 'diredawa-friday-market-2026', 'name' => 'Dire Dawa Friday Market', 'status' => 'published', 'start' => strtotime('next friday 09:00'), 'days' => 1],
    ['slug' => 'diredawa-saturday-market-2026', 'name' => 'Dire Dawa Saturday Market', 'status' => 'published', 'start' => strtotime('next saturday 09:00'), 'days' => 1],
    ['slug' => 'diredawa-home-style-2026', 'name' => 'Dire Dawa Home Style', 'status' => 'published', 'start' => strtotime('+9 days'), 'days' => 2],
    ['slug' => 'diredawa-family-week-2026', 'name' => 'Dire Dawa Family Week', 'status' => 'published', 'start' => strtotime('+21 days'), 'days' => 3],
];

$eventRows = [];
foreach ($events as $idx => $ev) {
    $orgId = $organizerIds[$idx % count($organizerIds)];
    $start = $ev['start'];
    $end = strtotime('+' . $ev['days'] . ' days', $start);
    $salesStart = strtotime('-12 days', $start);
    $salesEnd = strtotime('-1 day', $end);
    $eid = upsertBy($pdo, 'bazar_events', ['slug' => $ev['slug']], [
        'name' => $ev['name'],
        'venue' => randPick(['Millennium Hall', 'Exhibition Center', 'Friendship Hall', 'Unity Arena', 'Dire Dawa Trade Hall']),
        'city' => strpos($ev['slug'], 'diredawa-') === 0 ? 'Dire Dawa' : randPick(['Addis Ababa', 'Adama', 'Hawassa', 'Bahir Dar']),
        'organizer_app_user_id' => $orgId,
        'lat' => 9.03 + (mt_rand(-40, 40) / 1000),
        'lng' => 38.74 + (mt_rand(-40, 40) / 1000),
        'radius_meters' => 550,
        'max_sellers' => 120,
        'ticket_sales_start' => date('Y-m-d H:i:s', $salesStart),
        'ticket_sales_end' => date('Y-m-d H:i:s', $salesEnd),
        'event_start' => date('Y-m-d H:i:s', $start),
        'event_end' => date('Y-m-d H:i:s', $end),
        'status' => $ev['status'],
        'notes' => 'Demo data generated for showcase.',
    ]);
    $eventRows[] = ['id' => $eid, 'status' => $ev['status'], 'start' => $start, 'end' => $end];
}

// 3) Participants + stalls
foreach ($eventRows as $ev) {
    $eid = (int)$ev['id'];
    $sellerSample = $sellerProfiles;
    shuffle($sellerSample);
    $sellerSample = array_slice($sellerSample, 0, 9);
    foreach ($sellerSample as $si => $s) {
        upsertBy($pdo, 'event_participants', ['event_id' => $eid, 'app_user_id' => (int)$s['app_user_id']], [
            'role_in_event' => 'seller',
            'status' => 'approved',
        ]);
        upsertBy($pdo, 'stalls', ['event_id' => $eid, 'seller_id' => (int)$s['seller_id']], [
            'stall_number' => 'S-' . str_pad((string)($si + 1), 3, '0', STR_PAD_LEFT),
            'lat' => 9.03 + (mt_rand(-20, 20) / 2000),
            'lng' => 38.74 + (mt_rand(-20, 20) / 2000),
        ]);
    }

    $buyerSample = $buyerIds;
    shuffle($buyerSample);
    $buyerSample = array_slice($buyerSample, 0, 18);
    foreach ($buyerSample as $bid) {
        upsertBy($pdo, 'event_participants', ['event_id' => $eid, 'app_user_id' => (int)$bid], [
            'role_in_event' => 'buyer',
            'status' => 'approved',
        ]);
        $ticketCode = 'TKT' . $eid . str_pad((string)$bid, 5, '0', STR_PAD_LEFT);
        $ticketStatus = 'active';
        $usedAt = null;
        if ($ev['status'] === 'ended') {
            $ticketStatus = (mt_rand(0, 100) < 85) ? 'used' : 'active';
            if ($ticketStatus === 'used') {
                $usedAt = date('Y-m-d H:i:s', strtotime('-1 day', $ev['end']));
            }
        }
        upsertBy($pdo, 'tickets', ['ticket_code' => $ticketCode], [
            'buyer_id' => (int)$bid,
            'event_id' => $eid,
            'qr_data' => 'TICKET|' . $ticketCode . '|' . $eid,
            'status' => $ticketStatus,
            'used_at' => $usedAt,
        ]);
    }
}

// 4) Products with commodity images (every row gets a real image URL)
$commodityNames = [
    ['Tomato', 'Fresh organic tomato', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1546094096-0df4bcaaa337?auto=format&fit=crop&w=900&q=70'],
    ['Potato', 'Clean farm potato', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1518977676601-b53f82aba655?auto=format&fit=crop&w=900&q=70'],
    ['Carrot', 'Crunchy carrot bundle', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1447175008436-170170753d51?auto=format&fit=crop&w=900&q=70'],
    ['Red Onion', 'Cooking onion sack', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1518977959857-f760c2880529?auto=format&fit=crop&w=900&q=70'],
    ['Green Pepper', 'Bell pepper mix', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1563565375-f3fdfdbefa83?auto=format&fit=crop&w=900&q=70'],
    ['Banana', 'Sweet banana bunch', 'dozen', 'Fruits', 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=900&q=70'],
    ['Avocado', 'Creamy avocado', 'piece', 'Fruits', 'https://images.unsplash.com/photo-1601039641847-7857b994d704?auto=format&fit=crop&w=900&q=70'],
    ['Strawberry', 'Fresh strawberry pack', 'box', 'Fruits', 'https://images.unsplash.com/photo-1464965911861-746a04b4bca6?auto=format&fit=crop&w=900&q=70'],
    ['Orange', 'Juicy oranges', 'kg', 'Fruits', 'https://images.unsplash.com/photo-1547514701-42782101795e?auto=format&fit=crop&w=900&q=70'],
    ['Apple', 'Crisp apples', 'kg', 'Fruits', 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=900&q=70'],
    ['Mango', 'Sweet mangoes', 'kg', 'Fruits', 'https://images.unsplash.com/photo-1605027990121-cbae4b1bbc57?auto=format&fit=crop&w=900&q=70'],
    ['Coffee Beans', 'Ethiopian arabica beans', 'kg', 'Coffee', 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=900&q=70'],
    ['Green Tea', 'Loose leaf tea', 'pack', 'Coffee', 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=900&q=70'],
    ['Milk', 'Local fresh milk', 'liter', 'Dairy', 'https://images.unsplash.com/photo-1563636619-e9143da7973b?auto=format&fit=crop&w=900&q=70'],
    ['Cheese', 'Soft farmer cheese', 'pack', 'Dairy', 'https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?auto=format&fit=crop&w=900&q=70'],
    ['Yogurt Cup', 'Creamy yogurt', 'pack', 'Dairy', 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=900&q=70'],
    ['Honey', 'Natural forest honey', 'jar', 'General', 'https://images.unsplash.com/photo-1587049633312-d628ae50a8ae?auto=format&fit=crop&w=900&q=70'],
    ['Brown Eggs', 'Farm eggs tray', 'tray', 'Dairy', 'https://images.unsplash.com/photo-1582722872445-44dc5f07e124?auto=format&fit=crop&w=900&q=70'],
    ['Sourdough Bread', 'Artisan loaf', 'piece', 'Bakery', 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=900&q=70'],
    ['Birthday Cake', 'Vanilla celebration cake', 'piece', 'Bakery', 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?auto=format&fit=crop&w=900&q=70'],
    ['Butter Cookies', 'Crisp cookies box', 'box', 'Bakery', 'https://images.unsplash.com/photo-1499636136210-6f66ee425819?auto=format&fit=crop&w=900&q=70'],
    ['Basmati Rice', 'Long grain rice bag', 'kg', 'Foods', 'https://images.unsplash.com/photo-1584270354949-c66b0d3770f9?auto=format&fit=crop&w=900&q=70'],
    ['Penne Pasta', 'Durum wheat pasta', 'pack', 'Foods', 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?auto=format&fit=crop&w=900&q=70'],
    ['Chicken Thighs', 'Fresh poultry', 'kg', 'Foods', 'https://images.unsplash.com/photo-1604503468506-a8da13d82791?auto=format&fit=crop&w=900&q=70'],
    ['Salmon Fillet', 'Chilled fish', 'kg', 'Foods', 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=900&q=70'],
    ['Berbere Spice', 'Traditional spice blend', 'jar', 'Spices', 'https://images.unsplash.com/photo-1596040033229-a9821ebd058d?auto=format&fit=crop&w=900&q=70'],
    ['Fresh Mint', 'Bunch for tea and dishes', 'bunch', 'Herbs', 'https://images.unsplash.com/photo-1618375529961-c5036a66ed88?auto=format&fit=crop&w=900&q=70'],
    ['Smartphone', 'Latest Android smartphone', 'piece', 'Mobiles', 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=70'],
    ['Laptop', 'Work and study laptop', 'piece', 'Mobiles', 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=70'],
    ['Wireless Headphones', 'Noise isolating headset', 'piece', 'Mobiles', 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=900&q=70'],
    ['Wood Sofa', 'Living room sofa set', 'set', 'Furniture', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=900&q=70'],
    ['Dining Chair', 'Solid wood chair', 'piece', 'Furniture', 'https://images.unsplash.com/photo-1503602642458-232111445657?auto=format&fit=crop&w=900&q=70'],
    ['Coffee Table', 'Compact living table', 'piece', 'Furniture', 'https://images.unsplash.com/photo-1530018607912-effad9753d90?auto=format&fit=crop&w=900&q=70'],
    ['Queen Bed Frame', 'Bedroom frame', 'set', 'Furniture', 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=70'],
    ['Desk Lamp', 'LED study lamp', 'piece', 'Furniture', 'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=900&q=70'],
    ['Wall Paint', 'Interior wall paint bucket', 'box', 'Home Materials', 'https://images.unsplash.com/photo-1562259949-e8e7689d7828?auto=format&fit=crop&w=900&q=70'],
    ['Power Drill', 'Cordless drill kit', 'set', 'Home Materials', 'https://images.unsplash.com/photo-1504148455328-c376907d081c?auto=format&fit=crop&w=900&q=70'],
    ['Window Curtains', 'Thermal curtains pair', 'pair', 'Home Materials', 'https://images.unsplash.com/photo-1585128798170-8375d9f88308?auto=format&fit=crop&w=900&q=70'],
    ['Area Rug', 'Soft living rug', 'piece', 'Home Materials', 'https://images.unsplash.com/photo-1600166898405-6e4b6f66fb82?auto=format&fit=crop&w=900&q=70'],
    ['Gas Stove', 'Two-burner gas stove', 'piece', 'Stoves', 'https://images.unsplash.com/photo-1556911220-bff31c812dba?auto=format&fit=crop&w=900&q=70'],
    ['Cotton Shirt', 'Comfortable cotton shirt', 'piece', 'Clothes', 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=70'],
    ['Summer Dress', 'Light cotton dress', 'piece', 'Clothes', 'https://images.unsplash.com/photo-1595777457583-95e059d581b8?auto=format&fit=crop&w=900&q=70'],
    ['Running Shoes', 'Sport sneakers', 'pair', 'Clothes', 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=70'],
    ['Mineral Water', 'Pure drinkable water', 'liter', 'Beverages', 'https://images.unsplash.com/photo-1564419320408-38e24e038c1d?auto=format&fit=crop&w=900&q=70'],
    ['Orange Juice', 'Fresh pressed juice', 'liter', 'Beverages', 'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=900&q=70'],
    ['Cola Pack', 'Chilled soda multipack', 'pack', 'Beverages', 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?auto=format&fit=crop&w=900&q=70'],
    ['Wheat Flour', 'Baking flour sack', 'kg', 'Bakery', 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=900&q=70'],
    ['Cooking Oil', 'Vegetable oil bottle', 'liter', 'Foods', 'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?auto=format&fit=crop&w=900&q=70'],
];

$productIdsBySeller = [];
$productsPerSeller = min(14, count($commodityNames));
foreach ($sellerProfiles as $s) {
    $sid = (int)$s['seller_id'];
    $allowed = array_map('strtolower', (array) ($s['commodity_categories'] ?? []));
    $sample = array_values(array_filter($commodityNames, static function (array $c) use ($allowed): bool {
        $cat = strtolower((string) ($c[3] ?? ''));
        if ($allowed === []) {
            return true;
        }
        return in_array($cat, $allowed, true);
    }));
    if ($sample === []) {
        $sample = $commodityNames;
    }
    shuffle($sample);
    $sample = array_slice($sample, 0, min($productsPerSeller, count($sample)));
    foreach ($sample as $pi => $c) {
        [$name, $desc, $unit, $cat, $img] = $c;
        $img = $img !== '' ? $img : seed_product_image_url($name, $cat);
        $price = mt_rand(40, 460);
        $discount = mt_rand(0, 100) < 40 ? mt_rand(5, 25) : 0;
        $pid = upsertBy($pdo, 'products', ['seller_id' => $sid, 'name' => $name . ' ' . ($pi + 1)], [
            'description' => $desc,
            'price' => $price,
            'discount_pct' => $discount,
            'unit' => $unit,
            'stock' => mt_rand(15, 140),
            'image_url' => $img,
            'category' => $cat,
            'is_available' => 1,
        ]);
        if (!isset($productIdsBySeller[$sid])) {
            $productIdsBySeller[$sid] = [];
        }
        $productIdsBySeller[$sid][] = $pid;
    }
}

// 4b) Every product must show an image (covers older rows / manual inserts without image_url)
if (cExists($pdo, 'products', 'image_url')) {
    $allProducts = all($pdo, 'SELECT id, name, category, image_url FROM products');
    foreach ($allProducts as $pr) {
        $url = trim((string) ($pr['image_url'] ?? ''));
        if ($url === '') {
            $fix = seed_product_image_url((string) ($pr['name'] ?? ''), (string) ($pr['category'] ?? ''));
            execQ($pdo, 'UPDATE products SET image_url = ? WHERE id = ?', [$fix, (int) $pr['id']]);
        }
    }
}

// 5) Flash sales
if (tExists($pdo, 'flash_sales')) {
    foreach ($productIdsBySeller as $sid => $pidList) {
        $pick = array_slice($pidList, 0, 2);
        foreach ($pick as $pid) {
            $pr = one($pdo, 'SELECT price FROM products WHERE id = ?', [$pid]);
            $price = (float)($pr['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $pct = mt_rand(10, 35);
            $sale = round($price * (1 - ($pct / 100)), 2);
            upsertBy($pdo, 'flash_sales', ['product_id' => $pid, 'seller_id' => $sid], [
                'event_id' => null,
                'discount_pct' => $pct,
                'original_price' => $price,
                'sale_price' => $sale,
                'starts_at' => date('Y-m-d H:i:s', strtotime('-1 day', $now)),
                'ends_at' => date('Y-m-d H:i:s', strtotime('+7 days', $now)),
                'is_active' => 1,
            ]);
        }
    }
}

// 6) Transactions + items for history
$liveOrPastEvents = array_values(array_filter($eventRows, static fn($e) => in_array($e['status'], ['live', 'ended'], true)));
for ($t = 1; $t <= 120; $t++) {
    $seller = randPick($sellerProfiles);
    $sid = (int)$seller['seller_id'];
    $buyer = (int)randPick($buyerIds);
    $ev = randPick($liveOrPastEvents);
    $pidList = $productIdsBySeller[$sid] ?? [];
    if (empty($pidList)) {
        continue;
    }
    $pid = (int)randPick($pidList);
    $prod = one($pdo, 'SELECT name, price, discount_pct, unit FROM products WHERE id = ?', [$pid]);
    if (!$prod) {
        continue;
    }
    $qty = mt_rand(1, 4);
    $unit = (float)$prod['price'] * (1 - ((int)$prod['discount_pct'] / 100));
    $total = round($qty * $unit, 2);
    $txCode = 'DEMO' . str_pad((string)$t, 6, '0', STR_PAD_LEFT);
    $methods = ['cash', 'telebirr', 'p2p', 'wallet_qr'];
    $method = randPick($methods);
    $status = 'completed';
    if ($method === 'cash' && cExists($pdo, 'transactions', 'payment_status')) {
        $status = (tExists($pdo, 'transaction_cash_confirms') && mt_rand(0, 100) < 35) ? 'pending_confirmation' : 'completed';
    }
    $buyerUser = one($pdo, 'SELECT display_name, phone FROM app_users WHERE id = ?', [$buyer]);
    $txId = upsertBy($pdo, 'transactions', ['tx_id' => $txCode], [
        'seller_id' => $sid,
        'buyer_id' => $buyer,
        'event_id' => (int)$ev['id'],
        'buyer_name' => (string)($buyerUser['display_name'] ?? 'Buyer'),
        'buyer_phone' => (string)($buyerUser['phone'] ?? ''),
        'total_amount' => $total,
        'payment_method' => $method,
        'payment_status' => $status,
        'notes' => 'Demo seeded transaction',
        'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 35) . ' days')),
    ]);
    upsertBy($pdo, 'transaction_items', ['transaction_id' => $txId, 'product_id' => $pid], [
        'product_name' => (string)$prod['name'],
        'unit_price' => $unit,
        'quantity' => $qty,
        'subtotal' => $total,
    ]);
}

// 7) Gatekeepers (event_staff mini users)
if (tExists($pdo, 'event_staff')) {
    for ($i = 1; $i <= 6; $i++) {
        $login = 'gate.demo' . $i;
        $id = upsertBy($pdo, 'app_users', ['login_uid' => $login], [
            'password_hash' => $hash,
            'display_name' => 'Gate Keeper ' . $i,
            'role' => 'gatekeeper',
            'phone' => '091400' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
            'email' => "gate{$i}@qrbazar.local",
            'residence_city' => 'Addis Ababa',
            'is_active' => 1,
        ]);
        $ev = randPick($eventRows);
        $cols = ['app_user_id', 'event_id', 'valid_until'];
        $vals = [$id, (int)$ev['id'], date('Y-m-d H:i:s', strtotime('+120 days'))];
        $row = one($pdo, 'SELECT 1 AS o FROM event_staff WHERE app_user_id = ? AND event_id = ? LIMIT 1', [$id, (int)$ev['id']]);
        if (!$row) {
            ins($pdo, 'event_staff', array_combine($cols, $vals));
        }
        $credentials[] = "gatekeeper | {$login} | {$defaultPass}";
    }
}

// 8) Announcements (marquee-like)
if (tExists($pdo, 'event_announcements')) {
    foreach (array_slice($eventRows, 0, 3) as $ev) {
        $org = one($pdo, 'SELECT organizer_app_user_id FROM bazar_events WHERE id = ?', [(int)$ev['id']]);
        $orgId = (int)($org['organizer_app_user_id'] ?? 0);
        if ($orgId <= 0) {
            continue;
        }
        upsertBy($pdo, 'event_announcements', ['event_id' => (int)$ev['id'], 'title' => 'MARQUEE: Live deals every hour'], [
            'organizer_id' => $orgId,
            'body' => 'Welcome! Scan tickets at gate, explore discount zones, and check top sellers leaderboard.',
        ]);
        upsertBy($pdo, 'event_announcements', ['event_id' => (int)$ev['id'], 'title' => 'MARQUEE: Phones, furniture, clothes, coffee and more'], [
            'organizer_id' => $orgId,
            'body' => 'Compare categories, filter by seller, and discover new offers every day.',
        ]);
        upsertBy($pdo, 'event_announcements', ['event_id' => (int)$ev['id'], 'title' => 'MARQUEE: Video promos now live'], [
            'organizer_id' => $orgId,
            'body' => 'Watch seller promo videos to preview stock before visiting stalls.',
        ]);
    }
}

$direDawaEvent = one($pdo, "SELECT id, organizer_app_user_id FROM bazar_events WHERE slug = 'diredawa-tech-market-2026' LIMIT 1");
if ($direDawaEvent && tExists($pdo, 'event_announcements')) {
    $ddId = (int) ($direDawaEvent['id'] ?? 0);
    $ddOrg = (int) ($direDawaEvent['organizer_app_user_id'] ?? 0);
    if ($ddId > 0 && $ddOrg > 0) {
        upsertBy($pdo, 'event_announcements', ['event_id' => $ddId, 'title' => 'Dire Dawa Thu-Fri-Sat Program'], [
            'organizer_id' => $ddOrg,
            'body' => 'Thursday launch, Friday peak discounts, Saturday finale awards. Follow promo videos and marquee updates.',
        ]);
        upsertBy($pdo, 'event_announcements', ['event_id' => $ddId, 'title' => 'Dire Dawa Seller Battle'], [
            'organizer_id' => $ddOrg,
            'body' => 'Top sellers in phones, furniture, clothes, coffee, bakery, and home goods compete for best value and service.',
        ]);
        upsertBy($pdo, 'event_announcements', ['event_id' => $ddId, 'title' => 'Dire Dawa Competition Route'], [
            'organizer_id' => $ddOrg,
            'body' => 'Scan at gate, open event mode, then visit top promo stalls in sequence for the full competition experience.',
        ]);
    }
}

// extra marquee text on events when branding column exists
if (cExists($pdo, 'bazar_events', 'marquee_text')) {
    foreach ($eventRows as $ev) {
        $marq = randPick([
            'Today: coffee corners, mobile tech stalls, furniture deals, and family food zones.',
            'Live now: compare prices across clothes, stoves, home materials, and drinks.',
            'New arrivals every hour — check Discover and promo videos for the latest stock.',
            'Event mode on: navigate stalls quickly and catch flash deals before they end.',
            'Support local sellers — fresh foods, bakery, phones, furniture, and more.',
        ]);
        execQ($pdo, 'UPDATE bazar_events SET marquee_text = ? WHERE id = ?', [$marq, (int) $ev['id']]);
    }
}

// 9) Promo posts: image + video + marquee text
if (tExists($pdo, 'promo_posts')) {
    $ownerSeller = $sellerProfiles[0]['seller_id'] ?? 0;
    $ownerOrg = $organizerIds[0] ?? 0;
    if ($ownerSeller > 0) {
        upsertBy($pdo, 'promo_posts', ['title' => 'Fresh Vegetables Mega Drop'], [
            'description' => 'Top quality vegetables now available. New stock every morning. OFFER: 12% off mixed crates today.',
            'content_type' => 'image',
            'media_url' => 'https://images.unsplash.com/photo-1610832958506-aa56368176cf?auto=format&fit=crop&w=1200&q=70',
            'thumbnail_url' => null,
            'owner_type' => 'seller',
            'owner_id' => (int)$ownerSeller,
            'target' => 'homepage',
            'status' => 'active',
            'is_sponsored' => 0,
        ]);
        upsertBy($pdo, 'promo_posts', ['title' => 'How We Pack Fresh Goods'], [
            'description' => 'Behind-the-scenes stall prep. OFFER: 10% off first orders this week.',
            'content_type' => 'image',
            'media_url' => 'https://images.unsplash.com/photo-1556910103-1c02745aae4d?auto=format&fit=crop&w=1200&q=70',
            'thumbnail_url' => null,
            'owner_type' => 'seller',
            'owner_id' => (int)$ownerSeller,
            'target' => 'homepage',
            'status' => 'active',
            'is_sponsored' => 0,
        ]);
        upsertBy($pdo, 'promo_posts', ['title' => 'MARQUEE: Flash deals start every 30 minutes'], [
            'description' => 'Stay in Event Mode and watch for rotating discounts across stalls.',
            'content_type' => 'text',
            'media_url' => null,
            'thumbnail_url' => null,
            'owner_type' => 'seller',
            'owner_id' => (int)$ownerSeller,
            'target' => 'homepage',
            'status' => 'active',
            'is_sponsored' => 0,
        ]);
    }
    if ($ownerOrg > 0) {
        upsertBy($pdo, 'promo_posts', ['title' => 'Organizer Spotlight: Weekend Program'], [
            'description' => 'Family activities, cooking demos, and best-seller awards this weekend. OFFER: free kids activities with any family ticket.',
            'content_type' => 'image',
            'media_url' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=1200&q=70',
            'thumbnail_url' => null,
            'owner_type' => 'organization',
            'owner_id' => (int)$ownerOrg,
            'target' => 'homepage',
            'status' => 'active',
            'is_sponsored' => 1,
        ]);
    }

    // Add many category promos (image/video/text) across sellers
    $categoryPromoPack = [
        ['Phones Mega Promo', 'Best smartphone bundles this week. OFFER: 8% off bundles of 2+.', 'image', 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1200&q=70', null],
        ['Furniture Weekend Deals', 'Sofa, tables, and shelves at promo prices. Save $40 on display sets.', 'image', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=1200&q=70', null],
        ['Clothes New Arrivals', 'Fresh shirts, dresses, and daily wear stock. OFFER: 15% off second item.', 'image', 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=1200&q=70', null],
        ['Coffee Lovers Corner', 'Premium Ethiopian beans and blends. OFFER: buy 2 bags, get 10% off.', 'image', 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=70', null],
        ['Home Materials Value Pack', 'Paint, hardware, and tools in one place. OFFER: up to 18% off bulk paint.', 'image', 'https://images.unsplash.com/photo-1562259949-e8e7689d7828?auto=format&fit=crop&w=1200&q=70', null],
        ['Bakery Daily Fresh', 'Bread and pastry trays baked each morning. OFFER: 20% off after 5pm.', 'image', 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=1200&q=70', null],
        ['Drinkables and Juices', 'Cold drinks and healthy juices now stocked. OFFER: 3 for price of 2 on juices.', 'image', 'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=1200&q=70', null],
        ['Stove and Kitchen Kits', 'Cooking stoves and home kitchen essentials. OFFER: free delivery this weekend.', 'image', 'https://images.unsplash.com/photo-1586201375761-83865001e17e?auto=format&fit=crop&w=1200&q=70', null],
        ['Phone Stock Highlights', 'New phone arrivals — compare models side by side at our stall.', 'image', 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1200&q=70', null],
        ['Coffee Roast Highlights', 'Roast levels and aroma notes explained at the counter.', 'image', 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=70', null],
        ['Furniture Floor Sets', 'Featured living room sets with event-only pricing.', 'image', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=1200&q=70', null],
        ['Clothes Rack Preview', 'Trending outfits and new drops on display.', 'image', 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=1200&q=70', null],
        ['MARQUEE: Phones + furniture + clothes deals today', 'Limited-time category promos are rotating every hour. Stacked offers at select stalls.', 'text', null, null],
        ['MARQUEE: Coffee, bakery, foods, and drinks in one route', 'Use event map and discover filters to find the best stalls fast. OFFER: route map free at info desk.', 'text', null, null],
    ];

    $owners = array_slice($sellerProfiles, 0, 10);
    foreach ($categoryPromoPack as $idx => $pp) {
        $owner = $owners[$idx % max(1, count($owners))] ?? null;
        if (!$owner) {
            continue;
        }
        [$title, $desc, $ctype, $mediaUrl, $thumbUrl] = $pp;
        upsertBy($pdo, 'promo_posts', ['title' => $title], [
            'description' => $desc,
            'content_type' => $ctype,
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbUrl,
            'owner_type' => 'seller',
            'owner_id' => (int) ($owner['seller_id'] ?? 0),
            'target' => 'homepage',
            'status' => 'active',
            'is_sponsored' => 0,
        ]);
    }

    $direDawaPromoPack = [
        ['Dire Dawa Competition Kickoff (Thursday)', 'Welcome to Dire Dawa Tech Market. Thursday opens with early-bird seller promos across all major categories. OFFER: 5% extra off before noon.', 'text', null, null],
        ['Dire Dawa Friday Peak Deals', 'Friday is promo peak: phones, furniture, clothes, coffee, and kitchen goods all run stacked offers.', 'text', null, null],
        ['Dire Dawa Saturday Grand Finale', 'Saturday closes with final markdowns, top-seller highlights, and buyer choice awards. OFFER: up to 25% off clearance racks.', 'text', null, null],
        ['Dire Dawa Phones Arena', 'Top phone sellers show flagship and budget options with side-by-side offers. OFFER: bundle trade-in credit.', 'image', 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1200&q=70', null],
        ['Dire Dawa Furniture Zone', 'Modern sofas, tables, and home sets showcased with competition pricing. Save $60 on floor models.', 'image', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=1200&q=70', null],
        ['Dire Dawa Clothes Runway', 'Seasonal clothing promo lineup with quality and price tags clearly compared. OFFER: 2nd item 20% off.', 'image', 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=1200&q=70', null],
        ['Dire Dawa Coffee Experience', 'Specialty coffee sellers present roast levels, aroma notes, and event bundles. OFFER: tasting flight $5.', 'image', 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=70', null],
        ['Dire Dawa Seller Promo Wall', 'Multi-stall highlights and live category zones — visit the promo wall for today’s map.', 'image', 'https://images.unsplash.com/photo-1520607162513-77705c0f0d4a?auto=format&fit=crop&w=1200&q=70', null],
        ['Dire Dawa Event Highlights', 'Crowd flow, stalls, and marquee updates — plan your route before peak hours.', 'image', 'https://images.unsplash.com/photo-1472653431158-6364773b2a56?auto=format&fit=crop&w=1200&q=70', null],
    ];
    $ownersDd = array_slice($sellerProfiles, 0, 12);
    foreach ($direDawaPromoPack as $i => $pp) {
        [$title, $desc, $ctype, $mediaUrl, $thumbUrl] = $pp;
        $owner = $ownersDd[$i % max(1, count($ownersDd))] ?? null;
        if (!$owner) {
            continue;
        }
        upsertBy($pdo, 'promo_posts', ['title' => $title], [
            'description' => $desc,
            'content_type' => $ctype,
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbUrl,
            'owner_type' => 'seller',
            'owner_id' => (int) ($owner['seller_id'] ?? 0),
            'target' => 'homepage',
            'status' => 'active',
            'is_sponsored' => 0,
        ]);
    }
}

// 10) Credentials file
$credPath = __DIR__ . DIRECTORY_SEPARATOR . 'demo_credentials.txt';
$lines = [];
$lines[] = 'QR BAZAR DEMO LOGIN CREDENTIALS';
$lines[] = 'Generated: ' . date('Y-m-d H:i:s');
$lines[] = 'Default password for generated accounts: ' . $defaultPass;
$lines[] = str_repeat('-', 56);
$lines = array_merge($lines, $credentials);
file_put_contents($credPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo "Seed complete.\n";
echo "Credentials file: install/demo_credentials.txt\n";

