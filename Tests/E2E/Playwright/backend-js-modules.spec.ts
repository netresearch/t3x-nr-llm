import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

/**
 * Behaviour of backend ES modules that no TYPO3 page in the E2E seed reaches
 * with data: the analytics charts need usage rows, the overview reachability
 * needs configured providers, and the model-ID field needs a provider API.
 *
 * Each module is loaded unchanged into a static page on a fake origin. An
 * import map points its `@typo3/*` imports at small stubs, so the module under
 * test is the shipped file and only TYPO3's AJAX and notification layers are
 * replaced. No backend login is needed.
 */

const ORIGIN = 'http://nrllm-js.test';
const REPO = resolve(__dirname, '../../..');
const JS = resolve(REPO, 'Resources/Public/JavaScript');

const STUB_AJAX = `
export default class AjaxRequest {
  constructor(url) { this.url = url; }
  withQueryArguments() { return this; }
  get() { return this.answer(); }
  post() { return this.answer(); }
  answer() {
    globalThis.__ajaxCalls = (globalThis.__ajaxCalls || 0) + 1;
    const data = (globalThis.__ajax || {})[this.url];
    return data === undefined
      ? Promise.reject(new Error('no stubbed answer for ' + this.url))
      : Promise.resolve({ resolve: () => Promise.resolve(data) });
  }
}`;
const STUB_NOTIFICATION = 'export default { warning() {}, error() {}, success() {}, info() {} };';

