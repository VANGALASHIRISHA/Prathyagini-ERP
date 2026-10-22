const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
const PASS = 'Tmp#12345';
const ROLES = {
  bctest:     { label: 'Super Admin', pages: ['super_admin_dashboard.php', 'products.php', 'stock.php', 'purchase_new.php', 'pos.php', 'online_orders.php', 'reports.php', 'audit.php', 'users.php'] },
  bc_manager: { label: 'Manager',     pages: ['manager_dashboard.php', 'stock.php', 'stock_reports.php', 'stock_transfer.php', 'pos.php', 'online_orders.php', 'reports.php'] },
  bc_cashier: { label: 'Cashier',     pages: ['cashier_dashboard.php', 'pos.php', 'stock.php', 'cashier_invoices.php', 'product_finder.php'] },
  bc_godown:  { label: 'Godown',      pages: ['godown_dashboard.php', 'stock.php', 'scan_find.php', 'stock_transfer.php', 'stock_count.php', 'location_labels.php'] },
};
for (const [user, cfg] of Object.entries(ROLES)) {
  test(`${cfg.label} dashboards load and show no errors`, async ({ page }) => {
    await login(page, user, PASS);
    for (const p of cfg.pages) {
      const res = await page.goto(p);
      await expect(res.status(), p).toBeLessThan(400);
      await expect(page.locator('h1').first(), p).toBeVisible();
      const html = await page.content();
      expect(html, p + ' has a PHP error').not.toMatch(/Fatal error|Warning:|Parse error|Uncaught/);
      await page.screenshot({ path: `results/dashboards/${cfg.label.replace(' ', '')}-${p.replace('.php', '')}.png` });
      await page.waitForTimeout(Number(process.env.PAUSE || 0));
    }
  });
}
