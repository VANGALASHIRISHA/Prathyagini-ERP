const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { login } = require('./helpers');
const sql = (q) => execSync(`"C:/xampp/mysql/bin/mysql.exe" -uroot -N -B pimfi131_pratyangiportal -e "${q}"`, { encoding: 'utf8' }).split(String.fromCharCode(13)).join('').trim();
const row = sql("SELECT sku,purchase_cost FROM products WHERE purchase_cost>0 ORDER BY id DESC LIMIT 1").split('\t');

test('super admin: typing an existing code shows its cost price automatically', async ({ page }) => {
  await login(page);
  await page.goto('barcode_create.php');
  await expect(page.locator('#purchase_cost')).toHaveValue('');
  await page.fill('#sku', row[0]);
  await expect(page.locator('#purchase_cost')).toHaveValue(Number(row[1]).toFixed(2));
  await expect(page.locator('#barcodeMatch')).toContainText('Cost ₹' + Number(row[1]).toFixed(2));
  await page.screenshot({ path: 'results/dashboards/Barcode-cost-auto.png' });
  // an unknown code leaves the cost empty
  await page.reload();
  await page.fill('#sku', 'NO-SUCH-CODE-' + Date.now());
  await page.waitForTimeout(800);
  await expect(page.locator('#purchase_cost')).toHaveValue('');
});

test('manager sees the cost price automatically, read-only', async ({ page }) => {
  await login(page, 'bc_manager', 'Tmp#12345');
  await page.goto('barcode_create.php');
  await expect(page.locator('#purchase_cost')).toHaveCount(1);
  await expect(page.locator('#purchase_cost')).toHaveAttribute('readonly', '');
  await page.fill('#sku', row[0]);
  await expect(page.locator('#purchase_cost')).toHaveValue(Number(row[1]).toFixed(2));
  await expect(page.locator('#barcodeMatch')).toContainText('Cost ₹' + Number(row[1]).toFixed(2));
  await page.screenshot({ path: 'results/dashboards/Barcode-cost-manager.png' });
  // the manager still cannot receive purchased stock
  await expect(page.locator('text=Receive stock')).toHaveCount(0);
});

test('cashier, godown and auditor never receive the cost price', async ({ browser }) => {
  for (const u of ['bc_cashier', 'bc_godown', 'bc_auditor']) {
    const p = await browser.newPage();
    await login(p, u, 'Tmp#12345');
    const r = await p.request.get('api/product_lookup.php?sku=' + encodeURIComponent(row[0]));
    if (r.status() === 200) expect(JSON.stringify(await r.json())).not.toContain('purchase_cost');
    else expect(r.status()).toBe(403);
    await p.close();
  }
});
