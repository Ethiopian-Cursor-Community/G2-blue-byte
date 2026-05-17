const { test, expect } = require('@playwright/test');

const buyerLogin = process.env.E2E_BUYER_LOGIN || '';
const buyerPassword = process.env.E2E_BUYER_PASSWORD || '';

async function loginAsBuyer(page) {
  await page.goto('/login.php?portal=buyer');
  await page.getByLabel(/email|username/i).fill(buyerLogin);
  await page.getByLabel(/password/i).fill(buyerPassword);
  await page.getByRole('button', { name: /sign in|get started/i }).click();
}

test.describe('Gate + payment critical flows', () => {
  test.skip(!buyerLogin || !buyerPassword, 'Set E2E_BUYER_LOGIN and E2E_BUYER_PASSWORD to run authenticated flows.');

  test('buyer cannot access discover before ticket gate scan', async ({ page }) => {
    await loginAsBuyer(page);
    await page.goto('/buyer/discover.php');
    await expect(page.getByText(/Event access required/i)).toBeVisible();
    await expect(page.getByText(/ticket scan/i)).toBeVisible();
  });

  test('buyer scan page enforces gate scan session', async ({ page }) => {
    await loginAsBuyer(page);
    await page.goto('/buyer/scan.php');
    await expect(page.getByText(/Gate scan required/i)).toBeVisible();
  });
});
