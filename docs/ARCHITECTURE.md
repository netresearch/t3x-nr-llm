# Architecture — nr_llm

Agent-facing component map. For design rationale, the authoritative records are the ADRs under `Documentation/Adr/` (`Documentation/Adr/Index.rst` indexes them and documents the lifecycle). This file states *where things live* and *which dependencies are allowed*; it duplicates no decision text.

## System Overview

nr_llm is a TYPO3 (v13.4/v14.3) extension providing a unified LLM provider abstraction. Seven provider adapters (OpenAI, Claude, Gemini, Groq, Mistral, Ollama, OpenRouter) sit behind a common contract; feature services (completion, conversation, embedding, tool calling, translation, vision) consume them through a middleware pipeline that enforces fallback chains, budgets, caching, guardrails, circuit breaking and usage/telemetry tracking. A backend module manages the three-tier configuration (Provider → Model → Configuration) plus tasks, skills, prompt snippets, MCP servers, agent runs, analytics and governance.

## Directory Structure

```
nr_llm/
├── Classes/                    # PHP source (see Classes/AGENTS.md for the per-directory table)
│   ├── Attribute/              # #[AsLlmProvider] / #[AsTranslator] auto-registration
│   ├── Command/                # CLI maintenance commands (purge, reap, eval, API-key set)
│   ├── Controller/Backend/     # Backend controllers, request DTOs, Response objects
│   ├── DependencyInjection/    # ProviderCompilerPass, TranslatorCompilerPass
│   ├── Domain/                 # Entities, repositories, enums, DTOs, value objects
│   ├── Exception/              # Core domain exceptions (NrLlmExceptionInterface)
│   ├── Form/                   # TCA form elements and item providers
│   ├── Hook/                   # ProviderEndpointNormalizationHook
│   ├── Provider/               # 7 LLM adapters + Contract/ + Exception/ + Middleware/ + CircuitBreaker/ + Fallback/
│   ├── Service/                # Feature services + wizard, options, budget, analytics, governance, retrieval, tools
│   ├── Specialized/            # DeepL, speech (Whisper/TTS), image (DALL-E/FAL)
│   ├── Testing/                # Fake service doubles for consuming extensions' tests
│   ├── Updates/                # Upgrade wizards
│   ├── Utility/                # SafeCastTrait, ErrorMessageSanitizerTrait
│   └── Widgets/DataProvider/   # Backend dashboard widgets (cost, requests)
├── Configuration/              # TYPO3 config: TCA, Services.yaml, Caching.php, Icons, backend routes
├── Documentation/              # RST docs + guides.xml + Adr/ + Api/ + brand assets
├── Tests/                      # Unit, Integration, Functional, Fuzzy, Architecture, E2E
├── Resources/                  # Fluid templates, XLIFF (EN+DE), icons, CSS, JS
├── Build/                      # runTests.sh, PHPStan/Rector/Fractor configs, repo-check scripts
└── docs/                       # This file + exec-plans/
```

## Core Runtime Components

| Component | Path | Role |
|-----------|------|------|
| `LlmServiceManager` | `Classes/Service/LlmServiceManager.php` | Main public entry point |
| Feature services | `Classes/Service/Feature/` | Completion, Conversation, Embedding, ToolCalling, Translation, Vision — each with interface |
| Option objects | `Classes/Service/Option/` | Typed options on `AbstractOptions` (object-only API) |
| Provider contract | `Classes/Provider/Contract/` | `ProviderInterface` + capability interfaces (Streaming/Tool/Vision/Document) |
| Provider adapters | `Classes/Provider/*Provider.php` | One class per LLM vendor, extending `AbstractProvider` |
| Middleware pipeline | `Classes/Provider/Middleware/` | `MiddlewarePipeline` with Fallback, Budget, Cache, Guardrail, CircuitBreaker, Idempotency, Telemetry, Usage middlewares |
| Provider registry | `Classes/Provider/ProviderAdapterRegistry.php` | Registry backend controllers use instead of concrete adapters |
| Setup wizard | `Classes/Service/SetupWizard/` | ProviderDetector, ModelDiscovery facade, ConfigurationGenerator |
| Cache | `Classes/Service/CacheManager.php` + `Configuration/Caching.php` | `nrllm_responses` cache; backend chosen by host instance |

## Dependency Rules (phpat-enforced)

`Tests/Architecture/` is the authoritative, machine-enforced statement. Per test file:

- `ControllerLayerTest`: FormInput DTOs do not depend on persistence (their factories may depend on repositories); backend controllers use the `ProviderAdapterRegistry`, never concrete providers.
- `DomainLayerTest`: domain models do not depend on repositories, controllers, or HTTP classes; they depend only on Domain + core; the `Provider` model has explicitly limited dependencies.
- `DtoLayerTest`: DTOs are readonly with typed properties only.
- `ModuleSeamTest`: Specialized services and the Tool module do not depend on each other; the Guardrail module does not depend on the modules it protects; nothing outside the Backend depends on it; core does not depend on the Tool module.
- `ServiceLayerTest`: services do not depend on controllers, nor on concrete provider adapters (contracts only).

Run them via `./Build/Scripts/runTests.sh -s architecture`.

## Data Flow

A feature-service call goes: caller → `LlmServiceManager` / feature service → configuration resolution (three-tier: Configuration → Model → Provider) → `MiddlewarePipeline` (budget, cache, guardrails, circuit breaker, fallback chain, idempotency) → provider adapter → HTTP call. On the way back: response parsing into typed response objects, then usage/telemetry middlewares record cost and signals. API keys are resolved from nr-vault UUID identifiers at call time and never persisted in plaintext.

## Key Decisions

Do not re-derive these — read the record: `Documentation/Adr/Index.rst` indexes all ADRs. Load-bearing ones referenced from AGENTS.md files: ADR-013 (three-level configuration architecture), ADR-026 (provider middleware pipeline), ADR-027 (TaskController split / backend controller aliases), ADR-028 (public services policy). The public API surface is frozen by `Tests/Unit/Api/api-surface.txt`; deprecations follow `Documentation/Api/Deprecation.rst`.
