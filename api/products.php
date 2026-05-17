<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

startSession();
if (!isLoggedIn()) {
    jsonError('Unauthorized', 401);
}

$sellerId = (int)$_SESSION['seller_id'];
$method     = $_SERVER['REQUEST_METHOD'];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

// ── GET: List seller's products ─────────────
if ($method === 'GET') {
    $products = db()->fetchAll(
        "SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC",
        [$sellerId],
        'i'
    );
    jsonSuccess(['products' => $products]);
}

// ── POST: multipart = create or update product (+ optional PNG) ─────
if ($method === 'POST' && $isMultipart) {
    $productId = intval($_POST['id'] ?? 0);
    $name      = sanitize($_POST['name'] ?? '');
    $desc      = sanitize($_POST['description'] ?? '');
    $price     = floatval($_POST['price'] ?? 0);
    $unit      = sanitize($_POST['unit'] ?? 'unit');
    $stock     = intval($_POST['stock'] ?? 0);
    $category  = sanitize($_POST['category'] ?? 'General');
    $clearImg  = !empty($_POST['clear_image']);

    if (!$name) {
        jsonError('Product name is required');
    }
    if ($price <= 0) {
        jsonError('Price must be greater than 0');
    }

    $imagePath = null;

    if ($productId > 0) {
        $row = db()->fetchOne(
            'SELECT id, image_url FROM products WHERE id = ? AND seller_id = ?',
            [$productId, $sellerId],
            'ii'
        );
        if (!$row) {
            jsonError('Product not found', 404);
        }
        $imagePath = $row['image_url'] ?? '';

        if ($clearImg) {
            qb_delete_upload_file($imagePath);
            $imagePath = '';
        } elseif (!empty($_FILES['image']['tmp_name'])) {
            $up = qb_save_product_png($_FILES['image'], $sellerId);
            if ($up['error']) {
                jsonError($up['error']);
            }
            qb_delete_upload_file($imagePath);
            $imagePath = $up['path'];
        }

        $isAvailable = intval($_POST['is_available'] ?? 1);

        db()->execute(
            'UPDATE products SET name=?, description=?, price=?, unit=?, stock=?, category=?, image_url=?, is_available=?, updated_at=NOW() WHERE id=? AND seller_id=?',
            [$name, $desc, $price, $unit, $stock, $category, $imagePath, $isAvailable, $productId, $sellerId],
            'ssdsissiii'
        );

        if ($stock <= 0) {
            db()->execute('UPDATE products SET is_available = 0 WHERE id = ?', [$productId], 'i');
        }

        jsonSuccess([], 'Product updated');
    }

    if (!empty($_FILES['image']['tmp_name'])) {
        $up = qb_save_product_png($_FILES['image'], $sellerId);
        if ($up['error']) {
            jsonError($up['error']);
        }
        $imagePath = $up['path'];
    } else {
        $imagePath = sanitize($_POST['image_url'] ?? '');
    }

    if (qb_has_column('products', 'approval_status')) {
        db()->execute(
            'INSERT INTO products (seller_id, name, description, price, unit, stock, category, image_url, is_available, approval_status) VALUES (?,?,?,?,?,?,?,?,1,?)',
            [$sellerId, $name, $desc, $price, $unit, $stock, $category, $imagePath, 'pending']
        );
    } else {
        db()->execute(
            'INSERT INTO products (seller_id, name, description, price, unit, stock, category, image_url, is_available) VALUES (?,?,?,?,?,?,?,?,1)',
            [$sellerId, $name, $desc, $price, $unit, $stock, $category, $imagePath]
        );
    }
    $newId = db()->lastInsertId();
    if (!$newId) {
        jsonError('Failed to create product', 500);
    }

    $product = db()->fetchOne('SELECT * FROM products WHERE id = ?', [$newId]);
    jsonSuccess(['product' => $product], 'Product created');
}

$data = getJson();

