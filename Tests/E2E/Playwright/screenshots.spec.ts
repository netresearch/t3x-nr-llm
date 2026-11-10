/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

import { test, expect, loginToBackend } from './fixtures';
import type { Page } from '@playwright/test';

/**
 * Captures the documentation screenshots.
 *
 * WHY THE IFRAME AND NOT THE PAGE. TYPO3 v14 renders module content inside an
 * iframe, so screenshotting that element yields the module and nothing else —
 * no module menu, no toolbar, no breadcrumb. That is not a cosmetic
 * preference: every full-window screenshot in Documentation/Images/ went stale
 * the day the modules moved into the AI section (#812), because each one showed
 * `Administration > LLM` in the sidebar. A module-only shot cannot go stale
 * that way, so the menu can be reorganised again without touching sixteen PNGs.
 *
 * The figures in these shots are real: they are aggregated from the rows
 * `nrllm:demo:seed` writes, priced from each model's own cost_input/cost_output.
 * That command derives every value from a fixed seed, so re-running this spec
 * reproduces the same numbers and a diff between two screenshots is a real
 * change rather than noise.
 *
 * Run through Build/Scripts/screenshots.sh, which installs TYPO3 on SQLite,
 * seeds it and serves it — no ddev, no external database.
 */

/** Where each screenshot comes from. Filenames match Documentation/Images/. */
const SHOTS: ReadonlyArray<{ file: string; url: string; ready?: string; user?: string }> = [
  { file: 'backend-dashboard.png', url: '/typo3/module/nrllm/overview' },
  { file: 'backend-providers.png', url: '/typo3/module/nrllm/providers' },
  { file: 'backend-models.png', url: '/typo3/module/nrllm/models' },
  { file: 'backend-configurations.png', url: '/typo3/module/nrllm/configurations' },
  { file: 'backend-tasks.png', url: '/typo3/module/nrllm/tasks' },
  { file: 'backend-analytics.png', url: '/typo3/module/nrllm/analytics' },
  { file: 'backend-setup-wizard.png', url: '/typo3/module/nrllm/wizard', ready: '#setup-wizard' },
  { file: 'backend-aitasks-list.png', url: '/typo3/module/web/nrllm-aitasks' },
  // Shot as the head editor Build/Scripts/screenshots.sh creates: logged in
  // as the admin, the module shows everyone, which is not what it is for.
  { file: 'backend-group-usage.png', url: '/typo3/module/nrllm/group-usage', user: 'head-hugo' },
];

const OUT = 'Documentation/Images';

/**
 * The module iframe, sized to its content.
 *
 * The WINDOW is resized, not the iframe. Forcing the element taller leaves the
 * document inside it at its old height, so the shot gains a band of empty grey
 * below the content — visible, and exactly the kind of thing that gets
 * committed because nobody opens the PNG. Growing the viewport lets the iframe
 * grow with it and the document reflow into it.
 */
async function moduleElement(page: Page) {
  const frame = page.frameLocator('iframe').first();
  await frame.locator('body').waitFor({ state: 'visible', timeout: 15000 });

  const width = page.viewportSize()?.width ?? 1680;

  // The BOTTOM of the last thing actually drawn, not the body's own height:
  // TYPO3's module body carries a min-height, so measuring it returns the
  // viewport whatever the content is, and every shot keeps a few hundred
  // pixels of empty page below the last row.
  const content = await frame.locator('body').evaluate((body) => {
    let bottom = 0;
    for (const el of Array.from(body.querySelectorAll('*'))) {
      const r = el.getBoundingClientRect();
      if (r.height > 0 && r.width > 0) {
        bottom = Math.max(bottom, r.bottom + window.scrollY);
      }
    }
    return Math.ceil(bottom);
  });

  // Chrome refuses a viewport beyond 16384px, and a module that long is a
  // documentation problem rather than a screenshot one.
  // A short module still keeps some of its own canvas below the content, and
  // that is correct: the canvas is part of how the module looks. This bounds
  // the shot; it does not crop to the last pixel of text.
  const height = Math.min(Math.max(content + 40, 500), 4000);
  await page.setViewportSize({ width, height });

  // A bounded wait, not a condition. SonarCloud's typescript:S2925 asks for an
  // observable condition here and it is right in general — but both conditions
  // that fit (a requestAnimationFrame loop watching the iframe box, and one
  // watching the chart canvases settle) hang: rAF is throttled inside the module
  // iframe, so the promise never resolves and every test dies at its 30s
  // timeout. Measured, not assumed — that revision is what made all eight fail.
  await page.waitForTimeout(400);

  return page.locator('iframe').first();
}

