<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

startSession();

$action = $_GET['action'] ?? 'login';
$data   = getJson();

// ── Login ──────────────────────────────────
if ($action === 'login' || (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST')) {
    $phone    = sanitize($data['phone'] ?? '');
    $password = $data['password'] ?? '';

    if (!$phone || !$password) jsonError('Phone and password are required');

    $seller = db()->fetchOne("SELECT * FROM sellers WHERE phone = ? AND is_active = 1", [$phone]);
    if (!$seller || !verifyPassword($password, $seller['password_hash'])) {
        jsonError('Invalid phone number or password', 401);
    }

    loginSeller($seller['id'], $seller['uid'], $seller['full_name']);
    jsonSuccess([
        'seller' => [
            'id' => $seller['id'], 'uid' => $seller['uid'],
            'full_name' => $seller['full_name'], 'market_name' => $seller['market_name'],
            'phone' => $seller['phone'], 'category' => $seller['category'],
        ]
    ], 'Login successful');
}

// ── Register ───────────────────────────────
if ($action === 'register') {
    $full_name   = sanitize($data['full_name'] ?? '');
    $market_name = sanitize($data['market_name'] ?? '');
    $phone       = sanitize($data['phone'] ?? '');
    $email       = sanitize($data['email'] ?? '');
    $password    = $data['password'] ?? '';
    $location    = sanitize($data['location'] ?? '');
    $category    = sanitize($data['category'] ?? 'General');

    if (!$full_name || !$market_name || !$phone || !$password) {
        jsonError('Full name, market name, phone, and password are required');
    }
    if (strlen($password) < 6) jsonError('Password must be at least 6 characters');

    $existing = db()->fetchOne("SELECT id FROM sellers WHERE phone = ?", [$phone]);
    if ($existing) jsonError('This phone number is already registered');

    $uid       = generateUID('SEL');
    $hash      = hashPassword($password);
    $qrSecret  = bin2hex(random_bytes(16));

    $id = db()->insert(
        "INSERT INTO sellers (uid, full_name, market_name, phone, email, password_hash, location, category, qr_secret) VALUES (?,?,?,?,?,?,?,?,?)",
        [$uid, $full_name, $market_name, $phone, $email, $hash, $location, $category, $qrSecret],
        'sssssssss'
    );

    if (!$id) jsonError('Registration failed — please try again', 500);

    loginSeller($id, $uid, $full_name);
    jsonSuccess([
        'seller' => ['id' => $id, 'uid' => $uid, 'full_name' => $full_name, 'market_name' => $market_name]
    ], 'Registration successful');
}

// ── Logout ──────────────────────────────────
if ($action === 'logout') {
    logoutSeller();
    jsonSuccess([], 'Logged out');
}

// ── Check session ───────────────────────────
if ($action === 'me') {
    if (!isLoggedIn()) jsonError('Not authenticated', 401);
    $seller = getCurrentSeller();
    jsonSuccess(['seller' => $seller]);
}

jsonError('Invalid action', 400);
