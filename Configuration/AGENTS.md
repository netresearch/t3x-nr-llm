<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-23 -->

# AGENTS.md — Configuration

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3-standard configuration for nr_llm: backend module, DI services, TCA, caching, icons, routes, TypoScript.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
# Changes under Configuration/ take effect after:
ddev typo3 cache:flush
```
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
```
Configuration/
├── Backend/
│   ├── AjaxRoutes.php       # AJAX endpoint definitions
│   └── Modules.php          # Backend module registration
├── Extbase/Persistence/Classes.php   # Extbase persistence mapping
├── TCA/
│   ├── tx_nrllm_configuration.php
│   ├── tx_nrllm_model.php
│   ├── tx_nrllm_provider.php
│   ├── tx_nrllm_task.php
│   └── tx_nrllm_user_budget.php
├── TypoScript/
│   ├── constants.typoscript
│   └── setup.typoscript
├── Caching.php              # Cache configuration
├── Icons.php                # Icon registration
├── JavaScriptModules.php    # JS module registration
├── Services.php             # DI container configuration
├── Services.yaml            # Service definitions
└── Services.Dashboard.yaml  # Dashboard widgets DI
```

### Database Tables
| Table | Purpose |
|-------|---------|
| `tx_nrllm_provider` | API provider connections (OpenAI, Claude, etc.) |
| `tx_nrllm_model` | Available models per provider |
| `tx_nrllm_configuration` | Use-case configurations |
| `tx_nrllm_configuration_begroups_mm` | MM join — configurations ↔ backend user groups |
| `tx_nrllm_task` | Predefined task templates |
| `tx_nrllm_prompttemplate` | Reusable prompt templates |
| `tx_nrllm_user_budget` | Per-user AI spending ceilings |
| `tx_nrllm_service_usage` | Usage/cost tracking rows |

### Services
All services use autowiring. Public services defined in `Services.yaml`:
- `LlmServiceManager` — main entry point
- `ProviderAdapterRegistry` — provider management
- Feature services: `CompletionService`, `VisionService`, `EmbeddingService`, `TranslationService`
- Repositories: `ProviderRepository`, `ModelRepository`, `LlmConfigurationRepository`
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START commands -->
## Commands
See root `AGENTS.md` Setup — Configuration changes are validated by the full test matrix (`./Build/Scripts/runTests.sh`).
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style
1. All labels via `LLL:EXT:nr_llm/...` localization
2. TCA follows TYPO3 v14 conventions (no deprecated options)
3. Services use constructor injection, not `GeneralUtility::makeInstance`
4. Backend routes require proper access control
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START patterns -->
## Patterns

### Adding a Backend Route
```php
// Backend/AjaxRoutes.php
return [
    'nr_llm_my_action' => [
        'path' => '/nr-llm/my-action',
        'target' => MyController::class . '::myAction',
    ],
];
```

### Adding a TCA Field
```php
// TCA/tx_nrllm_provider.php
'my_field' => [
    'exclude' => true,
    'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.my_field',
    'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim'],
],
```
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security
- Never store plaintext API keys in TCA defaults or TypoScript — use nr-vault UUID identifiers
- Backend routes must restrict access (BE group, access mode) where appropriate
- Do not expose internal services as `public: true` in `Services.yaml` without a reason
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] Localized labels via `LLL:EXT:nr_llm/...`
- [ ] TCA changes accompanied by migration in `ext_tables.sql`
- [ ] New services wired in `Services.yaml`, not via `makeInstance`
- [ ] Cache flushed (`ddev typo3 cache:flush`) and functional tests pass
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> - Route example: `Backend/AjaxRoutes.php`
> - TCA example: `TCA/tx_nrllm_provider.php`
> - DI example: `Services.yaml`
> - Cache config: `Caching.php`
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- TCA reference: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/
- DI reference: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/DependencyInjection/Index.html
- Root `AGENTS.md` for project-wide patterns
<!-- AGENTS-GENERATED:END help -->
