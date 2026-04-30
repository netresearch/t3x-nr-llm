# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `CHANGELOG.md`, `CODEOWNERS`, GitHub issue templates (bug report, feature request).
- External JavaScript files for the Test and WizardChainPreview backend templates
  (replaces inline `<script>` tags to satisfy Content Security Policy).
- Canonical sections in `AGENTS.md` (Commands, Testing, Development Workflow,
  Architecture, File Map, Critical Constraints, Heuristics, Shared Utilities,
  Golden Samples).
- `Service/Budget/BackendUserContextResolverInterface` (with default
  implementation `BackendUserContextResolver`) — single seam for resolving
  the active TYPO3 backend user uid out of `$GLOBALS['BE_USER']`. The
  resolver returns `null` (rather than `0`) when no BE user is in scope
  so `BudgetMiddleware`'s "skip the check" branch fires for CLI /
  scheduler / FE callers without faking an unauthenticated principal.
  `CompletionService` injects the resolver and auto-populates
  `ChatOptions::beUserUid` when the caller did not set one — slice 15a
  of REC #4 (automatic budget pre-flight wiring; downstream feature
  services Embedding / Translation / Vision follow in slice 15b).
  `ChatOptions` (and by extension `ToolOptions`) gained typed
  `beUserUid` / `plannedCost` fields with `withBeUserUid()` /
  `withPlannedCost()` setters; `LlmServiceManager::chat()` translates
  these into `BudgetMiddleware::METADATA_BE_USER_UID` /
  `METADATA_PLANNED_COST` on the `ProviderCallContext` so the existing
  middleware reads them without changes. Fields are deliberately kept
  off `ChatOptions::toArray()` — they are pipeline metadata, not
  provider-side options, and must never reach the provider wire
  payload.

### Changed

- `Build/captainhook.json` is now the documented default location for git
  hooks (configured via `composer.json` `extra.captainhook.config`).
- `Makefile` test/quality targets delegate to `Build/Scripts/runTests.sh -s
  <suite>` instead of invoking PHPUnit / PHPStan / Rector directly.
- `Build/FunctionalTests.xml` testsuite names normalised to `functional` and
  `e2e-backend` (lowercase, conventional).
- E2E test fixtures use vault-UUID-style placeholders or runtime-built
  prefix concatenations rather than literal API-key strings.
- `TaskController` no longer carries inline SQL or filesystem reads.
  The eight `ConnectionPool` / `QueryBuilder` call sites and the
  `var/log/typo3_deprecations.log` read move to three new
  reader services under `Classes/Service/Task/`:

  - `RecordTableReader` (with `RecordTableReaderInterface`) — owns
    the schema-introspection + query work for the record-picker
    (list allowed tables, format table label, detect label field,
    fetch a record sample, load records by uid, fetch all rows for
    the table-input branch).
  - `SystemLogReader` (with `SystemLogReaderInterface`) — wraps
    the `sys_log` query used by the syslog input branch.
  - `DeprecationLogReader` (with `DeprecationLogReaderInterface`) —
    wraps the deprecation-log filesystem read.

  `TaskController`'s constructor loses the `ConnectionPool` and
  `TcaSchemaFactory` dependencies; it now injects the three reader
  interfaces. Behaviour is unchanged. This is slice 13a of the
  `TaskController` split (ADR-027).
- `TaskController` no longer carries the input-source dispatch logic.
  The `getInputData()` / `getSyslogData()` / `getTableData()` private
  helpers move into a new `Service/Task/TaskInputResolver` (with
  `TaskInputResolverInterface`). The resolver owns the `Task::INPUT_*`
  match plus the per-source formatting (timestamp + type-label
  localisation for syslog rows, "no table configured" / "read failed"
  placeholders for the table source) and delegates the actual data
  fetching to the slice-13a reader services. The controller's
  `getInputData()` becomes a single delegation; the `SystemLogReader`
  and `DeprecationLogReader` are no longer injected directly into the
  controller (the resolver owns them). Behaviour is unchanged. Slice
  13b of the `TaskController` split (ADR-027).
- `TaskController::executeAction()` no longer carries the LLM
  orchestration logic. Prompt building, configuration lookup, and
  dispatch to `LlmServiceManager` move into a new
  `Service/Task/TaskExecutionService` (with
  `TaskExecutionServiceInterface`). The service returns a typed
  `TaskExecutionResult` (`content`, `model`, `outputFormat`, `usage`)
  rather than a `CompletionResponse` so future Task-specific fields
  can attach without leaking into the LLM abstraction. The controller
  loses its direct `LlmServiceManagerInterface` injection (the service
  owns it now); the new service is the natural seam for the future
  REC #4 budget pre-flight, with the hook point documented in the
  service's class docblock. Behaviour is unchanged. Slice 13c of the
  `TaskController` split (ADR-027).
- `ProviderResponseException` carries typed `httpStatus`,
  `responseBody`, and `endpoint` properties so callers can branch on
  the actual HTTP semantics rather than re-parsing the message string.
  The previous positional constructor signature
  `(string $message, int $httpStatus = 0, ?Throwable $previous = null)`
  is preserved verbatim — the new `responseBody` and `endpoint`
  fields are appended after `$previous`, so existing callers writing
  `new ProviderResponseException($msg, $status, $previous)` keep
  working without silent type confusion. New callers populate the
  typed fields by name. Production call sites
  (`AbstractProvider::sendRequest()`, `OpenRouterProvider::handleOpenRouterError()`)
  populate the new fields; OpenRouter's handler now also receives the
  actual endpoint so non-`chat/completions` calls (e.g. `embeddings`)
  carry correct metadata. The `endpoint` field is sanitised before
  storage — any query string is stripped so providers like Gemini
  (which embed the API key as `?key=<secret>`) cannot leak
  credentials through exception logging or telemetry. Demonstrated
  the new typed-catch pattern in
  `ConfigurationController::testConfigurationAction()`, which now
  catches `ProviderResponseException` ahead of the generic
  `Throwable` and surfaces the upstream HTTP status as the AJAX
  response status (was always 500). REC #8 from the audit.
