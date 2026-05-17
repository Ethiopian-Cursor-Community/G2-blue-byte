# Chapa Integration (Production-Ready)

## 1) Environment Configuration

Copy `.env.example` to `.env` and set real values:

- `CHAPA_ENABLED=true`
- `CHAPA_MODE=test` (sandbox) or `live` (production)
- `CHAPA_PUBLIC_KEY=...`
- `CHAPA_SECRET_KEY=...`
- `CHAPA_ENCRYPTION_KEY=...`

## 2) Payment Flow Architecture

- **Initialize**: `qb_payment_intent_create()` + `qb_chapa_checkout_start()`
- **Hosted checkout**: redirect to Chapa `checkout_url`
- **Return URL**: `/chapa_return.php` (server verifies and fulfills)
- **Webhook URL**: `/api/chapa_callback.php` (signature validation + verify + idempotent fulfill)
- **Verification API**: `/api/chapa_verify.php` (manual/pending re-check)
- **Re-init API**: `/api/chapa_initialize.php` (retry failed/cancelled pending intents)

## 3) Database Objects

Created/managed by `qb_chapa_apply_schema()`:

- `payment_intents`
- `payment_verification_logs`
- `payment_webhook_events`

Stored data includes:

- `tx_ref` (`provider_tx_ref`)
- provider reference
- provider status (`pending|paid|fulfilled|failed|cancelled`)
- verification payload snapshots
- verification logs
- webhook event payload + idempotency hash

## 4) Business Flows Wired

- buyer ticket purchase
- merchant product purchase
- seller event apply fee
- role-upgrade request fee
- paid promo fast-track

Each flow is fulfilled only after server-side verify and then moved to fulfilled idempotently.

## 5) Security Controls

- secret keys are loaded from environment, not frontend
- webhook HMAC signature validation using `CHAPA_ENCRYPTION_KEY`
- duplicate webhooks ignored using unique `event_hash`
- frontend success is never trusted; verify endpoint is required
- fulfillment uses idempotent `consumed_at` guard

## 6) Sandbox Test Plan

Run through each:

1. successful payment
2. cancelled checkout
3. failed payment
4. webhook replay (duplicate same payload)
5. delayed callback then manual verify via `/api/chapa_verify.php`

Check:

- `payment_intents`
- `payment_verification_logs`
- `payment_webhook_events`
- `transactions` (for fulfilled purchasable flows)

## 7) Deployment Notes

- Use HTTPS only in production.
- Set `CHAPA_MODE=live` with live keys.
- Lock down firewall/WAF to allow Chapa webhook source access.
- Monitor 4xx/5xx on `/api/chapa_callback.php`.
- Keep DB backups for payment-related tables.
- Rotate keys periodically and update `.env`.
