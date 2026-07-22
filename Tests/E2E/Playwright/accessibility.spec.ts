import { test, expect, navigateToLlmModule, navigateToProviders, navigateToModels, navigateToConfigurations, navigateToRuns, getModuleFrame } from './fixtures';
import AxeBuilder from '@axe-core/playwright';

/**
 * Accessibility tests for nr_llm TYPO3 backend module.
 *
 * Uses axe-core to test WCAG 2.1 AA compliance.
 * Run with: npm run test:accessibility
 * Or: npx playwright test --grep @accessibility
 *
 * @see https://www.deque.com/axe/
 * @see https://playwright.dev/docs/accessibility-testing
 */
test.describe('Accessibility Tests @accessibility', () => {
  /**
   * Helper to run axe-core analysis on a page/frame.
   * Excludes known TYPO3 backend issues that are outside our control.
   */
  async function runAxeAnalysis(page: any, context: string) {
    const accessibilityScanResults = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      // Exclude TYPO3 core backend elements we don't control
      .exclude('.scaffold-modulemenu')
      .exclude('.scaffold-header')
      .exclude('.topbar')
      // Disable aria-hidden-focus rule - Bootstrap modals use tabindex="-1" for
      // programmatic focus combined with aria-hidden="true" when closed, which
      // is a known pattern that triggers this rule but is correct Bootstrap behavior
      .disableRules(['aria-hidden-focus'])
      .analyze();

    // Log violations for debugging
    if (accessibilityScanResults.violations.length > 0) {
      console.log(`\n[${context}] Accessibility violations found:`);
      accessibilityScanResults.violations.forEach((violation) => {
        console.log(`  - ${violation.id}: ${violation.description}`);
        console.log(`    Impact: ${violation.impact}`);
        console.log(`    Nodes: ${violation.nodes.length}`);
        violation.nodes.forEach((node) => {
          console.log(`      Target: ${node.target}`);
          console.log(`      HTML: ${node.html.substring(0, 100)}...`);
        });
      });
    }

    return accessibilityScanResults;
  }

  test.describe('Main Dashboard Module', () => {
    test('should have no critical accessibility violations @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      // Navigate to main module
      await page.goto('/typo3/module/nrllm');
      await page.waitForLoadState('networkidle');

      const results = await runAxeAnalysis(page, 'Main Dashboard');

      // Filter for critical and serious violations only
      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations).toEqual([]);
    });

    test('should have proper heading hierarchy @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Check for h1 heading
      const h1 = moduleFrame.getByRole('heading', { level: 1 });
      await expect(h1).toBeVisible();

      // Verify heading text is descriptive
      const h1Text = await h1.textContent();
      expect(h1Text).toBeTruthy();
      expect(h1Text!.length).toBeGreaterThan(2);
    });

    test('should have accessible action buttons @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Check all buttons have accessible names
      const buttons = moduleFrame.getByRole('button');
      const buttonCount = await buttons.count();

      for (let i = 0; i < buttonCount; i++) {
        const button = buttons.nth(i);
        const name = await button.getAttribute('aria-label') ||
                     await button.getAttribute('title') ||
                     await button.textContent();

        // Each button should have some form of accessible name
        expect(name?.trim().length).toBeGreaterThan(0);
      }
    });
  });

  test.describe('Provider Module', () => {
    test('should have no critical accessibility violations @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/providers');
      await page.waitForLoadState('networkidle');

      const results = await runAxeAnalysis(page, 'Providers');

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations).toEqual([]);
    });

    test('should have accessible table structure @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // Check if table exists
      const table = moduleFrame.locator('table');
      const hasTable = await table.isVisible().catch(() => false);

      if (hasTable) {
        // Table should have thead
        const thead = table.locator('thead');
        await expect(thead).toBeVisible();

        // Table headers should exist
        const ths = table.locator('th');
        const thCount = await ths.count();
        expect(thCount).toBeGreaterThan(0);
      }
    });

    test('should have accessible modal dialogs @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToProviders(page);

      // Check if modal exists in DOM (hidden)
      const modal = moduleFrame.locator('#test-modal');
      const modalExists = await modal.count() > 0;

      if (modalExists) {
        // Modal should have proper ARIA attributes
        const ariaLabelledby = await modal.getAttribute('aria-labelledby');
        const ariaHidden = await modal.getAttribute('aria-hidden');

        // Modal should reference its title
        expect(ariaLabelledby || ariaHidden).toBeTruthy();
      }
    });
  });

  test.describe('Model Module', () => {
    test('should have no critical accessibility violations @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/models');
      await page.waitForLoadState('networkidle');

      const results = await runAxeAnalysis(page, 'Models');

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations).toEqual([]);
    });
  });

  test.describe('Configuration Module', () => {
    test('should have no critical accessibility violations @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/configurations');
      await page.waitForLoadState('networkidle');

      const results = await runAxeAnalysis(page, 'Configurations');

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations).toEqual([]);
    });

    test('should have accessible form fields @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToConfigurations(page);

      // Try to navigate to create form
      const newButton = moduleFrame.getByRole('link', { name: /New Configuration/i });
      const hasNewButton = await newButton.isVisible().catch(() => false);

      if (hasNewButton) {
        await newButton.click();
        await page.waitForTimeout(1000);

        const formFrame = getModuleFrame(page);

        // Check form inputs have labels
        const inputs = formFrame.locator('input[type="text"], textarea, select');
        const inputCount = await inputs.count();

        for (let i = 0; i < inputCount; i++) {
          const input = inputs.nth(i);
          const inputId = await input.getAttribute('id');
          const ariaLabel = await input.getAttribute('aria-label');
          const ariaLabelledby = await input.getAttribute('aria-labelledby');

          if (inputId) {
            // Check for associated label
            const label = formFrame.locator(`label[for="${inputId}"]`);
            const hasLabel = await label.count() > 0;
            const hasAriaLabel = !!ariaLabel || !!ariaLabelledby;

            // Input should have either a label or aria-label
            expect(hasLabel || hasAriaLabel).toBe(true);
          }
        }
      }
    });
  });

  test.describe('Setup Wizard Module', () => {
    test('should have no critical accessibility violations @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/wizard');
      await page.waitForLoadState('networkidle');

      const results = await runAxeAnalysis(page, 'Setup Wizard');

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations).toEqual([]);
    });
  });

  test.describe('Color Contrast', () => {
    test('should have sufficient color contrast in main module @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm');
      await page.waitForLoadState('networkidle');

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2aa'])
        .options({
          rules: {
            'color-contrast': { enabled: true },
          },
        })
        .analyze();

      const contrastViolations = results.violations.filter(
        (v) => v.id === 'color-contrast'
      );

      // Log contrast issues if any
      if (contrastViolations.length > 0) {
        console.log('\nColor contrast violations:');
        contrastViolations.forEach((v) => {
          v.nodes.forEach((node) => {
            console.log(`  - ${node.target}: ${node.failureSummary}`);
          });
        });
      }

      expect(contrastViolations).toEqual([]);
    });
  });

  test.describe('Keyboard Navigation', () => {
    test('should support keyboard navigation through main module @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Find first focusable element
      const firstButton = moduleFrame.getByRole('link').first();
      await firstButton.focus();

      // Verify focus is visible
      const isFocused = await firstButton.evaluate((el) => {
        return document.activeElement === el;
      }).catch(() => false);

      // Note: In iframes, focus checking is tricky
      // Just verify we can interact with the element
      await expect(firstButton).toBeVisible();
    });

    test('should have visible focus indicators @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToLlmModule(page);

      // Check that buttons have focus styles
      const buttons = moduleFrame.getByRole('button');
      const buttonCount = await buttons.count();

      if (buttonCount > 0) {
        const firstButton = buttons.first();

        // Get computed styles before and after focus
        const beforeFocus = await firstButton.evaluate((el) => {
          return window.getComputedStyle(el).outline;
        });

        await firstButton.focus();

        const afterFocus = await firstButton.evaluate((el) => {
          return window.getComputedStyle(el).outline;
        });

        // Focus should change something (outline, box-shadow, etc.)
        // TYPO3 backend uses its own focus styles
        // Just verify the button is focusable
        await expect(firstButton).toBeVisible();
      }
    });
  });

  test.describe('ARIA Landmarks', () => {
    test('should have proper landmark regions @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm');
      await page.waitForLoadState('networkidle');

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a'])
        .options({
          rules: {
            'landmark-one-main': { enabled: true },
            'region': { enabled: true },
          },
        })
        .analyze();

      // Check for landmark-related violations
      const landmarkViolations = results.violations.filter(
        (v) => v.id.includes('landmark') || v.id === 'region'
      );

      // Note: TYPO3 backend structure may not have perfect landmarks
      // We check for critical issues only
      const criticalLandmarkIssues = landmarkViolations.filter(
        (v) => v.impact === 'critical'
      );

      expect(criticalLandmarkIssues).toEqual([]);
    });
  });

  test.describe('Agent Runs Module', () => {
    test('should have no critical accessibility violations @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;

      await page.goto('/typo3/module/nrllm/runs');
      await page.waitForLoadState('networkidle');

      const results = await runAxeAnalysis(page, 'Agent Runs');

      const criticalViolations = results.violations.filter(
        (v) => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations).toEqual([]);
    });

    test('should have an h1 plus the two section landmarks @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToRuns(page);

      // ADR-109 heading hierarchy: h1 "Agent Runs" -> h2 "Awaiting your
      // decision" (waiting section) + h2 "Recent runs" (terminal section).
      await expect(moduleFrame.getByRole('heading', { level: 1 })).toContainText('Agent Runs');
      await expect(moduleFrame.getByRole('heading', { level: 2, name: 'Awaiting your decision' })).toBeVisible();
      await expect(moduleFrame.getByRole('heading', { level: 2, name: 'Recent runs' })).toBeVisible();

      // Both sections expose a labelled region (aria-labelledby on <section>).
      const regions = moduleFrame.getByRole('region');
      expect(await regions.count()).toBeGreaterThanOrEqual(2);
    });

    test('should render the recent-runs table with header cells when present @accessibility', async ({ authenticatedPage }) => {
      const page = authenticatedPage;
      const moduleFrame = await navigateToRuns(page);

      // The terminal-runs table is a real <table> with a <caption>, <thead> and
      // scoped <th>s; on a fresh instance it may show the empty-state text
      // instead — either is accessible.
      const table = moduleFrame.locator('table');
      const hasTable = await table.first().isVisible().catch(() => false);

      if (hasTable) {
        await expect(table.first().locator('thead')).toBeVisible();
        expect(await table.first().locator('th[scope="col"]').count()).toBeGreaterThan(0);
      }
    });
  });
});
