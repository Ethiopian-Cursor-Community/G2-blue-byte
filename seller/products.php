<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
startSession();
requireSeller();
qb_apply_seller_product_slot_schema();
qb_apply_event_special_access_schema();

function qb_parse_datetime_local_input(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

$seller = getCurrentSeller();
$sid = (int)$seller['id'];
$allProductCats = qb_seller_category_catalog();
$sellerPickedCats = qb_seller_categories_from_row((array) $seller);
$productCats = [];
foreach ($sellerPickedCats as $slug) {
    if (isset($allProductCats[$slug])) {
        $productCats[$slug] = $allProductCats[$slug];
    }
}
if ($productCats === []) {
    // Fallback for legacy seller rows that do not have categories_json yet.
    $productCats = $allProductCats;
}
$defaultProductCat = array_key_first($productCats) ?: 'food_beverages';
$unitOptions = ['kg', 'g', 'liter', 'ml', 'piece', 'pack', 'set', 'meter', 'box', 'dozen'];
$defaultUnit = 'piece';
$slotFreeLimit = qb_seller_product_slot_free_limit();
$slotFeeEtb = qb_seller_product_slot_fee_etb();
$slotUsedCount = qb_seller_product_slot_usage($sid);
$slotPaidCount = qb_seller_product_slot_paid_total($sid);
$slotRemaining = max(0, ($slotFreeLimit + $slotPaidCount) - $slotUsedCount);
$flashTableReady = qb_table_exists('flash_sales');
if (qb_table_exists('bazar_events')) {
    db()->execute("
        CREATE TABLE IF NOT EXISTS event_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            seller_id INT NOT NULL,
            product_id INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_product (event_id, seller_id, product_id),
            KEY idx_ep_seller_event (seller_id, event_id),
            KEY idx_ep_product (product_id)
        )
    ");
}
$sellerEvents = [];
if ($flashTableReady && qb_table_exists('stalls') && qb_table_exists('bazar_events')) {
    $sellerEvents = db()->fetchAll(
        "SELECT DISTINCT e.id, e.name
         FROM stalls st
         INNER JOIN bazar_events e ON e.id = st.event_id
         WHERE st.seller_id = ?
           AND e.status IN ('published','live')
         ORDER BY e.event_start DESC",
        [$sid]
    );
}

$success = '';
$error = '';

$slotsPaid = !empty($_GET['slots_paid']);
$slotsQty = max(0, (int) ($_GET['slots_qty'] ?? 0));
if ($slotsPaid) {
    $success = $slotsQty > 0
        ? ('Slot payment successful. Added ' . $slotsQty . ' commodity slot(s).')
        : 'Slot payment successful.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'buy_product_slots') {
        $qty = max(1, (int) ($_POST['slot_qty'] ?? 1));
        if ($qty > 500) {
            $qty = 500;
        }
        if ($slotFeeEtb <= 0) {
            $error = 'Slot fee is not configured. Ask admin to set it in system settings.';
        } elseif (!qb_chapa_ready()) {
            $error = 'Chapa is not configured yet. Contact admin.';
        } else {
            $appUid = (int) ($_SESSION['app_user_id'] ?? 0);
            $amount = round($slotFeeEtb * $qty, 2);
            $intentSeed = qb_payment_intent_create($appUid, 'seller_product_slots', (string) $sid, $amount, [
                'seller_id' => $sid,
                'slots_qty' => $qty,
                'unit_fee_etb' => $slotFeeEtb,
                'redirect' => APP_URL . '/seller/products.php',
            ]);
            db()->execute(
                "INSERT INTO seller_product_slot_payments (intent_id, app_user_id, seller_id, slots_qty, unit_fee_etb, total_amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')
                 ON DUPLICATE KEY UPDATE slots_qty = VALUES(slots_qty), unit_fee_etb = VALUES(unit_fee_etb), total_amount = VALUES(total_amount), status = 'pending'",
                [(string) $intentSeed['intent_id'], $appUid, $sid, $qty, $slotFeeEtb, $amount]
            );
            $user = currentUser() ?: [];
            $kickoff = qb_chapa_checkout_start(
                qb_payment_intent_get((string) $intentSeed['intent_id']) ?: [],
                (string) ($user['email'] ?? ''),
                (string) ($seller['full_name'] ?? ($user['display_name'] ?? 'Seller')),
                (string) ($seller['phone'] ?? ($user['phone'] ?? ''))
            );
            if (!empty($kickoff['ok']) && !empty($kickoff['checkout_url'])) {
                header('Location: ' . (string) $kickoff['checkout_url']);
                exit;
            }
            $error = (string) ($kickoff['error'] ?? 'Could not start Chapa checkout for product slots.');
        }
    } elseif ($action === 'add' || $action === 'edit') {
        $name = sanitize($_POST['name'] ?? '');
        $desc = sanitize($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $isFreeItem = !empty($_POST['is_free_item']);
        $freeLabel = sanitize((string) ($_POST['free_label'] ?? ''));
        $unit = sanitize($_POST['unit'] ?? $defaultUnit);
        if (!in_array($unit, $unitOptions, true)) {
            $unit = $defaultUnit;
        }
        $stock = (int)($_POST['stock'] ?? 0);
        $catSlug = sanitize($_POST['category'] ?? '');
        if ($catSlug === '') {
            $catSlug = $defaultProductCat;
        } elseif (!isset($productCats[$catSlug])) {
            if ($action === 'edit') {
                $pidCheck = (int) ($_POST['id'] ?? 0);
                $prevRow = $pidCheck > 0
                    ? db()->fetchOne('SELECT category FROM products WHERE id=? AND seller_id=?', [$pidCheck, $sid])
                    : null;
                $prevCat = (string) ($prevRow['category'] ?? '');
                if ($prevCat !== $catSlug) {
                    $catSlug = $defaultProductCat;
                }
            } else {
                $catSlug = $defaultProductCat;
            }
        }
        
        if (!$name || (!$isFreeItem && $price <= 0)) {
            $error = 'Name and valid price are required.';
        } elseif ($action === 'add' && $slotRemaining <= 0) {
            $error = 'You used all commodity slots. Buy more slots to add another product.';
        } else {
            $dPct = max(0, min(90, (int) ($_POST['discount_pct'] ?? 0)));
            $hasDiscCol = qb_has_column('products', 'discount_pct');
            $hasImageCol = qb_has_column('products', 'image_url');
            $uploadedImage = null;
            if ($hasImageCol && !empty($_FILES['image']['tmp_name'])) {
                $up = qb_save_product_png($_FILES['image'], $sid);
                if (!empty($up['error'])) {
                    $error = (string) $up['error'];
                } else {
                    $uploadedImage = (string) ($up['path'] ?? '');
                }
            }
            if ($action === 'add' && $hasImageCol && empty($uploadedImage)) {
                $error = 'Product image is required.';
            }
        }
        if ($error === '') {
            $dPct = max(0, min(90, (int) ($_POST['discount_pct'] ?? 0)));
            if ($flashTableReady && !empty($_POST['flash_enabled'])) {
                // Flash sale and regular discount must not run together.
                $dPct = 0;
            }
            $hasDiscCol = qb_has_column('products', 'discount_pct');
            $hasImageCol = qb_has_column('products', 'image_url');
            $productId = 0;
            if ($action === 'add') {
                if (qb_has_column('products', 'approval_status')) {
                    if ($hasDiscCol) {
                        if ($hasImageCol) {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, discount_pct, unit, stock, category, image_url, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $dPct, $unit, $stock, $catSlug, $uploadedImage, 'pending']
                            );
                            $productId = (int) db()->lastInsertId();
                        } else {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, discount_pct, unit, stock, category, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $dPct, $unit, $stock, $catSlug, 'pending']
                            );
                            $productId = (int) db()->lastInsertId();
                        }
                    } else {
                        if ($hasImageCol) {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, unit, stock, category, image_url, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $unit, $stock, $catSlug, $uploadedImage, 'pending']
                            );
                            $productId = (int) db()->lastInsertId();
                        } else {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, unit, stock, category, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $unit, $stock, $catSlug, 'pending']
                            );
                            $productId = (int) db()->lastInsertId();
                        }
                    }
                    $success = 'Product submitted. It will appear to buyers after admin approval.';
                } else {
                    if ($hasDiscCol) {
                        if ($hasImageCol) {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, discount_pct, unit, stock, category, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $dPct, $unit, $stock, $catSlug, $uploadedImage]
                            );
                            $productId = (int) db()->lastInsertId();
                        } else {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, discount_pct, unit, stock, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $dPct, $unit, $stock, $catSlug]
                            );
                            $productId = (int) db()->lastInsertId();
                        }
                    } else {
                        if ($hasImageCol) {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, unit, stock, category, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $unit, $stock, $catSlug, $uploadedImage]
                            );
                            $productId = (int) db()->lastInsertId();
                        } else {
                            db()->execute(
                                'INSERT INTO products (seller_id, name, description, price, unit, stock, category) VALUES (?, ?, ?, ?, ?, ?, ?)',
                                [$sid, $name, $desc, $price, $unit, $stock, $catSlug]
                            );
                            $productId = (int) db()->lastInsertId();
                        }
                    }
                    $success = 'Product added successfully.';
                }
            } else {
                $pid = (int)$_POST['id'];
                $existing = db()->fetchOne('SELECT image_url FROM products WHERE id=? AND seller_id=?', [$pid, $sid]);
                $imagePath = (string) ($existing['image_url'] ?? '');
                if (!empty($_POST['clear_image']) && $hasImageCol) {
                    qb_delete_upload_file($imagePath);
                    $imagePath = '';
                }
                if (!empty($uploadedImage) && $hasImageCol) {
                    qb_delete_upload_file($imagePath);
                    $imagePath = (string) $uploadedImage;
                }
                if ($hasDiscCol) {
                    if ($hasImageCol) {
                        db()->execute(
                            'UPDATE products SET name=?, description=?, price=?, discount_pct=?, unit=?, stock=?, category=?, image_url=? WHERE id=? AND seller_id=?',
                            [$name, $desc, $price, $dPct, $unit, $stock, $catSlug, $imagePath, $pid, $sid]
                        );
                    } else {
                        db()->execute(
                            'UPDATE products SET name=?, description=?, price=?, discount_pct=?, unit=?, stock=?, category=? WHERE id=? AND seller_id=?',
                            [$name, $desc, $price, $dPct, $unit, $stock, $catSlug, $pid, $sid]
                        );
                    }
                } else {
                    if ($hasImageCol) {
                        db()->execute(
                            'UPDATE products SET name=?, description=?, price=?, unit=?, stock=?, category=?, image_url=? WHERE id=? AND seller_id=?',
                            [$name, $desc, $price, $unit, $stock, $catSlug, $imagePath, $pid, $sid]
                        );
                    } else {
                        db()->execute(
                            'UPDATE products SET name=?, description=?, price=?, unit=?, stock=?, category=? WHERE id=? AND seller_id=?',
                            [$name, $desc, $price, $unit, $stock, $catSlug, $pid, $sid]
                        );
                    }
                }
                $success = 'Product updated successfully.';
                $productId = $pid;
            }
            if ($productId > 0 && qb_has_column('products', 'is_free_item')) {
                db()->execute(
                    'UPDATE products SET is_free_item = ?, free_label = ?, price = ?, discount_pct = ? WHERE id = ? AND seller_id = ?',
                    [$isFreeItem ? 1 : 0, ($freeLabel !== '' ? $freeLabel : null), ($isFreeItem ? 0.0 : $price), 0, $productId, $sid]
                );
            }
            if ($flashTableReady && $productId > 0 && !empty($_POST['flash_enabled']) && !$isFreeItem) {
                $flashPctRaw = (int) ($_POST['flash_discount_pct'] ?? 0);
                if ($flashPctRaw <= 0) {
                    $flashPctRaw = (int) $dPct;
                }
                $flashPct = max(1, min(90, $flashPctRaw));
                $flashStarts = qb_parse_datetime_local_input((string) ($_POST['flash_starts_at'] ?? ''));
                $flashEnds = qb_parse_datetime_local_input((string) ($_POST['flash_ends_at'] ?? ''));
                $flashEventRaw = (string) ($_POST['flash_event_id'] ?? '');
                $flashEventId = ($flashEventRaw === '' || $flashEventRaw === '0') ? null : (int) $flashEventRaw;
                if ($flashEventId !== null && $flashEventId > 0) {
                    $okEv = db()->fetchOne('SELECT 1 FROM stalls WHERE seller_id = ? AND event_id = ? LIMIT 1', [$sid, $flashEventId]);
                    if (!$okEv) {
                        $error = 'Flash scope event must be one of your assigned bazars.';
                    } elseif (!$flashStarts || !$flashEnds) {
                        $evWindow = db()->fetchOne(
                            "SELECT e.event_start, e.event_end
                             FROM bazar_events e
                             INNER JOIN stalls st ON st.event_id = e.id
                             WHERE st.seller_id = ? AND e.id = ?
                             LIMIT 1",
                            [$sid, $flashEventId]
                        );
                        $flashStarts = !empty($evWindow['event_start']) ? (string) $evWindow['event_start'] : date('Y-m-d H:i:s');
                        $flashEnds = !empty($evWindow['event_end']) ? (string) $evWindow['event_end'] : date('Y-m-d H:i:s', strtotime('+7 days'));
                        if ($flashStarts >= $flashEnds) {
                            $flashEnds = date('Y-m-d H:i:s', strtotime($flashStarts . ' +1 day'));
                        }
                    }
                }
                if ($error === '' && (!$flashStarts || !$flashEnds || $flashStarts >= $flashEnds)) {
                    $error = 'Flash window invalid. Set start/end date correctly.';
                }
                if ($error === '') {
                    $flashSalePrice = round($price * (1 - ($flashPct / 100)), 2);
                    $existingFlash = db()->fetchOne(
                        "SELECT id FROM flash_sales
                         WHERE seller_id = ? AND product_id = ? AND is_active = 1
                           AND starts_at < ? AND ends_at > ?
                         ORDER BY id DESC LIMIT 1",
                        [$sid, $productId, $flashEnds, $flashStarts]
                    );
                    if ($existingFlash) {
                        db()->execute(
                            'UPDATE flash_sales SET event_id = ?, discount_pct = ?, original_price = ?, sale_price = ?, starts_at = ?, ends_at = ?, is_active = 1 WHERE id = ? AND seller_id = ?',
                            [$flashEventId, $flashPct, $price, $flashSalePrice, $flashStarts, $flashEnds, (int) $existingFlash['id'], $sid]
                        );
                    } else {
                        db()->execute(
                            'INSERT INTO flash_sales (product_id, seller_id, event_id, discount_pct, original_price, sale_price, starts_at, ends_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                            [$productId, $sid, $flashEventId, $flashPct, $price, $flashSalePrice, $flashStarts, $flashEnds]
                        );
                    }
                    $success .= ($success !== '' ? ' ' : '') . 'Flash discount scheduled.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $pid = (int)$_POST['id'];
        db()->execute('DELETE FROM products WHERE id=? AND seller_id=?', [$pid, $sid]);
        $success = 'Product deleted.';
    } elseif ($action === 'toggle') {
        $pid = (int)$_POST['id'];
        db()->execute('UPDATE products SET is_available = NOT is_available WHERE id=? AND seller_id=?', [$pid, $sid]);
        $success = 'Product availability toggled.';
    } elseif ($action === 'stock_ping') {
        $pid = (int) ($_POST['id'] ?? 0);
        $row = db()->fetchOne('SELECT id, name FROM products WHERE id = ? AND seller_id = ?', [$pid, $sid]);
        if ($row) {
            $sk = 'qb_stock_ping_' . $pid;
            $last = (int) ($_SESSION[$sk] ?? 0);
            if (time() - $last < 90) {
                $error = 'Please wait a moment before sending another ping.';
            } elseif (function_exists('qb_notify_organizer_stock_ping') && qb_notify_organizer_stock_ping((int) $_SESSION['app_user_id'], (string) $seller['market_name'], $pid, (string) $row['name'])) {
                $_SESSION[$sk] = time();
                $success = 'Organizer notified — they may reach out about restocking.';
            } else {
                $error = 'No organizer found for your bazar assignment, or notifications failed.';
            }
        }
    } elseif ($action === 'event_assign_all') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            $error = 'Select an event first.';
        } else {
            $okEv = db()->fetchOne('SELECT 1 FROM stalls WHERE seller_id = ? AND event_id = ? LIMIT 1', [$sid, $eventId]);
            if (!$okEv) {
                $error = 'You can only assign products to events where you have a stall.';
            } else {
                $all = db()->fetchAll('SELECT id FROM products WHERE seller_id = ?', [$sid]);
                foreach ($all as $r) {
                    $pid = (int) ($r['id'] ?? 0);
                    if ($pid <= 0) continue;
                    db()->execute(
                        "INSERT INTO event_products (event_id, seller_id, product_id, is_active)
                         VALUES (?, ?, ?, 1)
                         ON DUPLICATE KEY UPDATE is_active = 1",
                        [$eventId, $sid, $pid]
                    );
                }
                $success = 'All products assigned to selected event.';
            }
        }
    } elseif ($action === 'event_clear_all') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            $error = 'Select an event first.';
        } else {
            db()->execute('UPDATE event_products SET is_active = 0 WHERE seller_id = ? AND event_id = ?', [$sid, $eventId]);
            $success = 'Event product assignments cleared.';
        }
    } elseif ($action === 'event_toggle_product') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $pid = (int) ($_POST['id'] ?? 0);
        if ($eventId <= 0 || $pid <= 0) {
            $error = 'Invalid event/product.';
        } else {
            $okEv = db()->fetchOne('SELECT 1 FROM stalls WHERE seller_id = ? AND event_id = ? LIMIT 1', [$sid, $eventId]);
            $okProd = db()->fetchOne('SELECT 1 FROM products WHERE seller_id = ? AND id = ? LIMIT 1', [$sid, $pid]);
            if (!$okEv || !$okProd) {
                $error = 'Cannot change assignment for this event/product.';
            } else {
                $cur = db()->fetchOne('SELECT id, is_active FROM event_products WHERE seller_id = ? AND event_id = ? AND product_id = ? LIMIT 1', [$sid, $eventId, $pid]);
                if ($cur) {
                    $next = ((int) ($cur['is_active'] ?? 0) === 1) ? 0 : 1;
                    db()->execute('UPDATE event_products SET is_active = ? WHERE id = ?', [$next, (int) $cur['id']]);
                    $success = $next ? 'Product assigned to event.' : 'Product removed from event.';
                } else {
                    db()->execute(
                        "INSERT INTO event_products (event_id, seller_id, product_id, is_active)
                         VALUES (?, ?, ?, 1)
                         ON DUPLICATE KEY UPDATE is_active = 1",
                        [$eventId, $sid, $pid]
                    );
                    $success = 'Product assigned to event.';
                }
            }
        }
    }
}

