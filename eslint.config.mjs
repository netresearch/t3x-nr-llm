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
            // Warning, not error, and the reason is measured rather than
            // cautious. Switched on as an error it reports 12 assignments —
            // and none of them is a hole: the code escapes first, visibly, at
            // `SetupWizard.js:471-474` ("SECURITY: Escape all external data")
            // through `escapeHtml()`. The rule cannot recognise that helper as
            // a sanitizer, which is a known shape for it.
            //
            // The plugin is still loaded, because two files carry
            // `eslint-disable-line no-unsanitized/property` — suppressions
            // written for a rule that never ran. Without the plugin those
            // comments become "rule not found" errors the moment linting is
            // switched on.
            //
            // Making them errors would land a backlog of twelve judgements
            // nobody asked for on a change whose job is #825. Leaving the rule
            // out entirely would drop a real check silently. The findings are
            // triaged in their own issue, with the escapeHtml evidence, so the
            // security question lives somewhere it can be argued rather than
            // in a config comment.
            'no-unsanitized/property': 'warn',
            'no-unsanitized/method': 'warn',
        },
    },
];
