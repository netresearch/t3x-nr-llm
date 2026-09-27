#!/usr/bin/env node
/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Refuse colour in the backend UI that ignores the backend colour scheme.
 *
 * TYPO3 13.4 and 14.3 define every `--typo3-*` colour once with light-dark()
 * and select the scheme from html[data-color-scheme]; the core classes
 * (`badge-*`, `btn-default`, `callout-*`, `alert-*`) are built on those
 * variables. Colour that bypasses them breaks one of the two schemes, and
 * nothing but a person looking at the page in both notices. Measured on
 * 2026-09-27: 245 axe contrast violations in dark and 27 in light across the
 * nr-llm views.
 *
 * The files are parsed, not pattern-matched: JavaScript with espree (the
 * parser ESLint uses), CSS with css-tree, templates and SVG with parse5 (the
 * WHATWG HTML parser, which reads SVG as foreign content). CSS and HTML that
 * live inside JavaScript strings, attributes, `<style>` elements, `srcdoc`
 * and data URIs are parsed the same way. The regular-expression version of
 * this check lost three review rounds in a row to forms it could not see.
 *
 * Scanned: Fluid templates and partials, backend CSS, backend JavaScript
 * (not the vendored Chart.js), and the icons in Resources/Public/Icons.
 * Comments are ignored. Refused:
 *
 *  1. colour literals — hex (also `%23…` in a data URI), rgb()/rgba()/hsl()/
 *     hsla()/hwb()/lab()/lch()/oklab()/oklch()/color() — in any CSS value, in
 *     a colour attribute, and in any JavaScript string; and any of the 148
 *     CSS named colours as the value of a property that carries colour
 *     (color, background, border, outline, fill, stroke, filter, box-shadow,
 *     text-shadow, caret-color, accent-color, text-decoration, column-rule,
 *     …), of a custom property (`--x: black`) or of `@property …
 *     initial-value`. In JavaScript: the value assigned to a colour property
 *     (`el.style.color = …`, `el.style['color'] = …`, `ctx.fillStyle = …`),
 *     given to a colour key of an object (Chart.js options, `Object.assign(
 *     el.style, …)`), or passed to setProperty() / setAttribute() — read
 *     through arrays, `? :`, `||`, `??`, string concatenation, `.join()` of
 *     literals and template literals, across any number of lines. Any string
 *     that holds HTML is parsed as HTML, `cssText` and a `style` attribute as
 *     CSS. In templates and SVG: colour attributes (`fill`, `stroke`,
 *     `stop-color`, `color`, `bgcolor`, …), `<animate>`/`<set>` values,
 *     `<meta name="theme-color">`, and `srcdoc`, which is parsed as HTML. A
 *     value inside light-dark() carries both schemes and passes;
 *  2. `var(--bs-…)`: TYPO3 never sets data-bs-theme, so Bootstrap's variables
 *     keep their light values in the dark scheme;
 *  3. own scheme switches — `@media (prefers-color-scheme` and
 *     `[data-color-scheme` selectors: an unguarded media query renders dark
 *     for a user who chose Light while the OS is dark;
 *  4. Bootstrap classes that core's backend CSS does not define, or defines
 *     for one scheme only: `text-bg-*`, `bg-light|white|dark|body*|*-subtle`,
 *     `text-dark|white|black|light`, `text-body*` (not defined on 13.4.35 or
 *     14.3.7, so it does nothing — core's muted text is `text-variant`),
 *     `table-light|dark`, `btn-light|dark|secondary`, `btn-outline-*` (not
 *     defined on either), `alert-light|dark`, and a Bootstrap `bg-*` colour
 *     on a badge. Core uses `badge badge-*` and `btn-default` (v14.3.7: 585
 *     btn-default, 2 btn-secondary in templates). `alert-*` itself is allowed:
 *     13.4.35 and 14.3.7 define alert-default|primary|secondary|info|notice|
 *     success|warning|danger on the surface-container tokens, and core's own
 *     templates use them (14 uses at v14.3.7);
 *  5. in an icon (every SVG except `*.legacy.svg`, the v13 teal tiles, and
 *     Extension.svg, the full-colour extension tile), any fill, stroke,
 *     stop-color, color, flood-color or lighting-color other than
 *     `currentColor`, `none` or `var(--nr-icon-accent, <fallback>)` — as an
 *     attribute, in a style attribute, in the icon's own <style>, or as an
 *     `<animate>`/`<set>` value — and any colour literal outside the accent's
 *     fallback: an icon is drawn in the text colour so it follows the scheme.
 *
 * Not detected: a colour held in a variable and assigned later
 * (`const c = 'black'; … el.style.color = c;`). Seeing it needs data-flow
 * tracking across statements and functions, which a parser alone does not
 * give; the check reads the expression at the point of use.
 *
 * Exemption: a comment containing `scheme-independent: <reason>` exempts the
 * value rules in 1 for exactly one syntax node — the statement, object
 * property, CSS declaration or rule on the comment's own line if there is
 * code there, otherwise the first such node after it; in a template the next
 * element's start tag.
 *
 * Usage: node Build/Scripts/check-backend-colors.mjs [root]
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, extname, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as espree from 'espree';
import * as csstree from 'css-tree';
import { Tokenizer, TokenizerMode } from 'parse5';

const ROOT = resolve(process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '..', '..'));

