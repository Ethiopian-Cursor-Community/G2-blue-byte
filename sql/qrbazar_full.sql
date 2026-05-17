-- ============================================================
-- QR BAZAR — Full Database Schema + Seed Data
-- Run this ONCE in phpMyAdmin on the `qr_bazaar` database
-- Password hash below = bcrypt("password")
-- ============================================================

CREATE DATABASE IF NOT EXISTS qr_bazaar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qr_bazaar;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS flash_sales;
DROP TABLE IF EXISTS event_announcements;
DROP TABLE IF EXISTS stalls;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS event_participants;
DROP TABLE IF EXISTS bazar_events;
DROP TABLE IF EXISTS analytics_events;
DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS transaction_items;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS sellers;
DROP TABLE IF EXISTS app_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- USERS (unified)
-- ============================================================
CREATE TABLE app_users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    public_uuid     VARCHAR(36) NULL DEFAULT NULL,
    login_uid       VARCHAR(64) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NOT NULL,
    role            ENUM('super_admin','organizer','seller','buyer') NOT NULL,
    phone           VARCHAR(20) DEFAULT NULL,
    email           VARCHAR(100) DEFAULT NULL,
    residence_city  VARCHAR(120) DEFAULT NULL,
    avatar          VARCHAR(255) DEFAULT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    is_locked       TINYINT(1) NOT NULL DEFAULT 0,
    is_banned       TINYINT(1) NOT NULL DEFAULT 0,
    moderation_note VARCHAR(500) NULL DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_login_uid (login_uid),
    UNIQUE KEY uq_app_users_public_uuid (public_uuid),
    KEY idx_role (role),
    KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SELLERS (linked to app_users)
-- ============================================================
CREATE TABLE sellers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    app_user_id     INT NOT NULL,
    uid             VARCHAR(16) UNIQUE NOT NULL,
    full_name       VARCHAR(100) NOT NULL,
    market_name     VARCHAR(100) NOT NULL,
    phone           VARCHAR(20) NOT NULL,
    email           VARCHAR(100),
    password_hash   VARCHAR(255) NOT NULL,
    location        VARCHAR(150),
    category        VARCHAR(50) DEFAULT 'General',
    profile_image   VARCHAR(255),
    qr_secret       VARCHAR(64) NOT NULL,
    allow_direct_sales TINYINT(1) DEFAULT 0,
    is_active       TINYINT(1) DEFAULT 1,
    is_flagged      TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BAZAR EVENTS
