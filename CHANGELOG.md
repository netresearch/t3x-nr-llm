# Changelog

All notable changes to this extension are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `FailureClass` and `FailureClassifier` (ADR-095): one shared taxonomy behind
  the retry, circuit-breaker and streaming-retry decisions, which had each kept
  a private `instanceof` ladder that drifted. A 5xx is now classified as a
  provider-side fault.
- `SpecializedServiceException::getStatusCode()` exposes the upstream HTTP
  status the error carries (0 for a transport failure), so the specialized
  failures can be classified once they reach the shared pipeline.

- Tool egress is governed by a data classification instead of denylists alone:
  every tool has a `ToolDataClass` (from `publicContent` up to
  `secretAdjacent`), every provider declares a `TrustZone` (`local`,
  `privateHosted`, `externalEu`, `externalGlobal`) implying a ceiling, and the
  new public `ToolCallPolicyInterface` decides — registered, enabled, permitted,
  within the configuration's groups, within the ceiling — returning a typed
  reason instead of a silent absence (ADR-094).
  **Ships in observe mode**: `tools.dataClassEnforcement = observe` logs what
  enforcement would do without removing anything. Run the
  "Declare a trust zone for existing LLM providers" upgrade wizard and perform a
  database compare after upgrading; an un-stamped provider resolves to the
  strictest zone.
- `AgentRunTerminationReason` records **why** an agent run ended — completed,
  iteration cap, exhausted budget, policy denial, denied approval, provider
  failure or cancellation — in a new `termination_reason` column. A budget stop
  and an iteration cap were previously indistinguishable: both are completed
  and truncated (ADR-092).
- `nrllm:agent:cancel <uuid>` retires a run that is stuck queued, running or
  awaiting a decision. `CANCELLED` is now a state runs actually reach.
- `AiActorContext` — an explicit caller identity (backend user, service account
  or anonymous) for the stateful entry points, so a queue worker can act for the
  user who queued the work instead of inheriting the ambient backend user
  (ADR-091).
- `LlmServiceManagerInterface::chatForConfiguration()`, the message-list
  counterpart of `completeForConfiguration()`.
- `ConfigurationResolver::getActiveByIdentifierForActor()` evaluates activity
  and BE-group restrictions against a passed actor instead of
  `$GLOBALS['BE_USER']`.
- `AiSessionRepositoryInterface::appendMessageAtNextSequence()` allocates a
  message sequence race-free.
- Per-category data retention: `privacy.retention.conversation`,
  `.agentRun`, `.approval`, `.telemetry`, `.evaluation` and `.skillAudit`
  override the global `privacy.retentionDays` window, so conversation
  transcripts can expire long before telemetry does (ADR-064).
- `nrllm:privacy:purge` now covers **every** content-bearing table:
  conversation sessions (`tx_nrllm_ai_session`,
  `tx_nrllm_ai_session_message`) and agent runs (`tx_nrllm_agentrun`,
  `tx_nrllm_agentrun_event`) join evaluation results, the skill audit and
  telemetry. It reports the window and the row count per category.
- `AgentRunRepositoryInterface::purgeUnfinishedOlderThan()` reaps runs that
  never reached a terminal status on the separate, longer `approval` window.
- Administration guide "Data retention & purge"
  (`Documentation/Administration/DataRetention.rst`).

### Changed

- A provider 5xx now triggers fallback to the next configuration and counts
  towards opening the provider's circuit breaker (ADR-095). Previously only a
  connection error and a 429 did, so a provider returning 500 repeatedly neither
  failed over nor tripped — the two "this provider is unhealthy" mechanisms both
  ignored the signal.
- **Breaking:** the specialized image/speech/translation services throw
  `ServiceQuotaExceededException` on HTTP 429 instead of the generic
  `ServiceUnavailableException`, so a rate limit is distinguishable from an
  outage. Both extend `SpecializedServiceException`; a catch on the base class is
  unaffected (ADR-095).

- **Breaking (tool contract):** the per-configuration tool gate — the skills'
  declared allow-list intersected with `allowed_tool_groups` — is applied inside
  `ToolLoopService` instead of in the tool playground. Every consumer of the
  published `ToolLoopServiceInterface` is now subject to it; previously only the
  playground was, so a downstream caller received the full globally-enabled set
  (ADR-093). The playground's own behaviour is unchanged.
- `get_tca` routes its table access through the shared `TableReadAccessService`
  like its sibling `get_full_tca`, and therefore no longer describes the
  extension's own or `nr_vault`'s tables — to anyone, administrators included.
  It previously checked `tables_select` directly, which every admin passes.
- The `rag` tool group declares a `configured_endpoint` egress scope and
  `SolrSearchBackend` validates its assembled URL against the configured host
  (http(s) only, no credentials in the URL, exact host:port). The policy
  previously claimed the group could not egress at all while the backend was
  issuing HTTP requests — an audit gate, not a new confidentiality boundary,
  since the host was always operator-supplied (ADR-093).
- A guardrail stop is recorded as a policy outcome (`policy_denied`, or
  `approval_denied` when an approval was required and never obtained) instead
  of as a provider failure, so a denial can no longer be mistaken for an outage
  in the run table (ADR-092).
- An agent run can no longer be settled twice: `finishRun()` transitions only
  non-terminal runs and reports whether it did. A late settle — for instance
  the streamed path's `finally` block after a client disconnect — previously
  overwrote a finished run's totals and error class.
- The playground now fails a run whose approval state could not be stored
  instead of answering "awaiting approval". An approval-gated tool is
  side-effecting; promising a resume that cannot happen is worse than an error.
- **Breaking:** `ConversationServiceInterface::startSession()` and `send()` take
  a leading `AiActorContext`. A session uuid is no longer sufficient to continue
  a conversation: the actor must own the session, be an administrator, or be a
  service account (ADR-091). Previously any caller holding a uuid could read and
  continue another backend user's conversation.
- A conversation turn now runs against the configuration the session was opened
  with, resolved fresh on every turn. Previously the stored identifier was never
  read and every turn silently used the installation default — a different
  model, budget and guardrail set than the session started with. A deactivated
  or newly restricted configuration now stops the session instead of falling
  back.
- Conversation turns are attributed to the acting backend user, so per-user
  budgets apply to conversations as they do to one-shot completions.
- **Schema:** `tx_nrllm_ai_session_message` gets a UNIQUE key on
  `(session, sequence)` and `tx_nrllm_ai_session` a UNIQUE key on `uuid`. An
  installation that already produced colliding rows through the sequence race
  must resolve those duplicates before the database analyzer can apply the
  index.

- Persisted agent-run steps follow the central privacy level. At the default
  metadata level the stored payload keeps timings, tokens, cost, tool names and
  sizes but no prompts, tool arguments, tool results or raw provider bodies;
  `redacted` masks them; `full` stores them verbatim. The live playground trace
  is unaffected — it renders from memory.
- `AgentRunRepositoryInterface::purgeOlderThan()` deletes only **terminal**
  runs. Previously a purge by age could delete a run suspended for a human
  approval, destroying work in flight together with its resumable state.
- `nrllm:session:purge` and `nrllm:telemetry:purge` take their default window
  from the central privacy policy instead of a hardcoded 30 days.
- Session and agent-run purges delete in chunks of 500 instead of building one
  unbounded `IN()` list on a long-neglected installation.

### Fixed

- `DeepLTranslator` now runs a budget pre-flight on `translate()` and
  `translateBatch()`. It was the last paid external call with no cap at all;
  `TranslationService` threads `plannedCost` and `configuration` through to it
  alongside the already-threaded `beUserUid` (ADR-078, amended).
