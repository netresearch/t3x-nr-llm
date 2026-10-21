/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

// The shipped ES module runs in an actual browser; only TYPO3 imports and the
// HTTP stream boundary are fixtures. This does not claim a backend login or live
// provider integration. The companion functional test pins PHP's blank-member
// parsing and the real controller/runtime plain-completion path.
const ORIGIN = 'http://nrllm-playground.test';
const JS = resolve(__dirname, '../../../Resources/Public/JavaScript/Backend');

async function openPlayground(page: Page): Promise<void> {
  const imports = {
    '@typo3/core/ajax/ajax-request.js': `${ORIGIN}/ajax.js`,
    '@typo3/backend/notification.js': `${ORIGIN}/notification.js`,
    '@netresearch/nr-llm/Backend/HtmlEscape.js': `${ORIGIN}/HtmlEscape.js`,
    '@netresearch/nr-llm/Backend/AjaxError.js': `${ORIGIN}/AjaxError.js`,
  };
  await page.route(`${ORIGIN}/**`, async route => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname === '/ajax.js' || pathname === '/notification.js') {
      const body = pathname === '/ajax.js'
        ? 'export default class AjaxRequest {}'
        : 'export default {error(){},warning(){}}';
      await route.fulfill({ contentType: 'application/javascript', body });
    } else if (pathname.endsWith('.js')) {
      await route.fulfill({ contentType: 'application/javascript', body: readFileSync(resolve(JS, pathname.slice(1))) });
    } else {
      await route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">
<script type="importmap">${JSON.stringify({ imports })}</script>
<script>window.TYPO3={settings:{ajaxUrls:{probe:'${ORIGIN}/run'}}};</script></head><body>
<div id="nrllm-tool-playground" data-ajax-route="probe">
<select id="nrllm-tool-config"><option value="41">Configuration</option></select>
<textarea id="nrllm-tool-prompt">Literal question</textarea>
<input type="checkbox" class="js-toolgroup-select" data-group="read" checked>
<input type="checkbox" class="js-tool-select" value="first_read" data-group="read" checked>
<input type="checkbox" class="js-tool-select" value="second_read" data-group="read" checked>
<button id="nrllm-tool-run">Run</button><div id="nrllm-tool-output"></div></div>
<script type="module" src="${ORIGIN}/ToolPlayground.js"></script></body></html>` });
    }
  });
  await page.goto(ORIGIN);
}

for (const selection of ['none', 'first', 'all', 'group-none']) {
  test(`Playground sends explicit ${selection} tool selection`, async ({ page }) => {
    const errors: string[] = [];
    const requests: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await openPlayground(page);
    await page.route(`${ORIGIN}/run`, async route => {
      requests.push(route.request().postData() || '');
      await route.fulfill({ contentType: 'application/x-ndjson', body: '{"event":"done","finalContent":"Literal answer","usage":{}}\n' });
    });
    if (selection === 'none') {
      for (const checkbox of await page.locator('.js-tool-select').all()) await checkbox.uncheck();
    }
    if (selection === 'first') await page.locator('.js-tool-select[value="second_read"]').uncheck();
    if (selection === 'group-none') await page.locator('.js-toolgroup-select').uncheck();
    await page.locator('#nrllm-tool-run').click();
    await expect(page.locator('#nrllm-tool-output')).toContainText('Completed');
    await expect(page.locator('#nrllm-tool-run')).toBeEnabled();
    expect(errors).toEqual([]);
    expect(requests).toHaveLength(1);
    const values = [...requests[0].matchAll(/name="tools\[\]"\r\n\r\n([^\r]*)\r\n/g)].map(match => match[1]);
    expect(values.length).toBeGreaterThan(0);
    expect(values.filter(value => value !== '')).toEqual(selection === 'all'
      ? ['first_read', 'second_read']
      : selection === 'first' ? ['first_read'] : []);
  });
}
