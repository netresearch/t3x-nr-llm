/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, getModuleFrame, loginToBackend } from './fixtures';
import type { FrameLocator, Locator, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * The text under a record select follows the chosen option (ADR-255,
 * ChoiceHelp.js): a native <option> holds one line of text, so what a
 * configuration is for is shown below the select.
 *
 * The spec creates its two configurations through the record form and
 * deletes them afterwards. A seed would leave them in place for every other
 * spec, and several of those expect an installation without configurations.
 */
const CONFIGURATIONS = [
  { identifier: 'e2e-choice-teasers', name: 'E2E Choice A', description: 'Writes short teasers <b>for pages</b>' },
  { identifier: 'e2e-choice-legal', name: 'E2E Choice B', description: 'Checks legal wording' },
];
const created: number[] = [];

const NEW_TASK_URL = '/typo3/record/edit?edit%5Btx_nrllm_task%5D%5B0%5D=new&returnUrl=%2Ftypo3%2Fmain';
const NEW_TERM_LIST_URL = '/typo3/record/edit?edit%5Btx_nrllm_term_list%5D%5B0%5D=new&returnUrl=%2Ftypo3%2Fmain';

async function createConfiguration(page: Page, configuration: typeof CONFIGURATIONS[number]): Promise<number> {
  await page.goto('/typo3/record/edit?edit%5Btx_nrllm_configuration%5D%5B0%5D=new&returnUrl=%2Ftypo3%2Fmain');
  const frame = getModuleFrame(page);
  const identifier = frame.locator('[data-formengine-input-name$="[identifier]"]');
  await identifier.waitFor({ state: 'visible', timeout: 15000 });
  await identifier.fill(configuration.identifier);
  await frame.locator('[data-formengine-input-name$="[name]"]').fill(configuration.name);
  await frame.locator('textarea[name$="[description]"]').fill(configuration.description);
  await frame.locator('button[name="_savedok"]').click();
  // After the save the form reloads as the edit form of the new record.
  const saved = frame.locator('[data-formengine-input-name^="data[tx_nrllm_configuration]"][data-formengine-input-name$="[identifier]"]');
  await expect(saved).toHaveAttribute('data-formengine-input-name', /\[\d+\]\[identifier\]$/, { timeout: 15000 });
  const name = await saved.getAttribute('data-formengine-input-name') ?? '';
  const uid = name.match(/\[(\d+)\]\[identifier\]$/);
  return Number(uid?.[1] ?? 0);
}

async function deleteConfiguration(page: Page, uid: number): Promise<void> {
  await page.goto(`/typo3/record/edit?edit%5Btx_nrllm_configuration%5D%5B${uid}%5D=edit&returnUrl=%2Ftypo3%2Fmain`);
  const frame = getModuleFrame(page);
  await frame.locator('.t3js-editform-delete-record').click();
  await page.locator('.t3js-modal button[name="yes"]').click();
  await page.waitForLoadState('networkidle');
}

/** The field item holding the select whose name ends in [field], and its help text. */
function fieldOf(frame: FrameLocator, field: string): { item: Locator; help: Locator } {
  const item = frame.locator('.formengine-field-item').filter({
    has: frame.locator(`select[name$="[${field}]"]`),
  });
  return { item, help: item.locator('.nrllm-choice-help') };
}

/** Press ArrowDown until the select's first selected option has the value. */
async function arrowTo(page: Page, select: Locator, value: string): Promise<void> {
  const current = () => select.evaluate((element: HTMLSelectElement) => element.selectedOptions[0]?.value ?? '');
  for (let step = 0; step < 30 && await current() !== value; step++) {
    await page.keyboard.press('ArrowDown');
  }
  expect(await current()).toBe(value);
}

test.describe.serial('Record select help', () => {
  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginToBackend(page);
    for (const configuration of CONFIGURATIONS) {
      created.push(await createConfiguration(page, configuration));
    }
    expect(created.every((uid) => uid > 0)).toBe(true);
    await page.close();
  });

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginToBackend(page);
    for (const uid of created) {
      await deleteConfiguration(page, uid);
    }
    await page.close();
  });

  test('describes the chosen configuration and follows a keyboard change', async ({ authenticatedPage: page }) => {
    await page.goto(NEW_TASK_URL);
    const frame = getModuleFrame(page);
    const { item, help } = fieldOf(frame, 'configuration_uid');
    const select = item.locator('select');
    await select.waitFor({ state: 'visible', timeout: 15000 });

    // ChoiceHelp.js ties the text to the select once the module has run;
    // wait for that instead of reading the attribute once.
    const helpId = await help.getAttribute('id');
    await expect(select, 'the select is described by the help text').toHaveAttribute('aria-describedby', new RegExp(`(^| )${helpId}( |$)`));
    await expect(help).toHaveAttribute('aria-live', 'polite');

    // Keyboard only: move from the default to "E2E Choice A", then on to B.
    await select.focus();
    await arrowTo(page, select, String(created[0]));
    // The stored description is written as text, not as markup.
    await expect(help).toContainText('Writes short teasers <b>for pages</b>');
    await expect(help.locator('b')).toHaveCount(0);
    await expect(help).toContainText('No model selected yet');

    await page.keyboard.press('ArrowDown');
    await expect(select).toHaveValue(String(created[1]));
    await expect(help).toContainText('Checks legal wording');
    await expect(help).not.toContainText('Writes short teasers');
  });

  test('describes each available item while the arrow keys move through a side-by-side select', async ({ authenticatedPage: page }) => {
    // A new term list: its configurations field is a selectMultipleSideBySide.
    await page.goto(NEW_TERM_LIST_URL);
    const frame = getModuleFrame(page);
    const field = frame.locator('.formengine-field-item').filter({ has: frame.locator('.nrllm-choice-help') });
    const available = field.locator('select.t3js-formengine-select-itemstoselect');
    await available.waitFor({ state: 'visible', timeout: 15000 });
    const help = field.locator('.nrllm-choice-help');
    const helpId = await help.getAttribute('id');
    await expect(available).toHaveAttribute('aria-describedby', new RegExp(`(^| )${helpId}( |$)`));

    // Keyboard only: step through the available items. Moving does not add an item.
    await available.focus();
    await arrowTo(page, available, String(created[0]));
    await expect(help).toContainText('Writes short teasers');
    await page.keyboard.press('ArrowDown');
    await expect(help).toContainText('Checks legal wording');
    await expect(help).not.toContainText('Writes short teasers');
  });

  test('offers creating and managing configurations to an administrator', async ({ authenticatedPage: page }) => {
    await page.goto(NEW_TASK_URL);
    const { item } = fieldOf(getModuleFrame(page), 'configuration_uid');
    await item.waitFor({ state: 'visible', timeout: 15000 });

    await expect(item.getByRole('link', { name: 'Create new' })).toBeVisible();
    await expect(item.getByRole('link', { name: 'Show and manage all configurations' })).toBeVisible();
  });

  test('has no accessibility violations on the field @accessibility', async ({ authenticatedPage: page }) => {
    await page.goto(NEW_TASK_URL);
    const { item } = fieldOf(getModuleFrame(page), 'configuration_uid');
    await item.waitFor({ state: 'visible', timeout: 15000 });

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
      .analyze();
    const onTheField = results.violations.filter((violation) => violation.nodes.some(
      (node) => node.html.includes('nrllm-choice-help') || node.html.includes('configuration_uid') || node.html.includes('t3js-formengine-fieldcontrol'),
    ));
    expect(onTheField, JSON.stringify(onTheField, null, 2)).toEqual([]);
  });
});