- `FalImageService` passes the caller's configuration identifier into its budget
  check, so per-configuration caps apply to it and not only the per-user cap —
  it previously passed `null` and only the user cap could ever fire.
- Corrected the middleware ordering documented in four middleware docblocks
  (`BudgetMiddleware` claimed a "Guardrail outermost at 115" that does not
  exist; `Fallback`, `Usage` and `Cache` omitted Guardrail and Idempotency
  entirely) and removed the stale note in `GuardrailInterface` calling input
  guardrails an unimplemented follow-up — they shipped in ADR-087.
- Restored the missing `[0.23.0]` changelog link definition; `[Unreleased]` now
  compares against `v0.23.0` instead of `v0.22.0`.

## [0.23.0] - 2026-07-20

Adds a content-policy guardrail pipeline, human-in-the-loop tool approval,
persistent AI sessions and agent runs, schema-validated structured outputs, and
typed provider exceptions, alongside broad tool-egress hardening and a bilingual
documentation site.

### Added

- Content-policy guardrail pipeline screening outgoing prompts and responses,
  with end-of-stream auditing and live streaming redaction via a holdback buffer
  (ADR-085, ADR-087, ADR-088, ADR-089).
- Human-in-the-loop tool approval: the agent loop can suspend for a human
  decision and resume the run (ADR-084).
- Persistent AI sessions with memory (ADR-083) and durable agent-run persistence
  with a queryable event stream (ADR-081).
- Schema-validated structured outputs with automatic repair (ADR-082).
- Typed `ProviderAuthenticationException` (HTTP 401) and
  `ProviderRateLimitException` (HTTP 429) provider exceptions (ADR-080).
- `ToolLoopServiceInterface` so downstream extensions can inject and test-double
  the tool loop (`runLoop()` / `resume()`) without depending on the final
  `ToolLoopService`.
- Bilingual GitHub Pages documentation site with ADRs, search, developer
  feature deep-dives (streaming/tools, RAG, providers), and on-device AI answers
  rendered as Markdown.

### Changed

- On resume, the tool loop restores the suspended run's original allow-list and
  options and re-applies the tool gate (permission, global enablement, RBAC) to
  the pending calls, fail-closed (ADR-084).
- Keep the system prompt on every turn and advance the run sequence on a failed
  turn.

### Fixed

- Harden provider response parsing against malformed and hostile upstreams,
  including typed guards for the DALL-E and DeepL response shapes.
- Null-guard site-config key normalization; clamp `maxRetries` to its TCA upper
  bound.
- Purge agent-run events by run id.

### Security

- Tool egress hardening: enforce FAL file-mount boundaries and backend language
  access in read tools, exclude workspace-draft references, and broaden the
  credential egress denylist (digit-suffix and concatenated secret columns,
  `apitoken`, `sk-proj-` keys, and Composer `auth.json` in `read_source`).

## [0.22.0] - 2026-07-17

Pulls the retrieval/document capabilities forward that the 0.21.0 revisit issues
had deferred to a second consumer, and extends the named-configuration model to
completion and translation.

### Added

