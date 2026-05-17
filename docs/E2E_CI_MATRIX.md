# E2E CI Matrix

## Goal

Automate critical role and payment/gate flows in CI.

## Workflow

- File: `.github/workflows/e2e-matrix.yml`
- Suites:
  - `gate-payment`: `tests/e2e/payment-gate.spec.js`
  - `role-access`: `tests/e2e/role-portal-access.spec.js`

## Required CI Secrets

- `E2E_BASE_URL`
- `E2E_BUYER_LOGIN`, `E2E_BUYER_PASSWORD`
- `E2E_SELLER_LOGIN`, `E2E_SELLER_PASSWORD`
- `E2E_ORGANIZER_LOGIN`, `E2E_ORGANIZER_PASSWORD`
- `E2E_ADMIN_LOGIN`, `E2E_ADMIN_PASSWORD`
- `E2E_GATEKEEPER_LOGIN`, `E2E_GATEKEEPER_PASSWORD`

## Local Commands

- Run all E2E:
  - `npm run test:e2e`
- Run headed:
  - `npm run test:e2e:headed`
- List tests:
  - `npx playwright test --list`

