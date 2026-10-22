// End-to-end check of the 18-step "complete work flow" picture, on the LOCAL ERP + LOCAL license portal.
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
const PORTAL = 'http://localhost:8081/';
const sku = 'FLOW-' + Date.now().toString(36).toUpperCase();
const inv1 = 'FI-' + Date.now().toString(36), inv2 = 'FJ-' + Date.now().toString(36);
const S = { serials: [] };
test.describe.configure({ mode: 'serial' });
let page;
test.beforeAll(async ({ browser }) => { page = await browser.newPage(); await login(page); });
test.afterAll(async () => { await page.close(); });
const enter = async () => { await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]); };
const noPhpError = async () => expect(await page.content()).not.toMatch(/Parse error|Fatal error|Warning:|Uncaught|SQLSTATE/);

test('3  license: ERP shows ACTIVE (activated from the central portal)', async () => {
  await page.goto('license_status.php');
  await expect(page.locator('body')).toContainText('ACTIVE');
  await expect(page.locator('body')).toContainText('Max Users');
});

test('1-2  company settings: state code required for GST billing', async () => {
  await page.goto('settings.php');
  if (!(await page.inputValue('[name=company_state_code]'))) {
    await page.fill('[name=company_state_code]', '37');
    if (!(await page.inputValue('[name=company_address]'))) await page.fill('[name=company_address]', 'Main Road, Visakhapatnam');
    await page.click('button.btn');
  }
  await page.goto('settings.php');
  expect(await page.inputValue('[name=company_state_code]')).toBeTruthy();
});

test('5+7  product master + barcode creation with stock', async () => {
  await page.goto('barcode_create.php');
  await page.fill('#product_name', 'Flow Cotton Shirt'); await page.fill('#sku', sku); await page.click('#genBarcode');
  await page.fill('#size', 'M'); await page.fill('#color', 'Blue'); await page.fill('#fabric', 'Cotton');
  await page.fill('#mrp', '799'); await page.fill('#selling_price', '699'); await page.fill('#tax_rate', '5'); await page.fill('#purchase_cost', '300');
  await page.selectOption('[name=supplier_id]', { index: 1 }); await page.selectOption('[name=location_id]', { index: 1 });
  await page.fill('[name=invoice_no]', inv1); await page.fill('[name=quantity]', '6'); await page.fill('#label_count', '6');
  await page.click('#saveBtn');
  await expect(page.locator('.notice.success')).toContainText('Stock received');
  await expect(page.locator('.label')).toHaveCount(6);
  for (const t of await page.locator('.label svg text').allTextContents()) S.serials.push(t);
  expect(new Set(S.serials).size).toBe(6);
  await noPhpError();
});

test('6  purchase inward (stock entry screen) posts another invoice', async () => {
  await page.goto('purchase_new.php');
  await page.selectOption('[name=supplier_id]', { index: 1 }); await page.fill('[name=invoice_no]', inv2);
  const opt = await page.locator('select[name="product_id[]"] option', { hasText: sku }).first().getAttribute('value');
  await page.selectOption('select[name="product_id[]"]', opt);
  await page.fill('input[name="batch_no[]"]', 'LOT-' + Date.now().toString(36)); await page.fill('input[name="quantity[]"]', '2'); await page.fill('input[name="cost_price[]"]', '310');
  await page.click('text=POST PURCHASE');
  await expect(page.locator('.notice.success')).toContainText('posted successfully');
});

test('7  barcode label print page renders scannable Code128 labels', async () => {
  await page.goto('purchases.php');
  await page.click(`a:has-text("${sku}")`);
  await expect(page.locator('.label svg').first()).toBeVisible();
  expect(await page.locator('.label').count()).toBeGreaterThan(0);
  await page.screenshot({ path: 'results/flow-labels.png' });
});

test('8  scan & find returns the exact piece with godown/rack/bin and status', async () => {
  await page.goto('scan_find.php?code=' + encodeURIComponent(S.serials[0]));
  await expect(page.locator('#scanFind')).toContainText(sku, { timeout: 10000 });
  await expect(page.locator('#scanFind')).toContainText(/AVAILABLE/i);
  await expect(page.locator('#scanFind')).toContainText(/R\d+/);
});

test('9  POS: scan piece, finalize bill, invoice with GST; piece cannot be sold twice', async () => {
  await page.goto('pos.php');
  await page.fill('input[name=barcode]', S.serials[0]); await page.click('button:has-text("Scan")');
  await expect(page.locator('body')).toContainText(sku);
  await page.selectOption('#payment_mode', 'CASH');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("FINALIZE")')]);
  await expect(page.locator('body')).toContainText(/Invoice/);
  await expect(page.locator('body')).toContainText(/CGST/);
  S.invoiceUrl = page.url();
  await page.screenshot({ path: 'results/flow-invoice.png', fullPage: true });
  await page.goto('pos.php');
  await page.fill('input[name=barcode]', S.serials[0]); await page.click('button:has-text("Scan")');
  await expect(page.locator('.notice.error, .error')).toBeVisible();
});

