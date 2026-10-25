/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Runs axe-core against every rendered product page, in a real browser.
 *
 * landingpage/build/check_accessibility.py covers what is decidable from markup
 * alone. The defects it cannot see — colour contrast above all, because that
 * needs resolved CSS and a compositing model — are what this file is for. This
 * page passes today; the point of the gate is that it keeps passing, because
 * the same audit found 213 failures on a sibling site whose static gate was
 * equally green.
 *
 *   node landingpage/axe-audit.mjs [output-dir]
 *
 * Exits non-zero on automated WCAG 2.2 AA or enhanced text-contrast failures,
 * document overflow at 320/390 CSS pixels, or text below the brand's 14px floor.
 * This is a regression gate, not a claim of full WCAG conformance.
 * Uses the Playwright Chromium and
 * the AxeBuilder the repository already runs against the backend modules;
 * a second browser stack or a second axe package for one gate would not buy
 * anything. The target is different — Tests/E2E covers the TYPO3 backend
 * against a live instance, this covers the published landing page.
 *
 * Serves the output over loopback rather than opening file:// URLs: the pages
 * reference their stylesheet by path, and an unstyled page has no contrast
 * failures at all — it would pass for the wrong reason.
 *
 * The pages are built for a base path (/t3x-nr-llm/ on GitHub Pages), so the
 * server mounts the output there. Serving it at / instead makes every absolute
 * asset URL 404: the audit then measures a stylesheet-less page and reports the
 * browser's default link colour, which is neither a defect nor a pass.
 */

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join, extname, relative, sep } from 'node:path';
import { chromium, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const DIST = process.argv[2] ?? 'landingpage/public';

/** The path the site is served from, with exactly one slash at each end. */
const baseSegments = (process.argv[3] ?? process.env.PAGES_BASE_PATH ?? '/')
  .split('/')
  .filter(Boolean)
  .join('/');
const BASE = baseSegments ? `/${baseSegments}/` : '/';

// WCAG 2.2 AA. 'best-practice' is deliberately
// not included: it flags stylistic preferences, and a gate that fails on those
// gets disabled instead of fixed.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.woff2': 'font/woff2',
  '.xml': 'application/xml; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
};

/**
 * Every servable file below the output directory, as URL path → absolute file.
 *
 * The tree is indexed once and the request handler only looks paths up. Nothing
 * is joined with, or stat'ed from, a request path: a URL cannot name a file the
 * index does not already hold, so `..` has nothing to escape into.
 */
function indexFiles(dir, base = dir, into = new Map()) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      indexFiles(full, base, into);
    } else {
      const url = `/${relative(base, full).split(sep).join('/')}`;
      into.set(url, full);
      if (entry === 'index.html') into.set(url.replace(/index\.html$/, ''), full);
    }
  }
  return into;
}

/**
 * The routes a visitor can open: every directory with an index.html, plus every
 * other .html file. Named pages matter — a discovery that looks for index.html
 * alone silently skips whole sections and still reports success.
 */
function htmlRoutes(files) {
  return [...files.keys()]
    .filter((url) => url.endsWith('/') || (url.endsWith('.html') && !url.endsWith('/index.html')))
    .filter((url) => {
      // A meta-refresh stub carries no content and the browser leaves it at
      // once; auditing it measures whatever it redirected to, a second time.
      const html = readFileSync(files.get(url), 'utf-8');
      return !/<meta[^>]+http-equiv=["']refresh["']/i.test(html);
    })
    .sort((a, b) => a.localeCompare(b));
}

/**
 * The route shape a page belongs to. Segments that name one repository, one
 * date or one document collapse to '*': those pages are the same template with
 * different data, and auditing all of them costs deploy time to re-find what
 * the first one already found.
 */
function routeShape(route) {
  return route
    .replace(/(\/(?:repo|snapshot|adr)\/)[^/]+(\/|$)/g, '$1*$2')
    .replace(/\/[^/]+\.html$/, '/*.html');
}

/** One representative route per shape, and how many that left out. */
function sampleRoutes(routes) {
  const groups = new Map();
  for (const route of routes) {
    const shape = routeShape(route);
    if (!groups.has(shape)) groups.set(shape, []);
    groups.get(shape).push(route);
  }
  return [...groups.entries()].map(([shape, members]) => ({
    shape,
    route: members[0],
    skipped: members.length - 1,
  }));
}

function serve(files) {
  const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    const file = path.startsWith(BASE) ? files.get(`/${path.slice(BASE.length)}`) : undefined;
    if (!file) {
      res.writeHead(404).end('not found');
      return;
    }
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' });
    res.end(await readFile(file));
  });
  return new Promise((resolve_) => {
    server.listen(0, '127.0.0.1', () => resolve_({ server, port: server.address().port }));
  });
}