$slotUsedCount = qb_seller_product_slot_usage($sid);
$slotPaidCount = qb_seller_product_slot_paid_total($sid);
$slotRemaining = max(0, ($slotFreeLimit + $slotPaidCount) - $slotUsedCount);

$search = trim((string) ($_GET['q'] ?? ''));
$filterStatus = sanitize($_GET['status'] ?? 'all');
$filterAvail = sanitize($_GET['avail'] ?? 'all');
$filterCat = sanitize($_GET['cat'] ?? 'all');
$filterStock = sanitize($_GET['stock'] ?? 'all');
$manageEventId = (int) ($_GET['manage_event'] ?? 0);
if ($filterCat !== 'all' && $filterCat !== '' && !isset($productCats[$filterCat])) {
    $filterCat = 'all';
}

$where = ['seller_id = ?'];
$params = [$sid];
if ($search !== '') {
    $where[] = '(name LIKE ? OR description LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($filterCat !== 'all' && $filterCat !== '') {
    $where[] = 'category = ?';
    $params[] = $filterCat;
}
if ($filterAvail === 'available') {
    $where[] = 'is_available = 1';
} elseif ($filterAvail === 'hidden') {
    $where[] = 'is_available = 0';
}
if ($filterStock === 'low') {
    $where[] = 'stock <= 5';
} elseif ($filterStock === 'out') {
    $where[] = 'stock <= 0';
} elseif ($filterStock === 'in') {
    $where[] = 'stock > 0';
}
if (qb_has_column('products', 'approval_status')) {
    if (in_array($filterStatus, ['approved', 'pending', 'rejected'], true)) {
        $where[] = 'approval_status = ?';
        $params[] = $filterStatus;
    } else {
        $filterStatus = 'all';
    }
} else {
    $filterStatus = 'all';
}

