const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { login } = require('./helpers');

const tmp = (name, text) => { const p = path.join(require('os').tmpdir(), name); fs.writeFileSync(p, text); return p; };

test('bulk import: bad file blocks everything, good file posts once with unique barcodes', async ({ page }) => {
  await login(page);
  const inv = 'IMP-' + Date.now().toString(36);
  await page.goto('purchase_import.php');
  const bad = tmp('bad.csv', 'sku,location_code,quantity,purchase_rate,batch_no\r\nNO-SUCH-SKU,MAIN-GDN,2,100,B1\r\nBCT-001,NOWHERE,0,-5,B2\r\n');
  await page.selectOption('[name=supplier_id]', { index: 1 });
  await page.fill('[name=invoice_no]', inv);
  await page.setInputFiles('[name=csv]', bad);
  await page.click('text=Check File');
  await expect(page.locator('body')).toContainText('SKU not found');
  await expect(page.locator('body')).toContainText('Location code not found');
  await expect(page.locator('#postBtn')).toHaveCount(0);

  const good = tmp('good.csv', 'sku,location_code,quantity,purchase_rate,batch_no\r\nBCT-001,MAIN-GDN,3,410,\r\n12345,SHOWROOM,2,99,LOTX-' + inv + '\r\n');
  await page.goto('purchase_import.php');
  await page.selectOption('[name=supplier_id]', { index: 1 });
  await page.fill('[name=invoice_no]', inv);
  await page.setInputFiles('[name=csv]', good);
  await page.click('text=Check File');
  await expect(page.locator('body')).toContainText('0 problem(s)');
  await page.click('#postBtn');
  await expect(page.locator('.notice.success')).toContainText('5 unique piece barcode');
  // same invoice cannot be posted twice
  await page.goto('purchase_import.php');
  await page.selectOption('[name=supplier_id]', { index: 1 });
  await page.fill('[name=invoice_no]', inv);
  await page.setInputFiles('[name=csv]', good);
  await page.click('text=Check File');
  await expect(page.locator('.notice.error')).toContainText('already posted');
});

test('physical count records rejected scans for review', async ({ page }) => {
  await login(page);
  await page.goto('stock_count.php');
  await page.selectOption('[name=location_id]', { index: 0 });
  await Promise.all([page.waitForNavigation(), page.click('button.btn')]);
  await page.fill('[name=barcode]', 'NOT-A-REAL-BARCODE');
  await page.click('text=COUNT +1');
  await expect(page.locator('.notice.error')).toBeVisible();
  await expect(page.locator('body')).toContainText('Count review');
  await expect(page.locator('body')).toContainText('NOT-A-REAL-BARCODE');
});
