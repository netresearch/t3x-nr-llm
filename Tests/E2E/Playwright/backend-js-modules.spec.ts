import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

/**
 * Behaviour of backend ES modules that no TYPO3 page in the E2E seed reaches
 * with data: the analytics charts need usage rows, the overview reachability
 * needs configured providers, and the model test and model-ID field need a
 * provider API.
 *
 * Each module is loaded unchanged into a static page on a fake origin. An
 * import map points its `@typo3/*` imports at small stubs, so the module under
 * test is the shipped file and only TYPO3's AJAX, modal and notification
 * layers are replaced. Where a test measures colour or size it loads core's
 * backend CSS as installed in `.Build` (the TYPO3 the E2E job runs) and the
 * extension's own stylesheet. No backend login is needed.
 */

const ORIGIN = 'http://nrllm-js.test';
const REPO = resolve(__dirname, '../../..');
const JS = resolve(REPO, 'Resources/Public/JavaScript');
const CSS = resolve(REPO, 'Resources/Public/Css');
const CORE_CSS = resolve(REPO, '.Build/vendor/typo3/cms-backend/Resources/Public/Css/backend.css');

/**
 * TYPO3 13.4's backend CSS, which the E2E job does not install (it resolves
 * 14.3). Fetched once from the upstream tag and refused unless it is the
 * exact file pinned here, so a run measures the same CSS every time.
 */
const CORE_134 = {
  url: 'https://raw.githubusercontent.com/TYPO3/typo3/v13.4.35/typo3/sysext/backend/Resources/Public/Css/backend.css',
  sha256: '872350960f4f5aeda6698e4b5ef43e66d8067c862c349d08789580fcceee407d',
  path: resolve(REPO, '.Build/core-css/v13.4.35/backend.css'),
};

async function core134Css(): Promise<Buffer> {
  const digest = (data: Buffer) => createHash('sha256').update(data).digest('hex');
  if (existsSync(CORE_134.path) && digest(readFileSync(CORE_134.path)) === CORE_134.sha256) {
    return readFileSync(CORE_134.path);
  }
  const response = await fetch(CORE_134.url);
  expect(response.ok, `fetching ${CORE_134.url}: HTTP ${response.status}`).toBe(true);
  const data = Buffer.from(await response.arrayBuffer());
  expect(digest(data), `${CORE_134.url} is not the pinned file`).toBe(CORE_134.sha256);
  mkdirSync(dirname(CORE_134.path), { recursive: true });
  writeFileSync(CORE_134.path, data);
  return data;
}

const STUB_AJAX = `
export default class AjaxRequest {
  constructor(url) { this.url = url; }
  withQueryArguments() { return this; }
  get() { return this.answer(); }
  post() { return this.answer(); }
  answer() {
    globalThis.__ajaxCalls = (globalThis.__ajaxCalls || 0) + 1;
    if (globalThis.__ajaxPending) {
      return new Promise(() => {});
    }
    const data = (globalThis.__ajax || {})[this.url];
    return data === undefined
      ? Promise.reject(new Error('no stubbed answer for ' + this.url))
      : Promise.resolve({ resolve: () => Promise.resolve(data) });
  }
}`;
const STUB_NOTIFICATION = 'export default { warning() {}, error() {}, success() {}, info() {} };';
// TYPO3's modal renders the content it is given; here it lands in #modal-host.
const STUB_MODAL = `export default {
  sizes: { default: 'default' },
  advanced(options) { document.getElementById('modal-host').appendChild(options.content); },
  dismiss() {},
};`;
const STUB_SEVERITY = 'export default { info: 1, ok: 0, warning: 2, error: 3 };';

type Scheme = 'light' | 'dark';

