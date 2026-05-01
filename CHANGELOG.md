# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **REC #8b (slice 23b):** Replaced catch-all `catch (Throwable $e)`
  blocks with typed exception handlers across the four admin
  controllers (`ProviderController`, `ModelController`,
  `ConfigurationController`, `LlmModuleController`). 13 catch sites
  updated. Provider errors (`ProviderResponseException`, base
  `ProviderException`) and Doctrine DBAL errors now route to specific
  arms with appropriate HTTP statuses (502 for upstream provider
  failures); the final `Throwable` arm logs full exception detail and
  surfaces a generic message instead of leaking `$e->getMessage()`
  (which can carry SQL error text or provider response bodies). All
  four controllers gained a `LoggerInterface` constructor parameter
  (autowired by Symfony DI). The `ConfigurationController::testConfigurationAction`
  intentionally still surfaces `ProviderResponseException::getMessage()`
  with the upstream HTTP status — the message is already sanitised
  by `AbstractProvider::sanitizeErrorMessage()` and the frontend toast
  needs the model-specific text to be useful for diagnostics. Unit
  test assertions updated to assert "See system log" instead of the
  raw exception text — verifying the new generic-message contract.

### Removed

- `ProviderAdapterRegistryInterface::registerAdapter()` and the
  matching `ProviderAdapterRegistry::registerAdapter()` public
  mutator have been removed (audit 2026-04-23 REC #3, slice 22).
  The registry now exposes a read-only contract: the adapter map
  is fixed at construction time as the union of the built-in
  `ADAPTER_CLASS_MAP` and an optional `array $adapterOverrides`
  constructor argument (defaults to `[]`; production wiring uses
  the empty default). Custom-adapter / built-in-override registration
  is therefore a constructor concern rather than a runtime
  service-locator call. There were no production callers of the
  removed method (the search yielded only test usages). The
  `customAdapters` private property is gone; the per-call
  "Registered custom adapter" debug log is gone with it (registration
  is now construction-time and side-effect-free for valid input).
  Validation of override classes (must extend `AbstractProvider`)
  still throws `ProviderConfigurationException` — the same exception
  type that was thrown by `registerAdapter()`, raised from the
  constructor instead. The `ProviderAdapterRegistry` class stays
  `final`, public-in-DI (so the backend module can resolve it for
  diagnostics — REC #9c is a separate slice).

### Added

- **REC #13 (audit 2026-04-30):** New
  `Tests/Architecture/ServiceLayerTest.php` (phpat) codifies the
  Service-layer rules previously enforced by convention only:
  (1) `Service\*` must not depend on `Controller\*` (reverse-dependency
  guard); (2) `Service\*` must not depend on concrete provider adapter
  classes (`OpenAiProvider`, `ClaudeProvider`, …) — provider invocation
  goes through `Provider\Contract\ProviderInterface` /
  `Provider\Middleware\MiddlewarePipeline` /
  `ProviderAdapterRegistry`, never via direct adapter imports
  (ADR-026). Cross-feature `Service\Feature\*` coupling is still
  convention-guarded — a precise rule is left to a follow-up because
  the obvious form would also forbid each service depending on its
  own `*ServiceInterface` in the same namespace. No code changes were
  required: both new rules pass against the current tree.
- **REC #9c (slice 25):** ADR-028 documents the
  `Configuration/Services.yaml` `public: true` policy. The 37
  current overrides are categorised (public LLM API surface,
  specialized services, test-resolvable repositories, SetupWizard
  collaborators) and each carries a load-bearing reason for being
  public. New `Tests/Unit/Configuration/PublicServicesPolicyTest`
  asserts the count and the ADR's presence — so a future
  `public: true` addition either matches the documented set or the
  PR fails with a prompt to update both the ADR and the test
  expectation. Audit recommendation: "reduce to only those genuinely
  needed". Resolution: the count is the deliberate set, locked in
  the ADR + test rather than mass-reduced (which would break
  ~22 functional tests that resolve repositories/wizard services
  via `$this->get()` — see ADR-028 "Alternative considered").
- `Classes/Domain/DTO/ProviderOptions` — typed value object for
  `Provider::$options` (REC #6 slice 20, closes the typed-DTO follow-up
  to slice 16f). `final readonly class` with three fields: `proxy`
  (`?string`), `customHeaders` (`array<string, string>`), and `extra`
  (`array<string, mixed>`) for everything else. The well-known fields
  cover the transport-level options that real adapters consume today
  (the TCA placeholder is `{"custom_header": "value"}` and existing
  test fixtures use `proxy`); the rest of the open-ended JSON column
  flows through `$extra` so a hand-edited DB row never silently loses
  data. Permissive parsing — `fromArray()` / `fromJson()` drop
  type-mismatched well-known fields rather than throwing; sibling
  helpers (`get()`, `has()`, `withProxy()`, `withCustomHeaders()`,
  `withExtra()`) mirror the read patterns existing
  `getOptionsArray()` callers already use so migration is a straight
  substitution. The DTO is the typed application-level surface; the
  entity still persists JSON to keep Extbase property mapping working
  unchanged.
- `Provider::getOptionsObject(): ProviderOptions` and
  `Provider::setOptionsObject(ProviderOptions): void` — typed accessors
  on the entity (REC #6 slice 20). The DTO is built fresh from the
  persisted JSON on each `get` call (cheap — single `json_decode` plus
  a few key extractions) and never throws on malformed input.
  `setOptionsObject()` collapses an empty DTO to the empty-string
  sentinel `''` rather than persisting `'[]'`, matching how
  `setOptions('')` historically cleared the field. The legacy string
  / array accessors do NOT route through the typed accessor — they
  preserve their pre-REC-#6 behaviour byte-for-byte.
- Five typed Response DTOs for the Setup Wizard backend AJAX
  endpoints (slice 21, REC #5b — closes the audit gap left over
  from the slice-13 `TaskController` split):
  `Response/ProviderDetectionResponse` (wraps a `DetectedProvider`
  for `detectAction`), `Response/WizardTestConnectionResponse`
  (slim `{success, message}` shape for `testAction` — distinct from
  the existing `TestConnectionResponse` that also carries a model
  list), `Response/DiscoveredModelsResponse` (wraps the
  `DiscoveredModel` list from `discoverAction`),
  `Response/GeneratedConfigurationsResponse` (wraps the
  `SuggestedConfiguration` list from `generateAction`), and
  `Response/WizardSaveResponse` (success payload for `saveAction`
  with the persisted-provider summary). Each DTO follows the
  established `final readonly` + `implements JsonSerializable` +
  typed `jsonSerialize()` return shape pattern. The wire shape
  consumed by `Backend/SetupWizard.js` is preserved byte-for-byte
  — every new DTO's `jsonSerialize()` returns exactly the array
  shape the previous inline literal produced.
- `Classes/Specialized/AbstractSpecializedService` — base class for
  every single-task AI service that talks to a provider over HTTP
  (DALL-E, FAL, Whisper, TTS, DeepL — slice 18, REC #7). Concentrates
  the HTTP scaffolding that each service was reimplementing
  separately: extension-config loading (with fail-soft logging),
  availability check, JSON POST, status-code → typed-exception
  mapping (`ServiceConfigurationException` for 401/403,
  `ServiceUnavailableException` for 429 / 5xx / network errors),
  endpoint URL construction. Subclasses declare their identity
  (`getServiceDomain()` / `getServiceProvider()` for exception
  payloads, `getProviderLabel()` for log messages) and the auth
  scheme (`buildAuthHeaders()` — three are in active use today:
  `Bearer ` (OpenAI), `Key ` (FAL), `DeepL-Auth-Key ` (DeepL)).
- `Classes/Specialized/MultipartBodyBuilderTrait` — multipart/form-data
  body construction for services that upload files
  (`WhisperTranscriptionService`, `DallEImageService`). Kept out of
  the base class so JSON-only services don't carry the trait's
  footprint. Pure body builder (`encodeMultipartBody()`) plus a
  full request dispatcher (`sendMultipartRequest()`) that ties into
  the base's `executeRequest()`.

### Changed

- **REC #8b (slice 23a):** Replaced catch-all `catch (Throwable $e)`
  blocks with typed exception handlers across the three Task pathway
  controllers (`TaskExecutionController`, `TaskRecordsController`,
  `TaskWizardController`). Seven catch sites updated. Provider errors
  (`ProviderResponseException`, base `ProviderException`), Doctrine
  DBAL errors, and domain `InvalidArgumentException` now route to
  specific arms with appropriate HTTP statuses; the final `Throwable`
  arm logs full exception detail and surfaces a generic message
  instead of leaking `$e->getMessage()` (which can carry SQL error
  text or provider response bodies). All three controllers gained a
  `LoggerInterface` constructor parameter (autowired by Symfony DI;
  TYPO3 v13's container handles `Psr\Log\LoggerInterface` natively).
  No HTTP-status changes for AJAX paths — `TaskExecutionController`
  keeps its intentional 200-with-`success:false` envelope so the
  frontend `AjaxRequest` can read the JSON.
- **REC #2 (slice 24):** Feature services (`CompletionService`,
  `TranslationService`) now build typed `ChatMessage` VOs at the
  point of construction instead of inline associative arrays.
  `LlmServiceManager` would normalise either shape via
  `ChatMessage::fromArray()`, but typed-from-the-source means
  PHPStan catches role/content drift earlier and the call site is
  self-documenting. The provider/manager interfaces keep accepting
  the `list<ChatMessage|array<string, mixed>>` union for back-compat
  with third-party callers — that's the intentional end-state
  documented on the interface itself. Tests updated to assert
  `instanceof ChatMessage` + `->role` / `->content` field access
  instead of `$messages[0]['role']` array shape.
- `SetupWizardController` (`detectAction`, `testAction`,
  `discoverAction`, `generateAction`, `saveAction`) now returns
  every JSON body through a typed `Response/*` DTO instead of an
  ad-hoc `new JsonResponse([...])` literal. Ten call sites
  migrated total (five success replies + five error/exception
  replies; `ErrorResponse` was reused for every error branch).
  Closes the REC #5 follow-up audit item; brings the wizard
  controller in line with the
  `ConfigurationController` / `ProviderController` /
  post-split task controllers precedent. No behaviour change —
  the AJAX wire format consumed by `Backend/SetupWizard.js` is
  byte-identical to the pre-DTO output. Slice 21.
- `DallEImageService`, `FalImageService`, `WhisperTranscriptionService`,
  `TextToSpeechService`, and `DeepLTranslator` now extend
  `AbstractSpecializedService` instead of carrying their own copies
  of `loadConfiguration()`, `ensureAvailable()`, `executeRequest()`,
  and the auth-header / JSON-POST boilerplate. **Public API is
  unchanged** — every public method on every service keeps its
  signature, and the constructor signature is identical (Symfony DI
  autowires the same dep set as before). Whisper and TTS retain
  their own request-execution path (Whisper because text/srt/vtt
  formats return raw strings, not JSON; TTS because the response
  is binary audio bytes); the rest of the scaffolding still comes
  from the base. Per-service variation points (DALL-E's 400
  validation branch, FAL's 422 branch, DeepL's 456 quota branch,
  FAL's `detail`/`message` error shape, DeepL's top-level `message`
  error shape) override `mapErrorStatus()` / `decodeErrorMessage()`
  hooks on the base. Closes REC #7. Net per-service LOC reduction
  averages ~12% (2828 → 2491 across the five services), but the
  real win is centralisation: a future bug in HTTP error handling
  or auth-header threading lives in one place to fix instead of
  five.
- `ModelSelectionService::modelMatchesCriteria()` now routes capability
  membership through the typed `Model::getCapabilitySet()->has()` instead
  of the legacy string-CSV `Model::hasCapability()`. The legacy strict
  `in_array(... , true)` over `explode(',')` already returned `false`
  for unknown criteria tokens, so the observable outcome is unchanged
  for every previously-valid input. The behavioural delta is in two
  edge cases: capability tokens from external input are now trimmed and
  enum-validated consistently (so `' chat'` resolves the same as
  `'chat'`), and unknown tokens that may exist in the persisted CSV
  (schema drift, removed-but-still-stored capabilities) are dropped at
  parse time rather than matched against an equally-unknown criteria
  string. Coverage:
  `modelMatchesCriteriaTrimsCapabilityTokensFromExternalInput` (the
  trim case) and `modelMatchesCriteriaRejectsUnknownCapabilityToken`
  (documents the no-change-for-unknowns contract). REC #6 slice 16b.

### Deprecated

- `Provider::getOptionsArray(): array<string, mixed>` and
  `setOptionsArray(array<string, mixed>)` are now also deprecated
  since 0.8.0 in favour of the typed
  `getOptionsObject(): ProviderOptions` /
  `setOptionsObject(ProviderOptions)` accessors (REC #6 slice 20 —
  follow-up to slice 16f). Slice 16f had stopped at the array-typed
  surface on the rationale that the `options` column was too
  open-ended for a typed DTO; that argument was reconsidered against
  the parallel `Capabilities`/`FallbackChain`/`ModelSelectionCriteria`
  DTOs and the small but well-defined transport keys actually used
  in production (TCA placeholder `{"custom_header": "value"}`, test
  fixtures `proxy`, `custom_param`). The new `ProviderOptions` DTO
  types those well-known keys (`proxy`, `customHeaders`) and routes
  everything else through an `extra: array<string, mixed>` bag so no
  existing data is lost. The array accessor is retained for
  back-compat with the `ProviderAdapterRegistry::buildAdapterConfig()`
  call site that merges it into the adapter-init config; that call
  site will migrate in a follow-up slice. The string and array
  accessors will not be removed before a major version bump.
- `Provider::getOptions(): string` and `setOptions(string)` are
  deprecated since 0.8.0 in favour of the typed
  `getOptionsObject(): ProviderOptions` /
  `setOptionsObject(ProviderOptions)` accessors (REC #6 slice 20,
  updated rationale from slice 16f). The legacy raw-JSON methods
  remain for Extbase property mapping (the framework hydrates the
  entity through this getter / setter pair) and will not be removed
  before a major version bump. REC #6 slice 16f — superseded by
  slice 20.
- `LlmConfiguration::getOptions(): string` and `setOptions(string)` are
  deprecated since 0.8.0 in favour of the typed
  `getOptionsArray(): array<string, mixed>` /
  `setOptionsArray(array<string, mixed>)` accessors. The `options`
  field carries provider-specific extras beyond the typed entity
  columns (`temperature`, `maxTokens`, `topP`, `frequencyPenalty`,
  `presencePenalty`, `systemPrompt`, …) — its shape is open-ended by
  design and varies per provider, so REC #6 stops at the array-typed
  surface rather than introducing a typed DTO that would impose
  false structure. The legacy raw-JSON methods remain for Extbase
  property mapping (the framework hydrates the entity through this
  getter / setter pair) and will not be removed before a major
  version bump. REC #6 slice 16e.
- `LlmConfiguration::getModelSelectionCriteria(): string` and
  `setModelSelectionCriteria(string)` are deprecated since 0.8.0 in
  favour of the typed `getModelSelectionCriteriaDTO(): ModelSelectionCriteria` /
  `setModelSelectionCriteriaDTO(ModelSelectionCriteria)` accessors
  (the typed `ModelSelectionCriteria` DTO has lived in
  `Classes/Domain/DTO/` for a while and is the documented
  application-level surface). The legacy methods remain for Extbase
  property mapping (the framework hydrates the entity through this
  getter / setter pair) and will not be removed before a major
  version bump. Production callers that consume the array shape
  (`ModelSelectionService::resolveModel()` via
  `getModelSelectionCriteriaArray()`) are NOT migrated in this slice
  — `findMatchingModel(array $criteria)` keeps its array signature
  for now; a future slice can adopt the typed DTO end-to-end. REC #6
  slice 16d.
- `LlmConfiguration::getFallbackChain(): string` and
  `setFallbackChain(string)` are deprecated since 0.8.0 in favour of
  the typed `getFallbackChainDTO(): FallbackChain` /
  `setFallbackChainDTO(FallbackChain)` accessors (the typed `FallbackChain`
  DTO has lived in `Classes/Domain/DTO/` since the middleware-pipeline
  rework — see ADR-026 — and every production caller already routes
  through it; the slice's only delta is to nudge new application code
  off the raw JSON string surface). The legacy methods remain for
  Extbase property mapping (the framework hydrates the entity through
  this getter / setter pair) and will not be removed before a major
  version bump. REC #6 slice 16c.
- `Model::getCapabilities()`, `getCapabilitiesArray()`,
  `getCapabilitiesAsEnums()`, `setCapabilities()`,
  `setCapabilitiesArray()`, `hasCapability()`, `addCapability()`,
  `removeCapability()` are deprecated since 0.8.0 in favour of
  `getCapabilitySet()` / `setCapabilitySet()` (typed
  `Domain\DTO\CapabilitySet`). The legacy accessors remain functional
  and are not removed before a major version bump — TCA-driven
  persistence still hands the entity raw CSV strings, and the
  duplicate-preserving semantics of the legacy accessors
  (relevant when callers iterate the CSV directly) are kept
  byte-for-byte. REC #6 slice 16b.

### Added

- `Domain/DTO/CapabilitySet` — typed value object wrapping a deduplicated,
  order-preserving `list<ModelCapability>` for the model's capability set.
  `Model` gains `getCapabilitySet(): CapabilitySet` and
  `setCapabilitySet(CapabilitySet)` accessors; the legacy
  `getCapabilities()` / `getCapabilitiesArray()` / `getCapabilitiesAsEnums()`
  / `setCapabilities()` / `setCapabilitiesArray()` accessors remain
  byte-for-byte unchanged (they do NOT route through the new DTO so
  duplicate-preserving semantics survive intact). CSV serialisation of
  the entity field is unchanged (`Model::$capabilities` stays `string`);
  the DTO is the typed runtime representation. Slice 16a of REC #6;
  slice 16b will migrate callers to the typed accessors. The DTO's
  factories `fromCsv()` and `fromArray()` defensively drop unknown
  tokens (schema drift, stray whitespace via trim) so an old DB row
  carrying a capability that has since been removed from the enum
  cannot crash readers. Token matching is case-sensitive — the
  persisted CSV is always lowercase (TCA `eval=trim,lower`).
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
  `CompletionService`, `EmbeddingService`, `VisionService` and
  `TranslationService` inject the resolver and auto-populate
  `beUserUid` on their respective options when the caller did not set
  one — slices 15a (`CompletionService`) and 15b (`EmbeddingService` /
  `VisionService` / `TranslationService`) of REC #4 (automatic budget
  pre-flight wiring across all feature services).
  `ChatOptions` (and by extension `ToolOptions`) gained typed
  `beUserUid` / `plannedCost` fields with `withBeUserUid()` /
  `withPlannedCost()` setters; slice 15b extends the same fields to
  `EmbeddingOptions`, `VisionOptions` and `TranslationOptions`.
  `LlmServiceManager::chat()` / `complete()` / `chatWithTools()` /
  `embed()` / `vision()` translate the values into
  `BudgetMiddleware::METADATA_BE_USER_UID` /
  `METADATA_PLANNED_COST` on the `ProviderCallContext` so the existing
  middleware reads them without changes; the helper
  `buildBudgetMetadata()` takes raw nullable values rather than a
  typed option object so every option type can reuse it without a
  marker interface. Fields are deliberately kept off every option
  type's `toArray()` — they are pipeline metadata, not provider-side
  options, and must never reach the provider wire payload.
  `TranslationService` is the only service that builds `ChatOptions`
  internally (translate / detectLanguage / scoreTranslationQuality);
  each construction site forwards `beUserUid` (resolver-resolved or
  explicit) and `plannedCost` so the BudgetMiddleware sees them.
  Specialized translators (DeepL et al.) bypass `LlmServiceManager`
  entirely and are not subject to BudgetMiddleware in this slice.

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

- The eight `Model::CAPABILITY_*` legacy public class constants
  (`CAPABILITY_CHAT`, `CAPABILITY_COMPLETION`, `CAPABILITY_EMBEDDINGS`,
  `CAPABILITY_VISION`, `CAPABILITY_STREAMING`, `CAPABILITY_TOOLS`,
  `CAPABILITY_JSON_MODE`, `CAPABILITY_AUDIO`) have been REMOVED. They
  have been marked `@deprecated` since the introduction of the
  `Domain\Enum\ModelCapability` backed enum, and the architecture
  audit (REC #10) flagged the parallel-truths state as a structural
  debt to clear. Downstream consumers must migrate references to the
  enum value: `Model::CAPABILITY_CHAT` → `ModelCapability::CHAT->value`
  (or pass the enum directly anywhere that accepts
  `string|ModelCapability` — e.g. `CapabilitySet::has()`,
  `with()`, `without()`). The `Model::getAllCapabilities()` static
  helper (used by `ModelController` to populate the BE list view's
  capability label dropdown) is unchanged — it is keyed on the enum
  values, not on the removed constants. REC #10.
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
