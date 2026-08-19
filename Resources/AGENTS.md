<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — Resources

<!-- AGENTS-GENERATED:START overview -->
## Overview
Static assets for the backend module: Fluid templates, XLIFF translations (EN + DE), SVG icons, CSS, and ES module JavaScript.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
No build step. Files served directly by TYPO3. JavaScript uses ES modules via `@typo3/` imports.
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files

### Templates (`Private/Templates/Backend/`)

One directory per backend surface (`Provider/`, `Model/`, `Configuration/`, `Task/`, `AiTask/`, `Skill/`, `PromptSnippet/`, `McpServer/`, `Tool/`, `AgentRun/`, `Playground/`, `Analytics/`, `UseCase/`, `EditorAction/`, `SetupWizard/`), mostly `List.html` plus surface-specific views (`Execute.html`, `WizardForm.html`, `WizardPreview.html`, `Show.html`), and top-level pages `Index.html`, `Governance.html`, `Help.html`, `Test.html`. Follow the sibling surface's structure when adding a view.

### Language Files (`Private/Language/`)

| File | Purpose |
|------|---------|
| `locallang.xlf` | General labels |
| `locallang_tca.xlf` | TCA field labels |
| `locallang_dashboard.xlf` | Dashboard widget labels |
| `locallang_mod.xlf` | Backend module labels |
| `locallang_mod_<surface>.xlf` | One file per module surface (overview, provider, model, config, task, aitasks, wizard, skill, snippet, tool, mcp, runs, playground, analytics, usecase) |
| `de.locallang*.xlf` | German translations — EVERY EN file has a `de.` twin |

### JavaScript (`Public/JavaScript/Backend/`)

One ES module per backend interaction concern, named after the surface it drives (`ProviderList.js`, `TaskExecute.js`, `SetupWizard.js`, `ToolPlayground.js`, `AgentRunInbox.js`, …), plus shared helpers (`AjaxError.js`, `HtmlEscape.js`, `ModuleAction.js`). `Public/JavaScript/Vendor/` holds the vendored `chart.umd.js`.

### Icons (`Public/Icons/`)
- `Extension.svg` — Branded teal tile with white chip motif + orange accent (extension icon, also TCA iconfile)
- `Provider.svg`, `Model.svg` — Entity icons (v14 three-color style: currentColor + `--nr-icon-accent` teal)
- `*.legacy.svg` — v13 full-bleed teal-tile variants, selected via `Typo3Version` in `Configuration/Icons.php`
- `provider-*.svg` — Provider-specific icons (OpenAI, Claude, etc.), currentColor only
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- XLIFF 1.2 format for translations
- SVG format for all icons
- JavaScript: ES modules via `@typo3/` imports
- CSS: TYPO3 backend variables for consistency
- Extension.svg must stay a full-color branded teal tile (plain fills, no `<style>`/`<text>`); module/record icons follow the TYPO3 v14 three-color spec (currentColor + `var(--nr-icon-accent, #2F99A4)`) with `.legacy.svg` teal-tile twins for v13
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START patterns -->
## Common patterns

### Adding a Translation
Add `<trans-unit>` to appropriate `locallang*.xlf`, then add German translation to corresponding `de.locallang*.xlf`.

### Using in Fluid
```html
<f:translate key="LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:my.label" />
```

### Adding Icons
Place SVG in `Public/Icons/`, register in `Configuration/Icons.php`.
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security
- Never embed API keys or credentials in templates
- JavaScript AJAX calls use TYPO3 CSRF tokens
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] New labels have both EN and DE translations
- [ ] Icons are SVG format
- [ ] JavaScript uses `@typo3/` ES module imports
- [ ] Templates use `<f:translate>` for all user-facing text
- [ ] New icons registered in `Configuration/Icons.php`
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> **Look at existing files:**
> - Template: `Private/Templates/Backend/Task/List.html`
> - XLIFF: `Private/Language/locallang_mod_task.xlf`
> - JavaScript: `Public/JavaScript/Backend/TaskExecute.js`
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- TYPO3 Fluid docs: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/FluidViewHelper/Index.html
- XLIFF: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Localization/Xliff.html
- Icons: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Icon/Index.html
<!-- AGENTS-GENERATED:END help -->
