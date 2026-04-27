const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8080';
const isCI = !!process.env.CI;

module.exports = defineConfig({
  testDir: './playwright/tests',
  timeout: 30_000,
  expect: {
    timeout: 10_000,
  },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['list'],
  ],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    ...(
      isCI
        ? [
            {
              name: 'firefox',
              use: { ...devices['Desktop Firefox'] },
            },
          ]
        : [
            {
              name: 'chromium',
              use: { ...devices['Desktop Chrome'] },
            },
            {
              name: 'firefox',
              use: { ...devices['Desktop Firefox'] },
            },
            {
              name: 'webkit',
              use: { ...devices['Desktop Safari'] },
            },
          ]
    ),
  ],
});
