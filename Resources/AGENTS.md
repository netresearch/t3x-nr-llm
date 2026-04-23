<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-23 -->

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

| Template | Purpose |
|----------|---------|
| `Index.html` | Dashboard overview |
| `Provider/List.html` | Provider management |
| `Model/List.html` | Model management |
| `Configuration/List.html` | Configuration management |
| `Configuration/WizardForm.html` | AI-powered config wizard form |
| `Configuration/WizardPreview.html` | Config wizard preview |
| `Task/List.html` | Task management |
| `Task/Execute.html` | Task execution UI |
| `Task/WizardForm.html` | AI-powered task wizard form |
| `Task/WizardPreview.html` | Task wizard preview |
| `Task/WizardChainPreview.html` | Task chain wizard preview |
| `SetupWizard/Index.html` | Initial setup wizard |
| `Help.html` | Help page |
| `Test.html` | Test prompt page |

### Language Files (`Private/Language/`)

| File | Purpose |
|------|---------|
| `locallang.xlf` | General labels |
| `locallang_tca.xlf` | TCA field labels |
| `locallang_mod.xlf` | Backend module labels |
| `locallang_mod_{overview,provider,model,config,task,wizard}.xlf` | Module-specific labels |
| `de.locallang*.xlf` | German translations (all files) |

### JavaScript (`Public/JavaScript/Backend/`)

| File | Purpose |
|------|---------|
| `ProviderList.js` | Provider list interactions |
| `ModelList.js` | Model list interactions |
| `ConfigurationList.js` | Configuration list interactions |
| `ConfigurationConstraints.js` | Model constraint display |
| `TaskExecute.js` | Task execution with live output |
| `SetupWizard.js` | Setup wizard flow |
| `ModelIdField.js` | Model ID TCA field |
| `WizardFormLoading.js` | Wizard loading states |

### Icons (`Public/Icons/`)
- `Extension.svg` — Netresearch symbol-only logo (extension icon)
- `Provider.svg`, `Model.svg` — Entity icons
- `provider-*.svg` — Provider-specific icons (OpenAI, Claude, etc.)
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- XLIFF 1.2 format for translations
- SVG format for all icons
- JavaScript: ES modules via `@typo3/` imports
- CSS: TYPO3 backend variables for consistency
- Extension.svg must be Netresearch symbol-only logo
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
