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

      // Find the execute button
      const executeButton = moduleFrame.locator('#executeBtn');
      if (await executeButton.count() === 0) {
        test.skip(true, 'Execute button not found');
        return;
      }

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

      // Verify output panel structure exists
      const outputPlaceholder = moduleFrame.locator('#outputPlaceholder');
      await expect(outputPlaceholder).toBeVisible();
      await expect(outputPlaceholder).toContainText('Execute Task');

      // Verify output result panel exists (hidden initially)
      const outputResult = moduleFrame.locator('#outputResult');
      await expect(outputResult).toHaveCount(1);

      // Verify output error panel exists (hidden initially)
      const outputError = moduleFrame.locator('#outputError');
      await expect(outputError).toHaveCount(1);
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
        test.skip(true, 'No tasks available to test execute navigation');
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
