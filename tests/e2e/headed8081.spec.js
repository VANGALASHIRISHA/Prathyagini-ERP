const { test, expect } = require('@playwright/test');
test('8081 license dashboard: login, dashboard, every page, filters', async ({ page }) => {
  const B = 'http://localhost:8081/';
  const r = await page.goto(B + 'dashboard.php');
  await expect(page).toHaveURL(/login\.php/);               // protected when logged out
  await page.fill('input[name=username]', 'bc_license');
  await page.fill('input[name=password]', 'Tmp#License12345');
  await Promise.all([page.waitForNavigation(), page.click('button')]);
  await expect(page).toHaveURL(/dashboard\.php/);
  await expect(page.locator('h1')).toContainText('License Dashboard');
  await page.screenshot({ path: 'results/8081-dashboard.png', fullPage: true });
  console.log('cards:', (await page.locator('.row3 .panel').allInnerTexts()).map(t => t.replace(/\n/g, '=')).join(' | '));
  console.log('rows:', await page.locator('table').first().locator('tr').count() - 1);
  await page.click('a:has-text("Active")');                // clickable card filter
  await expect(page).toHaveURL(/status=active/);
  await page.goto(B + 'dashboard.php?q=pratyangi');
  await expect(page.locator('table').first()).toContainText(/pratyangi/i);
  for (const p of ['customers.php', 'events.php', 'license_new.php', 'change_password.php']) {
    const res = await page.goto(B + p); expect(res.status(), p).toBe(200);
    await expect(page.locator('body')).not.toContainText(/Fatal error|Warning:|Parse error/);
  }
  await page.goto(B + 'dashboard.php');
  await page.click('table >> text=Open >> nth=0');
  await expect(page).toHaveURL(/license_view\.php/);
  await page.screenshot({ path: 'results/8081-license-view.png', fullPage: true });
  await page.click('text=Logout');
  await page.goto(B + 'dashboard.php');
  await expect(page).toHaveURL(/login\.php/);
});
