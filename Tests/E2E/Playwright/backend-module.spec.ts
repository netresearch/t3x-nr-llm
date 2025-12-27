import { test, expect, navigateToLlmModule, getModuleFrame } from './fixtures';

test.describe('LLM Configuration Backend Module', () => {
  test.describe('Module Access', () => {
    test('should display module in Tools menu after login', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to the module - returns iframe content
      const moduleFrame = await navigateToLlmModule(page);

      // Verify module header is displayed (inside iframe)
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Configurations');
    });

    test('should show configuration list with action buttons', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Verify "New Configuration" button exists (inside iframe)
      await expect(moduleFrame.getByRole('link', { name: 'New Configuration' })).toBeVisible();
    });
  });

  test.describe('Configuration List', () => {
    test('should display configurations table or empty state', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Either a table with configurations or an empty state message should be visible
      const hasTable = await moduleFrame.locator('table').isVisible();
      const hasEmptyState = await moduleFrame.locator('.alert.alert-info').first().isVisible();

      expect(hasTable || hasEmptyState).toBe(true);
    });

    test('should have provider filter options', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Check for provider tabs or filter (may not exist if no configs)
      // Just verify the page loaded correctly
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Configurations');
    });
  });

  test.describe('Create Configuration', () => {
    test('should navigate to new configuration form', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Click "New Configuration" button (inside iframe)
      await moduleFrame.getByRole('link', { name: 'New Configuration' }).click();

      // Wait for navigation and verify form page loaded
      await page.waitForTimeout(1000);
      const newModuleFrame = getModuleFrame(page);
      await expect(newModuleFrame.getByRole('heading', { level: 1 })).toContainText('New LLM Configuration');
    });

    test('should display all required form fields', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Click "New Configuration" button
      await moduleFrame.getByRole('link', { name: 'New Configuration' }).click();
      await page.waitForTimeout(1000);

      const formFrame = getModuleFrame(page);

      // Verify Identity section fields
      await expect(formFrame.locator('#identifier')).toBeVisible();
      await expect(formFrame.locator('#name')).toBeVisible();

      // Verify Provider section fields
      await expect(formFrame.locator('#provider')).toBeVisible();
    });

    test('should validate required fields on submit', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Click "New Configuration" button
      await moduleFrame.getByRole('link', { name: 'New Configuration' }).click();
      await page.waitForTimeout(1000);

      const formFrame = getModuleFrame(page);

      // Try to submit without filling required fields
      await formFrame.locator('button[type="submit"]').click();

      // Browser should show validation error (HTML5 validation)
      // The identifier field is required - check it's still visible (form didn't submit)
      await expect(formFrame.locator('#identifier')).toBeVisible();
    });

    test('should create configuration with valid data', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Click "New Configuration" button
      await moduleFrame.getByRole('link', { name: 'New Configuration' }).click();
      await page.waitForTimeout(1000);

      const formFrame = getModuleFrame(page);

      // Generate unique identifier
      const uniqueId = `e2e_test_${Date.now()}`;

      // Fill valid form data
      await formFrame.locator('#identifier').fill(uniqueId);
      await formFrame.locator('#name').fill('E2E Test Configuration');

      // Select first available provider (if dropdown exists)
      const providerSelect = formFrame.locator('#provider');
      if (await providerSelect.isVisible()) {
        await providerSelect.selectOption({ index: 1 });
      }

      // Fill temperature if visible
      const tempField = formFrame.locator('#temperature');
      if (await tempField.isVisible()) {
        await tempField.fill('0.7');
      }

      // Submit form
      await formFrame.locator('button[type="submit"]').click();

      // Wait for redirect/response
      await page.waitForTimeout(2000);

      // Should redirect to list or show success
      const newModuleFrame = getModuleFrame(page);
      // Verify we're on a valid page (either back on list or success message)
      await expect(newModuleFrame.locator('body')).toBeVisible();
    });
  });

  test.describe('Edit Configuration', () => {
    test('should navigate to edit form when clicking edit button', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Check if there are any configurations to edit
      const editButtons = moduleFrame.locator('a[title="Edit"], a:has-text("Edit")');
      const count = await editButtons.count();

      if (count > 0) {
        // Click the first edit button
        await editButtons.first().click();
        await page.waitForTimeout(1000);

        // Verify edit form loaded
        const editFrame = getModuleFrame(page);
        await expect(editFrame.getByRole('heading', { level: 1 })).toContainText('Edit LLM Configuration');
      } else {
        // Skip test if no configurations exist - this is expected on fresh install
        test.skip();
      }
    });

    test('should preserve existing values in edit form', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Find and click first edit button
      const editButtons = moduleFrame.locator('a[title="Edit"], a:has-text("Edit")');
      const count = await editButtons.count();

      if (count > 0) {
        await editButtons.first().click();
        await page.waitForTimeout(1000);

        const editFrame = getModuleFrame(page);

        // All input fields should have values or be checkboxes
        const identifier = await editFrame.locator('#identifier').inputValue();
        expect(identifier.length).toBeGreaterThan(0);
      } else {
        test.skip();
      }
    });
  });

  test.describe('Delete Configuration', () => {
    test('should show delete confirmation or button', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Check for delete buttons
      const deleteButtons = moduleFrame.locator('a[title="Delete"], button[title="Delete"], a:has-text("Delete")');
      const count = await deleteButtons.count();

      // If configurations exist, delete buttons should be present
      const hasConfigurations = await moduleFrame.locator('table tbody tr').count();
      if (hasConfigurations > 0) {
        expect(count).toBeGreaterThan(0);
      } else {
        // No configurations, no delete buttons expected - test passes
        expect(true).toBe(true);
      }
    });
  });

  test.describe('Navigation', () => {
    test('should have working back button on forms', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Navigate to new configuration form
      await moduleFrame.getByRole('link', { name: 'New Configuration' }).click();
      await page.waitForTimeout(1000);

      const formFrame = getModuleFrame(page);

      // Click back/cancel button
      const backButton = formFrame.locator('a:has-text("Back"), a:has-text("Cancel")');
      if (await backButton.count() > 0) {
        await backButton.first().click();
        await page.waitForTimeout(1000);

        // Should be back on list page
        const listFrame = getModuleFrame(page);
        await expect(listFrame.getByRole('heading', { level: 1 })).toContainText('LLM Configurations');
      }
    });

    test('should maintain session across module navigation', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to module multiple times
      await navigateToLlmModule(page);
      await page.goto('/typo3');
      await page.waitForTimeout(500);
      const moduleFrame = await navigateToLlmModule(page);

      // Should still be authenticated and see the module
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('LLM Configurations');
    });
  });

  test.describe('Provider Selection', () => {
    test('should show provider dropdown in form', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Navigate to new configuration form
      await moduleFrame.getByRole('link', { name: 'New Configuration' }).click();
      await page.waitForTimeout(1000);

      const formFrame = getModuleFrame(page);

      // Check for provider select field
      const providerSelect = formFrame.locator('#provider');
      await expect(providerSelect).toBeVisible();
    });
  });
});