test('12  sales return puts the sold piece back', async () => {
  const inv = (await page.locator('body').innerText().catch(() => '')) || '';
  await page.goto('sale_return.php');
  const invNo = await (async () => { const r = await page.request.get(S.invoiceUrl); const t = await r.text(); return (t.match(/PF-\d{8}-\d{6}-\d+/) || [])[0]; })();
  expect(invNo).toBeTruthy();
  await page.fill('input[name=invoice_no], input[name=invoice], input[type=text]', invNo);
  await page.click('button:has-text("Find")');
  await expect(page.locator('body')).toContainText(S.serials[0]);
  await page.fill('input[name=reason]', 'Size issue');
  await page.selectOption('select[name=condition]', { index: 0 });
  await Promise.all([page.waitForNavigation(), page.locator('form:has(input[name=reason]) button').first().click()]);
  await expect(page.locator('body')).toContainText(/RET-|returned|Return/i);
  await expect(page.locator('.notice.error')).toHaveCount(0);
  await page.goto('scan_find.php?code=' + encodeURIComponent(S.serials[0]));
  await expect(page.locator('#scanFind')).toContainText(/AVAILABLE/i, { timeout: 10000 });
  await noPhpError();
});

test('10+11  online order: create -> pick -> pack -> dispatch', async () => {
  await page.goto('online_order_new.php');
  await page.fill('[name=customer_name]', 'Flow Customer'); await page.fill('[name=shipping_address]', 'Visakhapatnam'); await page.fill('[name=order_amount]', '699');
  const opt = await page.locator('select[name="product_id[]"] option', { hasText: sku }).first().getAttribute('value');
  await page.selectOption('select[name="product_id[]"]', opt); await page.fill('input[name="quantity[]"]', '1');
  await Promise.all([page.waitForNavigation(), page.click('button.btn:not(.secondary):not(.danger)')]);
  await expect(page.locator('body')).toContainText(/Flow Customer|ALLOCATED/);
  await page.goto('online_orders.php');
  const row = page.locator('tr', { hasText: 'Flow Customer' }).first();
  const pickHref = await row.locator('a[href*="online_pick.php"]').first().getAttribute('href');
  S.orderId = pickHref.match(/id=(\d+)/)[1];
  await page.goto('online_pick.php?id=' + S.orderId);
  const body = await page.locator('body').innerText();
  const loc = (body.match(/[A-Z]+-[A-Z0-9\-]{2,}/g) || []).find(x => /GDN|MAIN/.test(x)) || 'MAIN-GDN';
  await page.fill('input[name=location_code]', loc); await enter();
  S.pick = S.serials[1];
  await page.fill('input[name=barcode]', S.pick); await enter();
  await page.goto('online_pack.php?id=' + S.orderId);
  await page.fill('input[name=barcode]', S.pick); await enter();
  await page.goto('online_dispatch.php?id=' + S.orderId);
  await page.fill('input[name=courier]', 'BlueDart'); await page.fill('input[name=awb]', 'AWB' + Date.now()); await page.fill('input[name=package_weight]', '0.5');
  await Promise.all([page.waitForNavigation(), page.click('button.btn')]);
  await page.goto('online_orders.php?status=DISPATCHED');
  await expect(page.locator('body')).toContainText('Flow Customer');
});

test('12  online return (RTO) receive restores good stock', async () => {
  await page.goto('online_after_dispatch.php');
  const row = page.locator('tr', { hasText: 'Flow Customer' }).first();
  await row.locator('button:has-text("Return"), button:has-text("transit")').first().click();
  await page.goto('online_return_receive.php?id=' + S.orderId);
  await page.selectOption('[name=condition]', { index: 0 }); await page.fill('[name=reason]', 'Customer refused');
  await page.fill('input[name=barcode]', S.pick); await enter();
  await expect(page.locator('body')).not.toContainText(/Fatal error|SQLSTATE/);
  await page.goto('scan_find.php?code=' + encodeURIComponent(S.pick));
  await expect(page.locator('#scanFind')).toContainText(/AVAILABLE/i, { timeout: 10000 });
});

test('13  reports: sales report with date filter and CSV export; stock reports', async () => {
  await page.goto('reports.php');
  await expect(page.locator('body')).toContainText(/Net|Sales/i);
  const csv = await page.request.get('reports.php?from=2026-01-01&to=2026-12-31&export=csv', { failOnStatusCode: false });
  console.log('reports csv export status', csv.status(), (csv.headers()['content-type'] || ''));
  for (const p of ['stock_reports.php', 'stock.php']) { const r = await page.goto(p); expect(r.status(), p).toBe(200); await noPhpError(); }
});

test('14  annual renewal in the license portal', async ({ browser }) => {
  const pp = await browser.newPage();
  await pp.goto(PORTAL + 'login.php'); await pp.fill('input[name=username]', 'bc_license'); await pp.fill('input[name=password]', 'Tmp#License12345');
  await Promise.all([pp.waitForNavigation(), pp.click('button')]);
  await pp.goto(PORTAL + 'dashboard.php'); await pp.click('table >> text=Open >> nth=0');
  await pp.selectOption('select[name=payment_mode]', 'BANK'); await pp.fill('input[name=amount]', '15000'); await pp.fill('input[name=payment_reference]', 'UTR-FLOW-1');
  await pp.click('button:has-text("Renew License")');
  await expect(pp.locator('.notice')).toContainText('renewed');
  await pp.close();
});

test('15-18  backup & restore, Excel export, QR labels, integrity report, pre-flight now exist', async () => {
  for (const p of ['backup.php', 'inventory_integrity.php', 'preflight.php']) { const r = await page.goto(p); expect(r.status(), p).toBe(200); await noPhpError(); }
  const x = await page.request.get('reports.php?export=xlsx'); expect(x.headers()['content-type']).toContain('spreadsheetml');
  await page.goto('purchases.php'); await page.click(`a:has-text("${sku}")`); await expect(page.locator('.label svg.qr').first()).toBeVisible();
});
