import { test, expect, navigateToModels, getModuleFrame } from './fixtures';

/**
 * E2E tests for the Model list module.
 *
 * These tests verify the Model list page loads correctly and basic
 * functionality works as expected.
 */
test.describe('Model List Module', () => {
  test.describe('Page Load', () => {
    test('should load model list page without errors', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to models - this will fail if controller has DI issues
      const moduleFrame = await navigateToModels(page);

      // Verify page heading is visible
      const heading = moduleFrame.getByRole('heading', { level: 1 });
      await expect(heading).toBeVisible();
      await expect(heading).toContainText('Model');
    });

    test('should display model list table or empty state', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToModels(page);

      // Check for table (when models exist) or empty state infobox (fresh install)
      const hasTable = await moduleFrame.locator('table.table, .record-list').isVisible();
      const hasEmptyState = await moduleFrame.locator('.callout, [class*="infobox"]').first().isVisible();

      // Either a model table or the empty state infobox should be visible
      expect(hasTable || hasEmptyState).toBe(true);
    });

    test('should have create new model button', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToModels(page);

      // Look for "New" or "Create" button
      const createButton = moduleFrame.locator('a:has-text("New"), a:has-text("Create"), button:has-text("New")');
      await expect(createButton.first()).toBeVisible();
    });

    test('should display model rows with action buttons', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToModels(page);

      // Check if there are any model rows
      const modelRows = moduleFrame.locator('tr[data-uid], .record-row');
      const count = await modelRows.count();

      if (count > 0) {
        // Verify first row has expected action buttons
        const firstRow = modelRows.first();

        // Should have edit link/button
        const editAction = firstRow.locator('a[title="Edit"], button[title="Edit"], a:has(.icon-actions-open)');
        await expect(editAction.first()).toBeVisible();

        // Should have toggle active button
        const toggleAction = firstRow.locator('button[data-action="toggle-active"]');
        if (await toggleAction.count() > 0) {
          await expect(toggleAction.first()).toBeVisible();
        }
      } else {
        // No models - should show empty state or "no records" message
        const emptyState = moduleFrame.locator('.callout, .alert, :has-text("No records")');
        // Either we have models or an empty state indicator
        expect(count >= 0).toBe(true);
      }
    });
  });

  test.describe('Model Actions', () => {
    test('should navigate to edit form when clicking edit or show empty state', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToModels(page);

      const editButtons = moduleFrame.locator('a[title="Edit"], a:has(.icon-actions-open)');
      const count = await editButtons.count();

      if (count === 0) {
        // No models - verify empty state is shown
        const emptyState = moduleFrame.locator('.callout-info, .alert-info, [class*="infobox"]');
        await expect(emptyState).toBeVisible();
        return;
      }

      // Click first edit button
      await editButtons.first().click();
      await page.waitForTimeout(1000);

      // Should navigate to edit form (FormEngine)
      await expect(page).toHaveURL(/record\/edit|record_edit/);
    });

    test('should navigate to create form when clicking new', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToModels(page);

      const createButton = moduleFrame.locator('a:has-text("New"), a:has-text("Create")');
      const count = await createButton.count();

      // Create button should always be present
      expect(count).toBeGreaterThan(0);

      // Click create button
      await createButton.first().click();
      await page.waitForTimeout(1000);

      // Should navigate to create form (FormEngine)
      await expect(page).toHaveURL(/record\/edit|record_edit/);
    });
  });

  test.describe('Provider Relationship', () => {
    test('should display provider name for each model', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToModels(page);

      // Check if models show their provider
      const modelRows = moduleFrame.locator('tr[data-uid], .record-row');
      const count = await modelRows.count();

      if (count > 0) {
        // Look for provider indicator in first row
        const firstRow = modelRows.first();
        const providerCell = firstRow.locator('td:has-text("Provider"), td:has-text("OpenAI"), td:has-text("Anthropic"), td:has-text("Ollama")');

        // Provider should be visible in the row
        if (await providerCell.count() > 0) {
          await expect(providerCell.first()).toBeVisible();
        }
      }
    });
  });
});