async function openPage(page: Page, head: string, body: string, modules: string[], scheme: Scheme = 'light'): Promise<void> {
  const importMap = JSON.stringify({
    imports: {
      '@typo3/core/ajax/ajax-request.js': `${ORIGIN}/stub/ajax-request.js`,
      '@typo3/backend/notification.js': `${ORIGIN}/stub/notification.js`,
      '@typo3/backend/modal.js': `${ORIGIN}/stub/modal.js`,
      '@typo3/backend/severity.js': `${ORIGIN}/stub/severity.js`,
      '@typo3/backend/element/spinner-element.js': `${ORIGIN}/stub/empty.js`,
      '@typo3/backend/element/icon-element.js': `${ORIGIN}/stub/empty.js`,
      '@netresearch/nr-llm/': `${ORIGIN}/js/`,
    },
  });
  const html = `<!doctype html><html lang="en" data-color-scheme="${scheme}" data-theme="fresh"><head><meta charset="utf-8">
<script type="importmap">${importMap}</script>${head}</head><body>${body}
${modules.map((m) => `<script type="module" src="${ORIGIN}/js/${m}"></script>`).join('\n')}
</body></html>`;
  const stubs: Record<string, string> = {
    '/stub/ajax-request.js': STUB_AJAX,
    '/stub/notification.js': STUB_NOTIFICATION,
    '/stub/modal.js': STUB_MODAL,
    '/stub/severity.js': STUB_SEVERITY,
    '/stub/empty.js': 'export {};',
  };

  await page.route(`${ORIGIN}/**`, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/page.html') {
      return route.fulfill({ contentType: 'text/html', body: html });
    }
    if (stubs[path] !== undefined) {
      return route.fulfill({ contentType: 'text/javascript', body: stubs[path] });
    }
    if (path === '/core/backend.css') {
      return route.fulfill({ contentType: 'text/css', body: readFileSync(CORE_CSS) });
    }
    if (path === '/core/v13.4.35/backend.css') {
      return route.fulfill({ contentType: 'text/css', body: await core134Css() });
    }
    if (path.startsWith('/css/')) {
      return route.fulfill({ contentType: 'text/css', body: readFileSync(resolve(CSS, path.slice(5))) });
    }
    if (path.startsWith('/js/')) {
      return route.fulfill({ contentType: 'text/javascript', body: readFileSync(resolve(JS, path.slice(4))) });
    }
    return route.fulfill({ status: 404, body: '' });
  });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(`${ORIGIN}/page.html`);
  await page.waitForLoadState('load');
  expect(errors, 'the module threw while loading').toEqual([]);
}

/** Core's backend CSS as installed; the tests that read colour or size need it. */
function coreCss(): string {
  expect(existsSync(CORE_CSS), `core backend CSS not found at ${CORE_CSS}; install the dependencies first`).toBe(true);
  return `<link rel="stylesheet" href="${ORIGIN}/core/backend.css">`;
}

/** The core CSS each TYPO3 line ships: 14.3 as installed, 13.4.35 pinned. */
const CORES: Array<[string, () => string]> = [
  ['TYPO3 14.3 (installed)', coreCss],
  ['TYPO3 13.4.35 (pinned)', () => `<link rel="stylesheet" href="${ORIGIN}/core/v13.4.35/backend.css">`],
];

/**
 * WCAG contrast of an element's text against what it is drawn on: the colour's
 * alpha is composited over the first opaque background up the tree.
 */
function contrastOf(page: Page, selector: string): Promise<number> {
  return page.evaluate((sel) => {
    const parse = (c: string) => {
      if (c.startsWith('color(srgb')) {
        const m = c.slice(10).match(/[\d.]+/g)!.map(Number);
        return { r: m[0] * 255, g: m[1] * 255, b: m[2] * 255, a: m.length > 3 ? m[3] : 1 };
      }
      const m = c.match(/[\d.]+/g)!.map(Number);
      return { r: m[0], g: m[1], b: m[2], a: m.length > 3 ? m[3] : 1 };
    };
    const lum = (c: { r: number; g: number; b: number }) => {
      const f = (v: number) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
      return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
    };
    const el = document.querySelector(sel)!;
    let bg = { r: 255, g: 255, b: 255, a: 1 };
    const layers = [];
    for (let e: Element | null = el; e; e = e.parentElement) {
      const c = parse(getComputedStyle(e).backgroundColor);
      if (c.a > 0) layers.push(c);
      if (c.a >= 1) break;
    }
    if (!layers.length || layers[layers.length - 1].a < 1) {
      bg = document.documentElement.dataset.colorScheme === 'dark' ? { r: 0, g: 0, b: 0, a: 1 } : bg;
    }
    for (let i = layers.length - 1; i >= 0; i--) {
      const l = layers[i];
      bg = { r: l.r * l.a + bg.r * (1 - l.a), g: l.g * l.a + bg.g * (1 - l.a), b: l.b * l.a + bg.b * (1 - l.a), a: 1 };
    }
    const fg = parse(getComputedStyle(el).color);
    const eff = { r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a) };
    const a = lum(eff), b = lum(bg);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }, selector);
}

