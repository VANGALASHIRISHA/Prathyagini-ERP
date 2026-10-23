// Scanning a product must show the EXACT place: Godown > Floor > Section > Rack > Shelf > Bin. Local dev database only.
// The test sets a shelf on one location, checks every screen that shows a location, and puts the old value back.
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { login } = require('./helpers');
const sql = (q) => execSync(`"C:/xampp/mysql/bin/mysql.exe" -uroot -N -B pimfi131_pratyangiportal -e "${q}"`, { encoding: 'utf8' }).split(String.fromCharCode(13)).join('').trim();
test.describe.configure({ mode: 'serial' });

let loc, old, serial, productBarcode, sku, page;
test.beforeAll(async ({ browser }) => {
  [loc, serial, productBarcode, sku] = sql("select l.code, ps.serial_barcode, p.product_barcode, p.sku from product_serials ps join locations l on l.id=ps.location_id join products p on p.id=ps.product_id where ps.status='AVAILABLE' and l.active=1 and l.rack is not null and l.rack<>'' limit 1").split('\t');
  expect(serial, 'an available piece in a location with a rack').toBeTruthy();
  old = sql(`select coalesce(shelf,'<NULL>') from locations where code='${loc}'`);
  sql(`update locations set shelf='S-03' where code='${loc}'`);
  page = await browser.newPage();
  await login(page);
});
test.afterAll(async () => {
  sql(old === '<NULL>' ? `update locations set shelf=NULL where code='${loc}'` : `update locations set shelf='${old.replace(/'/g, '')}' where code='${loc}'`);
  await page.close();
});

test('scanning one piece returns the exact path with rack, shelf and bin in order', async () => {
  const r = await page.request.get('api/scan_find.php?code=' + encodeURIComponent(serial));
  const j = await r.json();
  const path = JSON.stringify(j);
  const rack = sql(`select rack from locations where code='${loc}'`), bin = sql(`select coalesce(shelf_bin,'') from locations where code='${loc}'`);
  const p = (j.scan && j.scan.location && j.scan.location.path) || '';
  expect(p, path.slice(0, 300)).toContain('Rack ' + rack);
  expect(p).toContain('Shelf S-03');
  if (bin) expect(p).toContain('Bin ' + bin);
  expect(p.indexOf('Rack ' + rack)).toBeLessThan(p.indexOf('Shelf S-03'));
  if (bin) expect(p.indexOf('Shelf S-03')).toBeLessThan(p.indexOf('Bin ' + bin));
  expect(j.scan.location.shelf).toBe('S-03');
});

test('the Scan & Find screen shows the shelf for a piece and for a product barcode', async () => {
  for (const code of [serial, productBarcode]) {
    await page.goto('scan_find.php?code=' + encodeURIComponent(code));
    await expect(page.locator('#scanFind')).toContainText('Shelf S-03', { timeout: 10000 });
    await expect(page.locator('#scanFind')).toContainText(/Rack /);
  }
  await page.screenshot({ path: 'results/dashboards/Scan-exact-location.png' });
});

test('Product Finder data and the Stock list also show the shelf', async () => {
  await page.goto('product_finder.php');
  await page.fill('input[type=search], #pfQuery, input[name=q]', sku).catch(() => {});
  const r = await page.request.get('api/product_stock.php?id=' + sql(`select id from products where sku='${sku}'`));
  expect(JSON.stringify(await r.json())).toContain('"shelf":"S-03"');
  await page.goto('stock.php?q=' + encodeURIComponent(sku));
  await expect(page.locator('table').first()).toContainText('Shelf S-03');
});

test('a location without rack, shelf and bin says so, not just the godown', async () => {
  const code = sql("select code from locations where active=1 and code<>'" + loc + "' limit 1");
  const was = sql(`select concat(coalesce(rack,'<N>'),'|',coalesce(shelf,'<N>'),'|',coalesce(shelf_bin,'<N>')) from locations where code='${code}'`).split('|');
  sql(`update locations set rack=NULL, shelf=NULL, shelf_bin=NULL where code='${code}'`);
  try {
    const r = await page.request.get('api/scan_find.php?code=' + encodeURIComponent(code));
    const j = await r.json();
    expect(JSON.stringify(j)).toContain('not assigned');
  } finally {
    const v = (x) => (x === '<N>' ? 'NULL' : `'${x.replace(/'/g, '')}'`);
    sql(`update locations set rack=${v(was[0])}, shelf=${v(was[1])}, shelf_bin=${v(was[2])} where code='${code}'`);
  }
});
