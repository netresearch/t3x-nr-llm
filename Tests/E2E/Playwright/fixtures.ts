import { test as base, expect, Page, FrameLocator } from '@playwright/test';

/**
 * TYPO3 Backend credentials for E2E testing.
 */
const TYPO3_CREDENTIALS = {
  username: process.env.TYPO3_USERNAME || 'admin',
  password: process.env.TYPO3_PASSWORD || 'Joh316!!',
};

/**
 * Get the module iframe content in TYPO3 v14 backend.
 * TYPO3 v14 loads module content inside an iframe.
 */
function getModuleFrame(page: Page): FrameLocator {
  return page.frameLocator('iframe').first();
}

/**
 * Login to TYPO3 backend.
 */
async function loginToBackend(page: Page): Promise<void> {
  await page.goto('/typo3/login');

  // Wait for login form to be visible
  await page.waitForSelector('input[name="username"]', { state: 'visible' });

  // Fill credentials
  // TYPO3 v14 uses name="username" for username, name="p_field" for password
  await page.fill('input[name="username"]', TYPO3_CREDENTIALS.username);
  await page.fill('input[name="p_field"]', TYPO3_CREDENTIALS.password);

  // Submit login form
  await page.click('button[type="submit"]');

  // Wait for backend to load (module menu should be visible)
  // TYPO3 v14 uses '.modulemenu' class (not '.scaffold-modulemenu' from earlier versions)
  await page.waitForSelector('.modulemenu', { state: 'visible', timeout: 15000 });
}

/**
 * Navigate to the nr_llm backend module.
 * Returns a FrameLocator for the module iframe content.
 */
async function navigateToLlmModule(page: Page): Promise<FrameLocator> {
  // Navigate directly to the module URL (module is under 'admin' parent)
  await page.goto('/typo3/module/nrllm');

  // TYPO3 v14 loads module content in an iframe
  const moduleFrame = getModuleFrame(page);

  // Wait for module content to load inside the iframe (main dashboard shows "LLM Providers")
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });

  return moduleFrame;
}

/**
 * Extended test fixture with authenticated backend session.
 */
export const test = base.extend<{
  authenticatedPage: Page;
}>({
  authenticatedPage: async ({ page }, use) => {
    await loginToBackend(page);
    await use(page);
  },
});

/**
 * Navigate to Providers sub-module.
 */
async function navigateToProviders(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/providers');
  const moduleFrame = getModuleFrame(page);
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

/**
 * Navigate to Models sub-module.
 */
async function navigateToModels(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/models');
  const moduleFrame = getModuleFrame(page);
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

/**
 * Navigate to Configurations sub-module.
 */
async function navigateToConfigurations(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/configurations');
  const moduleFrame = getModuleFrame(page);
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

export { expect, loginToBackend, navigateToLlmModule, getModuleFrame, navigateToProviders, navigateToModels, navigateToConfigurations };
