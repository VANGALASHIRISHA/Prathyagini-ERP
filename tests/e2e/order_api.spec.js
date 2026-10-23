// Order API (POST /api/order_create.php) and the Online Orders search. Runs on the LOCAL ERP + LOCAL dev database only.
// It starts its own PHP server on :8092 with a throwaway API key passed through the environment, so no config file is touched.
const { test, expect } = require('@playwright/test');
const { spawn, execSync } = require('child_process');
const crypto = require('crypto');
const path = require('path');
const { login } = require('./helpers');

const API = 'http://localhost:8092/api/order_create.php';
const KEY = crypto.randomBytes(24).toString('hex'); // 48 chars, never written anywhere
const T = Date.now().toString(36).toUpperCase();
const sql = (q) => execSync(`"C:/xampp/mysql/bin/mysql.exe" -uroot -N -B pimfi131_pratyangiportal -e "${q}"`, { encoding: 'utf8' }).split(String.fromCharCode(13)).join('').trim();
const auth = { Authorization: 'Bearer ' + KEY, 'Content-Type': 'application/json' };
test.describe.configure({ mode: 'serial' });

let server, sku, made = [];
test.beforeAll(async ({ request }) => {
  sku = sql("select p.sku from inventory_balances ib join products p on p.id=ib.product_id join locations l on l.id=ib.location_id where l.online_fulfillment_enabled=1 and p.active=1 and p.serial_tracking=1 group by p.sku having sum(ib.qty-ib.reserved_qty)>=4 limit 1").split('\n')[0];
  expect(sku, 'a SKU with sellable stock').toBeTruthy();
  server = spawn('C:/xampp/php/php.exe', ['-S', 'localhost:8092'], { cwd: path.join(__dirname, '../../public'), env: { ...process.env, API_ORDER_INGEST_KEY: KEY }, stdio: 'ignore' });
  for (let i = 0; i < 30; i++) { try { const r = await request.get('http://localhost:8092/login.php'); if (r.status() === 200) return; } catch (e) {} await new Promise((r) => setTimeout(r, 300)); }
  throw new Error('test server did not start');
});
test.afterAll(() => { if (server) server.kill(); });

const post = (request, body, headers = auth) => request.post(API, { headers, data: body });
const order = (ext, extra = {}) => ({ source: 'WEBSITE', external_order_id: ext, customer_name: 'API Test ' + T, customer_mobile: '9000000000', shipping_address: 'Test address', order_amount: 100, payment_status: 'PAID', items: [{ sku, quantity: 1 }], ...extra });

test('the order API is closed to wrong methods, missing keys, wrong keys and bad JSON', async ({ request }) => {
  const g = await request.get(API);
  expect([g.status(), (await g.json()).error]).toEqual([405, 'POST only']);
  expect((await request.post(API, { data: order('X') })).status()).toBe(401);
  expect((await post(request, order('X'), { Authorization: 'Bearer ' + 'z'.repeat(48), 'Content-Type': 'application/json' })).status()).toBe(401);
  const bad = await request.post(API, { headers: { Authorization: 'Bearer ' + KEY, 'Content-Type': 'application/json' }, data: '{not json' });
  expect([bad.status(), (await bad.json()).error]).toEqual([400, 'Invalid JSON']);
});

test('business errors stay clear: unknown SKU, no items, too many pieces', async ({ request }) => {
  let r = await post(request, order('E-' + T, { items: [{ sku: 'NO-SUCH-SKU', quantity: 1 }] }));
  expect([r.status(), (await r.json()).error]).toEqual([422, 'Unknown SKU: NO-SUCH-SKU']);
  r = await post(request, order('E2-' + T, { items: [] }));
  expect([r.status(), (await r.json()).error]).toEqual([422, 'At least one item is required.']);
  r = await post(request, order('E3-' + T, { items: [{ sku, quantity: 99999 }] }));
  expect(r.status()).toBe(422);
  expect((await r.json()).error).toContain('Insufficient sellable stock');
  expect(sql(`select count(*) from online_orders where external_order_id in ('E-${T}','E2-${T}','E3-${T}')`)).toBe('0'); // nothing half-created
});

