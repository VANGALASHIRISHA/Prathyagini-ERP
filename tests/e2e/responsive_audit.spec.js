// Crawls every page each role can reach, at phone / tablet / laptop / desktop widths, and reports layout problems.
// Report: results/responsive_audit.json. Fails when any page has a problem (so it can guard future changes).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { execSync } = require('child_process');
const { login } = require('./helpers');

const WIDTHS = [320, 360, 390, 768, 1024, 1366, 1920];
const ROLES = [['bctest', 'super_admin'], ['bc_manager', 'manager'], ['bc_cashier', 'cashier'], ['bc_godown', 'godown'], ['bc_auditor', 'auditor']];
const sql = (q) => execSync(`"C:/xampp/mysql/bin/mysql.exe" -uroot -N -B pimfi131_pratyangiportal -e "${q}"`, { encoding: 'utf8' }).split(String.fromCharCode(13)).join('').trim();
const EXTRA = {
  super_admin: ['vendor_form.php', 'purchase_bill.php', 'purchase_settings.php', 'users.php', 'settings.php', 'locations.php', 'categories.php', 'customers.php', 'online_order_new.php', 'stock_adjust.php', 'purchase_return.php', 'sale_return.php', 'product_form.php'],
  manager: ['customers.php', 'online_order_new.php', 'staff_listing.php', 'product_form.php'],
  cashier: [], godown: [], auditor: [],
};
const DETAIL = () => {
  const out = [];
  const pid = sql("SELECT id FROM purchases WHERE entry_mode='MODULE' ORDER BY id DESC LIMIT 1");
  const vid = sql('SELECT id FROM suppliers ORDER BY id DESC LIMIT 1');
  const sid = sql('SELECT id FROM sales ORDER BY id DESC LIMIT 1');
  if (pid) out.push('purchase_view.php?id=' + pid);
  if (vid) out.push('vendor_view.php?id=' + vid, 'vendor_form.php?id=' + vid);
  if (sid) out.push('invoice.php?id=' + sid);
  return out;
};

const probe = require('./layout_probe');

test.describe.configure({ mode: 'serial' });
const report = {};
test.afterAll(() => { fs.mkdirSync('results', { recursive: true }); fs.writeFileSync('results/responsive_audit.json', JSON.stringify(report, null, 1)); });

for (const [user, role] of ROLES) {
  test(`${role}: every reachable page at every width`, async ({ browser }) => {
    test.setTimeout(900000);
    const page = await browser.newPage({ viewport: { width: 1366, height: 800 } });
    await login(page, user, 'Tmp#12345');
    await page.goto('notifications.php');
    const links = await page.$$eval('#sideNav a', (as) => as.map((a) => a.getAttribute('href')).filter((h) => h && !h.startsWith('http') && !h.includes('logout')));
    const pages = [...new Set([...links, ...(EXTRA[role] || []), ...(role === 'super_admin' ? DETAIL() : [])])];
    const isOk = (r) => r && r.status() === 200;
    for (const w of WIDTHS) {
      await page.setViewportSize({ width: w, height: 800 });
      for (const p of pages) {
        const r = await page.goto(p, { waitUntil: 'load' }).catch(() => null);
        if (!isOk(r)) { report[`${role} ${p} @${w}`] = [`HTTP ${r ? r.status() : 'error'}`]; continue; }
        const probs = await page.evaluate(`(${probe.toString()})(${w})`);
        if (probs.length) report[`${role} ${p} @${w}`] = probs;
      }
    }
    await page.close();
  });
}

test('summary', () => {
  const keys = Object.keys(report);
  console.log('PAGES WITH PROBLEMS:', keys.length);
  expect(keys, keys.slice(0, 20).join('\n')).toEqual([]);
});
