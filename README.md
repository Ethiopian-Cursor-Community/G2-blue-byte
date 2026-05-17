# QR Bazar (Q-Bazaar) — G2 Blue Byte

> **Official G2 submission:** [Ethiopian-Cursor-Community/G2-blue-byte](https://github.com/Ethiopian-Cursor-Community/G2-blue-byte) — QR Bazar marketplace (G2 Blue Byte). See [docs/G2_BLUE_BYTE_QR_BAZAR.md](docs/G2_BLUE_BYTE_QR_BAZAR.md) for reviewers.

---

# Q-Bazaar — Setup & Installation Guide

## Requirements
- XAMPP / WAMP / Laragon (PHP 8.0+ with MySQL)
- A modern browser (Chrome recommended for camera access)

## Quick Setup (3 steps)

### Step 1 — Place files
Copy the entire `QR BAZAR` folder into your XAMPP `htdocs` directory:
```
C:\xampp\htdocs\QR BAZAR\
```

### Step 2 — Import database
1. Start **Apache** and **MySQL** from XAMPP Control Panel
2. Open `http://localhost/phpmyadmin`
3. Click **New** → create database named `qr_bazaar`
4. Select `qr_bazaar` → click **Import**
5. Choose `QR BAZAR/sql/schema.sql` → click **Go**

### Step 3 — Open the app
Visit: `http://localhost/QR BAZAR/`

---

## Demo Login Credentials

| Seller | Phone | Password |
|---|---|---|
| Abebe Girma (Vegetables) | 0911234567 | password |
| Tigist Bekele (Spices) | 0922345678 | password |
| Dawit Haile (Electronics) | 0933456789 | password |
| Marta Alemu (Clothing) | 0944567890 | password |

---

## Demo Flow (Hackathon Presentation)

1. Open `http://localhost/QR BAZAR/` — show the landing page
2. Register a new seller → get redirected to QR page
3. Go to **Products** → add some items
4. Open QR page → download or show QR on screen
5. Open `http://localhost/QR BAZAR/buyer/scan.php` in another tab
6. Click **"Demo Scan — Load Abebe's Market"**
7. Add items to cart → proceed with Chapa checkout → Confirm Payment
8. Receipt page appears — rate the seller
9. **Turn on Airplane Mode** (DevTools → Network → Offline)
10. Reload vendor page → it loads from cache! ← **WOW MOMENT**
11. Go back online → pending transactions auto-sync
12. Visit **Dashboard** → show revenue charts, trust score, credit score

---

## Competition Quick Flow (Judge Mode: 5 minutes)

1. Open `/` and scroll to **Competition Showcase — Dire Dawa**.
2. Click **Open event list** and show Dire Dawa Thu/Fri/Sat entries.
3. Click **View promos** to show image + video + marquee promos.
4. Sign in as a buyer (`buyer.demo1`) and open `buyer/home.php`.
5. Use **Competition Mode** buttons: Discover → Map → Scan → Leaderboards.
6. Sign in as organizer (`org.demo1`) and show event status cards + charts.
7. Sign in as seller (`seller.demo1`) and show sales KPIs + comparison charts.
8. Show `install/demo_credentials.txt` for multi-role demo accounts.

---

## Pages Overview

| URL | Description |
|---|---|
| `/` | Landing page |
| `/login.php` | Seller sign in |
| `/register.php` | New seller registration |
| `/seller/dashboard.php` | Analytics dashboard |
| `/seller/products.php` | Inventory management |
| `/seller/qr.php` | My QR Code |
| `/buyer/scan.php` | QR scanner |
| `/buyer/vendor.php?uid=SEL001ABEBE` | Abebe's storefront |
| `/buyer/receipt.php` | Payment receipt |
| `/discover.php` | Nearby vendors (proximity map) |
| `/ai-search.php` | AI-powered market search |

---

## Troubleshooting

**Blank page?** Check PHP errors: set `display_errors = On` in `php.ini`

**DB connection failed?** Verify DB credentials in `config.php`

**QR scanner not working?** Chrome requires HTTPS for camera. Use `localhost` or enable DevTools → Sensors → Override geolocation

**Camera not available in demo?** Use the **"Demo Scan"** button instead
