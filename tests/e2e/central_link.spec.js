const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
const PORTAL = 'http://localhost:8081/';
test('central license -> ERP activation end to end', async ({ page }) => {
  // 1. portal: create a licence and read the one-time key
  await page.goto(PORTAL + 'login.php');
  await page.fill('input[name=username]', 'bc_license');
  await page.fill('input[name=password]', 'Tmp#License12345');
  await Promise.all([page.waitForNavigation(), page.click('button')]);
  await page.goto(PORTAL + 'license_new.php');
  await page.fill('input[name=shop_name]', 'ERP Local Link Test ' + Date.now().toString(36));
  await Promise.all([page.waitForNavigation(), page.click('button.btn, button')]);
  const body = await page.locator('body').innerText();
  const key = (body.match(/PRAT-[A-Z0-9]{6}-[A-Z0-9]{6}-[A-Z0-9]{6}-[A-Z0-9]{6}/) || [])[0];
  expect(key, 'activation key shown by portal').toBeTruthy();
  // 2. ERP: before activation a Super Admin is sent to the activation page
  await login(page);
  await page.goto('stock.php');
  if (/license_activate\.php/.test(page.url())) await page.screenshot({ path: 'results/link-1-needs-activation.png' }); else await page.goto('license_activate.php');
  // 3. activate with the key
  await page.fill('input[name=license_key]', key);
  await Promise.all([page.waitForNavigation(), page.click('text=Activate License')]);
  await page.goto('license_status.php');
  await expect(page.locator('body')).toContainText(/ACTIVE/);
  await page.screenshot({ path: 'results/link-2-active.png', fullPage: true });
  // 4. ERP now works for real, without the bypass
  const r = await page.goto('stock.php');
  expect(r.status()).toBe(200);
  await expect(page).not.toHaveURL(/license_/);
  // 5. portal now shows the install
  await page.goto(PORTAL + 'dashboard.php');
  await expect(page.locator('body')).toContainText('ERP Local Link Test');
});
