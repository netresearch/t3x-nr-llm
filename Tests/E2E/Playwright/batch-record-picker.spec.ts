/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, getModuleFrame } from './fixtures';
import type { FrameLocator, Locator, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * The batch form of the Editor Action Center picks its records and its page
 * in TYPO3's element browser instead of asking for typed numbers
 * (BatchRecordPicker.js). The pick travels back from the browser's modal;
 * these tests drive the real browser, so they cover that hand-over on the
 * TYPO3 major the instance runs.
 *
 * Needs two pages on the root level: the batch form only reads the table
 * name, so no editor action has to be configured for the pickers to work.
 */
const BATCH_URL = '/typo3/module/web/nrllm-aitasks?controller=Backend%5CEditorAction&action=batch'
  + '&toolName=update_page_metadata&recordTable=pages&recordUids=';

async function openBatchForm(page: Page, recordUids: string): Promise<FrameLocator> {
  await page.goto(BATCH_URL + recordUids);
  const frame = getModuleFrame(page);
  await frame.getByRole('button', { name: 'Choose records' }).waitFor({ state: 'visible', timeout: 15000 });
  return frame;
}

/**
 * Click a browse button and wait until the modal has finished opening. On
 * TYPO3 13.4 the browser's own "add and close" is ignored while the modal is
 * still fading in, and no editor picks that fast.
 */
async function openFully(page: Page, browse: Locator): Promise<void> {
  await page.evaluate(() => {
    (window as unknown as { nrllmShown: boolean }).nrllmShown = false;
    document.addEventListener('typo3-modal-shown', () => {
      (window as unknown as { nrllmShown: boolean }).nrllmShown = true;
    }, { once: true });
  });
  await browse.click();
  await page.waitForFunction(() => (window as unknown as { nrllmShown: boolean }).nrllmShown);
}

/** The element browser in the backend modal (a sibling of the module frame). */
function elementBrowser(page: Page): FrameLocator {
  return page.frameLocator('.t3js-modal-iframe');
}

/**
 * Pick a root-level page in the record browser, which lists them on opening:
 * its "add" link, or the "add and close" one.
 */
async function pickPage(page: Page, uid: number, close: boolean): Promise<void> {
  const browser = elementBrowser(page);
  // The links answer only once the browser's modules have run; a click
  // before that follows href="#" and picks nothing.
  await browser.locator('body').evaluate(() => new Promise((resolve) => {
    if (document.readyState === 'complete') {
      resolve(null);
    } else {
      window.addEventListener('load', () => resolve(null), { once: true });
    }
  }));
  await browser.locator(`span[data-uid="${uid}"] a[data-close="${close ? 1 : 0}"]`).first().click();
}

test.describe('Batch form record browser', () => {
  test('the pickers have no serious accessibility violations @accessibility', async ({ authenticatedPage: page }) => {
    const frame = await openBatchForm(page, '41,42');
    await expect(frame.locator('#nrllm-batch-uids-summary')).toHaveText('Chosen: 2');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .exclude('.scaffold-modulemenu')
      .exclude('.scaffold-header')
      .exclude('.topbar')
      .disableRules(['aria-hidden-focus'])
      .analyze();

    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });

  test('records are picked, listed with title and removed again', async ({ authenticatedPage: page }) => {
    const frame = await openBatchForm(page, '41');
    const hidden = frame.locator('#nrllm-batch-uids');

    // Carried over from the record the catalogue was opened for: by number.
    await expect(hidden).toHaveValue('41');
    await expect(frame.getByRole('button', { name: 'Remove Record 41' })).toBeVisible();

    const browse = frame.getByRole('button', { name: 'Choose records' });
    await openFully(page, browse);
    await expect(page.locator('.t3js-modal-iframe')).toHaveAttribute('src', /mode=db/);

    // Add without closing, then add and close.
    await pickPage(page, 41, false);
    await expect(frame.getByRole('button', { name: 'Remove Products [41]' })).toBeVisible();
    await pickPage(page, 42, true);

    await expect(page.locator('.t3js-modal-iframe')).toHaveCount(0);
    await expect(hidden).toHaveValue('41,42');
    await expect(frame.locator('#nrllm-batch-uids-summary')).toHaveText('Chosen: 2');
    await expect(browse).toBeFocused();

    await frame.getByRole('button', { name: 'Remove About us [42]' }).click();
    await expect(hidden).toHaveValue('41');
    await expect(browse).toBeFocused();

    // The server receives what the picker wrote.
    await frame.getByRole('button', { name: 'Recalculate' }).click();
    await expect(frame.locator('table')).toContainText('#41');
    await expect(frame.locator('#nrllm-batch-uids')).toHaveValue('41');
  });

  test('the page at the top of the tree is picked and removed', async ({ authenticatedPage: page }) => {
    const frame = await openBatchForm(page, '');
    const hidden = frame.locator('#nrllm-batch-scope');
    const browse = frame.getByRole('button', { name: 'Choose page' });

    await expect(hidden).toHaveValue('');
    await expect(frame.locator('#nrllm-batch-scope-item')).toHaveText('None chosen.');

    // Picked as soon as the browser has loaded, which can be before the
    // modal has finished opening: the picker still closes it.
    await browse.click();
    await pickPage(page, 42, false);

    // One page: any pick ends the browsing.
    await expect(page.locator('.t3js-modal-iframe')).toHaveCount(0);
    await expect(hidden).toHaveValue('42');
    await expect(frame.locator('#nrllm-batch-scope-item')).toHaveText('About us [42]');
    await expect(browse).toBeFocused();
    await expect(browse).toHaveAttribute('aria-describedby', /nrllm-batch-scope-item/);

    await frame.getByRole('button', { name: 'Remove the choice' }).click();
    await expect(hidden).toHaveValue('');
    await expect(browse).toBeFocused();
  });
});