test.describe('documentation screenshots', () => {
  // Wide enough that no table column is dropped. The repository's own rule is
  // never to shoot the backend below 1440px; the module area is narrower than
  // the window by the width of the menu, so the window is wider than the floor.
  test.use({ viewport: { width: 1680, height: 1100 } });

  for (const shot of SHOTS) {
    test(shot.file, async ({ page }) => {
      await loginToBackend(page, shot.user);
      await page.goto(shot.url);

      const frame = page.frameLocator('iframe').first();
      if (shot.ready !== undefined) {
        await frame.locator(shot.ready).waitFor({ state: 'visible', timeout: 15000 });
      } else {
        await frame.getByRole('heading', { level: 1 }).waitFor({ state: 'visible', timeout: 15000 });
      }

      // Charts animate in; a shot taken mid-animation shows half a bar chart.
      // Fixed for the same reason as the wait in moduleElement() above.
      await page.waitForTimeout(1200);

      const element = await moduleElement(page);
      await element.screenshot({ path: `${OUT}/${shot.file}` });

      // A screenshot of an error page is still a screenshot, and it would be
      // committed without anyone looking. Assert the module actually rendered.
      await expect(frame.locator('body')).not.toContainText('Oops, an error occurred');
    });
  }

  // The grants and the group assignment of the "Head editors" group that
  // Build/Scripts/screenshots.sh creates. Only the two form sections that
  // belong to nr_llm, not the whole tab: the tab also lists every module,
  // which makes a shot several thousand pixels long and hides the point.
  test('backend-permissions-grants.png', async ({ page }) => {
    await loginToBackend(page);
    await page.goto('/typo3/record/edit?edit[be_groups][12]=edit');

    const frame = page.frameLocator('iframe').first();
    await frame.getByRole('tab', { name: 'Module Permissions', exact: true }).click();
    await frame.getByRole('tab', { name: 'AI (nr_llm) permissions', exact: true }).click();

    const element = await moduleElement(page);
    const iframeBox = await element.boundingBox();
    expect(iframeBox).not.toBeNull();

    // From the section of the custom options to the section of the
    // assignment field, which TCA places directly after it.
    const rect = await frame.locator('body').evaluate((body) => {
      const legend = Array.from(body.querySelectorAll('legend')).find((l) => l.textContent?.includes('[custom_options]'));
      const field = body.querySelector('[name$="[tx_nrllm_usage_visible_groups]"]');
      const first = legend?.closest('.form-section');
      const last = field?.closest('.form-section');
      if (!first || !last) {
        return null;
      }
      const a = first.getBoundingClientRect();
      const b = last.getBoundingClientRect();
      // The section's own box ends above its select boxes, which overflow
      // it: measure what is drawn, as moduleElement() does for the page.
      let bottom = b.bottom;
      for (const el of Array.from(last.querySelectorAll('*'))) {
        const r = el.getBoundingClientRect();
        if (r.height > 0 && r.width > 0) {
          bottom = Math.max(bottom, r.bottom);
        }
      }
      return { x: a.left, y: a.top, width: Math.max(a.right, b.right) - a.left, height: bottom - a.top };
    });
    expect(rect).not.toBeNull();
    if (iframeBox === null || rect === null) {
      return;
    }

    // A margin around the two sections: the select boxes of the assignment
    // field end exactly on the section's edge, and a shot cut there reads as
    // cropped. Never left of the iframe, whose edge is the module's edge.
    const pad = 16;
    const left = Math.max(rect.x - pad, 0);
    // The select boxes grow after moduleElement() measured the page, so the
    // section can end below the window, and a clip is cut at the window's
    // edge. Grow the window to hold it; the iframe grows with it.
    const needed = Math.ceil(iframeBox.y + rect.y + rect.height + 2 * pad);
    const viewport = page.viewportSize();
    if (viewport !== null && viewport.height < needed) {
      await page.setViewportSize({ width: viewport.width, height: needed });
      // Wait until the iframe has grown to hold the clip. Polled from here
      // over the protocol, not with requestAnimationFrame inside the frame,
      // which is what made the conditions in moduleElement() hang.
      const clipBottom = rect.y + rect.height + pad;
      await expect
        .poll(async () => (await element.boundingBox())?.height ?? 0, { timeout: 5000 })
        .toBeGreaterThanOrEqual(clipBottom);
    }
    await page.screenshot({
      path: `${OUT}/backend-permissions-grants.png`,
      clip: {
        x: iframeBox.x + left,
        y: iframeBox.y + rect.y - pad,
        width: rect.width + (rect.x - left) + pad,
        height: rect.height + 2 * pad,
      },
    });
  });
});
