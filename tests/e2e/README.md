# E2E Test Suite (Gate + Payment)

This suite validates the highest-risk user paths:

- gate-scan enforcement before buyer discovery
- gate-scan enforcement before scan/vendor purchase flow

## Setup

1. Install dependencies:
   - `npm install`
2. Install browsers:
   - `npx playwright install`
3. Set env vars:
   - `E2E_BASE_URL` (optional, default: `http://localhost/QR%20BAZAR`)
   - `E2E_BUYER_LOGIN`
   - `E2E_BUYER_PASSWORD`

## Run

- Headless: `npm run test:e2e`
- Headed: `npm run test:e2e:headed`
- UI mode: `npm run test:e2e:ui`

## Notes

- Tests are intentionally strict around gate/session requirements.
- If credentials are not provided, authenticated tests are skipped.