$products = db()->fetchAll(
    'SELECT * FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC',
    $params
);
$eventAssigned = [];
$eventAssignedMap = [];
if (qb_table_exists('event_products')) {
    $epRows = db()->fetchAll(
        "SELECT ep.product_id, ep.event_id, ep.is_active, e.name AS event_name
         FROM event_products ep
         INNER JOIN bazar_events e ON e.id = ep.event_id
         WHERE ep.seller_id = ?",
        [$sid]
    );
    foreach ($epRows as $ep) {
        $pid = (int) ($ep['product_id'] ?? 0);
        $eid = (int) ($ep['event_id'] ?? 0);
        $isActive = (int) ($ep['is_active'] ?? 0) === 1;
        if ($pid <= 0) continue;
        if (!isset($eventAssignedMap[$pid])) {
            $eventAssignedMap[$pid] = [];
        }
        $eventAssignedMap[$pid][$eid] = $isActive;
        if ($isActive) {
            if (!isset($eventAssigned[$pid])) {
                $eventAssigned[$pid] = [];
            }
            $eventAssigned[$pid][] = (string) ($ep['event_name'] ?? ('Event #' . (int) ($ep['event_id'] ?? 0)));
        }
    }
}
$flashByProduct = [];
if ($flashTableReady && !empty($products)) {
    $productIds = [];
    foreach ($products as $pp) {
        $pid = (int) ($pp['id'] ?? 0);
        if ($pid > 0) {
            $productIds[] = $pid;
        }
    }
    if ($productIds !== []) {
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $flashRows = db()->fetchAll(
            "SELECT fs.product_id, fs.discount_pct, fs.starts_at, fs.ends_at, fs.event_id, e.name AS event_name
             FROM flash_sales fs
             LEFT JOIN bazar_events e ON e.id = fs.event_id
             WHERE fs.seller_id = ? AND fs.is_active = 1 AND fs.product_id IN ($ph)
             ORDER BY (NOW() >= fs.starts_at AND NOW() <= fs.ends_at) DESC, fs.starts_at ASC, fs.id DESC",
            array_merge([$sid], $productIds)
        );
        foreach ($flashRows as $fr) {
            $pid = (int) ($fr['product_id'] ?? 0);
            if ($pid > 0 && !isset($flashByProduct[$pid])) {
                $flashByProduct[$pid] = $fr;
            }
        }
    }
}
$legacyProductSlugs = [];
foreach ($products as $p) {
    $c = (string) ($p['category'] ?? '');
    if ($c !== '' && !isset($productCats[$c])) {
        $legacyProductSlugs[$c] = true;
    }
}

