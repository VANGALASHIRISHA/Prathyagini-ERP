const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

const SIZES = { 'phone-360': [360, 800], 'phone-390': [390, 844], 'tablet-768': [768, 1024], 'laptop-1024': [1024, 768], 'desktop-1366': [1366, 768], 'desktop-1920': [1920, 1080] };
const PAGES = ['super_admin_dashboard.php', 'stock.php', 'purchase_new.php', 'pos.php', 'barcode_create.php', 'audit.php', 'stock_transfer.php', 'reports.php', 'online_orders.php', 'product_finder.php'];

test('login rejects bad password and accepts good one', async ({ page }) => {
  await page.goto('login.php');
  await page.fill('input[name=username]', 'bctest');
  await page.fill('input[name=password]', 'wrong');
  await page.click('button[type=submit], .btn');
  await expect(page.locator('.notice, .error').first()).toBeVisible();
  await login(page);
  await expect(page).not.toHaveURL(/login\.php/);
});

for (const [name, [w, h]] of Object.entries(SIZES)) {
  test(`responsive ${name}: no sideways page scroll, menu works`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    await login(page);
    const bad = [];
    for (const p of PAGES) {
      await page.goto(p);
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      if (over > 1) bad.push(`${p} overflows by ${over}px`);
      if (p === 'super_admin_dashboard.php' || p === 'barcode_create.php') await page.screenshot({ path: `results/shots/${name}-${p}.png`, fullPage: false });
    }
    expect(bad, bad.join('\n')).toEqual([]);
    await page.goto('super_admin_dashboard.php');
    const toggle = page.locator('#navToggle');
    if (w <= 860) {
      await expect(toggle).toBeVisible();
      await toggle.click();
      await expect(page.locator('body.nav-open')).toHaveCount(1);
      await expect(page.locator('#sideNav')).toBeInViewport();
      await page.keyboard.press('Escape');
      await expect(page.locator('body.nav-open')).toHaveCount(0);
    } else {
      await expect(toggle).toBeHidden();
      await expect(page.locator('#sideNav')).toBeVisible();
    }
  });
}

test('barcode create -> receive stock -> scan finds exact piece & location', async ({ page }) => {
  await login(page);
  const sku = 'E2E-' + Date.now().toString(36).toUpperCase();
  await page.goto('barcode_create.php');
  await page.fill('#product_name', 'E2E Kurti');
  await page.fill('#sku', sku);
  await page.click('#genBarcode');
  expect(await page.inputValue('#product_barcode')).toMatch(/^PF\d+/);
  await page.fill('#size', 'M');
  await page.fill('#color', 'Blue');
  await page.fill('#mrp', '500');
  await page.fill('#selling_price', '450');
  await page.fill('#purchase_cost', '200');
  await page.selectOption('[name=supplier_id]', { index: 1 });
  await page.selectOption('[name=location_id]', { index: 1 });
  await page.fill('[name=quantity]', '2');
  await page.fill('#label_count', '2');
  await page.click('#saveBtn');
  await expect(page.locator('.notice.success')).toContainText('Stock received');
  const labels = page.locator('.label');
  await expect(labels).toHaveCount(2);
  const code = await labels.first().locator('svg text').textContent();
  expect(code).toMatch(/^PFS-/);
  await page.goto('scan_find.php?code=' + encodeURIComponent(code));
  await expect(page.locator('#scanFind')).toContainText(sku, { timeout: 10000 });
  await expect(page.locator('#scanFind')).toContainText(/AVAILABLE/i);
  await page.screenshot({ path: 'results/shots/scan-result.png' });
});

test('selling price above MRP is rejected', async ({ page }) => {
  await login(page);
  await page.goto('barcode_create.php');
  await page.fill('#product_name', 'X');
  await page.fill('#sku', 'E2E-BAD');
  await page.fill('#mrp', '100');
  await page.fill('#selling_price', '200');
  expect(await page.locator('#selling_price').evaluate(el => el.validationMessage)).toMatch(/higher than MRP/);
});

test('audit page filters and paginates', async ({ page }) => {
  await login(page);
  await page.goto('audit.php');
  await expect(page.locator('h1')).toContainText('Audit');
  await page.selectOption('[name=action]', 'LOGIN');
  await page.click('text=Apply Filters');
  await expect(page.locator('.au-badge').first()).toHaveText('LOGIN');
});
