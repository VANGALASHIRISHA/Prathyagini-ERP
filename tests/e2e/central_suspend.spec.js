const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
const PORTAL = 'http://localhost:8081/';
test('suspend in portal blocks the ERP, re-activate restores it', async ({ page, browser }) => {
  await page.goto(PORTAL + 'login.php');
  await page.fill('input[name=username]', 'bc_license'); await page.fill('input[name=password]', 'Tmp#License12345');
  await Promise.all([page.waitForNavigation(), page.click('button')]);
  const erp0 = await browser.newPage(); await login(erp0, 'bctest', 'Tmp#12345'); await erp0.goto('license_status.php');
  const shop = (await erp0.locator('tr:has(th:has-text("Shop")) td').first().innerText()).trim(); await erp0.close();
  await page.goto(PORTAL + 'customers.php?q=' + encodeURIComponent(shop));
  await page.locator('a:has-text("Edit")').first().click();
  await page.click('table >> text=Open >> nth=0');
  const setStatus = async (s) => { await page.selectOption('form:has(input[value=status]) select[name=status]', s); await page.click('button:has-text("Update Status")'); await expect(page.locator('.notice')).toContainText('updated'); };
  await setStatus('SUSPENDED');
  // ERP: refresh now
  const erp = await browser.newPage(); await login(erp, 'bctest', 'Tmp#12345');
  await erp.goto('license_status.php'); await erp.click('text=Check / Refresh License Now');
  await erp.goto('license_status.php'); await expect(erp.locator('body')).toContainText('SUSPENDED');
  // a cashier is blocked
  const cash = await browser.newPage(); await login(cash, 'bc_cashier', 'Tmp#12345');
  const r = await cash.goto('pos.php'); expect(r.status()).toBe(403);
  await expect(cash.locator('body')).toContainText('SUSPENDED');
  await setStatus('ACTIVE');
  await erp.goto('license_status.php'); await erp.click('text=Check / Refresh License Now');
  await erp.goto('license_status.php'); await expect(erp.locator('body')).toContainText('ACTIVE');
  const r2 = await cash.goto('pos.php'); expect(r2.status()).toBe(200);
});
