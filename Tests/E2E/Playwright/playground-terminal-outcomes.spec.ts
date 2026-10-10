/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

// Real Chromium executes the shipped module and HTTP body handling. Core
// imports and the response boundary are fixtures, not backend authentication.
const ORIGIN = 'http://nrllm-playground.test';
const JS = resolve(__dirname, '../../../Resources/Public/JavaScript/Backend');
const RUN = '12345678-1234-1234-1234-123456789012';
const PREFIX = { kind: 'request', round: 1, messagesSent: [{ role: 'user', content: 'Literal question' }], toolSpecs: [] };

async function openPlayground(page: Page, batch: boolean): Promise<void> {
  const imports = {
    '@typo3/core/ajax/ajax-request.js': `${ORIGIN}/ajax.js`,
    '@typo3/backend/notification.js': `${ORIGIN}/notification.js`,
    '@netresearch/nr-llm/Backend/HtmlEscape.js': `${ORIGIN}/HtmlEscape.js`,
    '@netresearch/nr-llm/Backend/AjaxError.js': `${ORIGIN}/AjaxError.js`,
  };
  await page.route(`${ORIGIN}/**`, async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/ajax.js') {
      await route.fulfill({ contentType: 'application/javascript', body:
        `export default class AjaxRequest {constructor(url){this.url=url} async post(body){const r=await fetch(this.url,{method:'POST',body});return {resolve:()=>r.json()}}}` });
    } else if (path === '/notification.js') {
      await route.fulfill({ contentType: 'application/javascript', body: 'export default {error(){},warning(){}};' });
    } else if (path.endsWith('.js')) {
      await route.fulfill({ contentType: 'application/javascript', body: readFileSync(resolve(JS, path.slice(1))) });
    } else {
      await route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8">
<script type="importmap">${JSON.stringify({ imports })}</script>
<script>window.TYPO3={settings:{ajaxUrls:{probe:'${ORIGIN}/run'}}};${batch ? 'window.ReadableStream=undefined;' : ''}</script></head><body>
<div id="nrllm-tool-playground" data-ajax-route="probe">
<select id="nrllm-tool-config"><option value="41">Configuration</option></select>
<textarea id="nrllm-tool-prompt">Literal question</textarea><button id="nrllm-tool-run">Run</button>
<div id="nrllm-tool-output"></div></div><script type="module" src="${ORIGIN}/ToolPlayground.js"></script></body></html>` });
    }
  });
  await page.goto(ORIGIN);
}

const OUTCOMES = [
  ['awaiting_approval', 'Awaiting approval'],
  ['awaiting_input', 'Awaiting input'],
  ['guardrail_blocked', 'Blocked by a guardrail'],
  ['guardrail_approval_required', 'Guardrail approval required'],
  ['cancelled', 'Cancelled'],
] as const;

for (const batch of [false, true]) {
  for (const [status, label] of OUTCOMES) {
    test(`${batch ? 'batch' : 'stream'} shows ${status} rather than completion`, async ({ page }) => {
      const errors: string[] = [];
      page.on('pageerror', error => errors.push(error.message));
      await openPlayground(page, batch);
      const waiting = status.startsWith('awaiting_');
      const payload = { event: status, status, success: waiting, runUuid: waiting ? RUN : undefined,
        pendingTools: [{ name: 'approval_probe', arguments: { fixture: true } }],
        inputRequest: { tool: 'input_probe', schema: { type: 'object' } },
        turnDigest: 'fixture-digest-not-an-action-token', guardrail: 'FixtureGuardrail',
        error: waiting ? undefined : 'Bounded policy or cancellation reason.' };
      await page.route(`${ORIGIN}/run`, async route => {
        await route.fulfill({ contentType: batch ? 'application/json' : 'application/x-ndjson',
          body: batch ? JSON.stringify({ ...payload, steps: [PREFIX] }) :
            `${JSON.stringify({ event: 'step', step: PREFIX })}\n${JSON.stringify(payload)}\n` });
      });
      await page.locator('#nrllm-tool-run').click();
      await expect(page.locator('#nrllm-tool-run')).toBeEnabled();
      await expect(page.locator('.nrllm-pg-status')).toContainText(label);
      await expect(page.locator('.nrllm-pg-status')).not.toContainText('Completed');
      await expect(page.locator('.nrllm-pg-slrow')).toHaveCount(1);
      if (waiting) {
        await expect(page.locator('#nrllm-tool-output')).toContainText('Continue in AI > Operation > Agent Runs.');
        await expect(page.locator('#nrllm-tool-output')).toContainText(RUN);
        await expect(page.locator('#nrllm-tool-output')).not.toContainText(payload.turnDigest);
        await expect(page.locator('#nrllm-tool-output button[type="submit"]')).toHaveCount(0);
      } else {
        await expect(page.locator('#nrllm-tool-output')).toContainText(payload.error!);
      }
      expect(errors).toEqual([]);
    });
  }
  for (const [name, extra, label] of [
    ['completed', {}, 'Completed'],
    ['dry', { dryRun: true }, 'Dry run'],
    ['truncated', { truncated: true }, 'Truncated'],
  ] as const) {
    test(`${batch ? 'batch' : 'stream'} keeps the ${name} positive control`, async ({ page }) => {
      await openPlayground(page, batch);
      const payload = { event: 'done', success: true, finalContent: 'Literal final answer.', usage: {}, ...extra };
      await page.route(`${ORIGIN}/run`, route => route.fulfill({ contentType: batch ? 'application/json' : 'application/x-ndjson',
        body: batch ? JSON.stringify(payload) : `${JSON.stringify(payload)}\n` }));
      await page.locator('#nrllm-tool-run').click();
      await expect(page.locator('.nrllm-pg-status')).toContainText(label);
      await expect(page.locator('#nrllm-tool-run')).toBeEnabled();
    });
  }
  test(`${batch ? 'batch' : 'stream'} keeps the actual error positive control`, async ({ page }) => {
    await openPlayground(page, batch);
    const payload = { event: 'error', success: false, error: 'Literal failure <script>payload</script>.' };
    await page.route(`${ORIGIN}/run`, route => route.fulfill({ contentType: batch ? 'application/json' : 'application/x-ndjson',
      body: batch ? JSON.stringify(payload) : `${JSON.stringify(payload)}\n` }));
    await page.locator('#nrllm-tool-run').click();
    await expect(page.locator('.nrllm-pg-error')).toHaveText(payload.error);
    await expect(page.locator('#nrllm-tool-output script')).toHaveCount(0);
    await expect(page.locator('#nrllm-tool-run')).toBeEnabled();
  });
}

test('stream without a final event preserves its trace and shows incomplete', async ({ page }) => {
  await openPlayground(page, false);
  await page.route(`${ORIGIN}/run`, route => route.fulfill({ contentType: 'application/x-ndjson',
    body: `${JSON.stringify({ event: 'step', step: PREFIX })}\n` }));
  await page.locator('#nrllm-tool-run').click();
  await expect(page.locator('#nrllm-tool-run')).toBeEnabled();
  await expect(page.locator('.nrllm-pg-status')).toContainText('Incomplete');
  await expect(page.locator('.nrllm-pg-status')).not.toContainText('Completed');
  await expect(page.locator('.nrllm-pg-slrow')).toHaveCount(1);
});