-- ============================================================
CREATE TABLE bazar_events (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    slug                    VARCHAR(64) NOT NULL,
    name                    VARCHAR(200) NOT NULL,
    venue                   VARCHAR(200) DEFAULT NULL,
    city                    VARCHAR(100) DEFAULT NULL,
    organizer_app_user_id   INT DEFAULT NULL,
    lat                     DECIMAL(10,7) DEFAULT NULL,
    lng                     DECIMAL(10,7) DEFAULT NULL,
    radius_meters           INT DEFAULT 500,
    max_sellers             INT DEFAULT 50,
    ticket_sales_start      DATETIME DEFAULT NULL,
    ticket_sales_end        DATETIME DEFAULT NULL,
    event_start             DATETIME DEFAULT NULL,
    event_end               DATETIME DEFAULT NULL,
    status                  ENUM('draft','published','live','ended','postponed','canceled') NOT NULL DEFAULT 'draft',
    notes                   TEXT,
    lifecycle_note          TEXT,
    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug),
    KEY idx_org (organizer_app_user_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CO-ORGANIZERS (additional organizers per event; primary stays on bazar_events.organizer_app_user_id)
-- ============================================================
CREATE TABLE bazar_event_organizers (
    event_id        INT NOT NULL,
    app_user_id     INT NOT NULL,
    assigned_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, app_user_id),
    KEY idx_eo_user (app_user_id),
    CONSTRAINT fk_eo_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eo_user  FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EVENT PARTICIPANTS (buyers + sellers per event)
-- ============================================================
CREATE TABLE event_participants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    app_user_id     INT NOT NULL,
    role_in_event   ENUM('buyer','seller') NOT NULL,
    status          ENUM('pending','approved','rejected') DEFAULT 'approved',
    assigned_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_user (event_id, app_user_id),
    KEY idx_event (event_id),
    KEY idx_user (app_user_id),
    CONSTRAINT fk_ep_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_ep_user  FOREIGN KEY (app_user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- STALLS (seller positions inside event)
-- ============================================================
CREATE TABLE stalls (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    seller_id       INT NOT NULL,
    stall_number    VARCHAR(20) NOT NULL,
    lat             DECIMAL(10,7) DEFAULT NULL,
    lng             DECIMAL(10,7) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event (event_id),
    KEY idx_seller (seller_id),
    CONSTRAINT fk_stall_event  FOREIGN KEY (event_id)  REFERENCES bazar_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_stall_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TICKETS (buyer entry to event)
-- ============================================================
CREATE TABLE tickets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id        INT NOT NULL,
    event_id        INT NOT NULL,
    ticket_code     VARCHAR(32) UNIQUE NOT NULL,
    qr_data         TEXT,
    status          ENUM('active','used','cancelled') DEFAULT 'active',
    issued_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    used_at         DATETIME DEFAULT NULL,
    KEY idx_buyer (buyer_id),
    KEY idx_event (event_id),
    CONSTRAINT fk_ticket_buyer FOREIGN KEY (buyer_id) REFERENCES app_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PRODUCTS
-- ============================================================
CREATE TABLE products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT NOT NULL,
    event_id        INT DEFAULT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    price           DECIMAL(10,2) NOT NULL,
    discount_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    unit            VARCHAR(20) DEFAULT 'unit',
    stock           INT DEFAULT 0,
    image_url       VARCHAR(255),
    category        VARCHAR(50) DEFAULT 'General',
    is_available    TINYINT(1) DEFAULT 1,
    view_count      INT DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TRANSACTIONS
-- ============================================================
CREATE TABLE transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tx_id           VARCHAR(20) UNIQUE NOT NULL,
    seller_id       INT NOT NULL,
    buyer_id        INT DEFAULT NULL,
    event_id        INT DEFAULT NULL,
    buyer_name      VARCHAR(100) DEFAULT 'Anonymous',
    buyer_phone     VARCHAR(20),
    total_amount    DECIMAL(10,2) NOT NULL,
    payment_method  ENUM('telebirr','cash','p2p','wallet_qr') NOT NULL,
    payment_status  ENUM('pending','completed','failed') DEFAULT 'completed',
    notes           TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TRANSACTION ITEMS
-- ============================================================
CREATE TABLE transaction_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT NOT NULL,
    product_id      INT,
    product_name    VARCHAR(100) NOT NULL,
    unit_price      DECIMAL(10,2) NOT NULL,
    quantity        INT NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RATINGS
-- ============================================================
CREATE TABLE ratings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT NOT NULL,
    buyer_id        INT DEFAULT NULL,
    transaction_id  INT,
    buyer_name      VARCHAR(100) DEFAULT 'Anonymous',
    stars           TINYINT NOT NULL CHECK (stars BETWEEN 1 AND 5),
    comment         TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ANALYTICS EVENTS
-- ============================================================
CREATE TABLE analytics_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    seller_id       INT NOT NULL,
    event_type      ENUM('qr_scan','product_view','purchase','rating') NOT NULL,
    product_id      INT,
    metadata        JSON,
    event_hour      TINYINT,
    event_date      DATE,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- NOTIFICATIONS (buyer ticket notifications)
-- ============================================================
CREATE TABLE notifications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    type            ENUM('ticket','flash_sale','announcement','purchase','system') NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body            TEXT,
    link            VARCHAR(255) DEFAULT NULL,
    is_read         TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_read (is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FLASH SALES
-- ============================================================
CREATE TABLE flash_sales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT NOT NULL,
    seller_id       INT NOT NULL,
    event_id        INT DEFAULT NULL,
    discount_pct    TINYINT NOT NULL DEFAULT 10,
    original_price  DECIMAL(10,2) NOT NULL,
    sale_price      DECIMAL(10,2) NOT NULL,
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id)  REFERENCES sellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EVENT ANNOUNCEMENTS
-- ============================================================
CREATE TABLE event_announcements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT NOT NULL,
    organizer_id    INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body            TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event (event_id),
    CONSTRAINT fk_ann_event FOREIGN KEY (event_id) REFERENCES bazar_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- REPORTS / FRAUD
-- ============================================================
CREATE TABLE reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id     INT DEFAULT NULL,
    target_type     ENUM('seller','product','behavior') NOT NULL,
    target_id       INT NOT NULL,
    reason          VARCHAR(200) NOT NULL,
    details         TEXT,
    status          ENUM('open','reviewed','resolved') DEFAULT 'open',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AUDIT LOG
-- ============================================================
CREATE TABLE audit_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT DEFAULT NULL,
    action          VARCHAR(100) NOT NULL,
    target_type     VARCHAR(50),
    target_id       INT,
    metadata        JSON,
    ip_address      VARCHAR(45),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Password hash for "password"
SET @pwd := '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- Users
INSERT INTO app_users (public_uuid, login_uid, password_hash, display_name, role, phone, email) VALUES
('kR3vW9nLmPqXyZ2a', 'admin1',     @pwd, 'System Admin',    'super_admin', '0900000001', 'admin@qrbazar.et'),
('Lm8nPq2WxYz4Ab6c', 'organizer1', @pwd, 'Demo Organizer',  'organizer',   '0900000002', 'org@qrbazar.et'),
('Np9Qr3XyZa5Bc7dE', 'seller1',    @pwd, 'Abebe Seller',    'seller',      '0911234567', 'seller1@qrbazar.et'),
('Rs1Tu5VwXy7Za9bC', 'buyer1',     @pwd, 'Hana Buyer',      'buyer',       '0922000001', 'buyer1@qrbazar.et'),
('Wx3Yz7Ab9Cd1Ef2G', 'buyer2',     @pwd, 'Yonas Buyer',     'buyer',       '0922000002', 'buyer2@qrbazar.et');

-- Seller profile for seller1
INSERT INTO sellers (app_user_id, uid, full_name, market_name, phone, email, password_hash, location, category, qr_secret, allow_direct_sales, is_active)
SELECT id, 'SELLER1DEMO', 'Abebe Seller', 'Abebe Fresh Market', '0911234567', 'seller1@qrbazar.et', @pwd, 'Merkato, Addis Ababa', 'Vegetables', 'qr_secret_seller1_2026', 1, 1
FROM app_users WHERE login_uid = 'seller1';

-- Bazar event
INSERT INTO bazar_events (slug, name, venue, city, organizer_app_user_id, lat, lng, radius_meters, max_sellers, ticket_sales_start, ticket_sales_end, event_start, event_end, status, notes)
SELECT 'spring-bazar-2026', 'Spring Bazar 2026', 'Merkato Open Lot', 'Addis Ababa',
       id, 9.0320, 38.7469, 500, 30,
       NOW() - INTERVAL 1 DAY,
       NOW() + INTERVAL 60 DAY,
       NOW() + INTERVAL 1 HOUR,
       NOW() + INTERVAL 25 HOUR,
       'live',
       'Annual spring bazar — vegetables, spices, clothing, electronics.'
FROM app_users WHERE login_uid = 'organizer1';

-- Add seller to event
INSERT INTO event_participants (event_id, app_user_id, role_in_event, status)
SELECT e.id, u.id, 'seller', 'approved'
FROM bazar_events e, app_users u
WHERE e.slug = 'spring-bazar-2026' AND u.login_uid = 'seller1';

-- Add buyer1 + buyer2 to event
INSERT INTO event_participants (event_id, app_user_id, role_in_event, status)
SELECT e.id, u.id, 'buyer', 'approved'
FROM bazar_events e, app_users u
WHERE e.slug = 'spring-bazar-2026' AND u.login_uid IN ('buyer1','buyer2');

-- Stall for seller1
INSERT INTO stalls (event_id, seller_id, stall_number, lat, lng)
SELECT e.id, s.id, 'A-01', 9.0325, 38.7475
FROM bazar_events e, sellers s
WHERE e.slug = 'spring-bazar-2026' AND s.uid = 'SELLER1DEMO';

-- Products for seller1
INSERT INTO products (seller_id, name, description, price, unit, stock, category) VALUES
(1, 'Fresh Tomatoes',  'Locally grown ripe tomatoes from Ziway',  5.00, 'kg',   50, 'Vegetables'),
(1, 'Red Onions',      'Premium quality red onions',              3.00, 'kg',   80, 'Vegetables'),
(1, 'Potatoes',        'Fresh potatoes from highlands',           4.00, 'kg',  100, 'Vegetables'),
(1, 'Carrots',         'Orange carrots, freshly harvested',       6.00, 'kg',   30, 'Vegetables'),
(1, 'Berbere Spice',   'Traditional Ethiopian berbere blend',    25.00, '100g', 40, 'Spices'),
(1, 'Mitmita',         'Hot chili powder blend',                 20.00, '50g',  35, 'Spices');

-- Tickets for buyer1 + buyer2
INSERT INTO tickets (buyer_id, event_id, ticket_code, qr_data, status)
SELECT u.id, e.id,
       CONCAT('TKT', LPAD(u.id, 4, '0'), UPPER(SUBSTRING(MD5(RAND()), 1, 8))),
       JSON_OBJECT('event', e.slug, 'buyer', u.login_uid),
       'active'
FROM app_users u, bazar_events e
WHERE u.login_uid IN ('buyer1','buyer2') AND e.slug = 'spring-bazar-2026';

-- Transactions
INSERT INTO transactions (tx_id, seller_id, buyer_id, event_id, buyer_name, buyer_phone, total_amount, payment_method, payment_status)
SELECT 'TXN20260001', s.id, u.id, e.id, 'Hana Buyer', '0922000001', 25.00, 'telebirr', 'completed'
FROM sellers s, app_users u, bazar_events e
WHERE s.uid='SELLER1DEMO' AND u.login_uid='buyer1' AND e.slug='spring-bazar-2026';

INSERT INTO transactions (tx_id, seller_id, buyer_id, event_id, buyer_name, buyer_phone, total_amount, payment_method, payment_status)
SELECT 'TXN20260002', s.id, u.id, e.id, 'Yonas Buyer', '0922000002', 15.00, 'cash', 'completed'
FROM sellers s, app_users u, bazar_events e
WHERE s.uid='SELLER1DEMO' AND u.login_uid='buyer2' AND e.slug='spring-bazar-2026';

-- Ratings
INSERT INTO ratings (seller_id, buyer_id, buyer_name, stars, comment) VALUES
(1, 4, 'Hana Buyer',  5, 'Very fresh vegetables! Great seller.'),
(1, 5, 'Yonas Buyer', 4, 'Good quality, fair prices.');

-- Notifications for buyers
INSERT INTO notifications (user_id, type, title, body, link, is_read)
SELECT u.id, 'ticket', 'Ticket Confirmed!', 'Your ticket for Spring Bazar 2026 is ready. Show it at the entrance.', '/QR BAZAR/buyer/tickets.php', 0
FROM app_users u WHERE u.login_uid IN ('buyer1','buyer2');

INSERT INTO notifications (user_id, type, title, body, link, is_read)
SELECT u.id, 'announcement', 'Welcome to Spring Bazar 2026!', 'The bazar is now live. Explore nearby stalls and scan QR codes to buy.', '/QR BAZAR/buyer/home.php', 0
FROM app_users u WHERE u.login_uid IN ('buyer1','buyer2');

-- Analytics events
INSERT INTO analytics_events (seller_id, event_type, event_hour, event_date) VALUES
(1, 'qr_scan',  9,  CURDATE() - INTERVAL 6 DAY),
(1, 'purchase', 10, CURDATE() - INTERVAL 6 DAY),
(1, 'qr_scan',  11, CURDATE() - INTERVAL 5 DAY),
(1, 'purchase', 12, CURDATE() - INTERVAL 5 DAY),
(1, 'purchase', 14, CURDATE() - INTERVAL 5 DAY),
(1, 'qr_scan',  8,  CURDATE() - INTERVAL 4 DAY),
(1, 'purchase', 10, CURDATE() - INTERVAL 4 DAY),
(1, 'qr_scan',  15, CURDATE() - INTERVAL 3 DAY),
(1, 'purchase', 16, CURDATE() - INTERVAL 3 DAY),
(1, 'qr_scan',  9,  CURDATE() - INTERVAL 2 DAY),
(1, 'purchase', 11, CURDATE() - INTERVAL 2 DAY),
(1, 'purchase', 13, CURDATE() - INTERVAL 2 DAY),
(1, 'qr_scan',  8,  CURDATE() - INTERVAL 1 DAY),
(1, 'purchase', 9,  CURDATE() - INTERVAL 1 DAY),
(1, 'purchase', 11, CURDATE()),
(1, 'purchase', 14, CURDATE());
