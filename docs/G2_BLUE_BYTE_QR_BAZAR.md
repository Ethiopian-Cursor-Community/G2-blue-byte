# G2 Blue Byte — QR Bazar (Official Submission Branch)

This branch contains the **complete, production QR Bazar marketplace** for the Ethiopian Cursor Community **G2 Blue Byte** team.

## This is the real project

| Item | Detail |
|------|--------|
| **Product** | QR Bazar — multi-portal bazaar platform (buyers, sellers, organizers, gatekeepers, admins) |
| **Stack** | PHP 8+, MySQL, JavaScript, Chapa/Telebirr payments, QR tickets & gate scanning |
| **Team** | G2 Blue Byte |
| **Branch** | `qr-bazar` (this codebase) |

The repository `main` branch may contain other coursework (e.g. sign-language tooling). **Use this branch** for QR Bazar demos, grading, and review.

## Quick proof (what to open first)

1. `index.php` / `public_home.php` — public landing
2. `buyer/home.php` — buyer portal
3. `seller/dashboard.php` — seller portal
4. `sql/qrbazar_full.sql` — full database schema
5. `install/demo_credentials.txt` — demo accounts
6. `docs/INSA_SUMMER_CAMP_PROJECT_REPORT.md` — project report

## Run locally

1. XAMPP: copy project to `htdocs/QR BAZAR`
2. Import `sql/qrbazar_full.sql` into MySQL database `qr_bazaar`
3. Copy `.env.example` → `.env` and adjust DB credentials
4. Open `http://localhost/QR%20BAZAR/`

## Portals

- **Buyer** — scan QR, browse events, purchase tickets, Chapa checkout
- **Seller** — products, flash sales, QR stall, payments
- **Organizer** — events, sellers, ticket scan, promos
- **Gatekeeper** — entry scan
- **Admin** — users, events, fraud, reconciliation

## Stats (indicative)

- 200+ PHP/JS/CSS source files
- Multi-role RBAC, geofencing, offline-capable buyer flows
- E2E tests under `tests/e2e/`

---

**Repository:** [Ethiopian-Cursor-Community/G2-blue-byte](https://github.com/Ethiopian-Cursor-Community/G2-blue-byte)  
**Branch:** `qr-bazar`