- **Per-configuration translation** (#428, #429, #430).
  `TranslationService::translateForConfiguration()` translates with a stored
  `LlmConfiguration`'s persona/tone — routing through `chatWithConfiguration()`
  so the configuration's `system_prompt`, model and provider apply while the
  translation task rides in the user message. The translation path now also
  forwards the configured `model` (previously dropped), and a `configuration`
  field on `TranslationOptions` makes the config-selected specialized translator
  reachable.
- **Named-configuration completion** (ADR-077, #423). `CompletionService` gains
  a `*ForConfiguration()` family (`completeForConfiguration()`,
  `completeJsonForConfiguration()`, …) so plain completions resolve a named
  `LlmConfiguration` (its provider/model/prompt) and run through the middleware
  pipeline, enforcing budgets and attributing cost per configuration — matching
  the existing chat/tools/embedding `*ForConfiguration` entry points.
- **Budget pre-flight for specialized image/speech services** (ADR-078, #425).
  The DALL-E/FAL/Whisper/TTS services dispatch HTTP directly and bypassed the
  provider middleware; they now enforce per-user and per-configuration spend
  ceilings before the provider request (previously usage was attributed but not
  enforced on this path).
- **First-party fakes for Completion, Vision and Budget services** (ADR-079,
  #427). Ready-made doubles under the runtime-autoloaded
  `Netresearch\NrLlm\Testing\` namespace, so consumers stop hand-rolling doubles
  that break when an interface grows.
- **Neutral cross-encoder reranker protocol** (ADR-075, #414). `Service\Rerank\`
  ships `RerankerInterface` (id/text in, id/score out — no consumer DTOs),
  `HttpReranker` speaking the sidecar contract (batch cap 128, configurable
  timeout, typed `RerankerException` on transport/status/protocol failures),
  `NullReranker`, and a factory selecting by the `rerankerEndpoint` extension
  setting. The sidecar (`Build/reranker/`: cross-encoder scoring service,
  Dockerfile, protocol README) moves in from nr_ai_search so client and server
  version together. Integer candidate ids (TYPO3 uids) are accepted and
  normalized. DTO mapping, ordering merge, degradation policy and threshold
  gates stay consumer-side.
- **Document understanding** (ADR-076, #416).
  `Specialized\Document\DocumentAnalysisService` analyzes a PDF via the
  provider's native document path (`DocumentCapableInterface`: whole-document
  reasoning in one call) and falls back to poppler rasterization plus per-page
  vision otherwise. `PdfRasterizerInterface` + `PopplerPdfRenderer` (hardened:
  concurrent pipe draining, stderr in failure exceptions, race-free temp-stub
  handling) come from nr_ai_search's proven pipeline; `poppler-utils` is an
  optional runtime dependency (composer suggest). Ingestion orchestration
  stays consumer-side.
- **Hosted rank fusion** (ADR-074, #415). `Service\Retrieval\ReciprocalRankFusion`
  ports nr_ai_search's fusion math with an identical `fuse()` signature, so
  hybrid consumers migrate by swapping the namespace import. Newable utility,
  not a DI service; ADR-049's first-available-wins cascade is unchanged.

## [0.21.0] - 2026-07-17

### Added

- **Public keyword-search facade** (ADR-071). New public contract
  `Service\Retrieval\KeywordSearchInterface` — `search(string $query, int $limit,
  ?int $languageId = null): list<KeywordHit>` plus `isAvailable()` — over the ADR-049
  site-search cascade, so downstream extensions no longer bind to private retrieval
  internals. Input is clamped (never throws), results are always public-only, and any
  backend failure degrades to an empty list. A second registration,
  `nr_llm.keyword_search.index_backed`, excludes the priority-0 database LIKE fallback
  for consumers that must treat "index unavailable" as empty (e.g. hybrid dense+sparse
  fusion). Documented in `Documentation/Api/KeywordSearch.rst`; audited public-service
  count 26 → 28.
- **Retrieval-quality evaluation** (ADR-072). Golden question sets (MATCH/GAP forms,
  hard-class taxonomy, multi-target relevance labels) scored by top-1/top-3
  document-level hit rate, mirroring the ADR-060 golden-prompt framework.
  `EvaluatableRetrieverInterface` makes the retriever pluggable, so the builtin lexical
  cascade and external retrievers are measured with the same protocol; results persist
  through the existing regression machinery. `RetrievalEvalRunCommand` runs a set from
  the CLI. No golden questions ship with the extension.
- **First-party test doubles** (ADR-073). `Testing\FakeToolCallingService` and
  `Testing\FakeEmbeddingService` in a runtime-autoloaded namespace, so consumers stop
  hand-rolling fakes that drift from the interface. Each implements the real interface;
  excluded from container autoconfiguration.
- **`ConfigurationResolver::getActiveByIdentifier()`** (ADR-070). Resolves a named
  configuration by identifier for user-less contexts (CLI, Messenger workers, anonymous
  frontend), applying the isActive and backend-group access guards a raw repository
  lookup skips. Throws typed `ConfigurationNotFoundException`,
  `ConfigurationInactiveException`, or `AccessDeniedException`.
- **Seeded embedding-model dimensions.** The ADR-055 `dimensions` column is populated for
  well-known embedding models on setup and back-filled on existing rows by an upgrade
  wizard (never overwriting a configured value), so consumers take the fast path instead
  of a paid calibration probe.
- **Consumer recipes** in the developer documentation: protecting anonymous
  LLM-cost-bearing endpoints (per-IP rate limiting, `Sec-Fetch-Site` checks) and
  rendering LLM markdown server-side safely.

### Fixed

- **Solr URL scoping for scheme-relative site bases.** A `base: //host/` site base has an
  empty scheme, so `SolrSearchBackend::siteScopedUrl()` emitted the degenerate
  `://host/path` in evidence URLs and citations; the scheme now defaults to `https`.
  Document URLs with an empty or unparseable host are dropped rather than emitted to a
  foreign origin.
- **Adjacent text nodes in tool excerpts.** `strip_tags()` glued adjacent text
  (`<td>Price</td><td>100</td>` → `Price100`) in the excerpts handed to the model; a
  space is now inserted before non-inline tags, keeping inline-joined words intact.

## [0.20.0] - 2026-07-16

Closes out the operator-config audit: every backend-visible provider/model/configuration
setting now either works or is gone. Three breaking changes — see below.

> Note: version 0.19.1 was prepared but never tagged; its two fixes first ship in this
> release.

### Added

- **Per-configuration daily limits are now enforced** (#389). The Configuration record's
  *Max requests / tokens / cost per day* fields were stored but never consulted;
  `BudgetService` now aggregates the dispatched configuration's current-day usage from
  `tx_nrllm_service_usage` and denies requests once a cap is exhausted, in addition to
  the existing per-user budget — most restrictive wins. Callers without a backend user
  (CLI, scheduler, frontend) are gated by configuration caps too, where they previously
  bypassed budgeting entirely.
- **Model limits act as call defaults** (#390). A model's *Max output tokens* becomes the
  effective `max_tokens` when neither the call options nor the configuration set one
  (precedence: per-call option > configuration > model > provider default), and an
  embedding model's *Dimensions* fills `EmbeddingOptions` when the caller left it unset.
- **Organization ID and custom headers are now sent** (#388). The provider's
  *Organization ID* is emitted as the `OpenAI-Organization` header (OpenAI-compatible
  adapter types included), and `options.customHeaders` is applied on every request path
  — streaming builders included — with header names/values sanitized (CR/LF-injection
  guarded). The `options.proxy` key is documented as not implemented; the global TYPO3
  HTTP proxy applies.
- **Upgrade wizard `nrLlm_providerApiTimeout120`** migrates provider rows persisted at
  the old `api_timeout` default of 30 to the new default 120 (part of #384).

### Changed

- **BREAKING: `api_timeout` is applied as a total-response timeout** (#384). The field
  was write-only, so every provider request ran unbounded (TYPO3's default HTTP timeout
  of 0) and a silent provider could pin a PHP-FPM worker indefinitely. Requests are now
  bounded by the per-request `timeout` option (from the configuration's effective
  timeout, ≥ 120s by default) with the provider's `api_timeout` as fallback; the default
  moves 30 → 120; timed-out requests are not retried; streaming aborts at the same total
  timeout. Calls that legitimately run longer need a raised configuration/model timeout.
- **BREAKING: `max_retries` counts retries after the initial attempt** (#387). `0` now
  sends exactly one request (previously: zero requests failing with *"after 0 attempts:
  Unknown error"*); the default of 3 now sends up to 4 requests on persistently failing
  providers. Negative values clamp to "no retries".
- **BREAKING: `BudgetServiceInterface::check()` gained an optional `?LlmConfiguration`
  parameter** (#389) — third-party implementations of the interface must update their
  signature.
- `LlmConfiguration::setMaxTokens()` accepts 0 as "no explicit limit" (clamp floor moved
  from 1); existing records are unaffected since the old floor made 0 unstorable (#390).

### Removed

- **BREAKING: the PromptTemplate stack** — entity, repository, service + interface,
  exception, DI alias and the `tx_nrllm_prompttemplate` table declaration (#399,
  ADR-069). It was never usable at runtime (the table had no TCA) and had no consumers;
  prompt snippets (ADR-031) and tasks superseded the concept. The orphaned table can be
  dropped via the database analyzer; the audited public-service count moves 27 → 26.

### Fixed

- Criteria-mode configurations no longer share embedding cache entries under an empty
  model id — the cache key resolves the concrete model when the configuration has no
  direct model relation (#390 follow-up).
- `max_retries = 0` no longer disables a provider with a misleading connection error
  (#387, see Changed).

## [0.19.1] - 2026-07-15

### Fixed

- **Criteria-mode configurations could not be used by `*ForConfiguration()`.** `getAdapterFromConfiguration()` read the concrete model relation directly, which is null for criteria-mode configurations (`model_selection_mode = 'criteria'`, `model_uid = 0`), so every `embedForConfiguration()` / `chatWithConfiguration()` / tool call on such a configuration threw `Configuration "…" has no model assigned`. The model is now resolved through `ModelSelectionService` (which returns the directly configured model unchanged for fixed-mode configs), covering the embed / chat / tools / complete / stream paths through the single choke point. The configuration entity is deliberately not mutated — doing so would mark a repository-managed Extbase record dirty and persist `model_uid`, silently converting a criteria-mode record into a fixed-mode one (#372).
- The embedding cache tag built from the configuration identifier (`nrllm_configuration_<identifier>`) is now sanitized via the new `CacheManagerInterface::sanitizeCacheTag()`. A dotted preset identifier (`nr_ai_search.embeddings`) otherwise made the cache frontend reject the tag on `set()` when `cache_ttl > 0` — the same class as the cache key/tag sanitization shipped in 0.19.0 (#372).

## [0.19.0] - 2026-07-14

### Added

- **Per-user usage attribution for the specialized speech and image services** (ADR-057, the ADR-052 follow-up): `TranscriptionOptions`, `SpeechSynthesisOptions` and `ImageGenerationOptions` implement `BudgetAwareOptionsInterface` via `BudgetFieldsTrait` (optional `beUserUid`/`plannedCost` constructor params and `fromArray` keys, negative-value validation), and `WhisperTranscriptionService`, `TextToSpeechService` and `DallEImageService` forward the resolved uid to `trackUsage()` — so transcription, synthesis and image generation are attributed to the calling backend user instead of the ambient `be_user = 0` bucket for FE/CLI/worker callers. `FalImageService` reads a documented `beUserUid` options-array key (no DTO exists), `DallEImageService::createVariations()`/`edit()` gain an optional trailing `?int $beUserUid`. The budget fields stay out of `toArray()`, so they never reach the provider APIs. Attribution only — these services bypass the middleware pipeline, so per-user budget ceilings are still not enforced there (out of scope in ADR-057) (#362).

### Fixed

- **LLM configuration identifiers with dots crashed every cached call.** `CacheManager::generateCacheKey()` and the provider-derived cache tags used the raw provider/configuration identifier, but TYPO3 cache frontends only accept `A-Za-z0-9_%-&` in entry identifiers and tags. The documented preset naming scheme uses dots (e.g. `nr_ai_search.embeddings`), so every completion/embedding call for such a configuration threw `"… is not a valid cache entry identifier"` — found live on the first 0.18 deployment. The provider segment is now sanitized in both the key and every tag (#365).
- `LlmTranslator`'s underlying chat calls (translation and language detection) built their `ChatOptions` without the `beUserUid` that `TranslationService` attaches per ADR-052, so the pipeline-recorded chat row — which carries all tokens and cost — landed in the ambient bucket and `BudgetMiddleware` skipped per-user enforcement. The uid is now threaded into both `ChatOptions` constructions; the public `detectLanguage()` signature stays ambient (#361).
- Backend `Test.js` is loaded as an ES module, fixing the model-test button in the backend module (#363).

## [0.18.0] - 2026-07-14

### Added

- **Configuration presets module UI** (the ADR-056 follow-up): the Configurations backend module surfaces pending presets — one row per preset with name, identifier, description, and the preflight result (the model the criteria currently match, or the missing requirement) — and imports one via the existing `nrllm_preset_import` endpoint with a single click; the panel renders only while presets are pending. Imported records whose declaration changed since import are flagged with a non-blocking "Preset changed" hint (checksum comparison via the new `ConfigurationPresetRegistry::drifted()`), and `nrllm_preset_list` returns these as a new `drifted` list. The checksum-driven update flow remains future work (ADR-056).

### Changed

- The specialized translators forward the caller-supplied `beUserUid` to usage tracking: `TranslationService` re-attaches the resolved uid to the options array handed to `TranslatorInterface` implementations (`beUserUid` key — budget fields are deliberately excluded from `TranslationOptions::toArray()`), and `DeepLTranslator` / `LlmTranslator` pass it on to `trackUsage()`, so translator usage rows are attributed like the middleware-pipeline paths. The speech/image services (Whisper, TTS, DALL-E, FAL) keep ambient attribution — their option shapes carry no budget fields (ADR-052).
- The remaining validation errors thrown with PHP's global `InvalidArgumentException` (record table reader, retrieval queries, vision content, embedding responses, provider response parsing, backend response DTOs, provider vault-key checks, usage analytics) now throw nr_llm's `Exception\InvalidArgumentException`, so they are catchable via `NrLlmExceptionInterface` too. Backwards compatible: the nr_llm class extends `\InvalidArgumentException`, existing catches keep matching (ADR-053).

## [0.17.0] - 2026-07-13

### Added

- **Configuration presets**: consuming extensions declare the `LlmConfiguration` records they need via the `nr_llm.configuration_preset` DI tag (`ConfigurationPresetProviderInterface` + `ConfigurationPreset` value objects). Presets express requirements as `ModelSelectionCriteria` — never providers, models, or API keys — and a backend admin imports a pending preset with one confirmation through the new admin-gated AJAX endpoints `nrllm_preset_list` (pending presets incl. a per-preset preflight against the configured models) and `nrllm_preset_import`. Imported records are active criteria-mode configurations resolved at runtime by `ModelSelectionService`; the new `preset_checksum` column makes a changed declaration detectable (ADR-056) (#347).
- `ToolCallingService` / `ToolCallingServiceInterface` — the feature-service pair for tool-calling chat (`chatWithTools()`, `chatWithToolsForConfiguration()`), completing the narrow-interface catalogue so consumers no longer need to depend on the 19-method `LlmServiceManagerInterface` for tool calling (ADR-051).
- `NrLlmExceptionInterface` — a marker interface implemented by every exception thrown on the public API surface, so consumers can write a single `catch (NrLlmExceptionInterface $e)` instead of enumerating concrete classes. `ChatMessage`/`ToolSpec`/`ToolCall::fromArray()` normalisation errors now throw nr_llm's `Exception\InvalidArgumentException` (a subclass of PHP's, so existing catches keep matching) and are covered by the marker too (ADR-053).
- `ChatMessage` models the two tool-loop turns as typed value objects: an assistant turn carrying `$toolCalls` (`list<ToolCall>`) and a tool turn carrying `$toolCallId`, built via the new `ChatMessage::assistantToolCalls()` / `ChatMessage::toolResult()` factories. `toArray()`/`jsonSerialize()` emit the OpenAI wire shape (`tool_calls` with JSON-string `function.arguments`, empty arguments as `{}`; `tool_call_id`), `fromArray()` accepts the keys back (including `content: null` alongside `tool_calls`), and invalid combinations — tool calls on a non-assistant role, a `tool_call_id` on a non-tool role, an empty id — are rejected with nr_llm's `InvalidArgumentException`. `ToolLoopService` builds its turns through the factories instead of raw wire arrays, and the tool-calling developer docs are rewritten on top of the value objects (ADR-054) (#345).
- Configuration-record path for embeddings: `LlmServiceManager::embedForConfiguration()` and `EmbeddingService::embedForConfiguration()` / `embedBatchForConfiguration()` resolve the adapter from a DB-backed `LlmConfiguration` (vault key + model + pricing) and run through the middleware pipeline, so embedding consumers get per-configuration budgets and cost attribution like every chat-shaped capability. Per-call `EmbeddingOptions` override the configuration's stored defaults (an options `model` wins over the configuration's model id). Note: `LlmServiceManagerInterface` and `EmbeddingServiceInterface` gained these methods — implementers outside this repo must add them. New `dimensions` field on model records (`tx_nrllm_model`, 0 = unknown) so consumers can validate a persisted vector index against the configured model without a live calibration probe (ADR-055) (#346).

- Reasoning toggle for hybrid-thinking models (Ollama `think`) (#341).

### Changed

- Usage attribution honours the caller-supplied `beUserUid`: `UsageMiddleware` forwards the uid the options carry (`withBeUserUid()`, the same metadata the budget gate enforces against) to `UsageTrackerService`, instead of always reading the ambient `backend.user` aspect. Frontend/CLI callers that pass a uid no longer need to impersonate a technical backend user just to get correct `be_user` rows; without a caller uid the ambient fallback behaves as before. `UsageTrackerServiceInterface::trackUsage()` gains an optional trailing `?int $beUserUid = null` parameter — implementers outside this repo must add it (ADR-052).

### Fixed

- Ollama's native `message.thinking` is surfaced on completion responses (`CompletionResponse::$thinking` / `hasThinking()`) (#342).

## [0.16.1] - 2026-07-10

### Fixed

- The setup wizard's AJAX persist path now enforces the column limits FormEngine would apply: names are clamped to 255 characters, identifiers to 100, and caller-provided identifiers get the TCA contract (`alphanum_x`, lowercase). Strict-mode MySQL/MariaDB previously rejected overlong values with a 500 where SQLite silently truncated. Generated identifier suffixes are now random — the `time()`-based suffix collided for same-named records created in one batch (#335, #339).
- Decimal-backed model values (`temperature`, `top_p`, penalties, cost ceilings) are rounded to their column scale in the setters, so every DBMS stores and returns the identical value (#336, #339).

### Changed

- The MariaDB CI leg runs the full functional configuration (functional + e2e-backend suites) now that the strict-mode incompatibilities are fixed (#339).

## [0.16.0] - 2026-07-10

### Added

- **RAG site-search tools** (41 tools / 8 groups): new `rag` tool group with `site_rag_query` — cited evidence about the website's own public content (`source_id`, title, URL, match excerpt), retrieved through a priority cascade over whichever search index is installed (EXT:solr via its HTTP select API, ke_search, indexed_search) with an always-available pages/tt_content database fallback — and `site_fetch_source` to read a source's full indexed text. Index-level filtering is strictly public-only (what the anonymous visitor could read); the answering backend is named in every evidence package (ADR-049). The database fallback matches natural-language questions word-wise and ranks pages by how many query words they cover (#332, #333).

### Changed

- Functional tests additionally run against MariaDB in CI (one matrix cell), keeping the MySQL-only retrieval branches exercised; two pre-existing MySQL incompatibilities in the e2e-backend suite surfaced by this are tracked in #335/#336 (#334, #337).

## [0.15.0] - 2026-07-09

### Added

- **Playground run inspector ("glass box").** The admin Tool Playground was rebuilt around a full run trace: every outbound request (messages sent, tools offered), every model response (structured view, raw JSON, extracted thinking), every tool execution with arguments/result/duration, and the final answer appear as an ordered step list with a summary strip (rounds, tool calls, prompt/completion token split, estimated cost, wall time). Includes a dry-run mode that assembles and shows the exact prompt without calling the model, optional raw provider-response capture, and per-run overrides for skills, snippets and the system prompt (ADR-040) (#314).
- **Live streaming.** The inspector streams from the moment Run is clicked — the outbound request appears before the model answers, then responses and tool executions arrive as they happen (NDJSON over a padded stream that defeats proxy buffering). The run form collapses on Run and the layout stacks form above inspector; max-tokens and temperature are exposed as run controls, and a truncation warning appears when the model hits the token limit (ADR-041) (#317, #320).
- **28 new built-in tools** (39 total), organised in groups, covering the diagnostic questions "who changed this?", "why is this page broken?", "check my TCA/TypoScript":
  - *content:* `search_records`, `get_page_content`, `read_records`, `get_record_history` (sys_history: who changed what, `old → new` per field) (#321, #325)
  - *structure:* `get_full_tca` (navigable TCA index), `get_table_schema` (relations surfaced), `get_flexform_schema`, `resolve_url` (URL → page, routing only), `validate_tca` (structural checks: dangling `foreign_table`, showitem/palette references, v14 `ds_pointerField`) (#324, #325)
  - *configuration:* `get_typoscript`, `get_tsconfig` (page-effective, drill-down, redacted), `fluid_resolve`, `check_typoscript` (core syntax scanner over constants+setup, incl. site-set TypoScript), `get_site_config` (credential-keys redacted) (#321, #325, #328)
  - *code:* `get_last_exception` (parsed stack trace + source context from the TYPO3 logs), `read_source` (path-guarded), `search_code` (value-redacting) (#323)
  - *files:* `list_fal_storages`, `browse_fal_folder`, `search_fal_files` (case-insensitive on every DBMS), `get_fal_references`, `find_missing_files` (#327)
  - *system:* `probe_url` (GET against this instance only, 5xx auto-correlates the matching log exception), `list_extensions`, `list_scheduler_tasks` (never unserializes task blobs), `get_system_status`, `list_deprecations`, `list_middlewares` (#323, #328)
- **Tool groups.** Every tool declares a group; whole groups can be toggled centrally in the Tools module, restricted per LLM configuration (`allowed_tool_groups`), and (de)selected per run in the playground. Enablement cascades fail-closed: a tool runs only when group, tool and configuration all permit it (ADR-043) (#322).
- **Backend overview facelift.** The LLM module landing page is now a real starting point: a usage-and-cost band on top (30-day KPIs + a 7-day provider breakdown), a single unified "Set up & manage" card grid, and a "For developers" teaser. Setup guidance is folded onto the module cards themselves — each card shows its state (ready / next / empty / locked) so the recommended next step is always visible, without a separate stepper. The Providers card carries live, **token-free** reachability dots (a cached model-list/health ping, never a completion). Cards are whole-card links. New `OverviewReadinessService` (state matrix) and `ProviderReachabilityService` (cached probe over configured provider records). (#313)
### Changed

- **BREAKING:** `ToolInterface` now requires a `getGroup(): string` method. Every implementer must declare its group — third-party extensions are recommended to use their extension key, so an admin can disable an extension's whole tool family with one toggle (ADR-043, #322).

### Fixed

- Non-UTF-8 bytes anywhere in a run — tool output, provider request/response bodies or the trace itself — no longer crash the playground with an opaque 500; invalid sequences are substituted on every boundary (#315, #316).
- Out-of-range temperature values are clamped instead of raising an uncaught exception, and the run round count is capped server-side (#314, #317).
- The playground system-prompt placeholder renders as text instead of raw Fluid markup (#319).
- `FlexFormTools` calls are version-gated: TYPO3 v14 requires the schema argument the 13.4 signatures do not have, and the two majors expect opposite `ds` shapes (#324).
- `validate_tca` no longer flags core-managed (auto-created) columns declared via `ctrl` — enablecolumns, language fields, `origUid` — and `check_typoscript` scans site-set/site-local TypoScript instead of reporting "no template" on sys_template-less v13+ sites (#326).
- `resolve_url` handles relative site bases (`base: /`) and schemeless path input (#325).
- Scheduler tasks report their last run time; the middleware listing survives resolver changes and reports exact counts (#328).

### Security

- `probe_url` matches allowed hosts on exact scheme-defaulted `host:port` (a bare-host match would have let `localhost:6379` through), rejects userinfo URLs and strips credentials before echoing anything back (#323).
- `get_record_history` requires PAGE_SHOW on the record's page for non-admins — history values cannot leak from unreadable pages (fail-closed for unresolvable records) (#325).
- `browse_fal_folder` enforces folder-level file-mount boundaries for non-admins on top of the storage allow-list; outside a backend request FAL access fails closed rather than mount-blind. Server paths never egress from any FAL tool (#327).
- `check_typoscript` reports source + line + error kind only — never the offending line's content (a broken constants line may carry an API key); `get_site_config` redacts credential-like keys including camelCase forms (#325, #328).

### Documentation

- ADR-040 through ADR-048; the Tools guide covers all 39 built-ins, the group taxonomy and the playground inspector; a tip advises narrow tool groups for small local models — verified live: the seeded `qwen3:4b` makes no tool call when offered the full set, and picks correctly when restricted to a group (#329).

## [0.14.1] - 2026-07-05

### Fixed

- Tool calling with parameterless tools now works. A tool that takes no arguments declared its JSON-Schema `properties` as an empty PHP array, which serialises to `[]` — but JSON Schema requires an object, and strict providers (Ollama) reject the whole request with HTTP 400 (`Value looks like object, but can't find closing '}' symbol`). The same applied to a parameterless tool call's empty `{}` arguments when the agent loop replayed them (`json_decode('{}', true) === []`). Both are now emitted as `{}`, so the bounded agent loop and the Tool Playground work when a parameterless tool — environment, PHP info or backend user/group introspection — is offered (#308).

### Documentation

- Refreshed the Skills, Tools and Playground admin guide: corrected the LLM module section count, replaced the outdated built-in tool list with the full catalogue, documented the two-tier (admin / non-admin) tool authorization, clarified that skill injection is eager and complete, and updated the screenshots (#307).

## [0.14.0] - 2026-07-04

### Added

- **Skills.** Ingest `SKILL.md` from GitHub sources with SHA-pinned sync, disabled-by-default and orphan/auto-disable lifecycle, and an admin backend module for sources and review; marketplace `marketplace.json` / `{source: git, url}` parsing; attach skills to tasks and configurations and inject them into text-generation prompts with a token budget and integrity verification (ADR-035, ADR-036) (#259, #261, #263, #273, #277, #295, #297).
- **Tools.** Function-calling tool runtime — a bounded agent loop, a DI-tagged tool registry, Ollama tool support and per-run allow-list gating; per-tool enable/disable with a built-in system tool set; an interactive admin tool playground; the Playground and Tools backend modules are separate (ADR-038) (#262, #264, #265, #274, #276, #296).
- Together AI, Fireworks AI and Perplexity are now first-class OpenAI-compatible adapter types with canonical endpoints (#300, #304).
- Backend module UX overhaul (help, glossary, readiness checks, enablement flow), accessibility text alternatives and screen-reader status text, and EN/DE translations across backend/AJAX/wizard strings (#275, #280, #288).

### Changed

- **BREAKING:** `ToolInterface` now requires a `requiresAdmin(): bool` method. Every implementer must declare it — return `true` for tools exposing system/host/cross-user data (logs, environment, phpinfo, backend-user/group listings), `false` for tools that self-enforce the acting user's TYPO3 permissions. Without it, the tool fatals at runtime on instantiation (ADR-038, #262).
- CI now runs the functional + e2e-backend suites on every event, so they gate merges (previously skipped entirely; closes #272) (#298).
- TYPO3 v15 forward-compatibility declared via composer package metadata, with a version-drift guard (#271).
- Dependencies upgraded (non-framework); `symfony/yaml` to v8; Node/Playwright/axe-core bumps (#251, #252, #253, #260, #301).
- ADRs audited against the code — drift corrected, ADR-039 added (#302).

### Fixed

- Provider endpoints are canonicalized on save on both write paths — the Setup Wizard (#98, #299) and the manual TCA record editor via a DataHandler hook (#300, #303) — so a bare host gains the adapter's API version path (e.g. OpenAI `/v1`) instead of breaking unversioned.
- The configuration system prompt is now applied on all completion paths, including streaming (#292).
- Per-configuration backend-group access control now works. The `beGroups` MM relation on `LlmConfiguration` had no Extbase mapping, so `LlmConfigurationRepository::findAccessibleForGroups()` raised `MissingColumnMapException` for any backend user in a group, and the in-PHP fallback check always saw an empty group list. Present since at least v0.13.0 (#289).
- Skill sync no longer wedges on a crashed run (stale-lock recovery), the skill-source list status/type badges render again, and `SkillSource.githubToken` is hydrated so sync can authenticate (#295, #297).
- Fluid 5 strict compound-condition mis-evaluation in backend status warnings (#294).
- Backend TCA `ORDER BY` SQL error, the task Run button target, and the wizard "Go to Configurations" link (#291, #293).
- Functional test debt cleared — backend controller test construction repaired after the #256 refactor, and the remaining assertion failures resolved (#267, #269).
- Model/completion cache tags sanitized and capped to TYPO3's 250-char limit; usage keyed on configuration and the response model recorded when the configuration carries none (#292).

### Security

- Gemini API key sent via the `x-goog-api-key` header on all calls instead of the URL query string, so it no longer leaks into logs, history or the Referer header (#286, #292).
- CSRF tokens required on backend AJAX endpoints; middleware order pinned; all backend AJAX endpoints require an admin backend user (#262, #278).
- SSRF hardening — schemeless-endpoint bypass closed, empty-username credential leak fixed, api-key-less provider endpoints gated against the host filter (#292).
- Acting-user RBAC enforced on tool execution; surfaced exception messages redacted; tool-result size bounded (#276, #292).
- Input clamps (temperature, maxTokens, token counts, retry backoff) across providers and the wizard; API key cleared from Setup Wizard memory after save (#285, #286, #292).

## [0.13.0] - 2026-06-26

### Changed

- **BREAKING:** `LlmServiceManager::getProvider(null)` now throws
  `ProviderException` (4867297358) instead of falling back to an
  extension-config default provider.

  **Migration:** select a provider in one of the two supported ways —
  pin it per call via the options object's `provider` field
  (`new ChatOptions(provider: 'openai')`, likewise `EmbeddingOptions`/
  `VisionOptions`/`ToolOptions`), or mark a Configuration *active* and
  *default* in the LLM backend module so the generic `chat()`/`complete()`/
  `embed()` entry points resolve it automatically. To read the configured
  default programmatically, use
  `LlmConfigurationService::getDefaultConfiguration()` (access-checked) or
  `LlmConfigurationRepository::findDefault()` (raw).

### Removed

- **BREAKING:** `setDefaultProvider()` and `getDefaultProvider()` removed from
  `LlmServiceManagerInterface` (and its implementation), and the
  `ExtensionConfiguration['nr_llm']['defaultProvider']` setting is no longer
  read. These were a vestige of the pre-database provider-centric design and
  had no effect in production (the key was never exposed in
  `ext_conf_template.txt`). See ADR-034.

### Fixed

- Removed the orphaned `plugin.tx_nrllm` TypoScript constants/setup that were
  registered but never read by any code, and which misleadingly implied that
  provider selection was TypoScript-driven. The "No provider specified and no
  default provider configured" exception now carries actionable guidance
  pointing to the backend module, and the configuration docs describe the
  database-backed setup. (#254, #255)

## [0.12.0] - 2026-06-11

### Added

- **Prompt-snippet library.** New `tx_nrllm_promptsnippet` entity with a
  backend module tab: editors manage small named prompt fragments (personas,
  tones of voice, audiences, image styles, layouts) with free-form tags and
  optional metadata JSON; consuming extensions query them by tag and compose
  them into their prompts (`findActiveByTag()`, `findByUids()`,
  `PromptSnippetComposer`). See ADR-031.
- **Specialized usage and cost tracking.** Image, TTS and transcription calls
  now record real units (images, characters, audio seconds), token usage from
  gpt-image responses, the model id, and an estimated cost via a documented
  OpenAI price catalog with a model-row-first cascade — the Analytics module,
  cost widgets and budget windows finally see the full spend. Usage rows link
  `model_uid` and `configuration_uid` for per-model / per-configuration
  breakdowns. See ADR-032.
- **Specialized models join the model registry.** New model capabilities
  `image`, `text_to_speech` and `transcription`; the specialized services
  resolve their default model from active registry records
  (`resolveDefaultModel()`), guarded by a per-service vocabulary check so an
  OpenAI default never reaches the FAL endpoint and vice versa. See ADR-033.
- **Configuration-based resolution for specialized services.**
  `resolveModelForConfiguration()` and `getConfigurationSystemPrompt()` make
  `tx_nrllm_configuration` records the stable indirection layer for image and
  speech calls too: administrators swap models and maintain prompt preambles
  centrally; the `configuration` option attributes usage per configuration.
- **Per-request timeouts on the secure-client path.** The services' timeout
  now reaches the wire via nr-vault's new `withTimeout()`; the image default
  rose to 300s — large gpt-image-2 generations no longer die at the global
  HTTP timeout.
- **Arbitrary gpt-image sizes.** `ImageGenerationOptions` accepts any
  `WIDTHxHEIGHT` for gpt-image-* models (divisible by 16, aspect 1:3-3:1,
  max 3840x2160) alongside the documented standard sizes.

### Fixed

- **Name-style nr-vault identifiers work as API keys.** `Provider` accepted
  only UUID-v7 vault identifiers; name-style identifiers were misread as
  legacy plaintext, breaking key decryption (silent model-discovery fallback
  to a stale catalog) and even re-saving the provider record.
- **Model discovery is honest about fallbacks.** tts/whisper/dall-e models are
  no longer filtered out, the static fallback catalog is current (gpt-5.5,
  specialized entries), discovery failures are logged, and the model-fetch
  response flags `source: fallback` with a visible notice in the form.
- **Vault audit log readability.** Audit reasons carry the actual model and
  purpose (e.g. "OpenAI Images API call (gpt-image-2, generate)"), and the
  per-request audit context is consumed so later requests cannot inherit it.
- The Snippets module no longer crashes on inactive snippets (Fluid getter
  pair for the is-active flag), and the snippet count honours the hidden
  enable-field contract.

### Changed

- **nr-vault requirement raised to `^0.10.0`.** The secure-client integration
  now relies on the current nr-vault API surface (per-request `withTimeout()`,
  header-placement options); older constraint branches were untested claims.
- Overview module cards gained "+ New record" quick actions plus Snippets and
  Analytics cards.
- `runTests.sh` defaults to PHP 8.5, the upper supported bound.

## [0.11.1] - 2026-06-10

### Security

- **Setup-wizard requests go through the nr-vault secure HTTP client.**
  `ModelDiscovery` and `ConfigurationGenerator` now dispatch via the
  SSRF-guarded vault client with an `isHostAllowed()` pre-gate, so a
  malicious endpoint URL can no longer point the wizard at private
  networks or cloud metadata.
- **Streaming errors are no longer swallowed.** All seven provider
  adapters validate the streaming response status: 4xx raises a typed,
  credential-sanitized provider exception, other non-2xx a connection
  exception (previously failures could surface as empty streams).
- Provider error messages consistently redact credential query
  parameters (`?key=…` → `key=***`) on error and retry-exhaustion paths;
  `testConnection()` returns a generic client-facing message and logs
  the sanitized detail server-side.

### Fixed

- TTS hard-splitting of over-limit text is multibyte-safe
  (`mb_str_split`), so UTF-8 sequences are no longer cut mid-character.
- FAL polling clamps `pollInterval` to ≥1ms (a `0` setting busy-looped /
  divided by zero) and reports validation errors with explicit context.
- Whisper configuration values are type-guarded; an empty base URL falls
  back to the OpenAI default instead of producing invalid requests.

## [0.11.0] - 2026-06-10

### Added

- **The backend module's default Configuration now drives generic completion.**
  When a caller invokes `chat()`, `complete()`, or `streamChat()` without
  pinning a provider, the manager first resolves the active *default*
  database-backed `LlmConfiguration` (Provider → Model → Configuration, as
  managed in the backend module) and routes the call through it — provider
  adapter, model, and vault-backed credentials all come from the module's
  records. Per-call `ChatOptions` (temperature, response format, system
  prompt, …) override the configuration's stored defaults, so JSON mode and
  per-call prompts keep working unchanged.
- `chatWithConfiguration()`, `completeWithConfiguration()` and
  `streamChatWithConfiguration()` accept an `$optionOverrides` array whose
  entries take precedence over the configuration's stored options.

### Changed

- The extension-configuration `defaultProvider` is now a *fallback*: it is
  used only when no usable default configuration exists in the database. A
  default configuration is skipped (falling back) when it has no model
  assigned or when it is access-restricted to specific backend groups —
  group-restricted configurations are never auto-applied to callers without
  a backend-user context (e.g. the CLI messenger worker).

## [0.10.0] - 2026-06-09

### Changed

- **Specialized services authenticate through nr-vault.** The DALL-E, FAL,
  Whisper, TTS, and DeepL specialized services no longer read a plaintext API
  key. Each stores an nr-vault secret *identifier* and authenticates through
  the audited secure HTTP client (`$vault->http()->withAuthentication(...)`),
  mirroring the database-backed providers (ADR-012). The secret is resolved,
  injected, audited, and memory-scrubbed inside the vault and never surfaces in
  this extension. FAL (`Authorization: Key …`) and DeepL
  (`Authorization: DeepL-Auth-Key …`) use the nr-vault 0.8.0 `prefix` option;
  DeepL's Free/Pro routing stays automatic by retrieving the key once, lazily,
  only to test the `:fx` suffix, then scrubbing it. See ADR-030.

### Removed

- **Plaintext API keys for the specialized services.** The extension-configuration
  keys are now nr-vault identifiers: `providers.openai.apiKeyIdentifier`
  (DALL-E/Whisper/TTS), `image.fal.apiKeyIdentifier`, and
  `translators.deepl.apiKeyIdentifier`. Host applications that wrote plaintext
  keys into these settings must store a vault secret and write its identifier
  instead. Requires `netresearch/nr-vault ^0.8.0` (composer floor raised from
  `^0.6.0 || ^0.7.0`).

## [0.9.0] - 2026-06-08

### Added

- **gpt-image-\* model family support for image generation.** OpenAI retired
  DALL·E-3; accounts now expose the `gpt-image-*` family (`gpt-image-1`,
  `gpt-image-1-mini`, `gpt-image-1.5`, `gpt-image-2`, …). `ImageGenerationOptions`
  accepts these models by prefix and validates their size set
  (`1024x1024` / `1536x1024` / `1024x1536` / `auto`); `DallEImageService` maps the
  whole family to a shared capability profile and sends a minimal
  `model`/`prompt`/`n`/`size` payload (no `response_format`/`style`/`quality`,
  which gpt-image rejects), reading the returned `b64_json`.

### Fixed

- **Chat JSON mode was never requested.** `CompletionService::completeJson()` asks
  for `response_format=json` and then strictly decodes the reply, but
  `OpenAiProvider` dropped the option, so the model could return prose/Markdown-
  fenced JSON and the decode failed. The provider now maps `response_format=json`
  to OpenAI's `{"type":"json_object"}` in `chatCompletion()` /
  `chatCompletionWithTools()`.
- **Empty `baseUrl` broke the specialized services.** An empty ext_conf
  `image.dalle.baseUrl` / `image.fal.baseUrl` / `speech.tts.baseUrl` (the documented
  "use the provider default" value) was used verbatim as the request URL, producing
  a scheme-less URL and a Guzzle failure on a stock install. Empty now falls back to
  the provider default via a shared `nonEmptyStringOrDefault()` helper.

## [0.8.0] - 2026-06-02

### Added

- **Usage Analytics dashboard** — new *Admin Tools → LLM → Analytics* submodule with cost/usage trends, breakdowns by provider/model/service, KPI tiles, and per-user usage with budget consumption.
- **Real cost tracking** — `UsageMiddleware` now computes `estimated_cost` from model pricing (prompt/completion split); the `tx_nrllm_service_usage` table gained `model_uid`, `model_id`, `prompt_tokens`, `completion_tokens`.
- **Per-list usage columns** — the Providers, Models, Configurations, and Tasks list views show *Cost / Requests / Tokens (last 30 days)* per row.
- **Per-task usage tracking** — task executions record their `task_uid` (threaded through the provider middleware pipeline), so usage rolls up per task.
- **`ddev seed-usage`** — dev-only generator for ~90 days of realistic historic demo usage (creates paid demo providers/models/configurations/tasks so every list column and the dashboard have content).

### Fixed

- Cost was never recorded for LLM calls (`estimated_cost` was always `0.00`); the *AI cost this month* dashboard widget now shows real figures.
- **Mutation testing tool error (audit 2026-04-30, deferred item):**
  `Build/Scripts/runTests.sh -s mutation` previously errored out
  partway through Infection's initial test suite phase with an
  opaque `[ERROR]` and never reached the mutation step. Root cause:
  three test classes carried `#[CoversClass(...)]` attributes
  pointing at classes that are excluded from the coverage source
  in `Build/phpunit.xml` — `MessageRole` (an enum; the whole
  `Classes/Domain/Enum/` directory is excluded), `ProviderResponseException.php`
  excluded as a specific file (alongside most other files in
  `Classes/Provider/Exception/`, but not all — e.g.
  `FallbackChainExhaustedException.php` is not on the exclude
  list), and the `MessageRole` reference on `ChatMessageTest`.
  PHPUnit 12 raises "Class … is not a valid target for code
  coverage" warnings for those, and `failOnWarning=true` turns
  them fatal under `--coverage` runs only — which is what
  Infection does. Replaced the offending `#[CoversClass]`
  attributes with `#[CoversNothing]` on `MessageRoleTest` and
  `ProviderResponseExceptionTest`, and dropped the
  `#[CoversClass(MessageRole::class)]` line from `ChatMessageTest`
  (the `#[CoversClass(ChatMessage::class)]` attribution stays).
  The warnings drop from 39 to 0 under the `unitCoverage` suite;
  Infection can now run end-to-end. Mirrors the guidance already
  in `Tests/AGENTS.md`: "use `#[CoversNothing]` for
  enums/exceptions".
- **Audit-review-followup (audit 2026-04-30):** Four review
  comments left by Copilot and gemini-code-assist on the
  audit-merged PRs (#202 and #203) were missed in the merge
  rush — the merge queue does not gate on review-thread
  resolution and the threads were not cleared before merge.
  Addressed in this slice:
  (a) `TaskInputResolver::resolveTable()` — the comment on the
  `catch (InvalidArgumentException)` arm previously said the
  exception text was "safe to surface", which conflicted with
  the REC #11b contract that error-arm output never surfaces
  `$e->getMessage()` regardless of type. Comment rewritten to
  match what the code actually does.
  (b) `Tests/Unit/Provider/Exception/ProviderResponseExceptionTest.php`
  docblock — clarified that the coverage exclusion is per-file
  in `Build/phpunit.xml`, not directory-wide
  (`FallbackChainExhaustedException.php` is in the same directory
  but is not excluded).
  (c) `Tests/Unit/Domain/ValueObject/ChatMessageTest.php` — the
  inline note about why `MessageRole` is no longer attributed
  via `#[CoversClass]` is now a DocBlock (matches the rest of
  the test suite), with bare class names rather than path-like
  syntax.
  (d) The CHANGELOG entry for the mutation-tool fix above is
  rewritten not to overstate the directory-wide exclusion.
  Pure follow-up — no code behaviour change.

### Changed

- **REC #11b (audit 2026-04-30, follow-up to REC #11):**
  `TaskInputResolver` no longer interpolates `$e->getMessage()` into
  the LLM input string when sys_log or record-table reads fail.
  Three behaviour changes, all in
  `Classes/Service/Task/TaskInputResolver.php`:
  1. The constructor takes a new `LoggerInterface` parameter (autowired
     by Symfony DI — no `Services.yaml` change needed).
  2. The two `catch (Throwable $e)` arms now `$this->logger->warning(...)`
     the full exception together with task-uid / table / limit context,
     and return a generic localised "see system log" message instead
     of the raw exception text. The previous behaviour leaked DBAL
     error fragments (table names, column hints, sometimes SQL) into
     the LLM prompt and onward to user-visible task output.
  3. The table branch grew a dedicated `catch (InvalidArgumentException)`
     arm in front of the broad Throwable arm — picker-policy
     rejections (table on the exclusion list) are not runtime errors
     and now route through `$this->logger->info(...)` instead of
     `warning`. The user-facing string is the same generic
     "see system log" message.
  XLIFF: `task.syslog.readError` and `task.table.readError` lost
  their `: %s` placeholder — the new source / target strings are
  "Error reading X. See system log for details." (EN) and
  "Fehler beim Lesen ... Details siehe Systemlog." (DE). Plus a
  new private `translate()` helper wraps `LocalizationUtility::translate()`
  in a defensive try/catch so the resolver stays unit-testable
  without bootstrapping `LanguageServiceFactory` (which
  `LocalizationUtility::translate()` instantiates eagerly and
  which throws when called outside the TYPO3 framework
  bootstrap). New
  `Tests/Unit/Service/Task/TaskInputResolverTest.php` provides 8
  unit tests / 64 assertions covering the happy paths, both
  read-failure regression contracts (no message leak + warning
  emitted), and the picker-policy info-log routing.
- **REC #11 (audit 2026-04-30, partial):** Bare `catch (Throwable)`
  cleanup outside REC #8b's admin-controller scope. Two surgical
  changes:
  - `OllamaProvider::getAvailableModels()` — the catch arm that
    falls back to a hardcoded model list when the Ollama server is
    unreachable now logs a `warning` (with `exception` and
    `baseUrl` context) before returning the defaults. Operators
    can see when their endpoint is silently down instead of
    discovering it later via "the model picker only shows five
    options". `$this->logger` is already injected by
    `AbstractProvider`.
  - `Provider::getDecryptedApiKey()` — the silent
    `catch (Throwable) { return ''; }` is **kept** but the comment
    is sharpened to document why: the empty-string return is
    load-bearing for `isFullyConfigured()`, `toFullArray()`, and
    the two `ModelController` adapter-construction sites; adding a
    logger here trips `failOnWarning=true` in unit-test paths that
    construct providers without a vault service. The operational
    signal belongs at the controller call sites — deferred to a
    follow-up. Two other sites flagged by the audit
    (`TaskInputResolver:59,133`, `TextToSpeechService:429`) are
    not touched in this slice — see audit doc for the rationale
    (TaskInputResolver needs a test-first refactor; TextToSpeechService
    is already a typed-final-arm pattern that matches REC #8b's
    intent).
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

- **REC #15 (audit 2026-04-30):** ADR-026 gains a new
  "Diagnostic / connectivity calls intentionally bypass the pipeline"
  section. Documents the three actual call paths used by the
  test-action controllers — `ProviderController::testConnectionAction`
  goes through `ProviderAdapterRegistry::testProviderConnection()` →
  `ProviderInterface::testConnection()` (with an inline
  `preg_replace` sanitiser that mirrors
  `AbstractProvider::sanitizeErrorMessage()`'s shape but is
  implemented locally so the registry stays independent of the
  provider base class), while
  `ConfigurationController::testConfigurationAction` and
  `ModelController::testModelAction` go through
  `ProviderAdapterRegistry::createAdapterFromModel()` →
  `ProviderInterface::complete()` (sanitisation happens inside the
  adapter via `AbstractProvider::sanitizeErrorMessage()` before the
  `ProviderResponseException` reaches the controller). All three
  bypass `MiddlewarePipeline::run()` deliberately — Budget would
  mis-charge, Usage would distort dashboards, Fallback would mask
  the very condition the probe was designed to detect, and Cache
  would defeat the purpose of probing. Together with streaming
  (already documented in ADR-026 step 5), these three diagnostic
  actions are the documented exemptions from the "productive
  provider calls go through the pipeline" rule. New non-streaming
  productive entry points still go through the pipeline. Pure
  documentation slice — no code change.
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

[Unreleased]: https://github.com/netresearch/t3x-nr-llm/compare/v0.23.0...HEAD
[0.23.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.16.1...v0.17.0
[0.16.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.16.0...v0.16.1
[0.16.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.14.1...v0.15.0
[0.14.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.11.1...v0.12.0
[0.11.1]: https://github.com/netresearch/t3x-nr-llm/compare/v0.11.0...v0.11.1
[0.11.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/netresearch/t3x-nr-llm/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/netresearch/t3x-nr-llm/releases/tag/v0.7.0
