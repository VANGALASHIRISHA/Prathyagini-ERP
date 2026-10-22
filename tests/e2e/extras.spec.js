const { test, expect } = require('@playwright/test');
const fs = require('fs'); const path = require('path'); const os = require('os');
const { login } = require('./helpers');
test.describe.configure({ mode: 'serial' });
let page;
test.beforeAll(async ({ browser }) => { page = await browser.newPage(); await login(page); });
test.afterAll(async () => { await page.close(); });

test('Excel export: real .xlsx, opens as a zip with valid sheet', async () => {
  const r = await page.request.get('reports.php?from=2026-01-01&to=2026-12-31&export=xlsx');
  expect(r.status()).toBe(200);
  expect(r.headers()['content-type']).toContain('spreadsheetml');
  const buf = await r.body(); fs.writeFileSync(path.join(os.tmpdir(), 'sales_test.xlsx'), buf);
  expect(buf.slice(0, 2).toString()).toBe('PK');
  console.log('xlsx bytes', buf.length);
});
test('reports page: location filter, Excel/CSV/Print buttons', async () => {
  await page.goto('reports.php');
  await expect(page.locator('select[name=location_id]')).toBeVisible();
  await expect(page.locator('a:has-text("Export Excel")')).toBeVisible();
  await expect(page.locator('button:has-text("Print / Save PDF")')).toBeVisible();
  const opts = await page.locator('select[name=location_id] option').count();
  await page.selectOption('select[name=location_id]', { index: 1 });
  await page.click('button:has-text("Apply")');
  await expect(page.locator('.notice.error')).toHaveCount(0);
  expect(await page.content()).not.toMatch(/Fatal error|SQLSTATE/);
  console.log('location options', opts, '| url', page.url());
});
test('integrity report page runs and lists checks', async () => {
  await page.goto('inventory_integrity.php');
  await expect(page.locator('h3')).toHaveCount(10);
  await expect(page.locator('body')).toContainText('Stock balance equals stock ledger');
  console.log((await page.locator('.notice').first().innerText()).replace(/\s+/g, ' ').slice(0, 140));
  const x = await page.request.get('inventory_integrity.php?export=xlsx'); expect(x.status()).toBe(200);
});
test('pre-flight page runs every group', async () => {
  await page.goto('preflight.php');
  for (const g of ['Server', 'Folders', 'Database', 'Company settings', 'Security', 'Licence', 'Data health']) await expect(page.locator('h3', { hasText: g }).first()).toBeVisible();
  console.log((await page.locator('.notice').first().innerText()).replace(/\s+/g, ' ').slice(0, 150));
  const fails = await page.locator('tr:has(td:has-text("❌"))').allInnerTexts(); console.log('FAIL rows:', JSON.stringify(fails));
  await page.screenshot({ path: 'results/preflight.png', fullPage: true });
});
test('backup -> change data -> restore brings the data back; wrong confirm blocked', async () => {
  const count = async () => (await (await page.request.get('api/product_lookup.php?sku=BKP-MARK')).json()).found;
  // 1) make a backup
  await page.goto('backup.php');
  await page.click('#mkBtn');
  await expect(page.locator('.notice.success')).toContainText('Backup created');
  const name = (await page.locator('td', { hasText: /^db_\d{8}_\d{6}\.sql$/ }).first().innerText()).trim();
  // 2) download it & check it is a real dump
  const dl = await page.request.get('backup.php?download=' + name); expect(dl.status()).toBe(200);
  const txt = (await dl.body()).toString(); expect(txt.startsWith('-- PRATYANGI BACKUP v1')).toBeTruthy(); expect(txt).toContain('CREATE TABLE `products`');
  // 3) change data AFTER the backup (add a marker product)
  await page.goto('barcode_create.php'); await page.fill('#product_name', 'Backup Marker'); await page.fill('#sku', 'BKP-MARK'); await page.click('#genBarcode'); await page.click('#saveBtn');
  await expect(page.locator('.notice.success')).toBeVisible();
  expect(await count()).toBe(true);
  // 4) wrong confirmation does nothing
  await page.goto('backup.php');
  await page.selectOption('select[name=name]', name); await page.fill('input[name=confirm]', 'restore');
  page.once('dialog', d => d.accept());
  await page.click('button:has-text("Restore Backup")');
  await expect(page.locator('.notice.error')).toContainText('Type RESTORE');
  expect(await count()).toBe(true);
  // 5) correct restore
  await page.selectOption('select[name=name]', name); await page.fill('input[name=confirm]', 'RESTORE');
  page.once('dialog', d => d.accept());
  await page.click('button:has-text("Restore Backup")');
  await expect(page.locator('.notice.success')).toContainText('Restore complete');
  // session may survive; re-login to be safe
  await page.goto('dashboard.php'); if (/login\.php/.test(page.url())) await login(page);
  expect(await count()).toBe(false);   // marker product is gone: data rolled back to the backup
  await page.goto('backup.php');
  await expect(page.locator('td', { hasText: /^db_prerestore_/ }).first()).toBeVisible();   // safety copy exists
});
test('non-admin cannot open backup / preflight; auditor can open integrity only', async ({ browser }) => {
  const a = await browser.newPage(); await login(a, 'bc_auditor', 'Tmp#12345');
  for (const [p, code] of [['backup.php', 403], ['preflight.php', 403], ['inventory_integrity.php', 200]]) { const r = await a.goto(p); expect(r.status(), p).toBe(code); }
  const d = await a.request.get('backup.php?download=db_x.sql', { failOnStatusCode: false }); expect([403, 404]).toContain(d.status());
  await a.close();
});