- `TaskController` is split into four per-pathway controllers,
  closing REC #5 and the entire ADR-027 work:

  - `TaskListController` (135 LOC, 4 deps) — `list`.
  - `TaskWizardController` (270 LOC, 9 deps) — `wizardForm`,
    `wizardGenerate`, `wizardGenerateChain`, `wizardCreate`.
  - `TaskExecutionController` (210 LOC, 8 deps) — `executeForm`,
    `executeAction`, `refreshInputAction`.
  - `TaskRecordsController` (135 LOC, 1 dep) — `listTablesAction`,
    `fetchRecordsAction`, `loadRecordDataAction`.

  AJAX route identifiers and paths are unchanged; only the route
  `target:` field repoints to the new controllers, so the JS
  frontend (resolved via `PageRenderer::addInlineSettingArray`)
  needs no update. Backend module identifier `nrllm_tasks` is
  unchanged; `controllerActions` now distributes the action names
  across the three render-controllers. The original
  `Controller/Backend/TaskController.php` is removed. Slice 13e
  of the `TaskController` split (ADR-027), and the closure of the
  audit's REC #5.
- Every Task AJAX action now returns a typed `Response/*` DTO instead
  of a raw `JsonResponse([...])` literal — five new responses join the
  existing `ConfigurationController` / `ProviderController` precedent:
  `TableListResponse` (picker dropdown), `RecordListResponse` (picker
  fetch), `RecordDataResponse` (picker load by uid),
  `TaskExecutionResponse` (execute success; static `fromResult()`
  factory adapts the service-layer `TaskExecutionResult`),
  `TaskInputResponse` (refresh-input). All error branches now use the
  existing `ErrorResponse`. The wire shape consumed by
  `Backend/TaskExecute.js` and friends is preserved byte-for-byte.
  Slice 13d of the controller split (ADR-027); after slice 13e these
  actions live on `TaskExecutionController` and `TaskRecordsController`.
- Specialized translators register via the new `#[AsTranslator]` marker
  attribute, mirroring the `#[AsLlmProvider]` pattern used for LLM
  providers. The attribute carries no fields — translator identifier
  comes from `TranslatorInterface::getIdentifier()` (existing) and
  registration order from the new `TranslatorInterface::getPriority()`
  method (used by Symfony's `#[TaggedIterator(defaultPriorityMethod:
  'getPriority')]` in `TranslatorRegistry`). `TranslatorCompilerPass`
  auto-tags matching services so the existing `Services.yaml` `tags:`
  entries on `LlmTranslator` / `DeepLTranslator` are no longer needed
  (and were removed). Third-party translators outside the
  `Netresearch\NrLlm\Specialized\Translation\` namespace can keep using
  the legacy yaml-tag path; both mechanisms remain supported.

### Fixed

- `Resources/Public/Icons/Extension.svg` brand colour corrected to the official
  Netresearch teal `#2F99A4` (was `#2999a4` typo).

### BREAKING

- The following classes are now `final` (and `final readonly` where applicable)
  and can no longer be subclassed by downstream extensions: the four leaf
  provider exceptions (`ProviderConfigurationException`,
  `ProviderConnectionException`, `ProviderResponseException`,
  `UnsupportedFeatureException`); the four feature services
  (`Service/Feature/CompletionService`, `EmbeddingService`,
  `TranslationService`, `VisionService`); the two supporting services
  (`Service/ModelSelectionService`, `Service/PromptTemplateService`).
  Downstream consumers that extended any of these classes should switch to
  composition or open an issue if a documented extension point is needed.
  The base `ProviderException` is the only deliberately non-final class
  remaining (it parents the leaf exceptions); `LlmConfigurationService` and
  `BudgetService` are still non-final pending the same interface-extract
  pattern applied to the registry below.
- `ProviderAdapterRegistry` is now `final` and implements the new
  `ProviderAdapterRegistryInterface`. Downstream consumers that
  constructor-injected the concrete class should typehint the interface
  instead. The Symfony alias
  `ProviderAdapterRegistryInterface → ProviderAdapterRegistry` is wired
  in `Configuration/Services.yaml` so existing autowiring keeps working.
- `BudgetService` is now `final readonly` and implements the new
  `BudgetServiceInterface`. The DB-aggregation step previously embedded
  in the service's `aggregateWindowUsage()` method moved to a separate
  collaborator: `Service/Budget/UserBudgetUsageWindows` implementing
  `BudgetUsageWindowsInterface`. `BudgetService::__construct()` now
  takes `(UserBudgetRepository, BudgetUsageWindowsInterface)` rather
  than `(UserBudgetRepository, ConnectionPool)`. Symfony aliases
  `BudgetServiceInterface → BudgetService` and
  `BudgetUsageWindowsInterface → UserBudgetUsageWindows` keep autowiring
  transparent for callers that injected via `BudgetService`. Direct
  instantiation (rare) needs the new constructor signature.
- `LlmConfigurationService` is now `final readonly` and implements the
  new `LlmConfigurationServiceInterface`. Same migration story as the
  registry above: typehint the interface in constructor injection; the
  `LlmConfigurationServiceInterface → LlmConfigurationService` Symfony
  alias keeps autowiring transparent.

## [0.7.0] - 2026-04-22

Initial public release. See git history for prior commits.

[Unreleased]: https://github.com/netresearch/t3x-nr-llm/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.7.0
