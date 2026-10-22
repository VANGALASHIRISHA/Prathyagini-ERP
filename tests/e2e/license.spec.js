const { test, expect } = require('@playwright/test');
const LIC = process.env.LIC_URL || 'http://localhost:8081/';
const U = process.env.LIC_USER || 'bc_license';
const P = process.env.LIC_PASS || 'Tmp#License12345';

async function lic(page) {
  await page.goto(LIC + 'login.php');
  await page.fill('input[name=username]', U);
  await page.fill('input[name=password]', P);
  await Promise.all([page.waitForNavigation(), page.click('button, .btn')]);
}

test('central: login and dashboard with filters', async ({ page }) => {
  await lic(page);
  await expect(page).toHaveURL(/dashboard\.php/);
  await expect(page.locator('h1')).toContainText('License Dashboard');
  await expect(page.locator('nav')).toContainText('Customers');
  await page.screenshot({ path: 'results/dashboards/License-dashboard.png' });
  await page.fill('input[name=q]', 'zzz-no-such-shop');
  await page.click('text=Filter');
  await expect(page.locator('body')).toContainText('No licenses match');
});

test('central: create license -> view -> customer edit -> renew -> activity log', async ({ page }) => {
  await lic(page);
  const shop = 'E2E Shop ' + Date.now().toString(36);
  await page.goto(LIC + 'license_new.php');
  await page.fill('input[name=shop_name]', shop);
  await page.fill('input[name=mobile]', '9999999999');
  await Promise.all([page.waitForNavigation(), page.click('button.btn, button')]);
  await expect(page.locator('body')).toContainText(/license key|Activation Key|PF-|[A-Z0-9]{4}-/i);
  // customers page: find and edit
  await page.goto(LIC + 'customers.php?q=' + encodeURIComponent(shop));
  await expect(page.locator('table')).toContainText(shop);
  await page.click('text=Edit');
  await page.fill('input[name=contact_name]', 'Test Contact');
  await page.fill('input[name=email]', 'bad-email');
  await page.fill('input[name=gstin]', '29ABCDE1234F1Z5');
  await page.click('button.btn');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.click('button.btn');
  await expect(page.locator('.notice')).toContainText('saved');
  // licence view -> renew
  await page.click('text=Open');
  await page.click('button:has-text("Renew License")');
  await expect(page.locator('.notice')).toContainText('renewed');
  // activity log shows it
  await page.goto(LIC + 'events.php?q=' + encodeURIComponent(shop));
  await expect(page.locator('body')).toContainText(/CUSTOMER_UPDATED|LICENSE_RENEWED/);
});

test('central: change password validation', async ({ page }) => {
  await lic(page);
  await page.goto(LIC + 'change_password.php');
  await page.fill('input[name=current]', 'wrong-password');
  await page.fill('input[name=new]', 'NewPassw0rd123');
  await page.fill('input[name=confirm]', 'NewPassw0rd123');
  await page.click('button.btn');
  await expect(page.locator('.notice.error')).toContainText('Current password is incorrect');
  await page.fill('input[name=current]', P);
  await page.fill('input[name=new]', 'NewPassw0rd123');
  await page.fill('input[name=confirm]', 'Different12345');
  await page.click('button.btn');
  await expect(page.locator('.notice.error')).toContainText('do not match');
});

test('central: responsive at phone width, no page-level sideways scroll', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await lic(page);
  for (const p of ['dashboard.php', 'customers.php', 'events.php', 'license_new.php', 'change_password.php']) {
    await page.goto(LIC + p);
    const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(over, p).toBeLessThanOrEqual(1);
  }
  await page.goto(LIC + 'dashboard.php');
  await page.screenshot({ path: 'results/dashboards/License-dashboard-phone.png' });
});
