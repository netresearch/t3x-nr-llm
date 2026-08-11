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
 * Exits non-zero on any WCAG 2.1 AA violation. Uses Playwright's Chromium
 * because the repository already installs it for the end-to-end tests; adding a
 * second browser stack for one gate would not buy anything.
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
import { existsSync, readdirSync, statSync } from 'node:fs';
import { join, extname, relative } from 'node:path';
import { createRequire } from 'node:module';
import { chromium } from '@playwright/test';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core/axe.min.js');

const OUTPUT = process.argv[2] ?? 'landingpage/public';

/** The path the site is served from, with exactly one slash at each end. */
const BASE = `/${(process.argv[3] ?? process.env.PAGES_BASE_PATH ?? '/').replace(/^\/+|\/+$/g, '')}/`
  .replace(/^\/\/$/, '/');

// WCAG 2.1 AA, which is what the page claims. 'best-practice' is deliberately
// left out: it flags stylistic preferences, and a gate that fails on those gets
// disabled instead of fixed.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

// Both colour schemes: a dark palette is a separate set of colour pairs, so a
// light-only audit says nothing about it.
const SCHEMES = ['light', 'dark'];

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.woff2': 'font/woff2',
  '.xml': 'application/xml; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
};

/**
 * Every HTML page below the output directory, as the URL path a visitor uses.
 *
 * Not only index.html: the 152 ADR pages are named after their ADR, and a
 * discovery that looks for index.html alone silently audits eight pages out of
 * a hundred and sixty.
 */
function htmlRoutes(dir, base = dir) {
  const routes = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      routes.push(...htmlRoutes(full, base));
    } else if (entry === 'index.html') {
      const path = `/${relative(base, dir)}`.replace(/\/$/, '');
      routes.push(path === '/' || path === '/.' ? '/' : `${path}/`);
    } else if (entry.endsWith('.html')) {
      routes.push(`/${relative(base, full)}`);
    }
  }
  return routes.sort();
}

/**
 * The route shape a page belongs to: a named page collapses to '*.html'. Every
 * ADR is the same template with different prose, so auditing all 152 costs ten
 * minutes per deploy and finds what the first one already found.
 */
function routeShape(route) {
  return route.replace(/\/[^/]+\.html$/, '/*.html');
}

/** One representative route per shape, plus what that left out. */
function sample(routes) {
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

function serve(root) {
  const server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const path = decodeURIComponent(url.pathname);
    if (!path.startsWith(BASE)) {
      res.writeHead(404).end('outside the base path');
      return;
    }
    let file = join(root, path.slice(BASE.length));
    if (existsSync(file) && statSync(file).isDirectory()) file = join(file, 'index.html');
    if (!file.startsWith(root) || !existsSync(file)) {
      res.writeHead(404).end('not found');
      return;
    }
    res.writeHead(200, { 'content-type': MIME[extname(file)] ?? 'application/octet-stream' });
    res.end(await readFile(file));
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve({ server, port: server.address().port }));
  });
}

async function main() {
  const { server, port } = await serve(OUTPUT);
  const routes = htmlRoutes(OUTPUT);
  if (routes.length === 0) {
    throw new Error(`no pages found in ${OUTPUT} — run landingpage/build/build.py first`);
  }

  const browser = await chromium.launch();
  const failures = [];
  let checked = 0;

  const sampled = sample(routes);
  for (const { shape, route, skipped } of sampled) {
    if (skipped) {
      console.log(`  ${shape} → auditing ${route} (${skipped} further page(s) of this shape not audited)`);
    }
  }

  for (const { route } of sampled) {
    for (const scheme of SCHEMES) {
      const context = await browser.newContext({
        colorScheme: scheme,
        viewport: { width: 1280, height: 900 },
      });
      const page = await context.newPage();
      await page.goto(`http://127.0.0.1:${port}${BASE}${route.slice(1)}`, { waitUntil: 'networkidle' });
      await page.addScriptTag({ path: axePath });

      const results = await page.evaluate(
        async (tags) => await window.axe.run(document, { runOnly: { type: 'tag', values: tags } }),
        TAGS,
      );

      checked += 1;
      for (const violation of results.violations) failures.push({ route, scheme, violation });
      await context.close();
    }
  }

  await browser.close();
  server.close();

  for (const { route, scheme, violation } of failures) {
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

  if (failures.length) {
    console.error(
      `\naxe: ${failures.length} violation(s) across ${checked} page renders (${sampled.length} route shapes × ${SCHEMES.length} colour schemes, sampled from ${routes.length} pages)`,
    );
    process.exit(1);
  }

  console.log(
    `axe: no WCAG 2.1 AA violations in ${checked} page renders (${sampled.length} route shapes × ${SCHEMES.length} colour schemes, sampled from ${routes.length} pages) served from ${BASE}`,
  );
}

await main();