test.describe('Analytics charts (Analytics.js)', () => {
  const head = () => `${coreCss()}<link rel="stylesheet" href="${ORIGIN}/css/Backend/Analytics.css">
    <script src="${ORIGIN}/js/Vendor/chart.umd.js"></script>`;
  const body = `<div class="module"><div class="module-body"><div class="nrllm-analytics">
    <script type="application/json" id="nrllm-analytics-data">${JSON.stringify({
      trend: [
        { date: '2026-09-01', cost: 1.5, requests: 10 },
        { date: '2026-09-02', cost: 2.5, requests: 12 },
      ],
      byProvider: [{ label: 'openai', cost: 3 }],
    })}</script>
    <div style="height:200px"><canvas id="nrllm-trend-chart" role="img" aria-label="Trend"></canvas></div>
    <div style="height:200px"><canvas id="nrllm-provider-chart" role="img" aria-label="By provider"></canvas></div>
    <span id="expect-1"></span><span id="expect-2"></span>
  </div></div></div>`;

  const trend = (page: Page) => page.evaluate(() => {
    const chart = (globalThis as any).Chart.getChart(document.getElementById('nrllm-trend-chart'));
    return {
      lines: chart.data.datasets.map((d: any) => ({
        border: d.borderColor, dash: d.borderDash ?? [], point: d.pointStyle ?? null,
      })),
      usePointStyle: chart.options.plugins.legend.labels.usePointStyle === true,
    };
  });
  // What Analytics.css's two series tokens compute to in the scheme now set,
  // read independently of the module.
  const expected = (page: Page) => page.evaluate(() => {
    const read = (id: string, token: string) => {
      const el = document.getElementById(id)!;
      el.style.color = `var(${token})`;
      return getComputedStyle(el).color;
    };
    return [read('expect-1', '--nrllm-chart-series-1'), read('expect-2', '--nrllm-chart-series-2')];
  });

  test('resolves the stylesheet\'s scheme tokens to real colours and follows a scheme switch', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/Analytics.js']);
    const [light1, light2] = await expected(page);
    expect(light1).toMatch(/^(rgb|color)\(/);
    await expect.poll(async () => (await trend(page)).lines[0].border).toBe(light1);
    expect((await trend(page)).lines[1].border).toBe(light2);

    await page.evaluate(() => document.documentElement.setAttribute('data-color-scheme', 'dark'));
    const [dark1, dark2] = await expected(page);
    expect(dark1).not.toBe(light1);
    await expect.poll(async () => (await trend(page)).lines[0].border).toBe(dark1);
    expect((await trend(page)).lines[1].border).toBe(dark2);
  });

  test('tells the two trend lines apart by more than colour', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/Analytics.js']);
    await expect.poll(async () => (await trend(page)).lines.length).toBe(2);
    const { lines, usePointStyle } = await trend(page);
    expect(lines[0].dash).toEqual([]);
    expect(lines[1].dash.length).toBeGreaterThan(0);
    expect(lines[0].point).not.toBe(lines[1].point);
    expect(usePointStyle).toBe(true);
  });

  test('puts each chart\'s numbers in one hidden table, also after a re-render', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/Analytics.js']);
    const table = page.locator('#nrllm-trend-chart ~ table.visually-hidden');
    await expect(table).toHaveCount(1);
    await expect(table.locator('caption')).toHaveText('Trend');
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await expect(table.locator('tbody tr').first().locator('th[scope="row"]')).toHaveText('2026-09-01');

    const before = (await trend(page)).lines[0].border;
    await page.evaluate(() => document.documentElement.setAttribute('data-color-scheme', 'dark'));
    await expect.poll(async () => (await trend(page)).lines[0].border).not.toBe(before);
    await expect(table).toHaveCount(1);
  });
});

test.describe('Overview reachability (OverviewReachability.js)', () => {
  const body = `<div class="nrllm-ov-reach" data-label-up="erreichbar" data-label-down="nicht erreichbar" data-label-unknown="unbekannt">
    <span class="nrllm-ov-reach-badge is-unknown" data-nrllm-provider="openai"><span class="nrllm-ov-reach-ico" aria-hidden="true"></span><span class="nrllm-ov-reach-name">OpenAI</span><span class="visually-hidden nrllm-ov-reach-word">: unbekannt</span></span>
    <span class="nrllm-ov-reach-badge is-unknown" data-nrllm-provider="claude"><span class="nrllm-ov-reach-ico" aria-hidden="true"></span><span class="nrllm-ov-reach-name">Claude</span><span class="visually-hidden nrllm-ov-reach-word">: unbekannt</span></span>
  </div><a href="#" data-nrllm-recheck="1">Recheck</a>`;
  const head = `<script>
    globalThis.TYPO3 = { settings: { ajaxUrls: { nrllm_overview_reachability: '/reach' } } };
    globalThis.__ajax = { '/reach': { providers: [ { identifier: 'openai', status: 'up' }, { identifier: 'claude', status: 'down' } ] } };
  </script>`;

  test('writes the status as text inside each badge, not as an aria-label', async ({ page }) => {
    await openPage(page, head, body, ['Backend/OverviewReachability.js']);
    const openai = page.locator('[data-nrllm-provider="openai"]');
    const claude = page.locator('[data-nrllm-provider="claude"]');
    await expect(openai).toHaveClass(/is-up/);
    await expect(claude).toHaveClass(/is-down/);
    await expect(openai.locator('.nrllm-ov-reach-word')).toHaveText(': erreichbar');
    await expect(claude.locator('.nrllm-ov-reach-word')).toHaveText(': nicht erreichbar');
    await expect(openai).not.toHaveAttribute('aria-label');
    await expect(openai).toHaveText('OpenAI: erreichbar');
  });

  test('resets every badge to the unknown text before a recheck', async ({ page }) => {
    await openPage(page, head, body, ['Backend/OverviewReachability.js']);
    const word = page.locator('[data-nrllm-provider="openai"] .nrllm-ov-reach-word');
    await expect(word).toHaveText(': erreichbar');
    await page.evaluate(() => { (globalThis as any).__ajax = {}; });
    await page.getByText('Recheck').click();
    await expect(word).toHaveText(': unbekannt');
    await expect(page.locator('[data-nrllm-provider="openai"]')).toHaveClass(/is-unknown/);
  });
});

