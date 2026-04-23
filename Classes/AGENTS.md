<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-23 -->

# AGENTS.md — Classes

<!-- AGENTS-GENERATED:START overview -->
## Overview
PHP 8.2+ source code (139 files) with strict typing, PSR-12, PHPStan level 10. Three-tier domain: Providers -> Models -> Configurations.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START setup -->
## Setup
```bash
./Build/Scripts/runTests.sh -s phpstan      # Static analysis (level 10)
./Build/Scripts/runTests.sh -s cgl -n       # PHP-CS-Fixer dry-run
./Build/Scripts/runTests.sh -s cgl          # Apply fixes
./Build/Scripts/runTests.sh -s rector -n    # Rector dry-run
```
<!-- AGENTS-GENERATED:END setup -->

## Tests
```bash
./Build/Scripts/runTests.sh -s unit         # Unit tests for Classes/
./Build/Scripts/runTests.sh -s functional   # Functional tests (DB)
```
Full test matrix in root `AGENTS.md` Setup section.

<!-- AGENTS-GENERATED:START filemap -->
## Key Files

| Directory | Purpose |
|-----------|---------|
| `Attribute/` | `#[AsLlmProvider]` auto-registration attribute |
| `Controller/Backend/` | Backend module controllers (6), request DTOs (4), Response objects (9) |
| `DependencyInjection/` | ProviderCompilerPass |
| `Domain/DTO/` | BudgetCheckResult, FallbackChain, ModelSelectionCriteria |
| `Domain/Enum/` | ModelCapability, ModelSelectionMode, TaskCategory, TaskInputType, TaskOutputFormat |
| `Domain/Model/` | Entities: Provider, Model, LlmConfiguration, Task, PromptTemplate, UserBudget |
| `Domain/Repository/` | LlmConfiguration, Model, Provider, Task, PromptTemplate, UserBudget |
| `Domain/ValueObject/` | ChatMessage (currently unused — tracked in audit 2026-04-23) |
| `Exception/` | AccessDenied, ConfigurationNotFound, InvalidArgument, PromptTemplateNotFound |
| `Form/` | ModelIdElement (TCA), ModelConstraintsWizard (field wizard) |
| `Provider/` | 7 adapters: OpenAI, Claude, Gemini, Groq, Mistral, Ollama, OpenRouter |
| `Provider/Contract/` | ProviderInterface, Streaming/Tool/Vision/DocumentCapableInterface |
| `Provider/Exception/` | 5 typed provider exceptions |
| `Service/` | LlmServiceManager, CacheManager, ModelSelectionService, WizardGeneratorService, BudgetService, FallbackChainExecutor |
| `Service/Feature/` | CompletionService, EmbeddingService, TranslationService, VisionService |
| `Service/Option/` | ChatOptions, EmbeddingOptions, ToolOptions, TranslationOptions, VisionOptions |
| `Service/SetupWizard/` | ProviderDetector, ModelDiscovery, ConfigurationGenerator + DTOs |
| `Specialized/` | Image (DALL-E, FAL), Speech (Whisper, TTS), Translation (DeepL, LLM) |
| `Utility/` | SafeCastTrait |
| `Widgets/DataProvider/` | Backend dashboard widgets (MonthlyCost, RequestsByProvider) |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- `declare(strict_types=1);` required in ALL files
- All properties MUST be typed, all methods MUST have return types
- PSR-12 via PHP-CS-Fixer
- DTOs: `final readonly class` with typed constructor properties
- Domain models: extend `AbstractEntity` for Extbase persistence
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START architecture -->
## Architecture Rules (PHPat enforced)
1. Controllers must NOT depend on Repositories directly
2. Domain\Model must NOT depend on Domain\Repository
3. Domain\Model must NOT depend on Controller
4. DTOs must be readonly with typed properties only
5. Domain models excluded from mutation testing

See `Tests/Architecture/` for enforcement tests.
<!-- AGENTS-GENERATED:END architecture -->

<!-- AGENTS-GENERATED:START patterns -->
## Patterns

### New Provider
1. Create in `Provider/` extending `AbstractProvider`
2. Implement capability interfaces from `Provider/Contract/`
3. Add to `AdapterType` enum (`Domain/Model/AdapterType.php`)
4. Register via DI (auto-tagged by `ProviderCompilerPass`)
5. Add unit tests in `Tests/Unit/Provider/`
6. Add integration tests in `Tests/Integration/Provider/`

### New Feature Service
1. Create in `Service/Feature/`
2. Accept option object from `Service/Option/`
3. Return typed response from `Domain/Model/`
4. Add to `LlmServiceManager` public API
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security
- API keys stored as nr-vault UUID identifiers (envelope encryption via nr-vault extension)
- Input validation via typed DTOs
- Output treated as untrusted content
- Provider exceptions never expose API keys
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] `declare(strict_types=1);` present
- [ ] All properties and return types declared
- [ ] PHPStan level 10 passes
- [ ] PHP-CS-Fixer passes
- [ ] Architecture tests pass
- [ ] Unit tests cover new code
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Examples
> **Look at real code, not these stubs.**
> - Provider: `Provider/OpenAiProvider.php`
> - DTO: `Controller/Backend/DTO/ExecuteTaskRequest.php`
> - Feature service: `Service/Feature/CompletionService.php`
> - Option: `Service/Option/ChatOptions.php`
> - Wizard: `Service/SetupWizard/ProviderDetector.php`
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When Stuck
- Architecture decisions: `Documentation/Adr/` (20 ADRs)
- API reference: `Documentation/Api/` (9 pages)
- Run `./Build/Scripts/runTests.sh -s phpstan` to check types
<!-- AGENTS-GENERATED:END help -->