/**
 * Both colour schemes. The dark palette is a separate set of colour pairs, so a
 * light-only audit says nothing about it — and the pages ship dark mode.
 */
const SCHEMES = ['light', 'dark'];

/** Prints one violation. Extracted so main() stays readable. */
function report({ route, scheme, violation }) {
  console.error(`\n✗ ${route} [${scheme}] — ${violation.id} (${violation.impact})`);
  console.error(`  ${violation.help}`);
  console.error(`  ${violation.helpUrl}`);
  for (const node of violation.nodes.slice(0, 4)) {
    console.error(`    ${node.target.join(' ')}`);
    for (const line of (node.failureSummary ?? '').split('\n').filter(Boolean).slice(1)) {
      console.error(`      ${line}`);
    }
  }
  if (violation.nodes.length > 4) {
    console.error(`    … and ${violation.nodes.length - 4} more element(s)`);
  }
}

/** Check one viewport before the next resize changes the same page. */
async function auditResponsiveViewport(page, width, route, scheme) {
  await page.setViewportSize({ width, height: 900 });
  const rendered = await page.evaluate(() => ({
    width: document.documentElement.clientWidth,
    content: document.documentElement.scrollWidth,
    small: [...document.querySelectorAll('body *')].filter((el) =>
      el.checkVisibility() && (el.matches('input, select, textarea') || [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()))
      && Number.parseFloat(getComputedStyle(el).fontSize) < 13.99,
    ).slice(0, 5).map((el) => `${el.tagName}.${el.className}: ${getComputedStyle(el).fontSize}`),
  }));
  const found = [];
  const defects = [];
  if (rendered.content > rendered.width + 1) defects.push(`document ${rendered.content}px exceeds viewport ${rendered.width}px`);
  defects.push(...rendered.small);
  if (defects.length) found.push({
    route, scheme,
    violation: {
      id: 'responsive-brand-regression', impact: 'serious',
      help: `Keep content within ${width}px and visible text at least 14px`,
      helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/reflow.html',
      nodes: defects.map((target) => ({ target: [target], failureSummary: '' })),
    },
  });

  return found;
}

/** Text enlargement and spacing must preserve the same narrow-page boundary. */
async function auditTextAdaptation(page, route, scheme) {
  if (scheme !== 'light') return [];
  const defects = [];
  await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
  const enlarged = await page.evaluate(() => ({
    width: document.documentElement.scrollWidth,
    clipped: [...document.querySelectorAll('.capability-card dt, .capability-card dd')].filter((element) => {
      const bounds = element.getBoundingClientRect();
      const card = element.closest('.capability-card').getBoundingClientRect();
      return bounds.right > card.right + 1 || bounds.left < card.left - 1 || element.scrollWidth > element.clientWidth + 1;
    }).map((element) => `Clipped capability content: ${element.textContent.trim().slice(0,60)}`),
  }));
  if (enlarged.width > 321) defects.push('200% text enlargement exceeds the 320px viewport');
  defects.push(...enlarged.clipped);
  await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
  const spacing = await page.addStyleTag({ content: '*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}' });
  if ((await page.locator('html').evaluate((html) => html.scrollWidth)) > 321) defects.push('WCAG text spacing exceeds the 320px viewport');
  await spacing.evaluate((style) => style.remove());
  if (!defects.length) return [];
  return [{ route, scheme, violation: {
    id: 'text-adaptation-regression', impact: 'serious', help: 'Text enlargement and spacing must keep all content available',
    helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html',
    nodes: defects.map((target) => ({ target: [target], failureSummary: '' })),
  } }];
}

async function chooseTheme(page, preference) {
  const picker = page.locator('[data-nr-theme-picker]');
  if (!(await picker.evaluate((element) => element.open))) await picker.locator('summary').click();
  await picker.locator(`input[value="${preference}"]`).check();
}