test.describe('Model test progress (ModelList.js)', () => {
  const head = (css: string, pending: boolean) => `${css}<script>
    globalThis.TYPO3 = { settings: { ajaxUrls: { nrllm_model_test: '/model-test' } } };
    globalThis.__ajaxPending = ${pending};
    globalThis.__ajax = { '/model-test': { success: false, error: 'The provider refused the request.' } };
  </script>`;
  const body = `<div class="module"><div class="module-body">
    <button type="button" class="btn btn-default js-test-model" data-uid="7" data-name="GPT-5">Test</button>
    <div id="modal-host"></div>
  </div></div>`;

  for (const [core, css] of CORES) for (const scheme of ['light', 'dark'] as Scheme[]) {
    // On 13.4 text-variant is a fixed grey: 4.29:1 on alert-danger in light.
    test(`keeps the failure line readable on ${core} in ${scheme}`, async ({ page }) => {
      await openPage(page, head(css(), false), body, ['Backend/ModelList.js'], scheme);
      await page.locator('.js-test-model').click();
      const line = page.locator('#model-test-error small');
      await expect(line).toBeVisible();
      await expect(line).toContainText('Failed after');
      expect(await contrastOf(page, '#model-test-error small'), `failure line on ${core} in ${scheme}`).toBeGreaterThanOrEqual(4.5);
    });

    test(`keeps finished and current steps readable on ${core} in ${scheme}`, async ({ page }) => {
      await openPage(page, head(css(), true), body, ['Backend/ModelList.js'], scheme);
      await page.locator('.js-test-model').click();
      // updateStep(2) runs 500 ms after the click: two steps done, one current.
      await expect(page.locator('#step-connect')).toHaveClass(/text-success/);
      await expect(page.locator('#step-send')).toHaveClass(/text-success/);
      // Neither a finished nor the current step stays muted.
      for (const step of ['#step-connect', '#step-send', '#step-wait']) {
        await expect(page.locator(step)).not.toHaveClass(/text-variant/);
      }
      for (const text of ['#step-connect-text', '#step-send-text', '#step-wait-text']) {
        expect(await contrastOf(page, text), `${text} on ${core} in ${scheme}`).toBeGreaterThanOrEqual(4.5);
      }
    });
  }
});

