/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

// Real Chromium renders the skill usage (ADR-321) and skill evidence (ADR-322)
// steps of the shipped module. Core imports and the response are fixtures
// shaped like RunStep::toArray(), not a backend run.
const ORIGIN = 'http://nrllm-playground.test';
const JS = resolve(__dirname, '../../../Resources/Public/JavaScript/Backend');
const DIGEST = 'sha256:0123456789abcdef0123456789abcdef';
const OTHER_DIGEST = 'sha256:fedcba9876543210fedcba9876543210';
const XSS = '<img src=x onerror=alert(1)>';

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

function evidence(evaluation: number, model: string, reportedModel: string, fit: string) {
  return { evaluation, runDate: 1763424000, set: 'release-set', benchmark: 'bench-fingerprint',
    configuration: 'eval-config', provider: 'fixture-provider', model, reportedModel,
    grader: 'exact', gradingProtocol: 'v1', prompts: 4, passed: 4, passRate: 1.0, meanScore: 1.0,
    scope: 'completion', fit };
}

// The order an agent run emits: the skill versions before the call that
// carries them, the evidence after the answer it is judged against.
function steps(usage: object[], skillEvidence: object[]) {
  return [
    { kind: 'skill_usage', round: 1, durationMs: 0, skillUsage: usage },
    { kind: 'request', round: 1, durationMs: 0, messagesSent: [{ role: 'user', content: 'Literal question' }], toolSpecs: [] },
    { kind: 'llm', round: 1, durationMs: 120, content: 'Literal answer.', finishReason: 'stop',
      promptTokens: 10, completionTokens: 5, totalTokens: 15 },
    { kind: 'skill_evidence', round: 1, durationMs: 0, skillEvidence },
  ];
}

async function run(page: Page, batch: boolean, trace: object[]): Promise<void> {
  const done = { event: 'done', success: true, finalContent: 'Literal answer.', usage: {} };
  await page.route(`${ORIGIN}/run`, route => route.fulfill({
    contentType: batch ? 'application/json' : 'application/x-ndjson',
    body: batch
      ? JSON.stringify({ ...done, steps: trace })
      : trace.map(step => `${JSON.stringify({ event: 'step', step })}\n`).join('') + `${JSON.stringify(done)}\n`,
  }));
  await page.locator('#nrllm-tool-run').click();
  await expect(page.locator('#nrllm-tool-run')).toBeEnabled();
  await expect(page.locator('.nrllm-pg-status')).toContainText('Completed');
}

for (const batch of [false, true]) {
  const transport = batch ? 'batch' : 'stream';

  test(`${transport} renders the skill usage and evidence rows and their details`, async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await openPlayground(page, batch);
    await run(page, batch, steps(
      [{ skill: 7, source: 3, digest: DIGEST, identifier: 'release-notes', role: 'instruction',
        inclusion: 'composed', state: 'passed', approvalState: 'approved', approval: 12 }],
      [{ skill: 7, digest: DIGEST, evidence: [evidence(5, 'gpt-fixture', 'gpt-fixture-2026', 'match')] }],
    ));

    const rows = page.locator('.nrllm-pg-slrow');
    await expect(rows).toHaveCount(4);
    await expect(rows.nth(0).locator('.nrllm-pg-st1')).toHaveText('Skills · round 1');
    await expect(rows.nth(0).locator('.nrllm-pg-st2')).toHaveText('1 skill version');
    await expect(rows.nth(3).locator('.nrllm-pg-st1')).toHaveText('Skill evidence · round 1');
    await expect(rows.nth(3).locator('.nrllm-pg-st2')).toHaveText('1 of 1 tested');

    // The answer is selected by default, not the evidence row after it.
    await expect(page.locator('.nrllm-pg-slrow[aria-current="true"]')).toHaveCount(1);
    await expect(rows.nth(2)).toHaveAttribute('aria-current', 'true');
    await expect(page.locator('.nrllm-pg-dh-title')).toHaveText('Round 1 — answer');

    await rows.nth(0).click();
    await expect(rows.nth(0)).toHaveAttribute('aria-current', 'true');
    await expect(page.locator('.nrllm-pg-dh-title')).toHaveText('Skills · round 1');
    await expect(page.locator('.nrllm-pg-detail pre')).toHaveText(
      'release-notes #7 @sha256:0123456 instruction/composed/passed approval #12');

    await rows.nth(3).click();
    await expect(page.locator('.nrllm-pg-dh-title')).toHaveText('Skill evidence · round 1');
    await expect(page.locator('.nrllm-pg-detail pre')).toHaveText(
      '#7 @sha256:0123456: eval#5 release-set gpt-fixture-2026 pass=1.00 fit=match');
    expect(errors).toEqual([]);
  });

  test(`${transport} renders untested versions, unnamed models and identifiers as text`, async ({ page }) => {
    const errors: string[] = [];
    const dialogs: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
    await openPlayground(page, batch);
    await run(page, batch, steps(
      [
        { skill: 7, source: 3, digest: DIGEST, identifier: XSS, role: 'process',
          inclusion: 'loaded', state: 'loaded', approvalState: 'none', approval: 0 },
        { skill: 8, source: 4, digest: OTHER_DIGEST, identifier: 'style-guide', role: 'context',
          inclusion: 'carried', state: 'passed', approvalState: 'unknown', approval: 0 },
      ],
      [
        { skill: 7, digest: DIGEST, evidence: [evidence(6, '', '', 'unknown')] },
        { skill: 8, digest: OTHER_DIGEST, evidence: [] },
      ],
    ));

    const rows = page.locator('.nrllm-pg-slrow');
    await expect(rows.nth(0).locator('.nrllm-pg-st2')).toHaveText('2 skill versions');
    await expect(rows.nth(3).locator('.nrllm-pg-st2')).toHaveText('1 of 2 tested');
    await expect(rows.nth(2)).toHaveAttribute('aria-current', 'true');

    await rows.nth(0).click();
    await expect(page.locator('.nrllm-pg-detail pre')).toHaveText(
      `${XSS} #7 @sha256:0123456 process/loaded/loaded approval none\n`
      + 'style-guide #8 @sha256:fedcba9 context/carried/passed approval unknown');
    await expect(page.locator('#nrllm-tool-output img')).toHaveCount(0);

    await rows.nth(3).click();
    await expect(page.locator('.nrllm-pg-detail pre')).toHaveText(
      '#7 @sha256:0123456: eval#6 release-set - pass=1.00 fit=unknown\n'
      + '#8 @sha256:fedcba9: none');
    expect(errors).toEqual([]);
    expect(dialogs).toEqual([]);
  });
}
