# Configuration Directory

> TYPO3 extension configuration for nr_llm

## Overview

TYPO3-standard configuration files for backend module, services, TCA, and TypoScript.

## Structure

```
Configuration/
├── Backend/
│   ├── AjaxRoutes.php      # AJAX endpoint definitions
│   └── Modules.php         # Backend module registration
├── Extbase/
│   └── Persistence/
│       └── Classes.php     # Extbase persistence mapping
├── TCA/
│   ├── tx_nrllm_configuration.php
│   ├── tx_nrllm_model.php
│   ├── tx_nrllm_provider.php
│   └── tx_nrllm_task.php
├── TypoScript/
│   ├── constants.typoscript
│   └── setup.typoscript
├── Caching.php             # Cache configuration
├── Icons.php               # Icon registration
├── JavaScriptModules.php   # JS module registration
├── Services.php            # DI container configuration
└── Services.yaml           # Service definitions
```

## TCA Tables

| Table | Purpose |
|-------|---------|
| `tx_nrllm_provider` | API provider connections (OpenAI, Claude, etc.) |
| `tx_nrllm_model` | Available models per provider |
| `tx_nrllm_configuration` | Use-case configurations |
| `tx_nrllm_task` | Predefined task templates |

## Services

All services use autowiring. Public services defined in Services.yaml:

- `LlmServiceManager` - Main entry point
- `ProviderAdapterRegistry` - Provider management
- Feature services: `CompletionService`, `VisionService`, `EmbeddingService`, `TranslationService`
- Repositories: `ProviderRepository`, `ModelRepository`, `LlmConfigurationRepository`

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
    'config' => [
        'type' => 'input',
        'size' => 30,
        'eval' => 'trim',
    ],
],
```

## Critical Rules

1. All labels via `LLL:EXT:nr_llm/...` localization
2. TCA follows TYPO3 v14 conventions (no deprecated options)
3. Services use constructor injection, not GeneralUtility::makeInstance
4. Backend routes require proper access control
