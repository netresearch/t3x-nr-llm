<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

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
│   ├── tx_nrllm_mcp_server.php
│   ├── tx_nrllm_model.php
│   ├── tx_nrllm_promptsnippet.php
│   ├── tx_nrllm_provider.php
│   ├── tx_nrllm_skill.php
│   ├── tx_nrllm_skill_source.php
│   ├── tx_nrllm_task.php
│   └── tx_nrllm_user_budget.php
├── Caching.php              # Cache configuration
├── Icons.php                # Icon registration
├── JavaScriptModules.php    # JS module registration
├── Services.php             # DI container configuration
├── Services.yaml            # Service definitions
└── Services.Dashboard.php   # Dashboard widgets DI
```

New tables get a per-table file directly under `TCA/`; there is currently no `TCA/Overrides/` directory (nr_llm does not extend foreign tables).

### Database Tables

`ext_tables.sql` is the authoritative list (24 tables as of 2026-08-19). The core entities:

| Table | Purpose |
|-------|---------|
| `tx_nrllm_provider` | API provider connections (OpenAI, Claude, etc.) |
| `tx_nrllm_model` | Available models per provider |
| `tx_nrllm_configuration` | Use-case configurations (+ `_begroups_mm`, `_skill_mm` joins) |
| `tx_nrllm_task` | Predefined task templates (+ `_skill_mm` join) |
| `tx_nrllm_user_budget` | Per-user AI spending ceilings |
| `tx_nrllm_service_usage` | Usage/cost tracking rows |
| `tx_nrllm_skill`, `tx_nrllm_skill_source` | Skills and their sources (+ `tx_nrllm_skill_audit`) |
| `tx_nrllm_promptsnippet` | Reusable prompt snippets |
| `tx_nrllm_mcp_server`, `tx_nrllm_mcp_tool` | MCP server/tool registry |
| `tx_nrllm_agentrun`, `tx_nrllm_agentrun_event` | Agent runs and their event log |
| `tx_nrllm_ai_session`, `tx_nrllm_ai_session_message` | AI sessions |
| `tx_nrllm_telemetry`, `tx_nrllm_governance_event`, `tx_nrllm_eval_result` | Telemetry / governance / eval records |
| `tx_nrllm_tool_state`, `tx_nrllm_tool_group_state` | Tool enablement state |

### Services
All services use autowiring. Public services defined in `Services.yaml`:
- `LlmServiceManager` — main entry point
- `ProviderAdapterRegistry` — provider management
- Feature services: `CompletionService`, `VisionService`, `EmbeddingService`, `TranslationService`
- Repositories: `ProviderRepository`, `ModelRepository`, `LlmConfigurationRepository`
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START commands -->
## Build/Tests
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