qb_page_start('seller', 'Products', 'products.php', false);
$hasImageColList = qb_has_column('products', 'image_url');
$tableCols = 7 + ($hasImageColList ? 1 : 0) + (qb_has_column('products', 'discount_pct') ? 1 : 0) + (qb_has_column('products', 'approval_status') ? 1 : 0);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Manage Products</h1>
    <p class="page-subtitle">Add items, set prices, and manage inventory.</p>
  </div>
  <button class="btn btn-primary" onclick="showAddModal()"><?= qb_icon('plus') ?> Add Product</button>
</div>

<?php if ($success): ?>
  <div class="alert alert-success mb-2"><?= qb_icon('check', 'qb-icon', 16) ?> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger mb-2"><?= qb_icon('alert', 'qb-icon', 16) ?> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card mb-2" style="padding:0.8rem 1rem;">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap">
    <div>
      <div class="font-bold">Commodity slots</div>
      <div class="text-xs text-muted">Free: <?= (int) $slotFreeLimit ?> · Paid: <?= (int) $slotPaidCount ?> · Used: <?= (int) $slotUsedCount ?> · Remaining: <strong><?= (int) $slotRemaining ?></strong></div>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="openSlotCheckoutModal()"><?= qb_icon('plus', 'qb-icon', 14) ?> Buy slots</button>
  </div>
</div>

