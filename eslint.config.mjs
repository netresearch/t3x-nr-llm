// Lints the backend JavaScript. Nothing did before: a file whose methods had
// landed outside their class passed the whole CI matrix — 71 green checks —
// because PHPStan does not read JavaScript, the functional suite renders the
// template without executing the module, and no Playwright spec covers that
// view (#825).
//
// `node --check` was considered as a cheaper floor and rejected on evidence:
// it parses a `.js` file as a CommonJS script and accepted the broken file,
// while ESLint parses it as an ES module — which is how the browser loads it —
// and reports the parse error on the right line.
//
// The rule set is deliberately small. This is the first JavaScript gate in any
// of our TYPO3 extensions, and a large one would arrive as a backlog of
// pre-existing violations that nobody asked for.
import globals from 'globals';
import noUnsanitized from 'eslint-plugin-no-unsanitized';

// `escapeHtml()` from `Backend/HtmlEscape.js` is this codebase's sanitizer.
const ESCAPER = { escape: { methods: ['escapeHtml'] } };

export default [
    {
        files: ['Resources/Public/JavaScript/**/*.js'],
        // Vendored libraries are shipped as-is; linting them would report
        // somebody else's code in our gate.
        ignores: ['Resources/Public/JavaScript/Vendor/**'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // Provided by the TYPO3 backend at runtime, not importable.
                TYPO3: 'readonly',
                bootstrap: 'readonly',
            },
        },
        plugins: { 'no-unsanitized': noUnsanitized },
        rules: {
            // The rule that would have caught #825.
            'no-undef': 'error',
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            // Both are errors, and the twelve findings that blocked that in
            // #825 are gone rather than suppressed: `escapeHtml()` is declared
            // to the rule as what it is. The plugin recognises a fixed set of
            // sanitizers and a helper of ours was not among them, so every
            // assignment downstream of it read as unsafe.
            //
            // Declaring it does NOT blunt the rule, which was measured rather
            // than assumed. With the escaper declared, a bare interpolation, a
            // direct assignment, a value laundered through `.trim()`, an
            // `insertAdjacentHTML()` argument and — the one that matters — a
            // template where only ONE of two interpolations is escaped all
            // still report. Only a value that actually passed through
            // `escapeHtml()` is accepted.
            //
            // `methods` is the right key, not `taggedTemplates`: our helper is
            // called as a function, not used as a template tag.
            'no-unsanitized/property': ['error', ESCAPER],
            'no-unsanitized/method': ['error', ESCAPER],
        },
    },
];
