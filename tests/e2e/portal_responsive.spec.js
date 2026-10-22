// Same layout audit as the ERP, for the License Control Center (local portal on :8081).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { execSync } = require('child_process');
const probe = require('./layout_probe');
const LIC = process.env.LIC_URL || 'http://localhost:8081/';
const WIDTHS = [320, 360, 390, 768, 1024, 1366, 1920];
const psql = (q) => execSync(`"C:/xampp/mysql/bin/mysql.exe" -uroot -N -B pimfi131_cl -e "${q}"`, { encoding: 'utf8' }).split(String.fromCharCode(13)).join('').trim();
test('license portal: every page at every width', async ({ browser }) => {
  test.setTimeout(300000);
  psql("UPDATE admin_users SET active=1 WHERE username='bc_license'; DELETE FROM admin_login_attempts");
  const page = await browser.newPage({ viewport: { width: 1366, height: 800 } });
  const report = {};
  await page.goto(LIC + 'login.php');
  for (const w of WIDTHS) { await page.setViewportSize({ width: w, height: 800 }); const probs = await page.evaluate(`(${probe.toString()})(${w})`); if (probs.length) report['login @' + w] = probs; }
  await page.fill('input[name=username]', 'bc_license');
  await page.fill('input[name=password]', 'Tmp#License12345');
  await Promise.all([page.waitForNavigation(), page.click('button, .btn')]);
  const lid = psql('SELECT id FROM licenses ORDER BY id DESC LIMIT 1');
  const pages = ['dashboard.php', 'customers.php', 'events.php', 'license_new.php', 'change_password.php', ...(lid ? ['license_view.php?id=' + lid] : [])];
  for (const w of WIDTHS) {
    await page.setViewportSize({ width: w, height: 800 });
    for (const p of pages) {
      const r = await page.goto(LIC + p);
      if (r.status() !== 200) { report[`${p} @${w}`] = ['HTTP ' + r.status()]; continue; }
      const probs = await page.evaluate(`(${probe.toString()})(${w})`);
      if (probs.length) report[`${p} @${w}`] = probs;
    }
  }
  psql("UPDATE admin_users SET active=0 WHERE username='bc_license'");
  fs.mkdirSync('results', { recursive: true });
  fs.writeFileSync('results/portal_responsive_audit.json', JSON.stringify(report, null, 1));
  const keys = Object.keys(report);
  console.log('PORTAL PAGES WITH PROBLEMS:', keys.length);
  expect(keys, keys.map((k) => k + ' => ' + report[k].join(' ; ')).join('\n')).toEqual([]);
});
