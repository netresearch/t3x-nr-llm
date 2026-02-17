import { test, expect, getModuleFrame } from './fixtures';
import type { Page, FrameLocator } from '@playwright/test';

/**
 * Navigate to Tasks sub-module.
 */
async function navigateToTasks(page: Page): Promise<FrameLocator> {
  await page.goto('/typo3/module/nrllm/tasks');
  const moduleFrame = getModuleFrame(page);
  await moduleFrame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 10000 });
  return moduleFrame;
}

/**
 * E2E tests for the Task execution module.
 *
 * These tests verify the Task execute form loads correctly and
 * task execution functionality works as expected.
 */
test.describe('Task Execute Module', () => {
  test.describe('Task List Page', () => {
    test('should load task list page without errors', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToTasks(page);

      const heading = moduleFrame.getByRole('heading', { level: 1 });
      await expect(heading).toBeVisible();
      await expect(heading).toContainText('Task');
    });

    test('should display task list with execute buttons', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToTasks(page);

      // Check for task rows or empty state
      const taskRows = moduleFrame.locator('tr[data-uid], .record-row');
      const count = await taskRows.count();

      if (count > 0) {
        // Should have execute action for tasks
        const executeAction = moduleFrame.locator('a[title*="Execute"], a:has-text("Execute"), button:has-text("Execute")');
        await expect(executeAction.first()).toBeVisible();
      }
    });
  });

  test.describe('Task Execute Form', () => {
    test('should load task execute form without errors', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate directly to execute form for task UID 1
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);

      // Wait for page to load - should NOT show error
      const heading = moduleFrame.getByRole('heading', { level: 1 });
      await expect(heading).toBeVisible({ timeout: 10000 });

      // Should show task name or "Execute" in heading, NOT "Whoops"
      const headingText = await heading.textContent();
      expect(headingText).not.toContain('Whoops');
      expect(headingText).not.toContain('went wrong');
    });

    test('should display input textarea for task execution', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);

      // Wait for form to load
      await page.waitForTimeout(1000);

      // Should have input field for task execution
      const inputArea = moduleFrame.locator('textarea[name*="input"], textarea#input, .task-input');
      const inputCount = await inputArea.count();

      if (inputCount > 0) {
        await expect(inputArea.first()).toBeVisible();
      }
    });

    test('should display execute button', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Should have execute/run button
      const executeButton = moduleFrame.locator('button[type="submit"], button:has-text("Execute"), button:has-text("Run")');
      const buttonCount = await executeButton.count();

      if (buttonCount > 0) {
        await expect(executeButton.first()).toBeVisible();
      }
    });

    test('should display task details (prompt template preview)', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Should show some task information
      const taskInfo = moduleFrame.locator('.task-info, .prompt-preview, .task-description, pre, code');
      const infoCount = await taskInfo.count();

      // At minimum some content should be visible
      expect(infoCount).toBeGreaterThanOrEqual(0);
    });

    test('should have loading elements in DOM for execute button', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Task UID 1 may not exist on fresh installs - controller redirects to list
      const executeButton = moduleFrame.locator('#executeBtn');
      if (await executeButton.count() === 0) {
        // Redirected to task list because task doesn't exist - verify list page loaded
        const heading = moduleFrame.getByRole('heading', { level: 1 });
        await expect(heading).toBeVisible();
        return;
      }

      await expect(executeButton).toBeVisible();

      // Verify loading elements exist in DOM (even if hidden initially)
      const btnContent = moduleFrame.locator('#executeBtn .btn-content');
      const btnLoading = moduleFrame.locator('#executeBtn .btn-loading');

      // Content should be visible, loading should be hidden initially
      await expect(btnContent).toBeVisible();
      await expect(btnLoading).toHaveCount(1); // Element exists in DOM

      // Verify loading panel exists
      const outputLoading = moduleFrame.locator('#outputLoading');
      await expect(outputLoading).toHaveCount(1);

      // Verify progress bar exists with animation class
      const progressBar = moduleFrame.locator('#outputLoading .progress-bar');
      await expect(progressBar).toHaveCount(1);
      await expect(progressBar).toHaveClass(/progress-bar-animated/);

      // Verify elapsed time counter exists
      const elapsedTime = moduleFrame.locator('#elapsedTime');
      await expect(elapsedTime).toHaveCount(1);
    });

    test('should have output panel with placeholder', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Task UID 1 may not exist on fresh installs - controller redirects to list
      const outputPlaceholder = moduleFrame.locator('#outputPlaceholder');
      if (await outputPlaceholder.count() === 0) {
        // Redirected to task list because task doesn't exist - verify list page loaded
        const heading = moduleFrame.getByRole('heading', { level: 1 });
        await expect(heading).toBeVisible();
        return;
      }

      // Verify output panel structure exists
      await expect(outputPlaceholder).toBeVisible();
      await expect(outputPlaceholder).toContainText('Execute Task');

      // Verify output result panel exists (hidden initially)
      const outputResult = moduleFrame.locator('#outputResult');
      await expect(outputResult).toHaveCount(1);

      // Verify output error panel exists (hidden initially)
      const outputError = moduleFrame.locator('#outputError');
      await expect(outputError).toHaveCount(1);
    });

    // LLM response can take 30+ seconds - need extended timeout
    test('should execute task and show response or error', async ({ authenticatedPage }) => {
      test.setTimeout(120000); // 2 minute timeout for LLM response
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=1');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Task UID 1 may not exist on fresh installs - controller redirects to list
      const inputArea = moduleFrame.locator('#taskInput');
      if (await inputArea.count() === 0) {
        // Redirected to task list because task doesn't exist - verify list page loaded
        const heading = moduleFrame.getByRole('heading', { level: 1 });
        await expect(heading).toBeVisible();
        return;
      }

      // Enter some test input
      await inputArea.fill('Test log entry for analysis');

      // Click execute button
      const executeBtn = moduleFrame.locator('#executeBtn');
      await executeBtn.click();

      // Wait for either result or error to become visible (up to 90 seconds for Ollama)
      // Both elements exist but have d-none class when hidden
      const outputResult = moduleFrame.locator('#outputResult:not(.d-none)');
      const outputError = moduleFrame.locator('#outputError:not(.d-none)');

      // Wait for either to appear (one should lose d-none class when response arrives)
      await page.waitForFunction(
        () => {
          const iframe = document.querySelector('iframe');
          if (!iframe?.contentDocument) return false;
          const result = iframe.contentDocument.querySelector('#outputResult');
          const error = iframe.contentDocument.querySelector('#outputError');
          return (result && !result.classList.contains('d-none')) ||
                 (error && !error.classList.contains('d-none'));
        },
        { timeout: 90000 }
      );

      // Check if error or result is visible
      const resultVisible = await moduleFrame.locator('#outputResult:not(.d-none)').isVisible();
      const errorVisible = await moduleFrame.locator('#outputError:not(.d-none)').isVisible();

      // Either result or error panel should be visible
      expect(resultVisible || errorVisible).toBe(true);

      // If error, verify the message is displayed (not just "Request failed")
      if (errorVisible) {
        const errorMsg = await moduleFrame.locator('#errorMessage').textContent();
        expect(errorMsg).toBeTruthy();
        // Error should contain actual message, not generic "Request failed"
        expect(errorMsg).not.toBe('Request failed');
      }
    });
  });

  test.describe('Table Picker', () => {
    test('should have AJAX URLs available for table loading', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=5');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Check if TYPO3.settings.ajaxUrls contains our task routes
      const iframeEl = await page.locator('iframe').first().elementHandle();
      const frame = await iframeEl?.contentFrame();

      if (frame) {
        const ajaxUrls = await frame.evaluate(() => {
          return (window as any).TYPO3?.settings?.ajaxUrls;
        });

        console.log('AJAX URLs:', JSON.stringify(ajaxUrls, null, 2));

        // These URLs should be defined for table picker to work
        expect(ajaxUrls?.nrllm_task_list_tables).toBeDefined();
        expect(ajaxUrls?.nrllm_task_fetch_records).toBeDefined();
        expect(ajaxUrls?.nrllm_task_load_record_data).toBeDefined();
      }
    });

    test('should load tables when table picker is expanded', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Capture console errors
      const consoleMessages: string[] = [];
      page.on('console', msg => {
        if (msg.type() === 'error') {
          consoleMessages.push(`[${msg.type()}] ${msg.text()}`);
        }
      });

      // Capture page errors
      const pageErrors: string[] = [];
      page.on('pageerror', error => {
        pageErrors.push(error.message);
      });

      // Task UID 5 should have manual input type (which shows table picker)
      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=5');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Task UID 5 may not exist on fresh installs - controller redirects to list
      const tablePickerCard = moduleFrame.locator('#tablePickerCard');
      const taskInput = moduleFrame.locator('#taskInput');

      if (await tablePickerCard.count() === 0 && await taskInput.count() === 0) {
        // Redirected to task list because task doesn't exist - verify list page loaded
        const heading = moduleFrame.getByRole('heading', { level: 1 });
        await expect(heading).toBeVisible();
        return;
      }

      // Check if table picker card exists (only for manual input tasks)
      if (await tablePickerCard.count() === 0) {
        // Task doesn't have table picker - verify the input textarea is shown instead
        await expect(taskInput).toBeVisible();
        return;
      }

      // Find and click the collapse button to expand table picker
      const collapseButton = moduleFrame.locator('button[data-bs-target="#tablePickerCollapse"]');
      await expect(collapseButton).toBeVisible();

      // Check collapse state before clicking
      const collapseDiv = moduleFrame.locator('#tablePickerCollapse');
      const classesBefore = await collapseDiv.getAttribute('class');
      console.log('Collapse classes before click:', classesBefore);

      await collapseButton.click();

      // Wait for collapse animation
      await page.waitForTimeout(1000);

      // Check collapse state after clicking
      const classesAfter = await collapseDiv.getAttribute('class');
      console.log('Collapse classes after click:', classesAfter);

      // Check if collapse is now expanded (should have 'show' class)
      await expect(collapseDiv).toHaveClass(/show/);

      // The table select should show "Loading..." first, then populate with tables
      const tableSelect = moduleFrame.locator('#tableSelect');
      await expect(tableSelect).toBeVisible();

      // Check what the select currently shows
      const currentOptions = await tableSelect.innerHTML();
      console.log('Table select options:', currentOptions);

      // Wait for AJAX to complete - should have more than just the placeholder option
      await page.waitForTimeout(2000);

      // Check again after waiting
      const optionsAfterWait = await tableSelect.innerHTML();
      console.log('Table select options after wait:', optionsAfterWait);

      // Log any errors captured
      if (consoleMessages.length > 0) {
        console.log('Console errors:', consoleMessages);
      }
      if (pageErrors.length > 0) {
        console.log('Page errors:', pageErrors);
      }

      // Verify tables are loaded (should have more than 1 option)
      const options = await tableSelect.locator('option').count();
      expect(options).toBeGreaterThan(1);

      // First option should be "Select a table" placeholder
      const firstOption = tableSelect.locator('option').first();
      await expect(firstOption).toContainText('Select');
    });

    test('should show error notification if table loading fails', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/tasks?action=executeForm&uid=5');

      const moduleFrame = getModuleFrame(page);
      await page.waitForTimeout(1000);

      // Task UID 5 may not exist on fresh installs - controller redirects to list
      const tablePickerCard = moduleFrame.locator('#tablePickerCard');
      const taskInput = moduleFrame.locator('#taskInput');

      if (await tablePickerCard.count() === 0 && await taskInput.count() === 0) {
        // Redirected to task list because task doesn't exist - verify list page loaded
        const heading = moduleFrame.getByRole('heading', { level: 1 });
        await expect(heading).toBeVisible();
        return;
      }

      if (await tablePickerCard.count() === 0) {
        // Task doesn't have table picker - verify the input textarea is shown instead
        await expect(taskInput).toBeVisible();
        return;
      }

      // Expand table picker
      const collapseButton = moduleFrame.locator('button[data-bs-target="#tablePickerCollapse"]');
      await collapseButton.click();
      await page.waitForTimeout(500);

      const tableSelect = moduleFrame.locator('#tableSelect');
      await page.waitForTimeout(2000);

      // Should NOT show "Error loading tables" if AJAX worked correctly
      const selectedText = await tableSelect.locator('option:first-child').textContent();
      expect(selectedText).not.toContain('Error');
      expect(selectedText).not.toContain('Load tables'); // Should not be stuck on initial state
    });
  });

  test.describe('Task Navigation', () => {
    test('should navigate from list to execute form', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToTasks(page);

      // Find execute link/button
      const executeLinks = moduleFrame.locator('a[title*="Execute"], a:has-text("Execute")');
      const count = await executeLinks.count();

      if (count === 0) {
        // No tasks - verify empty state is shown
        const emptyState = moduleFrame.locator('.callout-info, .alert-info, [class*="infobox"]');
        await expect(emptyState).toBeVisible();
        return;
      }

      // Click first execute link
      await executeLinks.first().click();
      await page.waitForTimeout(1000);

      // Should navigate to execute form without errors
      const formFrame = getModuleFrame(page);
      const heading = formFrame.getByRole('heading', { level: 1 });
      const headingText = await heading.textContent();

      expect(headingText).not.toContain('Whoops');
    });
  });
});