test('a valid order is created once and reserves stock; the same website order id is a duplicate', async ({ request }) => {
  const before = Number(sql(`select coalesce(sum(ib.reserved_qty),0) from inventory_balances ib join products p on p.id=ib.product_id where p.sku='${sku}'`));
  const r = await post(request, order('OK-' + T));
  const j = await r.json();
  expect([r.status(), j.ok, j.status]).toEqual([201, true, 'ALLOCATED']);
  made.push('OK-' + T);
  expect(sql(`select count(*) from online_orders where source='WEBSITE' and external_order_id='OK-${T}'`)).toBe('1');
  expect(Number(sql(`select coalesce(sum(ib.reserved_qty),0) from inventory_balances ib join products p on p.id=ib.product_id where p.sku='${sku}'`)) - before).toBe(1);
  const again = await post(request, order('OK-' + T));
  const a = await again.json();
  expect([again.status(), a.duplicate, a.order.order_no]).toEqual([200, true, j.order_no]);
  expect(Number(sql(`select coalesce(sum(ib.reserved_qty),0) from inventory_balances ib join products p on p.id=ib.product_id where p.sku='${sku}'`)) - before).toBe(1); // no second reservation
});

test('two identical requests at the same moment still create exactly one order', async ({ request }) => {
  const body = order('RACE-' + T);
  const [a, b] = await Promise.all([post(request, body), post(request, body)]);
  made.push('RACE-' + T);
  expect([a.status(), b.status()].sort()).toEqual([200, 201]);
  expect(sql(`select count(*) from online_orders where source='WEBSITE' and external_order_id='RACE-${T}'`)).toBe('1');
});

test('a database fault is logged, never shown to the caller', async ({ request }) => {
  const existing = sql(`select order_no from online_orders where external_order_id='OK-${T}'`);
  const r = await post(request, order('FAULT-' + T, { order_no: existing })); // order_no is unique: forces a database error
  const txt = await r.text();
  expect(r.status()).toBe(500);
  expect(JSON.parse(txt).error).toBe('The order could not be saved. Please retry.');
  for (const leak of ['SQLSTATE', 'Duplicate', 'online_orders', 'order_no', 'uq_', 'PDO']) expect(txt).not.toContain(leak);
  expect(sql(`select count(*) from online_orders where external_order_id='FAULT-${T}'`)).toBe('0');
});

test('Online Orders search finds an order by every identifier, then cleanup', async ({ page }) => {
  await login(page);
  const row = sql(`select order_no, id from online_orders where external_order_id='OK-${T}'`).split('\t');
  const find = async (q, expectHit) => {
    await page.goto('online_orders.php?q=' + encodeURIComponent(q));
    const body = await page.locator('table').first().innerText();
    if (expectHit) expect(body, 'search "' + q + '"').toContain(row[0]); else expect(body, 'search "' + q + '"').not.toContain(row[0]);
  };
  await find(`OK-${T}`, true);        // website order id
  await find(row[0], true);           // ERP order number
  await find('API Test ' + T, true);  // customer name
  await find(sku, true);              // product SKU
  await find('9000000000', true);     // mobile
  await find('NOTHING-MATCHES-' + T, false);
  await find("100%_' OR 1=1 --", false); // wildcard and SQL-looking text is just text
  await noErrorPage(page);
  // narrow with the status filter too
  await page.goto('online_orders.php?q=' + encodeURIComponent('OK-' + T) + '&status=CANCELLED');
  expect(await page.locator('table').first().innerText()).not.toContain(row[0]);
  // cancel everything this test created, which also releases the reserved stock
  for (const ext of made) {
    await page.goto('online_orders.php?q=' + encodeURIComponent(ext));
    page.once('dialog', (d) => d.accept());
    await page.locator('table tr', { hasText: ext }).locator('button:has-text("Cancel")').first().click();
  }
  expect(sql(`select count(*) from online_orders where external_order_id like '%-${T}' and status<>'CANCELLED'`)).toBe('0');
  expect(Number(sql(`select coalesce(sum(a.quantity),0) from online_order_allocations a join online_orders o on o.id=a.online_order_id where o.external_order_id like '%-${T}' and a.status<>'RELEASED'`))).toBe(0);
});

async function noErrorPage(page) { expect(await page.content()).not.toMatch(/Parse error|Fatal error|Warning:|Uncaught|SQLSTATE/); }