/** All pages share this preference control; exercise persistence and OS following. */
async function auditThemePreference(page, scheme, route) {
  const opposite = scheme === 'dark' ? 'light' : 'dark';
  const defects = [];
  const background = () => page.locator('body').evaluate((body) => getComputedStyle(body).backgroundColor);
  const before = await background();
  await chooseTheme(page, opposite);
  const after = await background();
  if (before === after) defects.push('Explicit choice did not change colours');
  await page.reload();
  if (!(await page.locator(`[data-nr-theme-option][value="${opposite}"]`).isChecked())) defects.push('Explicit choice did not persist');
  await page.evaluate(() => localStorage.setItem('nrllm-theme', 'dark'));
  await chooseTheme(page, 'system');
  await page.reload();
  const system = await page.evaluate(() => ({
    selected: document.querySelector('[data-nr-theme-option]:checked').value,
    stored: localStorage.getItem('nr-theme'),
  }));
  if (system.selected !== 'system' || system.stored !== 'system' || (await background()) !== before) defects.push('System reset did not override the legacy choice');
  await page.emulateMedia({ colorScheme: opposite });
  await page.waitForFunction((expected) => document.documentElement.dataset.theme === expected, opposite);
  if ((await background()) !== after) defects.push('System choice did not follow a changed OS scheme');
  if (!defects.length) return [];
  return [{ route, scheme, violation: {
    id: 'theme-preference-regression', impact: 'serious', help: 'Appearance choices must persist and System must follow the OS',
    helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/consistent-identification.html',
    nodes: defects.map((target) => ({ target: [target], failureSummary: '' })),
  } }];
}

async function auditShellNavigation(page, route, scheme) {
  const menu = page.locator('[data-nr-mobile-menu]');
  await menu.locator('summary').focus();
  await page.keyboard.press('Enter');
  const opened = await menu.evaluate((element) => element.open);
  await page.keyboard.press('Escape');
  const closedAndFocused = await menu.evaluate((element) => !element.open && document.activeElement === element.querySelector('summary'));
  const local = page.locator('[data-nr-page-menu]');
  let localValid = true;
  if (await local.count()) {
    await local.locator('summary').focus();
    await page.keyboard.press('Enter');
    localValid = await local.locator('a').first().isVisible();
    await page.keyboard.press('Escape');
    localValid &&= await local.evaluate((element) => !element.open && document.activeElement === element.querySelector('summary'));
  }
  if (opened && closedAndFocused && localValid) return [];
  return [{route, scheme, violation: {id: 'shell-navigation', impact: 'serious', help: 'Native mobile navigation must support Enter, Escape and focus return', nodes: [{target: ['[data-nr-mobile-menu]'], failureSummary: ''}]}}];
}

async function auditSearch(page, route, scheme) {
  const input = page.locator('[data-search-input]');
  if (!(await input.count())) return [];
  await input.fill('TYPO3');
  const first = page.locator('[data-search-results] [role="option"]').first();
  await first.waitFor({state: 'visible'});
  await input.press('ArrowDown');
  const selection = await input.evaluate((element) => ({focused: document.activeElement === element, active: element.getAttribute('aria-activedescendant')}));
  const usable = await first.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const shell = document.querySelector('[data-nr-shell]').getBoundingClientRect();
    const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + Math.min(rect.height / 2, 12));
    return rect.top >= shell.bottom && rect.right <= innerWidth && rect.left >= 0 && element.contains(hit);
  });
  const firstId = await first.getAttribute('id');
  await input.press('Escape');
  const closed = await input.evaluate((element) => document.activeElement === element && element.getAttribute('aria-expanded') === 'false');
  await input.fill('');
  if (selection.focused && selection.active === firstId && usable && closed) return [];
  return [{route, scheme, violation: {id: 'product-search', impact: 'serious', help: 'Search results must remain visible, keyboard selectable and close without losing input focus', nodes: [{target: ['[data-search]'], failureSummary: 'Search selection, stacking or Escape focus failed'}]}}];
}

async function auditAnchorTarget(page, route, scheme) {
  if (!['/en/', '/de/'].includes(route)) return [];
  await page.locator('.toc a[href="#solution"]').click();
  // Wait for the anchor to reach its final CSS offset, including smooth scrolling.
  await page.waitForFunction(() => {
    const padding = Number.parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
    return Math.abs(document.querySelector('#solution').getBoundingClientRect().top - padding) < 2;
  });
  const visible = await page.locator('#solution h2').evaluate((heading) => {
    const rect = heading.getBoundingClientRect();
    const header = document.querySelector('[data-nr-shell]').getBoundingClientRect();
    return rect.top >= header.bottom && heading.contains(document.elementFromPoint(rect.left + 4, rect.top + 4));
  });
  if (visible) return [];
  return [{route, scheme, violation: {id: 'anchor-target', impact: 'serious', help: 'Anchor headings must remain visible below the sticky header', nodes: [{target: ['#solution h2'], failureSummary: 'The anchored heading is obscured'}]}}];
}