<div class="card mb-2 qb-one-line-box">
  <div class="text-xs text-muted mb-1">Filters and event assignment</div>
  <form method="get" class="qb-one-line-tools">
    <div class="form-group qb-line-field qb-line-field--search">
      <label class="form-label sr-only" for="filterQ">Search</label>
      <input id="filterQ" type="text" name="q" class="form-control" placeholder="Name or description" value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="form-group qb-line-field">
      <label class="form-label sr-only" for="filterCat">Category</label>
      <select id="filterCat" name="cat" class="form-control">
        <option value="all">All categories</option>
        <?php foreach ($productCats as $slug => $label): ?>
        <option value="<?= htmlspecialchars($slug) ?>" <?= $filterCat === $slug ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group qb-line-field">
      <label class="form-label sr-only" for="filterAvail">Visibility</label>
      <select id="filterAvail" name="avail" class="form-control">
        <option value="all"<?= $filterAvail === 'all' ? ' selected' : '' ?>>All</option>
        <option value="available"<?= $filterAvail === 'available' ? ' selected' : '' ?>>Available</option>
        <option value="hidden"<?= $filterAvail === 'hidden' ? ' selected' : '' ?>>Hidden</option>
      </select>
    </div>
    <div class="form-group qb-line-field">
      <label class="form-label sr-only" for="filterStock">Stock</label>
      <select id="filterStock" name="stock" class="form-control">
        <option value="all"<?= $filterStock === 'all' ? ' selected' : '' ?>>Any stock</option>
        <option value="in"<?= $filterStock === 'in' ? ' selected' : '' ?>>In stock</option>
        <option value="low"<?= $filterStock === 'low' ? ' selected' : '' ?>>Low stock (<= 5)</option>
        <option value="out"<?= $filterStock === 'out' ? ' selected' : '' ?>>Out of stock</option>
      </select>
    </div>
    <?php if (qb_has_column('products', 'approval_status')): ?>
    <div class="form-group qb-line-field">
      <label class="form-label sr-only" for="filterStatus">Approval</label>
      <select id="filterStatus" name="status" class="form-control">
        <option value="all"<?= $filterStatus === 'all' ? ' selected' : '' ?>>All approvals</option>
        <option value="approved"<?= $filterStatus === 'approved' ? ' selected' : '' ?>>Approved</option>
        <option value="pending"<?= $filterStatus === 'pending' ? ' selected' : '' ?>>Pending</option>
        <option value="rejected"<?= $filterStatus === 'rejected' ? ' selected' : '' ?>>Rejected</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="form-group qb-line-actions">
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="products.php" class="btn btn-ghost btn-sm">Reset</a>
    </div>
  </form>
  <?php if (!empty($sellerEvents)): ?>
  <form method="post" class="qb-one-line-tools qb-line-sep">
    <input type="hidden" name="action" id="eventBulkAction" value="event_assign_all">
    <div class="form-group qb-line-field">
      <label class="form-label sr-only" for="eventBulkId">Event product assignment</label>
      <select id="eventBulkId" name="event_id" class="form-control" required>
        <option value="">Select your event</option>
        <?php foreach ($sellerEvents as $sev): ?>
        <option value="<?= (int) ($sev['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($sev['name'] ?? 'Event')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group qb-line-actions">
      <button type="submit" class="btn btn-primary btn-sm" onclick="document.getElementById('eventBulkAction').value='event_assign_all'">Select all products</button>
      <button type="submit" class="btn btn-ghost btn-sm" onclick="document.getElementById('eventBulkAction').value='event_clear_all'">Clear all products</button>
    </div>
  </form>
  <form method="get" class="qb-one-line-tools qb-line-sep">
    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <input type="hidden" name="avail" value="<?= htmlspecialchars($filterAvail) ?>">
    <input type="hidden" name="cat" value="<?= htmlspecialchars($filterCat) ?>">
    <input type="hidden" name="stock" value="<?= htmlspecialchars($filterStock) ?>">
    <div class="form-group qb-line-field">
      <label class="form-label sr-only" for="manageEvent">Per-product event toggle</label>
      <select id="manageEvent" name="manage_event" class="form-control" onchange="this.form.submit()">
        <option value="0">Choose event for row toggles</option>
        <?php foreach ($sellerEvents as $sev): ?>
        <option value="<?= (int) ($sev['id'] ?? 0) ?>" <?= $manageEventId === (int) ($sev['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($sev['name'] ?? 'Event')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <?php endif; ?>
</div>

<style>
.qb-one-line-box{padding:.75rem}
.qb-one-line-tools{display:flex;flex-wrap:nowrap;gap:.45rem;align-items:center}
.qb-line-sep{margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--border)}
.qb-line-field{margin:0;min-width:130px;flex:1 1 130px}
.qb-line-field--search{flex:1.4 1 220px}
.qb-line-actions{margin:0;display:flex;gap:.35rem;flex-wrap:nowrap}
@media (min-width: 821px){
  .qb-one-line-box{overflow-x:auto}
}
@media (max-width: 820px){
  .qb-one-line-tools{flex-wrap:wrap}
  .qb-line-field,.qb-line-field--search,.qb-line-actions{flex:1 1 100%}
}
</style>

<div class="card">
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <?php if ($hasImageColList): ?>
          <th>Image</th>
          <?php endif; ?>
          <th>Product</th>
          <th>Category</th>
          <th>Price</th>
          <?php if (qb_has_column('products', 'discount_pct')): ?>
          <th>Discount</th>
          <?php endif; ?>
          <th>Stock</th>
          <th>Event</th>
          <?php if (qb_has_column('products', 'approval_status')): ?>
          <th>Approval</th>
          <?php endif; ?>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
          <tr><td colspan="<?= (int) $tableCols ?>" class="text-center text-muted">No products yet.</td></tr>
        <?php else: ?>
          <?php foreach ($products as $p): ?>
          <?php
              $rawCat = (string) ($p['category'] ?? '');
              $catLabel = $productCats[$rawCat] ?? $rawCat;
          ?>
          <tr>
            <?php if ($hasImageColList): ?>
            <td>
              <?php $imgPath = trim((string) ($p['image_url'] ?? '')); ?>
              <?php if ($imgPath !== ''): ?>
                <?php $imgSrc = qb_promo_external_media_url($imgPath) ? $imgPath : qb_public_upload_url($imgPath); ?>
                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="" class="qb-prod-thumb-square" loading="lazy" decoding="async"/>
              <?php else: ?>
                <span class="qb-prod-thumb-square qb-prod-thumb-square--ph" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr((string) ($p['name'] ?? 'P'), 0, 1))) ?></span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
              <div class="font-bold"><?= qb_esc_html($p['name']) ?></div>
              <?php
              $rawDesc = (string) ($p['description'] ?? '');
              if ($rawDesc !== '') {
                  $preview = mb_strlen($rawDesc) > 48 ? mb_substr($rawDesc, 0, 48) . '…' : $rawDesc;
                  echo '<div class="text-xs text-muted">' . qb_esc_html($preview) . '</div>';
              }
              ?>
            </td>
            <td>
              <?= htmlspecialchars($catLabel) ?>
              <?php $evNames = $eventAssigned[(int) ($p['id'] ?? 0)] ?? []; ?>
              <?php if ($evNames !== []): ?>
                <div class="text-xs text-muted mt-1">Events: <?= htmlspecialchars(implode(', ', array_slice($evNames, 0, 2))) ?><?= count($evNames) > 2 ? ' +' . (count($evNames) - 2) : '' ?></div>
              <?php endif; ?>
            </td>
            <td class="font-bold">
              <?php if (!empty($p['is_free_item'])): ?>
                <span class="badge badge-green">FREE</span>
                <?php if (!empty($p['free_label'])): ?><div class="text-xs text-muted mt-1"><?= htmlspecialchars((string) $p['free_label']) ?></div><?php endif; ?>
              <?php else: ?>
                <?= number_format($p['price'], 2) ?> ETB / <?= htmlspecialchars($p['unit']) ?>
              <?php endif; ?>
            </td>
            <?php if (qb_has_column('products', 'discount_pct')): ?>
            <td>
              <?php
              $dp = (int) ($p['discount_pct'] ?? 0);
              $pid = (int) ($p['id'] ?? 0);
              $frow = $flashByProduct[$pid] ?? null;
              if ($frow) {
                  $st = strtotime((string) ($frow['starts_at'] ?? '')) ?: 0;
                  $en = strtotime((string) ($frow['ends_at'] ?? '')) ?: 0;
                  $now = time();
                  $state = ($st > $now) ? 'Upcoming' : (($en > 0 && $en < $now) ? 'Ended' : 'Live');
                  $stateCls = $state === 'Live' ? 'badge-green' : ($state === 'Upcoming' ? 'badge-amber' : 'badge-gray');
                  echo '<div><span class="badge badge-amber">Flash ' . (int) ($frow['discount_pct'] ?? 0) . '%</span> <span class="badge ' . $stateCls . '">' . $state . '</span></div>';
                  if (!empty($frow['event_name'])) {
                      echo '<div class="text-xs text-muted mt-1">Event: ' . htmlspecialchars((string) $frow['event_name']) . '</div>';
                  }
                  echo '<div class="text-xs text-muted">Window: ' . htmlspecialchars(date('j M H:i', $st)) . ' - ' . htmlspecialchars(date('j M H:i', $en)) . '</div>';
              } elseif ($dp > 0) {
                  echo '<span class="badge badge-green">' . $dp . '% off</span>';
              } else {
                  echo '<span class="text-muted">—</span>';
              }
              ?>
            </td>
            <?php endif; ?>
            <td>
              <?php if($p['stock'] <= 5): ?>
                <span class="text-danger font-bold"><?= $p['stock'] ?> (Low)</span>
              <?php else: ?>
                <?= $p['stock'] ?>
              <?php endif; ?>
              <?php if ((int) $p['stock'] > 0 && (int) $p['stock'] <= 8): ?>
                <form method="post" class="mt-1" style="display:inline" onsubmit="return confirm('Notify the bazar organizer that you may need restock help for this product?')">
                  <input type="hidden" name="action" value="stock_ping"/>
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>"/>
                  <button type="submit" class="btn btn-ghost btn-sm" style="padding:0.15rem 0.45rem;font-size:0.7rem"><?= qb_icon('announce', 'qb-icon', 12) ?> Ping organizer</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($manageEventId > 0): ?>
                <?php $isOnEvent = !empty($eventAssignedMap[(int) ($p['id'] ?? 0)][$manageEventId]); ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="event_toggle_product">
                  <input type="hidden" name="event_id" value="<?= (int) $manageEventId ?>">
                  <input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
                  <button type="submit" class="badge <?= $isOnEvent ? 'badge-green' : 'badge-gray' ?>" style="border:none;cursor:pointer">
                    <?= $isOnEvent ? 'Assigned' : 'Not assigned' ?>
                  </button>
                </form>
              <?php else: ?>
                <span class="text-xs text-muted">Select event above</span>
              <?php endif; ?>
            </td>
            <?php if (qb_has_column('products', 'approval_status')): ?>
            <td>
              <?php
              $st = $p['approval_status'] ?? 'approved';
              $cls = $st === 'approved' ? 'badge-green' : ($st === 'pending' ? 'badge-amber' : 'badge-red');
              ?>
              <span class="badge <?= $cls ?>"><?= htmlspecialchars($st) ?></span>
            </td>
            <?php endif; ?>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" class="badge <?= $p['is_available'] ? 'badge-green' : 'badge-gray' ?>" style="border:none;cursor:pointer">
                  <?= $p['is_available'] ? 'Available' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td style="text-align:right">
              <?php
              $pJS = array_map(function($v) {
                return is_string($v) ? html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $v;
              }, $p);
              ?>
              <button class="btn btn-ghost btn-sm" onclick='editProduct(<?= json_encode($pJS) ?>)'><?= qb_icon('edit', 'qb-icon', 16) ?></button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this product?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm text-danger"><?= qb_icon('trash', 'qb-icon', 16) ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add / edit product modal -->
