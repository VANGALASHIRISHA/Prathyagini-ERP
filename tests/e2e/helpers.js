const USER = process.env.ERP_USER || 'bctest';
const PASS = process.env.ERP_PASS || 'Tmp#12345';
async function login(page, user = USER, pass = PASS) {
  await page.goto('login.php');
  await page.fill('input[name=username]', user);
  await page.fill('input[name=password]', pass);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], .btn')]);
}
module.exports = { login, USER, PASS };
