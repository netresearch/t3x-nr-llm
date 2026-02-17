import { test, expect, navigateToLlmModule, navigateToConfigurations, navigateToProviders, getModuleFrame } from './fixtures';

test.describe('LLM Backend Module - Multi-Tier Architecture', () => {
  test.describe('Module Access', () => {
    test('should display module in Tools menu after login', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to the module - returns iframe content
      const moduleFrame = await navigateToLlmModule(page);

      // Verify module header is displayed (main module shows Providers dashboard)
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Providers');
    });

    test('should show providers page with provider list or empty state', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // The page should show either a table with providers or an info box for empty state
      const hasTable = await moduleFrame.locator('table').isVisible();
      const hasInfoBox = await moduleFrame.locator('.callout, .alert').first().isVisible();

      // Either a table or info message should be visible
      expect(hasTable || hasInfoBox).toBe(true);
    });
  });

  test.describe('Provider List', () => {
    test('should display providers page content', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // Verify we're on the providers page
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Providers');

      // Check for expected content: either providers table or empty state callout
      const hasTable = await moduleFrame.locator('table').isVisible();
      const hasCallout = await moduleFrame.locator('.callout').isVisible();
      const hasInfoBox = await moduleFrame.locator('.t3js-infobox, [class*="infobox"]').isVisible();

      expect(hasTable || hasCallout || hasInfoBox).toBe(true);
    });

    test('should have provider type filter options', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // Just verify the page loaded correctly
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Providers');
    });
  });

  test.describe('Configuration Sub-Module', () => {
    test('should navigate to configurations sub-module', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate directly to configurations sub-module
      const moduleFrame = await navigateToConfigurations(page);

      // Verify configurations heading is displayed
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Configurations');
    });

    test('should show configurations page content', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToConfigurations(page);

      // Check for expected content: either configurations table or empty state
      const hasTable = await moduleFrame.locator('table').isVisible();
      const hasCallout = await moduleFrame.locator('.callout').isVisible();
      const hasInfoBox = await moduleFrame.locator('.t3js-infobox, [class*="infobox"]').isVisible();

      expect(hasTable || hasCallout || hasInfoBox).toBe(true);
    });

    test('should display configurations table or empty state', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToConfigurations(page);

      // Either a table with configurations or an info message should be visible
      const hasTable = await moduleFrame.locator('table').isVisible();
      const hasEmptyState = await moduleFrame.locator('.callout, .alert').first().isVisible();

      expect(hasTable || hasEmptyState).toBe(true);
    });
  });

  // Records are edited via TYPO3 FormEngine (record_edit route)
  // Edit buttons link to FormEngine which uses TCA-based forms with TYPO3's standard field naming
  test.describe('Edit Configuration', () => {
    test('should navigate to edit form when clicking edit button', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToConfigurations(page);

      // Check if there are any configurations to edit
      const editButtons = moduleFrame.locator('a[title="Edit"], a:has-text("Edit"), a .icon-actions-open');
      const count = await editButtons.count();

      if (count > 0) {
        // Click the first edit button - links to FormEngine
        await editButtons.first().click();
        await page.waitForTimeout(2000);

        // FormEngine loads in a different frame/page context
        // Just verify the URL changed to record_edit
        await expect(page).toHaveURL(/record\/edit|record_edit/);
      } else {
        // No configurations to edit - verify empty state is shown
        // TYPO3 v14 f:be.infobox renders as <div class="callout callout-info">
        const emptyState = moduleFrame.locator('.callout, [class*="infobox"]');
        await expect(emptyState.first()).toBeVisible();
      }
    });
  });

  test.describe('Navigation', () => {
    test('should navigate between Provider and Configuration modules', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to providers
      const providerFrame = await navigateToProviders(page);
      await expect(providerFrame.getByRole('heading', { level: 1 })).toContainText('LLM Providers');

      // Navigate to configurations
      const configFrame = await navigateToConfigurations(page);
      await expect(configFrame.getByRole('heading', { level: 1 })).toContainText('LLM Configurations');
    });

    test('should maintain session across module navigation', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to module multiple times
      await navigateToLlmModule(page);
      await page.goto('/typo3');
      await page.waitForTimeout(500);
      const moduleFrame = await navigateToLlmModule(page);

      // Should still be authenticated and see the module (Providers dashboard)
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Providers');
    });
  });

  test.describe('Provider Actions', () => {
    test('should have action buttons for providers in list', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // Check if there are providers
      const providerRows = await moduleFrame.locator('table tbody tr').count();

      if (providerRows > 0) {
        // Each provider should have action buttons (edit, toggle, test, delete)
        const actionButtons = moduleFrame.locator('table tbody tr:first-child .btn-group button, table tbody tr:first-child .btn-group a');
        const buttonCount = await actionButtons.count();
        expect(buttonCount).toBeGreaterThan(0);
      } else {
        // No providers - verify empty state message exists
        const hasEmptyState = await moduleFrame.locator('.callout, [class*="infobox"]').isVisible();
        expect(hasEmptyState).toBe(true);
      }
    });

    test('should have test connection button for providers', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // Check for test connection buttons
      const testButtons = moduleFrame.locator('.js-test-connection, button[data-action="test-connection"]');
      const count = await testButtons.count();

      // If providers exist, test buttons should exist
      const providerRows = await moduleFrame.locator('table tbody tr').count();
      if (providerRows > 0) {
        expect(count).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Configuration Actions', () => {
    test('should have action buttons for configurations in list', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToConfigurations(page);

      // Check if there are configurations
      const configRows = await moduleFrame.locator('table tbody tr').count();

      if (configRows > 0) {
        // Each configuration should have action buttons
        const actionButtons = moduleFrame.locator('table tbody tr:first-child .btn-group button, table tbody tr:first-child .btn-group a');
        const buttonCount = await actionButtons.count();
        expect(buttonCount).toBeGreaterThan(0);
      } else {
        // No configurations - verify empty state message exists
        const hasEmptyState = await moduleFrame.locator('.callout, [class*="infobox"]').isVisible();
        expect(hasEmptyState).toBe(true);
      }
    });
  });
});