/** Provider marks must retain visible ink when printed on white paper. */
async function auditPrintedProviders(page) {
  return page.locator('.provider-row img').evaluateAll((images) => images.flatMap((image) => {
    if (!image.complete || !image.naturalWidth || !image.checkVisibility()) {
      return [`provider image is unloaded or hidden: ${image.alt}`];
    }
    const style = getComputedStyle(image);
    const canvas = document.createElement('canvas');
    canvas.width = 64;
    canvas.height = 64;
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, 64, 64);
    context.filter = style.filter;
    context.globalAlpha = Number(style.opacity);
    context.drawImage(image, 0, 0, 64, 64);
    const pixels = context.getImageData(0, 0, 64, 64).data;
    const linear = (value) => {
      const channel = value / 255;
      return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    };
    for (let offset = 0; offset < pixels.length; offset += 4) {
      const luminance = 0.2126 * linear(pixels[offset]) + 0.7152 * linear(pixels[offset + 1]) + 0.0722 * linear(pixels[offset + 2]);
      if (1.05 / (luminance + 0.05) >= 3) return [];
    }
    return [`provider image has no visible ink on paper: ${image.alt}`];
  }));
}

/** Print must be readable and hide controls for explicit and OS preferences. */
async function auditPrint(page, route, scheme) {
  const defects = [];
  // The same page changes preference/media; each transition must finish first.
  await [scheme, 'system'].reduce((sequence, selection) => sequence.then(async () => {
    await page.emulateMedia({ media: 'screen', colorScheme: scheme });
    await chooseTheme(page, selection);
    await page.emulateMedia({ media: 'print' });
    const state = await page.evaluate(() => ({
      background: getComputedStyle(document.body).backgroundColor,
      heading: getComputedStyle(document.querySelector('h1')).color,
      controls: [...document.querySelectorAll('.nr-shell, .nr-pagebar, .ai, .search, .copy-btn')]
        .filter((element) => element.checkVisibility()).map((element) => element.className),
      footerBackground: getComputedStyle(document.querySelector('.site-footer')).backgroundColor,
    }));
    if (state.background !== 'rgb(255, 255, 255)' || state.heading !== 'rgb(0, 0, 0)' || state.footerBackground !== 'rgb(255, 255, 255)') {
      defects.push(`${selection}: paper background or heading colours were overridden`);
    }
    defects.push(...state.controls.map((control) => `${selection}: interactive print control: ${control}`));
    defects.push(...(await auditPrintedProviders(page)).map((defect) => `${selection}: ${defect}`));
  }), Promise.resolve());
  const results = await new AxeBuilder({ page }).options({
    runOnly: { type: 'tag', values: TAGS },
    rules: { 'color-contrast-enhanced': { enabled: true } },
  }).analyze();
  const found = results.violations.map((violation) => ({ route: `${route} (print)`, scheme, violation }));
  if (defects.length) found.push({ route, scheme, violation: {
    id: 'print-regression', impact: 'serious', help: 'Paper colours and hidden controls must survive every screen preference',
    helpUrl: 'https://www.w3.org/WAI/WCAG22/Understanding/contrast-enhanced.html',
    nodes: defects.map((target) => ({ target: [target], failureSummary: '' })),
  } });
  return found;
}