<div id="productModal" class="qb-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle" onclick="if(event.target===this)closeModal()">
  <div class="card" onclick="event.stopPropagation()">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
      <h3 class="font-bold" id="modalTitle">Add Product</h3>
      <button onclick="closeModal()" class="btn btn-ghost" style="padding:4px"><?= qb_icon('x') ?></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="id" id="formId" value="">
      
      <div class="grid grid-2 gap-2 mb-2">
        <div class="form-group" style="grid-column: span 2">
          <label class="form-label">Name</label>
          <input type="text" name="name" id="formName" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">List price (ETB)</label>
          <input type="number" step="0.01" name="price" id="formPrice" class="form-control" required>
        </div>
        <?php if (qb_has_column('products', 'discount_pct')): ?>
        <div class="form-group">
          <label class="form-label">Regular discount (%)</label>
          <input type="number" name="discount_pct" id="formDiscount" class="form-control" min="0" max="90" value="0" placeholder="0">
          <p class="text-xs text-muted mt-1">Optional ongoing discount. Buyers see the reduced price as a normal sale.</p>
        </div>
        <?php endif; ?>
        <?php if (qb_has_column('products', 'is_free_item')): ?>
        <div class="form-group">
          <label class="form-label">Free product</label>
          <label class="text-xs text-muted" style="display:inline-flex;gap:.35rem;align-items:center;margin-bottom:.4rem">
            <input type="checkbox" name="is_free_item" id="formIsFreeItem" value="1" onchange="toggleFreeItemFields()">
            Mark this product as FREE in event discover
          </label>
          <input type="text" name="free_label" id="formFreeLabel" class="form-control" placeholder="Optional free label (e.g. Guest Drink)">
        </div>
        <?php endif; ?>
        <?php if ($flashTableReady): ?>
        <div class="form-group" style="grid-column: span 2">
          <label class="form-label">Flash sale (timed)</label>
          <label class="text-xs text-muted" style="display:inline-flex;gap:0.35rem;align-items:center;margin-bottom:0.45rem">
            <input type="checkbox" name="flash_enabled" id="formFlashEnabled" value="1" onchange="toggleFlashSaleFields()">
            Schedule flash sale from this product form
          </label>
          <div id="formFlashDetails" class="grid grid-2 gap-2" style="display:none">
            <input type="number" name="flash_discount_pct" id="formFlashDiscount" class="form-control" min="1" max="90" value="" placeholder="Flash discount % (blank = use normal discount)">
            <select name="flash_event_id" id="formFlashEvent" class="form-control" onchange="toggleFlashSaleFields()">
              <option value="">Flash scope: all buyers</option>
              <?php foreach ($sellerEvents as $sev): ?>
              <option value="<?= (int) ($sev['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($sev['name'] ?? 'Event')) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="datetime-local" name="flash_starts_at" id="formFlashStarts" class="form-control" placeholder="Flash starts">
            <input type="datetime-local" name="flash_ends_at" id="formFlashEnds" class="form-control" placeholder="Flash ends">
          </div>
          <p class="text-xs text-muted mt-1">Tick the checkbox only when you want a timed flash sale. If unticked, only the normal discount applies.</p>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label class="form-label">Unit</label>
          <select name="unit" id="formUnit" class="form-control" required>
            <?php foreach ($unitOptions as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $u === $defaultUnit ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Stock</label>
          <input type="number" name="stock" id="formStock" class="form-control" value="0">
        </div>
        <div class="form-group" style="grid-column: span 2">
          <fieldset class="qb-form-fieldset mb-0">
            <legend class="form-label mb-2">Category</legend>
            <div class="qb-product-cat-scroll" role="radiogroup" aria-label="Product category">
              <div class="qb-segmented qb-segmented--grid">
                <?php foreach ($productCats as $slug => $label): ?>
                <label class="qb-segmented__opt">
                  <input type="radio" name="category" value="<?= htmlspecialchars($slug) ?>"<?= ($slug === $defaultProductCat) ? ' checked' : '' ?>/>
                  <span class="qb-segmented__face">
                    <span class="qb-segmented__title"><?= htmlspecialchars($label) ?></span>
                  </span>
                </label>
                <?php endforeach; ?>
                <?php foreach (array_keys($legacyProductSlugs) as $leg): ?>
                <label class="qb-segmented__opt">
                  <input type="radio" name="category" value="<?= htmlspecialchars($leg) ?>"/>
                  <span class="qb-segmented__face">
                    <span class="qb-segmented__title"><?= htmlspecialchars($leg) ?></span>
                    <span class="qb-segmented__hint">Legacy — replace when you can</span>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
          </fieldset>
        </div>
        <div class="form-group" style="grid-column: span 2">
          <label class="form-label">Description</label>
          <textarea name="description" id="formDesc" class="form-control" rows="3"></textarea>
        </div>
        <?php if (qb_has_column('products', 'image_url')): ?>
        <div class="form-group" style="grid-column: span 2">
          <label class="form-label">Product image (PNG/JPG/WEBP/GIF, max 2MB)</label>
          <input type="file" name="image" id="formImage" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif" required>
          <div id="formImagePreview" class="text-xs text-muted mt-1">Image is required.</div>
          <label class="text-xs mt-1" style="display:inline-flex;gap:0.35rem;align-items:center">
            <input type="checkbox" name="clear_image" id="formClearImage" value="1"> Remove current image
          </label>
        </div>
        <?php endif; ?>
      </div>
      
      <div style="text-align:right">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Product</button>
      </div>
    </form>
  </div>
</div>

<div id="slotCheckoutModal" class="qb-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="slotCheckoutTitle" onclick="if(event.target===this)closeSlotCheckoutModal()">
  <div class="card" onclick="event.stopPropagation()" style="max-width:420px;width:100%">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
      <h3 class="font-bold" id="slotCheckoutTitle">Buy commodity slots</h3>
      <button type="button" onclick="closeSlotCheckoutModal()" class="btn btn-ghost" style="padding:4px"><?= qb_icon('x') ?></button>
    </div>
    <p class="text-xs text-muted mb-2">First <?= (int) $slotFreeLimit ?> products are free. Extra products require paid slots via Chapa.</p>
    <form method="post">
      <input type="hidden" name="action" value="buy_product_slots">
      <div class="form-group mb-2">
        <label class="form-label" for="slotQtyInput">How many extra commodities?</label>
        <input id="slotQtyInput" type="number" min="1" max="500" name="slot_qty" class="form-control" value="1" oninput="refreshSlotCheckoutTotal()" required>
      </div>
      <div class="text-sm mb-2">
        <span class="text-muted">Price per slot:</span>
        <strong id="slotUnitFeeLabel"><?= number_format((float) $slotFeeEtb, 2) ?> ETB</strong>
      </div>
      <div class="text-sm mb-3">
        <span class="text-muted">Total to pay:</span>
        <strong id="slotPayTotalLabel"><?= number_format((float) $slotFeeEtb, 2) ?> ETB</strong>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:0.5rem">
        <button type="button" class="btn btn-ghost" onclick="closeSlotCheckoutModal()">Cancel</button>
        <button type="submit" class="btn btn-primary"><?= qb_icon('check', 'qb-icon', 14) ?> Pay with Chapa</button>
      </div>
    </form>
  </div>
</div>

<script>
var slotUnitFeeEtb = <?= json_encode((float) $slotFeeEtb) ?>;

function refreshSlotCheckoutTotal() {
  var qtyEl = document.getElementById('slotQtyInput');
  var totalEl = document.getElementById('slotPayTotalLabel');
  if (!qtyEl || !totalEl) return;
  var qty = parseInt(qtyEl.value || '1', 10);
  if (!isFinite(qty) || qty < 1) qty = 1;
  if (qty > 500) qty = 500;
  qtyEl.value = String(qty);
  var total = (slotUnitFeeEtb * qty);
  totalEl.textContent = total.toFixed(2) + ' ETB';
}

function openSlotCheckoutModal() {
  var modal = document.getElementById('slotCheckoutModal');
  if (!modal) return;
  modal.classList.add('is-open');
  refreshSlotCheckoutTotal();
}

function closeSlotCheckoutModal() {
  var modal = document.getElementById('slotCheckoutModal');
  if (!modal) return;
  modal.classList.remove('is-open');
}

function setProductFormCategory(slug) {
  var form = document.querySelector('#productModal form');
  if (!form) return;
  var inputs = form.querySelectorAll('input[name="category"]');
  var want = (slug === null || slug === undefined) ? '' : String(slug);
  var found = false;
  for (var i = 0; i < inputs.length; i++) {
    var on = inputs[i].value === want;
    inputs[i].checked = on;
    if (on) found = true;
  }
  if (!found && inputs.length) {
    var def = <?= json_encode($defaultProductCat) ?>;
    for (var j = 0; j < inputs.length; j++) {
      inputs[j].checked = inputs[j].value === def;
    }
  }
}

function toggleFlashSaleFields() {
  var enabled = document.getElementById('formFlashEnabled');
  var details = document.getElementById('formFlashDetails');
  if (!enabled || !details) return;
  details.style.display = enabled.checked ? 'grid' : 'none';

  var starts = document.getElementById('formFlashStarts');
  var ends = document.getElementById('formFlashEnds');
  var discount = document.getElementById('formFlashDiscount');
  var flashEvent = document.getElementById('formFlashEvent');
  var normalDiscount = document.getElementById('formDiscount');
  var normalDiscountWrap = normalDiscount ? normalDiscount.closest('.form-group') : null;

  if (enabled.checked) {
    var eventScoped = !!(flashEvent && String(flashEvent.value || '').trim() !== '');
    if (starts) starts.required = !eventScoped;
    if (ends) ends.required = !eventScoped;
    if (discount && String(discount.value || '').trim() === '' && normalDiscount && Number(normalDiscount.value || 0) > 0) {
      discount.value = String(Math.min(90, Math.max(1, Number(normalDiscount.value || 0))));
    }
    if (normalDiscount) normalDiscount.value = '0';
    if (normalDiscount) normalDiscount.readOnly = true;
    if (normalDiscountWrap) normalDiscountWrap.style.opacity = '0.6';
    if (normalDiscountWrap) normalDiscountWrap.style.pointerEvents = 'none';
  } else {
    if (starts) starts.required = false;
    if (ends) ends.required = false;
    if (normalDiscount) normalDiscount.readOnly = false;
    if (normalDiscountWrap) normalDiscountWrap.style.opacity = '';
    if (normalDiscountWrap) normalDiscountWrap.style.pointerEvents = '';
  }
}

function toggleFreeItemFields() {
  var freeBox = document.getElementById('formIsFreeItem');
  if (!freeBox) return;
  var price = document.getElementById('formPrice');
  var discount = document.getElementById('formDiscount');
  var freeLabel = document.getElementById('formFreeLabel');
  if (freeBox.checked) {
    if (price) price.value = '0';
    if (price) price.readOnly = true;
    if (discount) discount.value = '0';
    if (discount) discount.readOnly = true;
    if (freeLabel) freeLabel.placeholder = 'Shown to buyers as FREE';
  } else {
    if (price) price.readOnly = false;
    if (discount) discount.readOnly = false;
  }
}

function showAddModal() {
  document.getElementById('modalTitle').innerText = 'Add Product';
  document.getElementById('formAction').value = 'add';
  document.getElementById('formId').value = '';
  document.getElementById('formName').value = '';
  document.getElementById('formPrice').value = '';
  var fd = document.getElementById('formDiscount');
  if (fd) fd.value = '0';
  document.getElementById('formUnit').value = <?= json_encode($defaultUnit) ?>;
  document.getElementById('formStock').value = '0';
  setProductFormCategory(<?= json_encode($defaultProductCat) ?>);
  document.getElementById('formDesc').value = '';
  var fi = document.getElementById('formImage');
  if (fi) fi.value = '';
  var fp = document.getElementById('formImagePreview');
  if (fp) fp.textContent = 'Image is required.';
  var fc = document.getElementById('formClearImage');
  if (fc) fc.checked = false;
  var fe = document.getElementById('formFlashEnabled');
  if (fe) fe.checked = false;
  var fdp = document.getElementById('formFlashDiscount');
  if (fdp) fdp.value = '';
  var fse = document.getElementById('formFlashEvent');
  if (fse) fse.value = '';
  var fss = document.getElementById('formFlashStarts');
  if (fss) fss.value = '';
  var fse2 = document.getElementById('formFlashEnds');
  if (fse2) fse2.value = '';
  var freeBox = document.getElementById('formIsFreeItem');
  if (freeBox) freeBox.checked = false;
  var freeLabel = document.getElementById('formFreeLabel');
  if (freeLabel) freeLabel.value = '';
  toggleFlashSaleFields();
  toggleFreeItemFields();
  if (fi) fi.required = true;
  document.getElementById('productModal').classList.add('is-open');
}

function editProduct(p) {
  document.getElementById('modalTitle').innerText = 'Edit Product';
  document.getElementById('formAction').value = 'edit';
  document.getElementById('formId').value = p.id;
  document.getElementById('formName').value = p.name;
  document.getElementById('formPrice').value = p.price;
  var fd = document.getElementById('formDiscount');
  if (fd) fd.value = (p.discount_pct != null && p.discount_pct !== undefined) ? p.discount_pct : 0;
  var unitSel = document.getElementById('formUnit');
  if (unitSel) {
    var hasUnit = Array.from(unitSel.options).some(function (o) { return o.value === p.unit; });
    unitSel.value = hasUnit ? p.unit : <?= json_encode($defaultUnit) ?>;
  }
  document.getElementById('formStock').value = p.stock;
  setProductFormCategory(p.category);
  document.getElementById('formDesc').value = p.description;
  var fi = document.getElementById('formImage');
  if (fi) fi.value = '';
  var fp = document.getElementById('formImagePreview');
  if (fp) {
    if (p.image_url) {
      fp.textContent = 'Current image: ' + p.image_url;
    } else {
      fp.textContent = 'No image selected.';
    }
  }
  var fc = document.getElementById('formClearImage');
  if (fc) fc.checked = false;
  var fe = document.getElementById('formFlashEnabled');
  if (fe) fe.checked = false;
  var fdp = document.getElementById('formFlashDiscount');
  if (fdp) fdp.value = '';
  var fse = document.getElementById('formFlashEvent');
  if (fse) fse.value = '';
  var fss = document.getElementById('formFlashStarts');
  if (fss) fss.value = '';
  var fse2 = document.getElementById('formFlashEnds');
  if (fse2) fse2.value = '';
  var freeBox = document.getElementById('formIsFreeItem');
  if (freeBox) freeBox.checked = !!Number(p.is_free_item || 0);
  var freeLabel = document.getElementById('formFreeLabel');
  if (freeLabel) freeLabel.value = p.free_label || '';
  toggleFlashSaleFields();
  toggleFreeItemFields();
  if (fi) fi.required = !p.image_url;
  document.getElementById('productModal').classList.add('is-open');
}

function closeModal() {
  document.getElementById('productModal').classList.remove('is-open');
}

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && document.getElementById('productModal').classList.contains('is-open')) {
    closeModal();
    return;
  }
  if (e.key === 'Escape' && document.getElementById('slotCheckoutModal').classList.contains('is-open')) {
    closeSlotCheckoutModal();
  }
});

var formImage = document.getElementById('formImage');
if (formImage) {
  formImage.addEventListener('change', function () {
    var fp = document.getElementById('formImagePreview');
    if (!fp) return;
    if (formImage.files && formImage.files[0]) {
      fp.textContent = 'Selected: ' + formImage.files[0].name;
    } else {
      fp.textContent = 'Image is required.';
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  toggleFlashSaleFields();
  refreshSlotCheckoutTotal();
});
</script>

<?php qb_page_end(); ?>
