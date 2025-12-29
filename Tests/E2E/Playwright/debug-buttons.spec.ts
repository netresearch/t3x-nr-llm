import { test, expect, getModuleFrame } from './fixtures';

test('Debug: Click provider test button and capture network', async ({ authenticatedPage }) => {
  const page = authenticatedPage;

  // Enable request logging - capture ALL requests
  const requests: string[] = [];
  page.on('request', (req) => {
    requests.push(`${req.method()} ${req.url()}`);
  });

  // Capture page errors
  const pageErrors: string[] = [];
  page.on('pageerror', (err) => {
    pageErrors.push(err.message);
  });

  // Capture console messages from both main page and frames
  const consoleMessages: string[] = [];
  page.on('console', (msg) => {
    consoleMessages.push(`[main] ${msg.type()}: ${msg.text()}`);
  });

  // Navigate to providers (module is under 'admin' parent, path is /module/nrllm/providers)
  await page.goto('/typo3/module/nrllm/providers');
  await page.waitForTimeout(3000);

  const moduleFrame = getModuleFrame(page);

  // Also try to get console from the iframe
  const frames = page.frames();
  console.log('Number of frames:', frames.length);
  for (const frame of frames) {
    console.log('Frame URL:', frame.url());
  }

  // Wait for table to load
  await moduleFrame.locator('table').waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});

  // Check if test button exists
  const testButtons = moduleFrame.locator('.js-test-connection');
  const count = await testButtons.count();
  console.log('Number of test connection buttons:', count);

  if (count > 0) {
    // Get button info
    const firstBtn = testButtons.first();
    const uid = await firstBtn.getAttribute('data-uid');
    console.log('Button data-uid:', uid);

    // Check TYPO3.settings.ajaxUrls in the iframe context
    const iframeEl = await page.locator('iframe').first().elementHandle();
    if (iframeEl) {
      const frame = await iframeEl.contentFrame();
      if (frame) {
        const ajaxUrls = await frame.evaluate(() => {
          return (window as any).TYPO3?.settings?.ajaxUrls;
        }).catch(() => null);
        console.log('AJAX URLs in frame:', ajaxUrls);
      }
    }

    // Try to click
    console.log('Clicking button...');
    await firstBtn.click();

    // Wait for modal to appear (may be in shadow DOM or specific TYPO3 container)
    await page.waitForTimeout(3000);

    // Check for modal in various locations
    const modalInMain = page.locator('.modal.show, [role="dialog"], .modal-dialog');
    const modalCount = await modalInMain.count();
    console.log('Modals found in main page:', modalCount);

    const modalInFrame = moduleFrame.locator('.modal.show, [role="dialog"], .modal-dialog');
    const modalCountFrame = await modalInFrame.count();
    console.log('Modals found in frame:', modalCountFrame);

    // Check for TYPO3 modal wrapper
    const typo3Modal = page.locator('.t3js-modal, typo3-backend-modal');
    const typo3ModalCount = await typo3Modal.count();
    console.log('TYPO3 modals found:', typo3ModalCount);

    // List all modal-related elements for debugging
    const allModals = await page.locator('[class*="modal"]').count();
    console.log('Any modal-class elements:', allModals);

    // Log console messages
    console.log('Console messages:', consoleMessages);

    // Log page errors
    console.log('Page errors:', pageErrors);

    // Log POST requests specifically
    const postRequests = requests.filter(r => r.startsWith('POST'));
    console.log('POST requests made:', postRequests);

    // Log all nrllm requests
    const nrllmRequests = requests.filter(r => r.includes('nrllm'));
    console.log('nrllm requests:', nrllmRequests);

    // Take a screenshot
    await page.screenshot({ path: 'Tests/E2E/Playwright/test-results/debug-after-click.png', fullPage: true });
  } else {
    console.log('No test buttons found - check if providers exist');

    // Check table content
    const rows = await moduleFrame.locator('table tbody tr').count();
    console.log('Table rows:', rows);

    // Take screenshot
    await page.screenshot({ path: 'Tests/E2E/Playwright/test-results/debug-no-buttons.png', fullPage: true });
  }
});