test.describe('Model-ID field (ModelIdField.js)', () => {
  const head = (css = '') => `${css}<script>
    globalThis.__ajax = { '/fetch-models': { success: true, providerName: 'Stub', models: [
      { id: 'gpt-5', name: 'GPT-5', description: 'A model with a long description', capabilities: ['chat'], recommended: true, contextLength: 128000, maxOutputTokens: 16000 },
      { id: 'gpt-5-mini', name: 'GPT-5 mini', capabilities: ['chat'] },
    ] } };
  </script>`;
  // The markup Classes/Form/Element/ModelIdElement.php renders (keep in step).
  const body = `<div class="module"><div class="module-body">
<div class="formengine-field-item t3js-formengine-field-item">
  <div class="form-control-wrap">
    <div class="input-group">
      <input type="text" id="model-id" name="data[tx_nrllm_model][1][model_id]" value="" class="form-control" maxlength="150" placeholder="e.g., gpt-5.3-chat-latest, claude-sonnet-4-6" autocomplete="off" data-formengine-input-name="data[tx_nrllm_model][1][model_id]" />
      <button type="button" class="btn btn-default js-fetch-models" data-fetch-url="/fetch-models" data-input-id="model-id" data-provider-uid="3" data-table="tx_nrllm_model" aria-expanded="false" title="Fetch available models from provider API"><span class="icon icon-size-small"><span class="icon-markup"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"><path fill="currentColor" d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 12.5a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/><path fill="currentColor" d="M10.5 7.25H8.75V5.5a.75.75 0 0 0-1.5 0v1.75H5.5a.75.75 0 0 0 0 1.5h1.75v1.75a.75.75 0 0 0 1.5 0V8.75h1.75a.75.75 0 0 0 0-1.5z"/></svg></span></span> Fetch Models</button>
    </div>
    <div class="js-model-status mt-1" role="status" style="display:none;"></div>
  </div>
</div></div></div>`;

  test('announces the model list as a disclosure the button controls', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/ModelIdField.js']);
    const button = page.locator('.js-fetch-models');
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await button.click();

    const list = page.locator('.js-model-dropdown');
    await expect(list).toBeVisible();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(button).toHaveAttribute('aria-controls', 'model-id-models');
    await expect(list).toHaveAttribute('id', 'model-id-models');
    // Its own name, not the placeholder ("Filter models..."), which disappears once typed into.
    await expect(list.locator('input[type="text"]')).toHaveAccessibleName('Filter models');
    await expect(list.locator('[title="GPT-5"]')).toHaveCount(1);
  });

  test('keeps aria-expanded in step whichever way the list closes', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/ModelIdField.js']);
    const button = page.locator('.js-fetch-models');
    const list = page.locator('.js-model-dropdown');

    // Closed by the button itself.
    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await button.click();
    await expect(list).toBeHidden();
    await expect(button).toHaveAttribute('aria-expanded', 'false');

    // Closed with Escape in the filter.
    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('textbox', { name: 'Filter models' }).press('Escape');
    await expect(list).toBeHidden();
    await expect(button).toHaveAttribute('aria-expanded', 'false');

    // Closed by a click outside: below the list, which is at most 400 px tall.
    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await page.mouse.click(10, 700);
    await expect(list).toBeHidden();
    await expect(button).toHaveAttribute('aria-expanded', 'false');

    // Closed by choosing a model.
    await button.click();
    await list.locator('.list-group-item').first().click();
    await expect(list).toBeHidden();
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#model-id')).toHaveValue('gpt-5');
  });

  test('reopens the cached list from a click on the button\'s icon', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/ModelIdField.js']);
    const icon = page.locator('.js-fetch-models .icon-markup svg');
    const list = page.locator('.js-model-dropdown');

    await icon.click();
    await expect(list).toBeVisible();
    await icon.click();
    await expect(list).toBeHidden();
    await icon.click();
    await expect(list).toBeVisible();
    await expect(page.locator('.js-fetch-models')).toHaveAttribute('aria-expanded', 'true');
    // The third click reused the models; only the first fetched them.
    expect(await page.evaluate(() => (globalThis as any).__ajaxCalls)).toBe(1);
  });

  test('closes the list with Escape from a model in it', async ({ page }) => {
    await openPage(page, head(), body, ['Backend/ModelIdField.js']);
    await page.locator('.js-fetch-models').click();
    const list = page.locator('.js-model-dropdown');
    await expect(list).toBeVisible();

    await list.locator('.list-group-item').first().focus();
    await page.keyboard.press('Escape');
    await expect(list).toBeHidden();
    await expect(page.locator('.js-fetch-models')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#model-id')).toBeFocused();
  });

  test('sets badge and metadata in core\'s small text size', async ({ page }) => {
    await openPage(page, head(coreCss()), body, ['Backend/ModelIdField.js']);
    await page.locator('.js-fetch-models').click();
    const item = page.locator('.js-model-dropdown .list-group-item').first();
    await expect(item).toBeVisible();

    const sizes = await item.evaluate((el) => {
      const probe = document.createElement('span');
      probe.style.fontSize = 'var(--typo3-font-size-small)';
      document.body.appendChild(probe);
      const small = getComputedStyle(probe).fontSize;
      probe.remove();
      const badge = el.querySelector('.badge')!;
      const meta = el.lastElementChild!;
      return { small, badge: getComputedStyle(badge).fontSize, meta: getComputedStyle(meta).fontSize, metaText: meta.textContent };
    });
    expect(sizes.metaText).toContain('Ctx:');
    expect(Number.parseFloat(sizes.small)).toBeGreaterThanOrEqual(11);
    expect(sizes.badge).toBe(sizes.small);
    expect(sizes.meta).toBe(sizes.small);
  });
});
