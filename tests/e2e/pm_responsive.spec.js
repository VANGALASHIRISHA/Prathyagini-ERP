const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
test('purchase pages have no page-level sideways scroll on phone and tablet widths', async ({ page }) => {
  await login(page);
  const id = require('child_process').execSync('"C:/xampp/mysql/bin/mysql.exe" -uroot -N -B pimfi131_pratyangiportal -e "SELECT id FROM purchases WHERE entry_mode=\'MODULE\' ORDER BY id DESC LIMIT 1"', { encoding: 'utf8' }).trim();
  for (const w of [390, 768, 1024]) {
    await page.setViewportSize({ width: w, height: 844 });
    for (const p of ['vendors.php', 'vendor_form.php', 'purchase_bill.php', 'purchase_history.php', 'purchase_view.php?id=' + id, 'goods_receipt.php', 'vendor_payments.php', 'purchase_reports.php?report=invoice&from=2020-01-01&to=2099-12-31', 'gst_reports.php?from=2020-01-01&to=2099-12-31', 'purchase_settings.php']) {
      await page.goto(p);
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(over, w + ' ' + p).toBeLessThanOrEqual(1);
    }
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('purchase_bill.php');
  await page.screenshot({ path: 'results/dashboards/Purchase-bill-phone.png', fullPage: false });
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('purchase_view.php?id=' + id);
  await page.screenshot({ path: 'results/dashboards/Purchase-view.png', fullPage: true });
});
