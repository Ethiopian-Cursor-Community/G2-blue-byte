const { test, expect } = require('@playwright/test');

const creds = {
  buyer: {
    login: process.env.E2E_BUYER_LOGIN || '',
    password: process.env.E2E_BUYER_PASSWORD || '',
    portal: '/buyer/home.php',
  },
  seller: {
    login: process.env.E2E_SELLER_LOGIN || '',
    password: process.env.E2E_SELLER_PASSWORD || '',
    portal: '/seller/dashboard.php',
  },
  organizer: {
    login: process.env.E2E_ORGANIZER_LOGIN || '',
    password: process.env.E2E_ORGANIZER_PASSWORD || '',
    portal: '/organizer/dashboard.php',
  },
  admin: {
    login: process.env.E2E_ADMIN_LOGIN || '',
    password: process.env.E2E_ADMIN_PASSWORD || '',
    portal: '/admin/dashboard.php',
  },
  gatekeeper: {
    login: process.env.E2E_GATEKEEPER_LOGIN || '',
    password: process.env.E2E_GATEKEEPER_PASSWORD || '',
    portal: '/gatekeeper/dashboard.php',
  },
};

async function login(page, login, password) {
  await page.goto('/login.php');
  await page.getByLabel(/email|username|login/i).fill(login);
  await page.getByLabel(/password/i).fill(password);
  await page.getByRole('button', { name: /sign in|get started|continue/i }).click();
}

for (const [role, c] of Object.entries(creds)) {
  test.describe(`Portal access: ${role}`, () => {
    test.skip(!c.login || !c.password, `Missing E2E credentials for ${role}`);

    test(`${role} can open own portal`, async ({ page }) => {
      await login(page, c.login, c.password);
      await page.goto(c.portal);
      await expect(page).toHaveURL(new RegExp(c.portal.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    });
  });
}

