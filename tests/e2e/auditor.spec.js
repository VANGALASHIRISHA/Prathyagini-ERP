const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
test.beforeEach(async ({ page }) => { await login(page, 'bc_auditor', 'Tmp#12345'); });
test('auditor lands on the Auditor Dashboard with its own menu', async ({ page }) => {
  await expect(page).toHaveURL(/auditor_dashboard\.php/);
  await expect(page.locator('h1')).toContainText('Auditor Dashboard');
  await expect(page.locator('#sideNav')).toContainText('Audit Log');
  await expect(page.locator('#sideNav')).not.toContainText('POS Billing');
  await page.screenshot({ path: 'results/dashboards/Auditor-dashboard.png' });
});
test('auditor can read audit log, stock, reports', async ({ page }) => {
  for (const p of ['audit.php', 'stock.php', 'stock_reports.php', 'reports.php', 'scan_find.php', 'product_finder.php']) {
    const r = await page.goto(p); expect(r.status(), p).toBe(200);
  }
});
test('auditor is blocked from changing data', async ({ page }) => {
  for (const p of ['users.php', 'purchase_new.php', 'pos.php', 'stock_transfer.php', 'barcode_create.php', 'settings.php']) {
    const r = await page.goto(p); expect(r.status(), p).toBe(403);
  }
});
