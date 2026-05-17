<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$profiles = [
    [
        'category' => 'Bakery',
        'markets' => ['Golden Bakery', 'Fresh Oven', 'Morning Bread'],
        'catalog' => [
            ['Sourdough Bread', 'Artisan loaf', 'piece', 'Bakery', 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=900&q=70'],
            ['Birthday Cake', 'Vanilla celebration cake', 'piece', 'Bakery', 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?auto=format&fit=crop&w=900&q=70'],
            ['Butter Cookies', 'Crisp cookies box', 'box', 'Bakery', 'https://images.unsplash.com/photo-1499636136210-6f66ee425819?auto=format&fit=crop&w=900&q=70'],
            ['Wheat Flour', 'Baking flour sack', 'kg', 'Bakery', 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?auto=format&fit=crop&w=900&q=70'],
            ['Orange Juice', 'Fresh pressed juice', 'liter', 'Beverages', 'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=900&q=70'],
        ],
    ],
    [
        'category' => 'Tech',
        'markets' => ['Tech Hub', 'Smart World', 'Digital Point'],
        'catalog' => [
            ['Smartphone', 'Latest Android smartphone', 'piece', 'Mobiles', 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=900&q=70'],
            ['Laptop', 'Work and study laptop', 'piece', 'Mobiles', 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=70'],
            ['Wireless Headphones', 'Noise isolating headset', 'piece', 'Mobiles', 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=900&q=70'],
            ['Power Drill', 'Cordless drill kit', 'set', 'Home Materials', 'https://images.unsplash.com/photo-1504148455328-c376907d081c?auto=format&fit=crop&w=900&q=70'],
            ['Desk Lamp', 'LED study lamp', 'piece', 'Home Materials', 'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=900&q=70'],
        ],
    ],
    [
        'category' => 'Vegetables',
        'markets' => ['Green Basket', 'Farm Leaf', 'Fresh Harvest'],
        'catalog' => [
            ['Tomato', 'Fresh organic tomato', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1546094096-0df4bcaaa337?auto=format&fit=crop&w=900&q=70'],
            ['Potato', 'Clean farm potato', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1518977676601-b53f82aba655?auto=format&fit=crop&w=900&q=70'],
            ['Carrot', 'Crunchy carrot bundle', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1447175008436-170170753d51?auto=format&fit=crop&w=900&q=70'],
            ['Red Onion', 'Cooking onion sack', 'kg', 'Vegetables', 'https://images.unsplash.com/photo-1518977959857-f760c2880529?auto=format&fit=crop&w=900&q=70'],
            ['Fresh Mint', 'Bunch for tea and dishes', 'bunch', 'Herbs', 'https://images.unsplash.com/photo-1618375529961-c5036a66ed88?auto=format&fit=crop&w=900&q=70'],
        ],
    ],
    [
        'category' => 'Fruits',
        'markets' => ['Fruit Garden', 'Juicy Corner', 'Sweet Basket'],
        'catalog' => [
            ['Banana', 'Sweet banana bunch', 'dozen', 'Fruits', 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?auto=format&fit=crop&w=900&q=70'],
            ['Avocado', 'Creamy avocado', 'piece', 'Fruits', 'https://images.unsplash.com/photo-1601039641847-7857b994d704?auto=format&fit=crop&w=900&q=70'],
            ['Orange', 'Juicy oranges', 'kg', 'Fruits', 'https://images.unsplash.com/photo-1547514701-42782101795e?auto=format&fit=crop&w=900&q=70'],
            ['Apple', 'Crisp apples', 'kg', 'Fruits', 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?auto=format&fit=crop&w=900&q=70'],
            ['Mango', 'Sweet mangoes', 'kg', 'Fruits', 'https://images.unsplash.com/photo-1605027990121-cbae4b1bbc57?auto=format&fit=crop&w=900&q=70'],
        ],
    ],
];

$sellers = $pdo->query("
    SELECT s.id, s.app_user_id, s.market_name
    FROM sellers s
    INNER JOIN app_users u ON u.id = s.app_user_id
    WHERE u.login_uid LIKE 'seller.demo%'
    ORDER BY s.id ASC
")->fetchAll();

if ($sellers === []) {
    echo "No demo sellers found.\n";
    exit(0);
}

$updateSeller = $pdo->prepare('UPDATE sellers SET category = ?, market_name = ? WHERE id = ?');
$productRowsStmt = $pdo->prepare('SELECT id FROM products WHERE seller_id = ? ORDER BY id ASC');
$updateProduct = $pdo->prepare('UPDATE products SET name = ?, description = ?, unit = ?, category = ?, image_url = ?, is_available = 1 WHERE id = ?');
$insertProduct = $pdo->prepare(
    'INSERT INTO products (seller_id, name, description, price, discount_pct, unit, stock, image_url, category, is_available)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
);

$pdo->beginTransaction();
try {
    foreach ($sellers as $idx => $seller) {
        $profile = $profiles[$idx % count($profiles)];
        $market = $profile['markets'][$idx % count($profile['markets'])] . ' ' . ($idx + 1);
        $updateSeller->execute([$profile['category'], $market, (int) $seller['id']]);

        $productRowsStmt->execute([(int) $seller['id']]);
        $productRows = $productRowsStmt->fetchAll();
        $catalog = $profile['catalog'];
        $catalogCount = count($catalog);

        foreach ($productRows as $pi => $row) {
            $item = $catalog[$pi % $catalogCount];
            $name = $item[0] . ' ' . ($pi + 1);
            $updateProduct->execute([$name, $item[1], $item[2], $item[3], $item[4], (int) $row['id']]);
        }

        $targetCount = 10;
        $existingCount = count($productRows);
        for ($pi = $existingCount; $pi < $targetCount; $pi++) {
            $item = $catalog[$pi % $catalogCount];
            $insertProduct->execute([
                (int) $seller['id'],
                $item[0] . ' ' . ($pi + 1),
                $item[1],
                mt_rand(40, 460),
                mt_rand(0, 100) < 35 ? mt_rand(5, 22) : 0,
                $item[2],
                mt_rand(12, 140),
                $item[4],
                $item[3],
            ]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo "Demo sellers and commodities aligned successfully.\n";
