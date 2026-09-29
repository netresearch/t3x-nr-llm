/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Drives Build/Scripts/check-backend-colors.mjs against throwaway trees.
 * Run: node --test Build/Scripts/check-backend-colors.test.mjs
 *
 * Each refusal case is a shape that shipped and broke the dark (or light)
 * scheme, or a form a review round found the regular-expression version of
 * this check missing: `btn-secondary` drawn as a dark tile, `text-bg-success`
 * at 3.6:1, a `bg-light` card under light text, an unguarded
 * prefers-color-scheme block, `text-body-secondary` that core does not
 * define, named colours in JavaScript, Chart.js options and custom
 * properties, icons, declarations and assignments split across lines,
 * template literals, `@property`, SVG animation, `srcdoc`, and table rows in
 * a partial. The first 71 cases are the PHP test this file replaces.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const SCRIPT = join(dirname(fileURLToPath(import.meta.url)), 'check-backend-colors.mjs');

function check(files) {
    const root = mkdtempSync(join(tmpdir(), 'nrllm-colors-check-'));
    try {
        for (const [file, content] of Object.entries(files)) {
            mkdirSync(join(root, dirname(file)), { recursive: true });
            writeFileSync(join(root, file), content);
        }
        const run = spawnSync(process.execPath, [SCRIPT, root], { encoding: 'utf8' });
        return { exit: run.status, stderr: run.stderr };
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
}

const CASES = JSON.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'check-backend-colors.cases.json'), 'utf8'));
const REFUSED = CASES.refused;
const REFUSED_BY_PARSING = CASES.refusedByParsing;
const ACCEPTED = CASES.accepted;


for (const [name, file, content, rule] of REFUSED) {
    test(`refuses: ${name}`, () => {
        const { exit, stderr } = check({ [file]: content });
        assert.equal(exit, 1, stderr);
        assert.ok(stderr.includes(`${file}:1  ${rule}:`), stderr);
    });
}

for (const [name, file, content, line, rule] of REFUSED_BY_PARSING) {
    test(`refuses: ${name}`, () => {
        const { exit, stderr } = check({ [file]: content });
        assert.equal(exit, 1, stderr);
        assert.ok(stderr.includes(`${file}:${line}  ${rule}:`), stderr);
    });
}

for (const [name, file, content] of ACCEPTED) {
    test(`accepts: ${name}`, () => {
        const { exit, stderr } = check({ [file]: content });
        assert.equal(exit, 0, stderr);
    });
}

test('an exemption covers exactly one statement', () => {
    const file = 'Resources/Public/JavaScript/Backend/a.js';
    const { exit, stderr } = check({ [file]: "// scheme-independent: a light page on purpose\nframe.style.background = '#fff';\nel.style.color = '#888';\n" });
    assert.equal(exit, 1, stderr);
    assert.ok(stderr.includes(`${file}:3  colour literal:`), stderr);
    assert.ok(!stderr.includes(`${file}:2`), stderr);
});

test('an exemption covers a statement over several lines', () => {
    const file = 'Resources/Public/JavaScript/Backend/a.js';
    const { exit, stderr } = check({ [file]: "// scheme-independent: a light page on purpose\nframe.srcdoc = [\n    '<style>body{color:black}</style>',\n    'pre{background:#f5f5f5}',\n].join('');\nel.style.color = 'red';\n" });
    assert.equal(exit, 1, stderr);
    assert.ok(stderr.includes(`${file}:6  named colour in JavaScript:`), stderr);
    assert.ok(!/a\.js:[2-5]  /.test(stderr), stderr);
});

test('an exemption in a CSS rule covers one declaration', () => {
    const file = 'Resources/Public/Css/Backend/a.css';
    const { exit, stderr } = check({ [file]: ".a {\n    /* scheme-independent: a light page */\n    background: #fff;\n    color: #333;\n}\n" });
    assert.equal(exit, 1, stderr);
    assert.ok(stderr.includes(`${file}:4  colour literal:`), stderr);
    assert.ok(!stderr.includes(`${file}:3`), stderr);
});

test('an exemption above a CSS rule ends with that rule', () => {
    const file = 'Resources/Public/Css/Backend/a.css';
    const { exit, stderr } = check({ [file]: "/* scheme-independent: a light page */\n.a { background: #fff; }\n.b { color: #333; }\n" });
    assert.equal(exit, 1, stderr);
    assert.ok(stderr.includes(`${file}:3  colour literal:`), stderr);
    assert.ok(!stderr.includes(`${file}:2`), stderr);
});

test('an exemption in a template covers the next element', () => {
    const file = 'Resources/Private/Templates/a.html';
    const { exit, stderr } = check({ [file]: '<!-- scheme-independent: a preview of printed output -->\n<div style="color: #000">x</div>\n<div style="color: #111">y</div>\n' });
    assert.equal(exit, 1, stderr);
    assert.ok(stderr.includes(`${file}:3  colour literal:`), stderr);
    assert.ok(!stderr.includes(`${file}:2`), stderr);
});