// ── POST JSON: Create product ────────────────────
if ($method === 'POST' && !$isMultipart) {
    $name     = sanitize($data['name'] ?? '');
    $desc     = sanitize($data['description'] ?? '');
    $price    = floatval($data['price'] ?? 0);
    $unit     = sanitize($data['unit'] ?? 'unit');
    $stock    = intval($data['stock'] ?? 0);
    $category = sanitize($data['category'] ?? 'General');
    $imageUrl = sanitize($data['image_url'] ?? '');

    if (!$name) {
        jsonError('Product name is required');
    }
    if ($price <= 0) {
        jsonError('Price must be greater than 0');
    }

    if (qb_has_column('products', 'approval_status')) {
        db()->execute(
            'INSERT INTO products (seller_id, name, description, price, unit, stock, category, image_url, is_available, approval_status) VALUES (?,?,?,?,?,?,?,?,1,?)',
            [$sellerId, $name, $desc, $price, $unit, $stock, $category, $imageUrl, 'pending']
        );
    } else {
        db()->execute(
            'INSERT INTO products (seller_id, name, description, price, unit, stock, category, image_url, is_available) VALUES (?,?,?,?,?,?,?,?,1)',
            [$sellerId, $name, $desc, $price, $unit, $stock, $category, $imageUrl]
        );
    }
    $id = db()->lastInsertId();
    if (!$id) {
        jsonError('Failed to create product', 500);
    }

    $product = db()->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    jsonSuccess(['product' => $product], 'Product created');
}

// ── PUT: Update product ─────────────────────
if ($method === 'PUT') {
    $productId   = intval($data['id'] ?? 0);
    $name        = sanitize($data['name'] ?? '');
    $desc        = sanitize($data['description'] ?? '');
    $price       = floatval($data['price'] ?? 0);
    $unit        = sanitize($data['unit'] ?? 'unit');
    $stock       = intval($data['stock'] ?? 0);
    $category    = sanitize($data['category'] ?? '');
    $isAvailable = intval($data['is_available'] ?? 1);
    $imageUrl    = array_key_exists('image_url', $data) ? sanitize($data['image_url']) : null;

    if (!$productId) {
        jsonError('Product ID required');
    }

    $existing = db()->fetchOne(
        'SELECT id, image_url FROM products WHERE id = ? AND seller_id = ?',
        [$productId, $sellerId],
        'ii'
    );
    if (!$existing) {
        jsonError('Product not found', 404);
    }

    if ($imageUrl !== null && $imageUrl === '' && !empty($existing['image_url'])) {
        qb_delete_upload_file($existing['image_url']);
    }

    if ($imageUrl !== null) {
        db()->execute(
            'UPDATE products SET name=?, description=?, price=?, unit=?, stock=?, category=?, is_available=?, image_url=?, updated_at=NOW() WHERE id=? AND seller_id=?',
            [$name, $desc, $price, $unit, $stock, $category, $isAvailable, $imageUrl, $productId, $sellerId],
            'ssdsissiii'
        );
    } else {
        db()->execute(
            'UPDATE products SET name=?, description=?, price=?, unit=?, stock=?, category=?, is_available=?, updated_at=NOW() WHERE id=? AND seller_id=?',
            [$name, $desc, $price, $unit, $stock, $category, $isAvailable, $productId, $sellerId],
            'ssdsiiiii'
        );
    }

    if ($stock <= 0) {
        db()->execute('UPDATE products SET is_available = 0 WHERE id = ?', [$productId], 'i');
    }

    jsonSuccess([], 'Product updated');
}

// ── DELETE: Remove product ──────────────────
if ($method === 'DELETE') {
    $productId = intval($data['id'] ?? $_GET['id'] ?? 0);
    if (!$productId) {
        jsonError('Product ID required');
    }

    $existing = db()->fetchOne(
        'SELECT id, image_url FROM products WHERE id = ? AND seller_id = ?',
        [$productId, $sellerId],
        'ii'
    );
    if (!$existing) {
        jsonError('Product not found', 404);
    }

    qb_delete_upload_file($existing['image_url'] ?? null);

    db()->execute('DELETE FROM products WHERE id = ? AND seller_id = ?', [$productId, $sellerId], 'ii');
    jsonSuccess([], 'Product deleted');
}

jsonError('Invalid method', 405);
