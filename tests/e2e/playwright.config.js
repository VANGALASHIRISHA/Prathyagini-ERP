const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: '*.spec.js',
  timeout: 60000,
  workers: 1,
  reporter: [['list']],
  outputDir: 'results',
  use: { baseURL: process.env.ERP_URL || 'http://localhost:8000/', ignoreHTTPSErrors: true },
});
