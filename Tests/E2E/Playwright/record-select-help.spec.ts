/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, getModuleFrame } from './fixtures';
import AxeBuilder from '@axe-core/playwright';

/**
 * The text under a record select follows the chosen option (ADR-255,
 * ChoiceHelp.js): a native <option> holds one line of text, so what a
 * configuration is for and which model it runs on is shown below the select.
 *
 * Needs choice-help-seed.sql: configurations 9301 ("E2E Choice A") and 9302
 * ("E2E Choice B") on the same model, and task 9401 using 9301.
 */
const TASK_EDIT_URL = '/typo3/record/edit?edit%5Btx_nrllm_task%5D%5B9401%5D=edit&returnUrl=%2Ftypo3%2Fmain';

test.describe('Record select help', () => {
  test('describes the chosen configuration and follows a keyboard change', async ({ authenticatedPage: page }) => {
    await page.goto(TASK_EDIT_URL);
    const frame = getModuleFrame(page);
    const select = frame.locator('select[name="data[tx_nrllm_task][9401][configuration_uid]"]');
    await select.waitFor({ state: 'visible', timeout: 15000 });

    const helpId = (await select.getAttribute('aria-describedby') ?? '').split(' ').find((id) => id.startsWith('nrllm-choice-help-'));
    expect(helpId, 'the select is described by the help text').toBeTruthy();
    const help = frame.locator(`#${helpId}`);

    // First paint, rendered on the server: the stored description as text,
    // not markup, and the model with its provider.
    await expect(help).toHaveText(/Writes short teasers <b>for pages<\/b>/);
    await expect(help).toContainText('Model: E2E Choice Model (E2E Choice Provider)');
    await expect(help.locator('b')).toHaveCount(0);
    await expect(help).toHaveAttribute('aria-live', 'polite');

    // Keyboard only: focus the select and move to the next configuration
    // ("E2E Choice B" sorts right after "E2E Choice A").
    await select.focus();
    await page.keyboard.press('ArrowDown');
    await expect(select).toHaveValue('9302');
    await expect(help).toContainText('Checks legal wording');
    await expect(help).not.toContainText('Writes short teasers');
  });

  test('offers creating and managing configurations to an administrator', async ({ authenticatedPage: page }) => {
    await page.goto(TASK_EDIT_URL);
    const frame = getModuleFrame(page);
    const field = frame.locator('.formengine-field-item').filter({
      has: frame.locator('select[name="data[tx_nrllm_task][9401][configuration_uid]"]'),
    });
    await field.waitFor({ state: 'visible', timeout: 15000 });

    await expect(field.getByRole('link', { name: 'Create new' })).toBeVisible();
    await expect(field.getByRole('link', { name: 'Show and manage all configurations' })).toBeVisible();
  });

  test('has no accessibility violations on the field @accessibility', async ({ authenticatedPage: page }) => {
    await page.goto(TASK_EDIT_URL);
    const frame = getModuleFrame(page);
    await frame.locator('select[name="data[tx_nrllm_task][9401][configuration_uid]"]').waitFor({ state: 'visible', timeout: 15000 });

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
      .analyze();
    const onTheField = results.violations.filter((violation) => violation.nodes.some(
      (node) => node.html.includes('nrllm-choice-help') || node.html.includes('configuration_uid') || node.html.includes('t3js-formengine-fieldcontrol'),
    ));
    expect(onTheField, JSON.stringify(onTheField, null, 2)).toEqual([]);
  });
});
