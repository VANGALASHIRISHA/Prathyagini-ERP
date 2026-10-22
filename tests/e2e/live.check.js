// READ-ONLY checks of the live sites: no logins, no form submits, no writes.
const { test, expect } = require('@playwright/test');
const PANELS = { superadmin: 'Super Admin', manager: 'Manager', godown: 'Godown', cashier: 'Cashier', auditor: 'Auditor' };
const PHP_ERR = /Parse error|Fatal error|Warning:|Notice:|Uncaught|Stack trace|SQLSTATE/i;

for (const [sub, title] of Object.entries(PANELS)) {
  test(`${sub}: login page, protection, layout`, async ({ page }) => {
    const base = `https://${sub}.pratyangi.com/`;
    const errors = []; page.on('pageerror', e => errors.push(String(e))); page.on('response', r => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });
    const r = await page.goto(base + 'login.php');
    expect(r.status()).toBe(200);
    expect(page.url().startsWith('https://')).toBeTruthy();
    await expect(page).toHaveTitle(new RegExp(title + ' login'));
    await expect(page.locator('input[name=username]')).toBeVisible();
    await expect(page.locator('input[name=password]')).toBeVisible();
    await expect(page.locator('input[name=csrf]')).toHaveCount(1);
    expect(await page.content()).not.toMatch(PHP_ERR);
    await expect(page.locator('a:has-text("Forgot password")')).toBeVisible();
    // protected pages send a logged-out visitor to the login page
    for (const p of ['dashboard.php', 'stock.php', 'audit.php', 'users.php']) {
      await page.goto(base + p);
      expect(page.url(), p).toMatch(/login\.php|license|402|403/);
    }
    // sensitive files are not served
    for (const p of ['.env', 'config.php', 'sql/database.sql', 'app/bootstrap.php', 'license/public_key.pem']) {
      const res = await page.request.get(base + p, { failOnStatusCode: false });
      const body = await res.text();
      expect(res.status(), p).not.toBe(200);
      expect(body, p).not.toMatch(/DB_PASS|CREATE TABLE|<\?php|BEGIN PUBLIC KEY/);
    }
    // phone layout: no sideways scroll
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + 'login.php');
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({ path: `live-out/${sub}-phone.png` });
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.goto(base + 'login.php');
    await page.screenshot({ path: `live-out/${sub}-desktop.png` });
    expect(errors, errors.join('\n')).toEqual([]);
  });
}

test('forgot-password page opens (not submitted)', async ({ page }) => {
  const r = await page.goto('https://superadmin.pratyangi.com/forgot_password.php');
  console.log('forgot_password.php ->', r.status());
  expect(r.status()).toBe(200);
  expect(await page.content()).not.toMatch(PHP_ERR);
});

test('new files from the upload exist on live (redirect to login, not 404)', async ({ page }) => {
  const missing = [];
  for (const p of ['auditor_dashboard.php', 'purchase_import.php', 'stock_transfer_approve.php', 'stock_transfer_dispatch.php', 'stock_transfer_receive.php', 'forgot_password.php']) {
    const res = await page.request.get('https://superadmin.pratyangi.com/' + p, { failOnStatusCode: false, maxRedirects: 0 });
    if (res.status() === 404) missing.push(p);
    console.log(p, res.status());
  }
  expect(missing, 'missing on live: ' + missing.join(', ')).toEqual([]);
});

test('license portal: login, protection, ping, hidden files', async ({ page }) => {
  const base = 'https://centrallicense.pratyangi.com/';
  const r = await page.goto(base + 'login.php');
  expect(r.status()).toBe(200);
  await expect(page).toHaveTitle(/License Admin Login/);
  expect(await page.content()).not.toMatch(PHP_ERR);
  for (const p of ['dashboard.php', 'customers.php', 'events.php', 'license_new.php', 'change_password.php']) {
    const res = await page.goto(base + p);
    console.log(p, '->', page.url().replace(base, ''), res.status());
  }
  const ping = await (await page.request.get(base + 'api/v1/ping.php')).json();
  expect(ping.ok).toBe(true); expect(ping.service).toBe('PRATYANGI_LICENSE_SERVER');
  const g = await page.request.get(base + 'api/v1/activate.php', { failOnStatusCode: false });
  expect(g.status()).toBe(405); // activate only accepts POST
  for (const p of ['config.php', 'config.secret.php', 'keys/private.pem', 'keys/public.pem', 'sql/license_server.sql', 'app/bootstrap.php', 'error_log']) {
    const res = await page.request.get(base + p, { failOnStatusCode: false });
    const body = await res.text();
    expect(res.status(), p).not.toBe(200);
    expect(body, p).not.toMatch(/PRIVATE KEY|CREATE TABLE|<\?php/);
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + 'login.php');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  await page.screenshot({ path: 'live-out/portal-phone.png' });
});
