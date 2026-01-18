import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for nr_llm TYPO3 backend module E2E tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  testDir: './Tests/E2E/Playwright',
  fullyParallel: false, // Backend tests share session state
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1, // Sequential execution for backend tests
  reporter: [
    ['html', { outputFolder: 'Tests/E2E/Playwright/reports' }],
    ['list'],
  ],
  timeout: 30000,
  expect: {
    timeout: 10000,
  },
  use: {
    // Default to DDEV for local dev; CI sets TYPO3_BASE_URL to localhost
    baseURL: process.env.TYPO3_BASE_URL || 'https://v14.nr-llm.ddev.site',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true, // For DDEV with self-signed certificates
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  outputDir: 'Tests/E2E/Playwright/test-results',
});