async function openPage(page: Page, head: string, body: string, modules: string[]): Promise<void> {
  const importMap = JSON.stringify({
    imports: {
      '@typo3/core/ajax/ajax-request.js': `${ORIGIN}/stub/ajax-request.js`,
      '@typo3/backend/notification.js': `${ORIGIN}/stub/notification.js`,
      '@netresearch/nr-llm/': `${ORIGIN}/js/`,
    },
  });
  const html = `<!doctype html><html lang="en" data-color-scheme="light"><head><meta charset="utf-8">
<script type="importmap">${importMap}</script>${head}</head><body>${body}
${modules.map((m) => `<script type="module" src="${ORIGIN}/js/${m}"></script>`).join('\n')}
</body></html>`;

  await page.route(`${ORIGIN}/**`, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/page.html') {
      return route.fulfill({ contentType: 'text/html', body: html });
    }
    if (path === '/stub/ajax-request.js') {
      return route.fulfill({ contentType: 'text/javascript', body: STUB_AJAX });
    }
    if (path === '/stub/notification.js') {
      return route.fulfill({ contentType: 'text/javascript', body: STUB_NOTIFICATION });
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

test.describe('Analytics charts (Analytics.js)', () => {
  // Tokens as the backend defines them: one light-dark() value per scheme.
  // Chart.js cannot evaluate light-dark(), so the module has to resolve it.
  const head = `<style>
    :root { color-scheme: light dark; }
    [data-color-scheme="light"] { color-scheme: light; }
    [data-color-scheme="dark"] { color-scheme: dark; }
    .nrllm-analytics {
      --nrllm-chart-series-1: light-dark(rgb(10, 20, 30), rgb(200, 210, 220));
      --nrllm-chart-series-1-fill: light-dark(rgba(10, 20, 30, 0.15), rgba(200, 210, 220, 0.2));
      --nrllm-chart-series-2: light-dark(rgb(40, 50, 60), rgb(230, 240, 250));
      --nrllm-chart-text: light-dark(rgb(1, 1, 1), rgb(254, 254, 254));
      --nrllm-chart-grid: light-dark(rgb(2, 2, 2), rgb(253, 253, 253));
    }
  </style><script src="${ORIGIN}/js/Vendor/chart.umd.js"></script>`;
  const body = `<div class="nrllm-analytics">
    <script type="application/json" id="nrllm-analytics-data">${JSON.stringify({
      trend: [
        { date: '2026-09-01', cost: 1.5, requests: 10 },
        { date: '2026-09-02', cost: 2.5, requests: 12 },
      ],
      byProvider: [{ label: 'openai', cost: 3 }],
    })}</script>
    <div style="height:200px"><canvas id="nrllm-trend-chart" role="img" aria-label="Trend"></canvas></div>
    <div style="height:200px"><canvas id="nrllm-provider-chart" role="img" aria-label="By provider"></canvas></div>
  </div>`;

  const trend = (page: Page) => page.evaluate(() => {
    const chart = (globalThis as any).Chart.getChart(document.getElementById('nrllm-trend-chart'));
    return {
      lines: chart.data.datasets.map((d: any) => ({
        border: d.borderColor, dash: d.borderDash ?? [], point: d.pointStyle ?? null,
      })),
      usePointStyle: chart.options.plugins.legend.labels.usePointStyle === true,
    };
  });

  test('resolves the scheme tokens to real colours and follows a scheme switch', async ({ page }) => {
    await openPage(page, head, body, ['Backend/Analytics.js']);
    await expect.poll(async () => (await trend(page)).lines[0].border).toBe('rgb(10, 20, 30)');
    expect((await trend(page)).lines[1].border).toBe('rgb(40, 50, 60)');

    await page.evaluate(() => document.documentElement.setAttribute('data-color-scheme', 'dark'));
    await expect.poll(async () => (await trend(page)).lines[0].border).toBe('rgb(200, 210, 220)');
    expect((await trend(page)).lines[1].border).toBe('rgb(230, 240, 250)');
  });

  test('tells the two trend lines apart by more than colour', async ({ page }) => {
    await openPage(page, head, body, ['Backend/Analytics.js']);
    await expect.poll(async () => (await trend(page)).lines.length).toBe(2);
    const { lines, usePointStyle } = await trend(page);
    expect(lines[0].dash).toEqual([]);
    expect(lines[1].dash.length).toBeGreaterThan(0);
    expect(lines[0].point).not.toBe(lines[1].point);
    expect(usePointStyle).toBe(true);
  });

  test('puts each chart\'s numbers in one hidden table, also after a re-render', async ({ page }) => {
    await openPage(page, head, body, ['Backend/Analytics.js']);
    const table = page.locator('#nrllm-trend-chart ~ table.visually-hidden');
    await expect(table).toHaveCount(1);
    await expect(table.locator('caption')).toHaveText('Trend');
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await expect(table.locator('tbody tr').first().locator('th[scope="row"]')).toHaveText('2026-09-01');

    await page.evaluate(() => document.documentElement.setAttribute('data-color-scheme', 'dark'));
    await expect.poll(() => page.evaluate(() => (globalThis as any).Chart.getChart(document.getElementById('nrllm-trend-chart')).data.datasets[0].borderColor)).toBe('rgb(200, 210, 220)');
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

test.describe('Model-ID field (ModelIdField.js)', () => {
  const head = `<script>
    globalThis.__ajax = { '/fetch-models': { success: true, providerName: 'Stub', models: [
      { id: 'gpt-5', name: 'GPT-5', description: 'A model with a long description', capabilities: ['chat'] },
      { id: 'gpt-5-mini', name: 'GPT-5 mini', capabilities: ['chat'] },
    ] } };
  </script>`;
  const body = `<div class="formengine-field-item t3js-formengine-field-item"><div class="form-control-wrap">
    <div class="input-group">
      <input type="text" id="model-id" name="data[tx_nrllm_model][1][model_id]" class="form-control" data-formengine-input-name="data[tx_nrllm_model][1][model_id]">
      <button type="button" class="btn btn-default js-fetch-models" data-fetch-url="/fetch-models" data-input-id="model-id" data-provider-uid="3" data-table="tx_nrllm_model">Fetch Models</button>
    </div>
    <div class="js-model-status mt-1" role="status" style="display:none;"></div>
  </div></div>`;

  test('announces the model list as a disclosure the button controls', async ({ page }) => {
    await openPage(page, head, body, ['Backend/ModelIdField.js']);
    const button = page.locator('.js-fetch-models');
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
    await openPage(page, head, body, ['Backend/ModelIdField.js']);
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
});