/** Audits one page and returns the failures found on it. */
async function auditPage(browser, port, route, scheme) {
  const context = await browser.newContext({
    colorScheme: scheme,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();

  // A page whose stylesheet 404s has no contrast failures at all, so a silent
  // asset error turns this gate into a rubber stamp. Any request that does not
  // succeed is therefore a failure in its own right.
  const broken = [];
  page.on('response', (response) => {
    if (response.status() >= 400) broken.push(`${response.status()} ${response.url()}`);
  });

  await page.goto(`http://127.0.0.1:${port}${BASE}${route.slice(1)}`, { waitUntil: 'networkidle' });

  await page.evaluate(() => document.fonts.ready);
  const results = await new AxeBuilder({ page }).options({
    runOnly: { type: 'tag', values: TAGS },
    rules: { 'color-contrast-enhanced': { enabled: true } },
  }).analyze();
  const found = results.violations.map((violation) => ({ route, scheme, violation }));

  // Resizes share a page and are deliberately chained, not parallelized.
  await [390, 320].reduce((sequence, width) => sequence.then(async () => {
    found.push(...(await auditResponsiveViewport(page, width, route, scheme)));
  }), Promise.resolve());
  // Argument evaluation finishes text adaptation before changing the theme.
  found.push(
    ...(await auditTextAdaptation(page, route, scheme)),
    ...(await auditThemePreference(page, scheme, route)),
    ...(await auditShellNavigation(page, route, scheme)),
    ...(await auditSearch(page, route, scheme)),
    ...(await auditAnchorTarget(page, route, scheme)),
    ...(await auditPrint(page, route, scheme)),
  );
  await context.close();
  if (broken.length) {
    found.unshift({
      route,
      scheme,
      violation: {
        id: 'asset-not-served',
        impact: 'critical',
        help: 'Every asset the page requests must be served — an unstyled page passes for the wrong reason',
        helpUrl: 'https://github.com/netresearch/t3x-nr-llm/blob/main/landingpage/axe-audit.mjs',
        nodes: broken.map((entry) => ({ target: [entry], failureSummary: '' })),
      },
    });
  }
  return found;
}

async function auditNoJavaScript(browser, port, routes) {
  const failures = [];
  // Separate contexts keep this progressive-enhancement probe isolated.
  await routes.reduce((sequence, route) => sequence.then(async () => {
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 320, height: 900}});
    const page = await context.newPage();
    await page.goto(`http://127.0.0.1:${port}${BASE}${route.slice(1)}`);
    await page.locator('.skip-link').focus();
    // Poll from the Node test host: page rAF callbacks are disabled in this context.
    await expect.poll(() => page.locator('.skip-link').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.top >= 0 && element.contains(document.elementFromPoint(rect.left + 8, rect.top + 8));
    })).toBe(true);
    const local = page.locator('[data-nr-page-menu]');
    if (await local.count()) {
      await local.locator('summary').focus();
      await page.keyboard.press('Enter');
      await expect(local.locator('a').first()).toBeVisible();
      await page.keyboard.press('Enter');
    }
    const menu = page.locator('[data-nr-mobile-menu]');
    await menu.locator('summary').focus();
    await page.keyboard.press('Enter');
    const valid = await menu.locator('nav a').first().isVisible()
      && await page.locator('.nr-shell-locales').isVisible()
      && !(await page.locator('[data-nr-theme-picker]').isVisible())
      && await page.evaluate(() => document.documentElement.scrollWidth === innerWidth);
    if (!valid) failures.push({route, scheme: 'no-js/320px', violation: {
      id: 'native-navigation', impact: 'serious', help: 'Native navigation and locales must work without JavaScript',
      nodes: [{target: ['[data-nr-shell]'], failureSummary: 'Unavailable links, inert appearance control or document overflow'}],
    }});
    await context.close();
  }), Promise.resolve());
  return failures;
}

async function main() {
  const files = indexFiles(DIST);
  const routes = htmlRoutes(files);
  if (routes.length === 0) throw new Error(`no pages found in ${DIST} — run landingpage/build/build.py first`);
  const { server, port } = await serve(files);

  // A gate that silently covers a subset reads as if it covered everything.
  const sampled = sampleRoutes(routes);
  for (const { shape, route, skipped } of sampled) {
    if (skipped) {
      console.log(`  ${shape} → auditing ${route} (${skipped} further page(s) of this shape not audited)`);
    }
  }

  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  const browser = await chromium.launch(executablePath ? { executablePath } : {});

  const failures = [];
  let checked = 0;

  for (const { route } of sampled) {
    for (const scheme of SCHEMES) {
      failures.push(...(await auditPage(browser, port, route, scheme)));
      checked += 1;
    }
  }

  failures.push(...(await auditNoJavaScript(browser, port, ['/', '/en/', '/de/', '/adr/'])));
  await browser.close();
  server.close();

  for (const failure of failures) report(failure);

  if (failures.length) {
    console.error(
      `\naxe: ${failures.length} violation(s) across ${checked} page renders (${sampled.length} route shapes × ${SCHEMES.length} colour schemes, sampled from ${routes.length} pages)`,
    );
    process.exit(1);
  }

  console.log(
    `axe: automated WCAG 2.2 AA, enhanced contrast, 14px type and 320/390px reflow checks passed in ${checked} page renders (${sampled.length} route shapes × ${SCHEMES.length} colour schemes, sampled from ${routes.length} pages) served from ${BASE}; manual conformance review remains separate`,
  );
}

await main();
