const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
test.use({ deviceScaleFactor: 4 });
const jsQR = require('jsqr'); const { PNG } = require('pngjs');
async function decode(locator) {
  const buf = await locator.screenshot({ scale: 'device' });
  const png = PNG.sync.read(buf);
  const r = jsQR(new Uint8ClampedArray(png.data), png.width, png.height);
  return r && r.data;
}
test('QR code beside the barcode decodes to the same code (create page, purchase labels, product label print)', async ({ page }) => {
  await login(page);
  const sku = 'QR-' + Date.now().toString(36).toUpperCase();
  await page.goto('barcode_create.php');
  await page.fill('#product_name', 'QR Test Kurti'); await page.fill('#sku', sku); await page.click('#genBarcode');
  await page.fill('#mrp', '999'); await page.fill('#selling_price', '899'); await page.fill('#purchase_cost', '400');
  await page.selectOption('[name=supplier_id]', { index: 1 }); await page.selectOption('[name=location_id]', { index: 1 });
  await page.fill('[name=invoice_no]', 'QI-' + Date.now().toString(36)); await page.fill('[name=quantity]', '2'); await page.fill('#label_count', '2');
  await page.click('#saveBtn');
  await expect(page.locator('.label')).toHaveCount(2);
  await expect(page.locator('.label svg.qr')).toHaveCount(2);
  const code1 = await page.locator('.label').first().getAttribute('data-code');
  expect(code1).toMatch(/^PFS-/);
  expect(await decode(page.locator('.label svg.qr').first())).toBe(code1);
  await page.locator('.label').first().screenshot({ path: 'results/qr-label-create.png' });
  // purchase-history label page
  const href = await page.locator('a:has-text("Open piece-label page")').getAttribute('href');
  await page.goto(href);
  await expect(page.locator('.label svg.qr').first()).toBeVisible();
  const code2 = await page.locator('.label').first().getAttribute('data-code');
  expect(await decode(page.locator('.label svg.qr').first())).toBe(code2);
  await page.locator('.label').first().screenshot({ path: 'results/qr-label-purchase.png' });
  // product-level label print page
  const pid = await page.evaluate(async (s) => { const r = await fetch('api/product_lookup.php?sku=' + s); return (await r.json()).product.id; }, sku);
  await page.goto('barcode_print.php?id=' + pid + '&count=1');
  const code3 = await page.locator('.label').first().getAttribute('data-code');
  await expect(page.locator('.label svg.qr')).toHaveCount(1);
  expect(await decode(page.locator('.label svg.qr'))).toBe(code3);
  await page.locator('.label').first().screenshot({ path: 'results/qr-label-product.png' });
  // 1D barcode is still there too
  expect(await page.locator('.label svg:not(.qr)').count()).toBeGreaterThan(0);
  const bw = await page.locator('.label svg:not(.qr)').first().evaluate(e => e.getBoundingClientRect().width); const lw = await page.locator('.label').first().evaluate(e => e.getBoundingClientRect().width); console.log('barcode width / label width =', (bw / lw).toFixed(2)); expect(bw / lw).toBeGreaterThan(0.85);
});
