<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_account_migrate.php';

$seed = [
    ['login' => 'co_org_1', 'name' => 'Co Organizer 1', 'phone' => '0911000001', 'email' => 'co_org_1@qrbazar.local'],
    ['login' => 'co_org_2', 'name' => 'Co Organizer 2', 'phone' => '0911000002', 'email' => 'co_org_2@qrbazar.local'],
    ['login' => 'co_org_3', 'name' => 'Co Organizer 3', 'phone' => '0911000003', 'email' => 'co_org_3@qrbazar.local'],
];

$passwordPlain = 'password';
$pwd = hashPassword($passwordPlain);

foreach ($seed as $row) {
    $exists = db()->fetchOne('SELECT id FROM app_users WHERE login_uid = ?', [$row['login']]);
    if ($exists) {
        echo $row['login'] . " already exists\n";
        continue;
    }

    $publicId = qb_user_account_schema_ready() ? qb_generate_public_id() : null;
    if ($publicId !== null) {
        db()->execute(
            'INSERT INTO app_users (public_uuid, login_uid, password_hash, display_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$publicId, $row['login'], $pwd, $row['name'], 'co_organizer', $row['phone'], $row['email']]
        );
    } else {
        db()->execute(
            'INSERT INTO app_users (login_uid, password_hash, display_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?)',
            [$row['login'], $pwd, $row['name'], 'co_organizer', $row['phone'], $row['email']]
        );
    }
    echo $row['login'] . " created\n";
}

echo "Default password: {$passwordPlain}\n";
