const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.', testMatch: 'live.check.js', timeout: 60000, workers: 1, outputDir: 'live-out/raw',
  reporter: [['list']],
  use: { launchOptions: { slowMo: Number(process.env.SLOW || 0), args: ['--host-resolver-rules=MAP *.pratyangi.com 216.10.252.31'] }, ignoreHTTPSErrors: false },
});
