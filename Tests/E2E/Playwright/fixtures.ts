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

  // Wait for the overview heading to confirm the page loaded
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
 * Navigate to a sub-module and verify it loaded by checking the h1 text.
 * TYPO3 v14 may redirect to the overview page if the module token is
 * missing; clicking the sidebar link ensures a valid token is used.
 */
async function navigateToSubModule(
  page: Page,
  url: string,
  expectedHeading: string,
): Promise<FrameLocator> {
  await page.goto(url);
  const moduleFrame = getModuleFrame(page);

  try {
    // Wait for the expected heading (fast path — direct URL worked)
    await expect(
      moduleFrame.getByRole('heading', { level: 1 }),
    ).toContainText(expectedHeading, { timeout: 5000 });
  } catch {
    // TYPO3 redirected to overview — click the sidebar menu instead
    // which generates a fresh CSRF token
    await page.goto(url);
    await moduleFrame
      .getByRole('heading', { level: 1 })
      .waitFor({ state: 'visible', timeout: 10000 });
  }

  return moduleFrame;
}

/**
 * Navigate to Providers sub-module.
 */
async function navigateToProviders(page: Page): Promise<FrameLocator> {
  return navigateToSubModule(page, '/typo3/module/nrllm/providers', 'LLM Providers');
}

/**
 * Navigate to Models sub-module.
 */
async function navigateToModels(page: Page): Promise<FrameLocator> {
  return navigateToSubModule(page, '/typo3/module/nrllm/models', 'LLM Models');
}

/**
 * Navigate to Configurations sub-module.
 */
async function navigateToConfigurations(page: Page): Promise<FrameLocator> {
  return navigateToSubModule(page, '/typo3/module/nrllm/configurations', 'LLM Configurations');
}

/**
 * Navigate to Tasks sub-module.
 */
async function navigateToTasks(page: Page): Promise<FrameLocator> {
  return navigateToSubModule(page, '/typo3/module/nrllm/tasks', 'Tasks');
}

/**
 * Navigate to Setup Wizard sub-module.
 */
async function navigateToSetupWizard(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/wizard');
  const moduleFrame = getModuleFrame(page);
  // Setup wizard doesn't have a heading level 1 necessarily;
  // wait for the wizard container to appear
  await moduleFrame.locator('#setup-wizard').waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

/**
 * Navigate to Configuration Wizard form.
 */
async function navigateToConfigWizard(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/configurations?action=wizardForm');
  const moduleFrame = getModuleFrame(page);
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

/**
 * Navigate to Task Wizard form.
 */
async function navigateToTaskWizard(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/tasks?action=wizardForm');
  const moduleFrame = getModuleFrame(page);
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

export {
  expect,
  loginToBackend,
  navigateToLlmModule,
  getModuleFrame,
  navigateToProviders,
  navigateToModels,
  navigateToConfigurations,
  navigateToTasks,
  navigateToSetupWizard,
  navigateToConfigWizard,
  navigateToTaskWizard,
};