const SOURCES = [
    ['Resources/Private', '.html'],
    ['Resources/Public/Css', '.css'],
    ['Resources/Public/JavaScript/Backend', '.js'],
    ['Resources/Public/Icons', '.svg'],
];
/** Icons that are not drawn in currentColor, and why. */
const ICON_EXCEPTIONS = new Set([
    // The extension tile: its own teal background with white and orange
    // marks, right on either scheme (Resources/AGENTS.md).
    'Extension.svg',
]);

const NAMED_COLOURS = new Set(`aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue
blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue
darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid
darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink
deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold
goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush
lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey
lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime
limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen
mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin
navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise
palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue
saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow
springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen`.split(/\s+/));
const COLOUR_FUNCTIONS = new Set(['rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color']);

// A property that carries colour, in CSS spelling (`box-shadow`) and in the
// DOM's camelCase spelling (`boxShadow`, `fillStyle`).
const CSS_COLOUR_PROPERTY = /^(?:[a-z-]*-)?(?:color|background|border|outline|fill|stroke|shadow|filter|text-decoration|column-rule|text-emphasis)(?:-[a-z]+)*$/i;
const JS_COLOUR_KEY = /^(?:[a-z][A-Za-z]*(?:Color|Background|Border|Shadow|Fill|Stroke|Style)|(?:color|background|border|outline|fill|stroke|filter|boxShadow|textShadow|caretColor|accentColor|textDecoration|columnRule|textEmphasis|stopColor|floodColor|lightingColor|scrollbarColor|fillStyle|strokeStyle|shadowColor)[A-Za-z]*)$/;
const COLOUR_ATTRIBUTES = new Set(['fill', 'stroke', 'stop-color', 'flood-color', 'lighting-color', 'color', 'bgcolor']);
const PAINT_ATTRIBUTES = new Set(['fill', 'stroke', 'stop-color', 'color', 'flood-color', 'lighting-color']);
const ANIMATION_VALUE_ATTRIBUTES = new Set(['to', 'from', 'values', 'by']);
// Attributes that hold a URL: `href="#abc"` is a fragment, not a colour.
const URL_ATTRIBUTES = new Set(['href', 'src', 'xlink:href', 'action', 'formaction', 'poster', 'data', 'cite']);
const ALLOWED_PAINT = /^(?:currentcolor|none|var\(\s*--nr-icon-accent\s*,\s*#[0-9a-f]{3,8}\s*\))$/i;

// Token rules: exact class names and tokens, read from the comment-free text.
const TOKEN_RULES = [
    ['Bootstrap variable', /var\(--bs-/],
    ['own scheme switch', /@media\s*\(\s*prefers-color-scheme|\[data-color-scheme/],
    ['scheme-pinned class', /(?<![\w-])(?:text-bg-[a-z]+|bg-(?:light|white|dark|body(?:-[a-z]+)?|[a-z]+-subtle)|text-(?:dark|white|black|light)|text-body(?:-[a-z]+)?|table-(?:light|dark)|btn-(?:light|dark|secondary)|btn-outline-[a-z]+|alert-(?:light|dark))(?![\w-])/],
    ['Bootstrap colour on a badge', /\bbadge\b[^"'`>]*(?<![\w-])bg-(?:primary|secondary|success|info|warning|danger)(?![\w-])/],
];
const EXEMPTABLE = new Set(['colour literal', 'named colour', 'named colour in a custom property', 'named colour in JavaScript', 'colour attribute']);
const PLACEHOLDER = 'nrllmexpr';

/* ------------------------------------------------------------------ files */

function collectFiles() {
    const files = [];
    for (const [dir, extension] of SOURCES) {
        const base = join(ROOT, dir);
        if (!existsSync(base)) {
            continue;
        }
        const walk = (path) => {
            for (const entry of readdirSync(path)) {
                const full = join(path, entry);
                if (statSync(full).isDirectory()) {
                    walk(full);
                } else if (extname(entry) === extension) {
                    if (extension === '.svg' && (entry.endsWith('.legacy.svg') || ICON_EXCEPTIONS.has(entry))) {
                        continue;
                    }
                    files.push(full);
                }
            }
        };
        walk(base);
    }
    // Code-unit order, not the locale's: the report reads the same everywhere.
    return files.sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
}

/* --------------------------------------------------------------- reporting */

class Report {
    constructor(file, source) {
        this.file = relative(ROOT, file);
        this.lines = source.split('\n');
        this.exempt = new Set();
        this.found = new Map();
    }

    add(line, rule, detail = null) {
        if (EXEMPTABLE.has(rule) && this.exempt.has(line)) {
            return;
        }
        const key = `${line}\u0000${rule}`;
        if (!this.found.has(key)) {
            this.found.set(key, { line, rule, detail });
        }
    }

    exemptRange(from, to) {
        for (let line = from; line <= to; line++) {
            this.exempt.add(line);
        }
    }

    render() {
        return [...this.found.values()]
            .sort((a, b) => a.line - b.line || a.rule.localeCompare(b.rule))
            .map(({ line, rule, detail }) => `${this.file}:${line}  ${rule}: ${detail ?? (this.lines[line - 1] ?? '').trim()}`);
    }
}

/** Blank the given [start, end) ranges, keeping newlines so lines survive. */
function blank(source, ranges) {
    const chars = [...source];
    for (const [start, end] of ranges) {
        for (let i = start; i < end && i < chars.length; i++) {
            if (chars[i] !== '\n') {
                chars[i] = ' ';
            }
        }
    }
    return chars.join('');
}

function tokenRules(report, text) {
    text.split('\n').forEach((line, index) => {
        for (const [rule, pattern] of TOKEN_RULES) {
            if (pattern.test(line)) {
                report.add(index + 1, rule);
            }
        }
    });
}

/* --------------------------------------------------------------------- CSS */

/**
 * Colour findings in a parsed CSS value: literals always, named colours when
 * `named` (the value belongs to a colour-carrying property).
 */
function checkValueAst(value, { named, onLiteral, onNamed, onDataUri, accent = false }) {
    csstree.walk(value, {
        enter(node) {
            if (node.type === 'Function') {
                const name = node.name.toLowerCase();
                if (name === 'light-dark') {
                    return this.skip;
                }
                // An icon's accent: var(--nr-icon-accent, <fallback>).
                if (accent && name === 'var' && node.children.first?.name === '--nr-icon-accent') {
                    return this.skip;
                }
                if (COLOUR_FUNCTIONS.has(name)) {
                    onLiteral(node);
                    return this.skip;
                }
            } else if (node.type === 'Hash') {
                onLiteral(node);
            } else if (node.type === 'Identifier') {
                if (named && NAMED_COLOURS.has(node.name.toLowerCase())) {
                    onNamed(node);
                }
            } else if (node.type === 'Url') {
                onDataUri?.(node);
            } else if (node.type === 'Raw') {
                // Unparsed text (Fluid `{…}` expressions): read its literals.
                rawLiterals(node.value, () => onLiteral(node));
            }
            return undefined;
        },
    });
}

/** Colour literals in text that is not CSS (a JS string, an HTML attribute). */
function rawLiterals(text, onLiteral) {
    const stripped = stripLightDark(text).replace(/var\(\s*--nr-icon-accent\s*,\s*#[0-9a-fA-F]{3,8}\s*\)/g, '');
    if (/(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b|%23(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![0-9a-zA-Z])|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(|(?<![\w.-])color\(/.test(stripped)) {
        onLiteral();
    }
}

/** Remove light-dark(…) calls, bracket-balanced. */
function stripLightDark(text) {
    let out = '';
    let i = 0;
    const lower = text.toLowerCase();
    while (i < text.length) {
        const at = lower.indexOf('light-dark(', i);
        if (at === -1) {
            out += text.slice(i);
            break;
        }
        out += text.slice(i, at);
        let depth = 0;
        let j = at + 'light-dark'.length;
        for (; j < text.length; j++) {
            if (text[j] === '(') depth++;
            else if (text[j] === ')' && --depth === 0) break;
        }
        i = j + 1;
    }
    return out;
}

function lineOf(node, fallback) {
    return node?.loc?.start?.line ?? fallback;
}

/**
 * Check CSS source. context: 'stylesheet' or 'declarationList'.
 * `line`/`column` place the fragment inside its file.
 */
function checkCss(report, source, { context = 'stylesheet', line = 1, column = 1, icon = false, exemptions = true, fallbackLine = line } = {}) {
    const comments = [];
    let ast;
    try {
        ast = csstree.parse(source, {
            context,
            positions: true,
            line,
            column,
            parseCustomProperty: true,
            onParseError: () => {},
            onComment: (value, loc) => comments.push({ value, loc }),
        });
    } catch {
        return;
    }

    const exemptNodes = [];
    csstree.walk(ast, {
        enter(node) {
            if ((node.type === 'Declaration' || node.type === 'Rule' || node.type === 'Atrule') && node.loc) {
                exemptNodes.push(node);
            }
        },
    });
    if (exemptions) {
        for (const comment of comments) {
            if (!comment.value.includes('scheme-independent:')) {
                continue;
            }
            const target = exemptionTarget(exemptNodes, comment.loc, report.lines);
            if (target) {
                report.exemptRange(target.loc.start.line, target.loc.end.line);
            }
        }
    }

    csstree.walk(ast, {
        enter(node) {
            if (node.type === 'Atrule' && node.name.toLowerCase() === 'media' && node.prelude
                && /prefers-color-scheme/i.test(csstree.generate(node.prelude))) {
                report.add(lineOf(node, fallbackLine), 'own scheme switch');
            }
            if (node.type === 'AttributeSelector' && node.name?.name?.toLowerCase() === 'data-color-scheme') {
                report.add(lineOf(node, fallbackLine), 'own scheme switch');
            }
            if (node.type !== 'Declaration') {
                return;
            }
            const property = node.property.toLowerCase();
            const inPropertyRule = this.atrule?.name?.toLowerCase() === 'property';
            const custom = property.startsWith('--');
            const where = lineOf(node, fallbackLine);
            if (icon && PAINT_ATTRIBUTES.has(property)) {
                const text = csstree.generate(node.value).trim();
                if (!ALLOWED_PAINT.test(text)) {
                    report.add(where, 'icon paint', `${property}: ${text} (allowed: currentColor, none, var(--nr-icon-accent, …))`);
                }
                return;
            }
            const named = custom || inPropertyRule || CSS_COLOUR_PROPERTY.test(property);
            const namedRule = custom || inPropertyRule ? 'named colour in a custom property' : 'named colour';
            checkValueAst(node.value, {
                named,
                accent: icon,
                onLiteral: (n) => report.add(lineOf(n, where), 'colour literal'),
                onNamed: (n) => report.add(lineOf(n, where), namedRule),
                onDataUri: (n) => checkDataUri(report, n.value, lineOf(n, where), icon),
            });
        },
    });
}

/** Find the node a `scheme-independent:` comment exempts. */
function exemptionTarget(nodes, loc, lines) {
    const line = loc.start.line;
    const before = (lines[line - 1] ?? '').slice(0, Math.max(0, loc.start.column - 1)).trim();
    const after = (lines[loc.end.line - 1] ?? '').slice(loc.end.column - 1).trim();
    const onSameLine = before !== '' || (after !== '' && !after.startsWith('*/'));
    if (onSameLine) {
        // The innermost node that covers the comment's line.
        const covering = nodes.filter((n) => n.loc.start.line <= line && n.loc.end.line >= line);
        covering.sort((a, b) => (a.loc.end.line - a.loc.start.line) - (b.loc.end.line - b.loc.start.line));
        return covering[0] ?? null;
    }
    // Otherwise the first node that starts after the comment, outermost first.
    const after2 = nodes.filter((n) => n.loc.start.offset >= loc.end.offset);
    after2.sort((a, b) => a.loc.start.offset - b.loc.start.offset || b.loc.end.offset - a.loc.end.offset);
    return after2[0] ?? null;
}

function checkDataUri(report, url, line, icon) {
    if (!/^data:image\/svg\+xml/i.test(url)) {
        return;
    }
    const comma = url.indexOf(',');
    let markup = url.slice(comma + 1);
    try {
        markup = /;base64,/i.test(url.slice(0, comma + 1)) ? Buffer.from(markup, 'base64').toString('utf8') : decodeURIComponent(markup);
    } catch {
        // Leave it encoded; the raw literal scan below still reads `%23…`.
    }
    rawLiterals(url, () => report.add(line, 'colour literal'));
    checkHtml(report, markup, { line, flat: true, icon });
}

/* -------------------------------------------------------------- HTML / SVG */

// Elements whose content the tokenizer must read as text, as a browser does.
const TEXT_MODE = new Map([
    ['style', TokenizerMode.RAWTEXT],
    ['xmp', TokenizerMode.RAWTEXT],
    ['script', TokenizerMode.SCRIPT_DATA],
    ['textarea', TokenizerMode.RCDATA],
    ['title', TokenizerMode.RCDATA],
]);

/**
 * Walk HTML as tokens. The tokenizer, not the tree builder: a Fluid partial
 * holding `<tr>`/`<td>` rows without a `<table>` keeps every tag and attribute,
 * where HTML tree construction would drop them. `<f:comment>` content and
 * HTML comments are skipped and reported to `onComment`.
 */
function scanHtml(source, { onStartTag, onStyleText, onComment }) {
    let fluidCommentDepth = 0;
    let fluidCommentStart = null;
    let styleText = null;
    const handler = {
        onStartTag(token) {
            const tag = token.tagName.toLowerCase();
            if (tag === 'f:comment') {
                if (fluidCommentDepth++ === 0) fluidCommentStart = token.location;
                return;
            }
            if (fluidCommentDepth > 0) return;
            onStartTag(tag, token);
            const mode = TEXT_MODE.get(tag);
            if (mode !== undefined && !token.selfClosing) {
                tokenizer.state = mode;
                tokenizer.lastStartTagName = tag;
                if (tag === 'style') styleText = { text: '', location: null };
            }
        },
        onEndTag(token) {
            const tag = token.tagName.toLowerCase();
            if (tag === 'f:comment' && fluidCommentDepth > 0) {
                if (--fluidCommentDepth === 0) onComment?.({ ...fluidCommentStart, endOffset: token.location.endOffset, fluid: true });
                return;
            }
            if (tag === 'style' && styleText) {
                onStyleText?.(styleText.text, styleText.location);
                styleText = null;
            }
        },
        onComment(token) {
            if (fluidCommentDepth === 0) onComment?.({ ...token.location, data: token.data });
        },
        onCharacter(token) {
            if (styleText) {
                styleText.location ??= token.location;
                styleText.text += token.chars;
            }
        },
        onWhitespaceCharacter(token) {
            handler.onCharacter(token);
        },
        onNullCharacter() {},
        onDoctype() {},
        onEof() {},
        onParseError: null,
    };
    const tokenizer = new Tokenizer({ sourceCodeLocationInfo: true }, handler);
    tokenizer.write(source, true);
}

function attr(token, name) {
    return token.attrs.find((a) => a.name.toLowerCase() === name.toLowerCase());
}

/** Check a single colour value given as text (attribute, JS string). */
function checkValueText(report, text, line, { named, namedRule, icon = false }) {
    if (icon) {
        if (!ALLOWED_PAINT.test(text.trim())) {
            report.add(line, 'icon paint', `${text.trim()} (allowed: currentColor, none, var(--nr-icon-accent, …))`);
        }
        return;
    }
    let value;
    try {
        value = csstree.parse(text, { context: 'value', onParseError: () => {} });
    } catch {
        rawLiterals(text, () => report.add(line, 'colour literal'));
        return;
    }
    checkValueAst(value, {
        named,
        onLiteral: () => report.add(line, 'colour literal'),
        onNamed: () => report.add(line, namedRule),
        onDataUri: (n) => checkDataUri(report, n.value, line, false),
    });
}

/**
 * Check HTML: a template, an SVG icon, or markup held in a string.
 * Token lines are shifted by `line - 1`, so markup inside a template literal
 * reports the line it is on; markup whose lines do not map onto the file (a
 * data URI, `srcdoc`, a concatenation) passes `flat` and reports `line`.
 * Returns the comment ranges, for the token rules.
 */
function checkHtml(report, source, { line = 1, flat = false, icon = false, exemptions = false } = {}) {
    const shift = line - 1;
    const lineAt = (l) => (flat ? line : (l ?? 1) + shift);
    const comments = [];
    const startTags = [];

    // First pass: comments and tags, so exemptions exist before any finding.
    scanHtml(source, {
        onComment: (loc) => comments.push(loc),
        onStartTag: (_tag, token) => startTags.push(token.location),
    });
    if (exemptions && !flat) {
        for (const comment of comments) {
            if (!source.slice(comment.startOffset, comment.endOffset).includes('scheme-independent:')) continue;
            const next = startTags.find((t) => t && t.startOffset >= comment.endOffset);
            if (next) report.exemptRange(next.startLine, next.endLine);
        }
    }

    scanHtml(source, {
        onStartTag(tag, token) {
            for (const { name, value } of token.attrs) {
                const lower = name.toLowerCase();
                const where = lineAt(token.location?.attrs?.[name]?.startLine ?? token.location?.startLine);
                if (lower === 'style') {
                    checkCss(report, value, { context: 'declarationList', line: where, icon, exemptions: false, fallbackLine: where });
                } else if (lower === 'srcdoc') {
                    checkHtml(report, value, { line: where, flat: true, icon });
                } else if (COLOUR_ATTRIBUTES.has(lower)) {
                    checkValueText(report, value, where, { named: true, namedRule: 'colour attribute', icon: icon && PAINT_ATTRIBUTES.has(lower) });
                } else if ((tag === 'animate' || tag === 'set' || tag === 'animatecolor') && ANIMATION_VALUE_ATTRIBUTES.has(lower)) {
                    const target = (attr(token, 'attributeName')?.value ?? '').toLowerCase();
                    if (target === '' || COLOUR_ATTRIBUTES.has(target) || CSS_COLOUR_PROPERTY.test(target)) {
                        for (const part of value.split(';')) {
                            checkValueText(report, part, where, { named: true, namedRule: 'colour attribute', icon });
                        }
                    }
                } else if (tag === 'meta' && lower === 'content' && (attr(token, 'name')?.value ?? '').toLowerCase() === 'theme-color') {
                    checkValueText(report, value, where, { named: true, namedRule: 'colour attribute' });
                } else if (!URL_ATTRIBUTES.has(lower)) {
                    // Any other attribute: a colour literal is still a colour.
                    rawLiterals(value, () => report.add(where, 'colour literal'));
                }
            }
        },
        onStyleText(text, loc) {
            const start = lineAt(loc?.startLine);
            checkCss(report, text, {
                line: start,
                column: flat ? 1 : (loc?.startCol ?? 1),
                icon,
                exemptions: !flat && shift === 0,
                fallbackLine: start,
            });
        },
    });

    return comments.map((c) => [c.startOffset, c.endOffset]);
}

/* -------------------------------------------------------------- JavaScript */

/** The text a static expression evaluates to, `${…}` as a placeholder; null if not static. */
function staticText(node) {
    if (!node) return null;
    switch (node.type) {
        case 'Literal':
            return typeof node.value === 'string' ? node.value : null;
        case 'TemplateLiteral':
            return node.quasis.map((q) => q.value.cooked ?? q.value.raw).join(PLACEHOLDER);
        case 'BinaryExpression': {
            if (node.operator !== '+') return null;
            const left = staticText(node.left);
            const right = staticText(node.right);
            if (left === null && right === null) return null;
            return (left ?? PLACEHOLDER) + (right ?? PLACEHOLDER);
        }
        case 'CallExpression': {
            // [ '…', '…' ].join('')
            const callee = node.callee;
            if (callee.type === 'MemberExpression' && !callee.computed && callee.property.name === 'join'
                && callee.object.type === 'ArrayExpression') {
                const parts = callee.object.elements.map(staticText);
                if (parts.every((p) => p !== null)) {
                    const sep = node.arguments.length ? staticText(node.arguments[0]) : ',';
                    return parts.join(sep ?? '');
                }
            }
            return null;
        }
        default:
            return null;
    }
}

/** The static texts an expression may produce: through ? :, ||, ??, arrays. */
function candidateTexts(node) {
    if (!node) return [];
    switch (node.type) {
        case 'ConditionalExpression':
            return [...candidateTexts(node.consequent), ...candidateTexts(node.alternate)];
        case 'LogicalExpression':
            return [...candidateTexts(node.left), ...candidateTexts(node.right)];
        case 'ArrayExpression':
            return node.elements.flatMap(candidateTexts);
        case 'SequenceExpression':
            return candidateTexts(node.expressions[node.expressions.length - 1]);
        case 'AssignmentExpression':
            return candidateTexts(node.right);
        default: {
            const text = staticText(node);
            return text === null ? [] : [{ text, node }];
        }
    }
}

function propertyName(member) {
    if (member.type !== 'MemberExpression') return null;
    if (!member.computed && member.property.type === 'Identifier') return member.property.name;
    const text = staticText(member.property);
    return text;
}

function keyName(property) {
    if (property.computed) return staticText(property.key);
    if (property.key.type === 'Identifier') return property.key.name;
    return staticText(property.key);
}

function isColourKey(name) {
    return typeof name === 'string' && (JS_COLOUR_KEY.test(name) || CSS_COLOUR_PROPERTY.test(name));
}

function checkJs(report, source) {
    let ast;
    try {
        ast = espree.parse(source, { ecmaVersion: 'latest', sourceType: 'module', loc: true, range: true, comment: true });
    } catch (error) {
        report.add(error.lineNumber ?? 1, 'parse error', String(error.message));
        return;
    }

    // Parents and the nodes an exemption can cover.
    const exemptable = [];
    const parents = new Map();
    const walk = (node, parent, visitor) => {
        if (!node || typeof node.type !== 'string') return;
        parents.set(node, parent);
        visitor(node, parent);
        for (const key of espree.VisitorKeys[node.type] ?? []) {
            const child = node[key];
            if (Array.isArray(child)) child.forEach((c) => walk(c, node, visitor));
            else walk(child, node, visitor);
        }
    };
    walk(ast, null, (node) => {
        if (/Statement$|Declaration$/.test(node.type) || node.type === 'Property') {
            exemptable.push(node);
        }
    });

    for (const comment of ast.comments) {
        if (!comment.value.includes('scheme-independent:')) continue;
        const loc = { start: comment.loc.start, end: comment.loc.end, startOffset: comment.range[0], endOffset: comment.range[1] };
        const line = loc.start.line;
        const before = (report.lines[line - 1] ?? '').slice(0, loc.start.column).trim();
        const after = (report.lines[loc.end.line - 1] ?? '').slice(loc.end.column).trim();
        let target;
        if (before !== '' || after !== '') {
            target = exemptable
                .filter((n) => n.loc.start.line <= line && n.loc.end.line >= line && n.type !== 'Program')
                .sort((a, b) => (a.loc.end.line - a.loc.start.line) - (b.loc.end.line - b.loc.start.line))[0];
        } else {
            target = exemptable
                .filter((n) => n.range[0] >= loc.endOffset)
                .sort((a, b) => a.range[0] - b.range[0] || b.range[1] - a.range[1])[0];
        }
        if (target) report.exemptRange(target.loc.start.line, target.loc.end.line);
    }

    const colourValue = (expression, fallbackRule = 'named colour in JavaScript') => {
        for (const { text, node } of candidateTexts(expression)) {
            checkValueText(report, text, node.loc.start.line, { named: true, namedRule: fallbackRule });
        }
    };
    const cssText = (expression) => {
        for (const { text, node } of candidateTexts(expression)) {
            checkCss(report, text, { context: 'declarationList', line: node.loc.start.line, exemptions: false, fallbackLine: node.loc.start.line });
        }
    };

    // Maximal static texts: every string the code holds, checked once.
    const handled = new Set();
    walk(ast, null, (node, parent) => {
        // Colour contexts.
        if (node.type === 'AssignmentExpression') {
            const name = propertyName(node.left);
            if (name === 'cssText') {
                cssText(node.right);
            } else if (isColourKey(name)) {
                colourValue(node.right);
            }
        } else if (node.type === 'Property' && !node.method) {
            const name = keyName(node);
            if (isColourKey(name)) {
                colourValue(node.value);
            }
        } else if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression') {
            const method = propertyName(node.callee);
            const first = staticText(node.arguments[0]);
            if (method === 'setProperty' && typeof first === 'string' && (first.startsWith('--') || CSS_COLOUR_PROPERTY.test(first))) {
                colourValue(node.arguments[1]);
            } else if (method === 'setAttribute' && typeof first === 'string') {
                const attribute = first.toLowerCase();
                if (attribute === 'style') {
                    cssText(node.arguments[1]);
                } else if (COLOUR_ATTRIBUTES.has(attribute)) {
                    colourValue(node.arguments[1]);
                }
            }
        }

        // Every maximal static text: colour literals anywhere, HTML parsed.
        const text = staticText(node);
        if (text === null || handled.has(node)) return;
        if (parent && staticText(parent) !== null && (parent.type === 'BinaryExpression' || parent.type === 'CallExpression')) return;
        if (parent && parent.type === 'ArrayExpression' && parents.get(parent)?.type === 'MemberExpression'
            && parents.get(parents.get(parent))?.type === 'CallExpression' && staticText(parents.get(parents.get(parent))) !== null) return;
        handled.add(node);
        const line = node.loc.start.line;
        rawLiterals(text, () => report.add(line, 'colour literal'));
        if (/<[a-zA-Z]/.test(text)) {
            // A literal or template keeps its line breaks; anything assembled does not.
            checkHtml(report, text, { line, flat: node.type !== 'TemplateLiteral' && node.type !== 'Literal' });
        }
    });
}

/* ------------------------------------------------------------------- main */

const offenders = [];
for (const file of collectFiles()) {
    const source = readFileSync(file, 'utf8');
    const report = new Report(file, source);
    const extension = extname(file);

    if (extension === '.js') {
        checkJs(report, source);
        let comments = [];
        try {
            comments = espree.parse(source, { ecmaVersion: 'latest', sourceType: 'module', range: true, comment: true }).comments;
        } catch {
            // Reported by checkJs.
        }
        tokenRules(report, blank(source, comments.map((c) => c.range)));
    } else if (extension === '.css') {
        checkCss(report, source);
        const ranges = [];
        try {
            csstree.parse(source, { positions: true, onComment: (_v, loc) => ranges.push([loc.start.offset, loc.end.offset]) });
        } catch {
            // Tolerant parser; nothing to blank.
        }
        tokenRules(report, blank(source, ranges));
    } else if (extension === '.svg') {
        checkHtml(report, source, { icon: true });
    } else {
        const comments = checkHtml(report, source, { exemptions: true });
        tokenRules(report, blank(source, comments));
    }
    offenders.push(...report.render());
}

if (offenders.length === 0) {
    process.exit(0);
}
process.stderr.write('Colour that ignores the backend colour scheme (see the header of this script):\n\n');
for (const offender of offenders) {
    process.stderr.write(`  ${offender}\n`);
}
process.stderr.write('\nUse the --typo3-* variables or core classes (badge-*, btn-default, callout-*, alert-*, text-variant);\n');
process.stderr.write('light-dark() for a colour core has no variable for; `scheme-independent: <reason>` only where one value is right in both schemes.\n');
process.exit(1);
