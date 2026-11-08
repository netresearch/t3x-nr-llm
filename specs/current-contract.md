# Current contract amendments

Status: current requirements on the audit-repair branch. Baseline: `f7ae68e40`. Review date: 2026-11-08.

These stable requirement IDs resolve the 35 audit findings. They override conflicting historical increment wording only in the named area. Evidence links identify ownership and regression fixtures; a test file link alone is not an assertion of a passing run. See the PR validation record for executed suites. External operational completion is separate from repository contract repair.

## S01: Identify the current contract and its amendments

The inventory below is the entry point for all current feature specifications (50 audited plus subsequently merged 047). Earlier increment text remains historical where an explicitly linked current amendment replaces it. Every normative change names a stable requirement ID, rationale, owning implementation and acceptance evidence. A newer date alone does not override a requirement.

Implementation and acceptance evidence: [constitution.md](../.specify/memory/constitution.md).

## C01: Unknown usage becomes zero cost on priced models

Preserve input/output token presence independently through adapter normalization and serialization (ADR-272). Absent or invalid counts remain unknown in per-call telemetry; explicitly reported zero is measured zero. Estimate split-token cost only when both counts and pricing are known. Independently reported monetary cost remains known without tokens. OpenRouter normalizes its documented `usage.cost` (finite nonnegative JSON number), including zero, before any local estimate; valid legacy `total_cost` is a fallback. Daily integer aggregates and request counts are not a completeness certificate.

Implementation and acceptance evidence: [UsageMiddleware.php](../Classes/Provider/Middleware/UsageMiddleware.php), [UsageMiddlewareTest.php](../Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php).

Adapter-to-accounting acceptance: [OpenRouterReportedCostTest](../Tests/Unit/Provider/OpenRouterReportedCostTest.php). Field semantics: [OpenRouter usage accounting](https://openrouter.ai/docs/cookbook/administration/usage-accounting).

## C02: Retry and fallback accounting have different meanings

A logical pipeline call is one telemetry row. provider_retries counts repeated HTTP attempts, including attempts on fallback providers; fallback_attempts counts provider hops. Neither counter is a count of logical calls. Latency includes attempted work; successful usage records what the serving provider reports, not inferred failed-attempt spend. Failed-call or missing-usage cost is unknown, not an invoice total. The original plan assumption that same-provider retries do not exist is superseded.

Implementation and acceptance evidence: [AbstractProvider.php](../Classes/Provider/AbstractProvider.php), [ext_tables.sql](../ext_tables.sql).

## C03: Run correlation does not provide per-call outcome attribution

The run UUID correlates multiple logical calls and is not a per-call identity. A telemetry row UID identifies one logical call; HTTP retries/fallback are attempts inside it. An outcome has grain (run UUID, source). Aggregate spend before joining to outcomes; never repeat run spend per joined call. Mixed-model runs are a separate population, not causal attribution to the final model. Explicit and observed outcome denominators remain separate; no automatic causal/canary quality claim follows from correlation.

Implementation and acceptance evidence: [AgentRunReference.php](../Classes/Domain/ValueObject/AgentRunReference.php).

## C04: Repeated preview failure needs an explicit binding-waiver contract

A successful stored preview binds the first approval to its displayed state. Failed revalidation suspends again and persists a failed preview plus changed-call indexes. The next card explicitly says that no preview is available. A second, fresh human approval is an allowed waiver of the former binding; it executes under ordinary current actor/tool authorization. The failed preview in the suspension state and the subsequent approval transition are the evidence of this unbound decision. Failure never causes automatic execution. A recovered preview requires a new displayed decision to bind again.

Implementation and acceptance evidence: [ApprovalPreviewComparator.php](../Classes/Service/Tool/ApprovalPreviewComparator.php), [locallang.xlf](../Resources/Private/Language/locallang.xlf).

## C05: Outcome observation depends on scheduler timing

The configured number of days is a minimum settlement delay after the latest recorded write, not a fixed observation interval. Only terminal runs are eligible; every later write resets the delay. History and record existence are observed when derivation runs, through the replacement row crdate/tstamp. Therefore a day-8 and day-30 derivation may intentionally differ. Reports must name the observation timestamp and delay; they must not label results as an invariant seven-day cohort.

Implementation and acceptance evidence: [ObservedOutcomeDeriver.php](../Classes/Service/Outcome/ObservedOutcomeDeriver.php), [WrittenRecordRepository.php](../Classes/Service/Outcome/WrittenRecordRepository.php).

## C06: Outcome labels conflate human edits and infrastructure failures

EDITED means subsequent recorded activity on the record, not proven human editing of generated fields. It may include automated or unrelated modifications. DISCARDED requires confirmed absence or a recorded deletion. A deliberately removed table counts as absent; other storage exceptions propagate so the command fails and can be retried, without persisting an invented negative outcome. No classification is a causal model-quality assessment.

Implementation and acceptance evidence: [WrittenRecordRepository.php](../Classes/Service/Outcome/WrittenRecordRepository.php), [ObservedOutcomeDeriver.php](../Classes/Service/Outcome/ObservedOutcomeDeriver.php).

## C07: History retention is not proved by the oldest surviving row

No oldest-row heuristic certifies complete history: selective deletion, installation exclusions and gaps can preserve older rows. With no affirmative later activity, derive UNKNOWN even when an old row survives. ACCEPTED_UNCHANGED remains readable for historical/imported data, but this deriver does not manufacture that conclusion. Later positive evidence may establish EDITED or DISCARDED; coverage remains explicitly limited.

Implementation and acceptance evidence: [ObservedOutcomeDeriverTest.php](../Tests/Unit/Service/Outcome/ObservedOutcomeDeriverTest.php).

## C08: Outcome replacement is not atomic under concurrency

The replacement key is (correlation UUID, outcome source), where the enum determines source. Acquire the TYPO3 exclusive lock on one of 256 bounded key stripes before a database transaction replaces that source; rollback preserves the previous answer on failure. Release in finally. Last successful lock holder wins conflicting ratings; explicit and observed sources coexist. Observed outcomes are materialized once by automatic selection; deliberate recomputation replaces them under the same measurement protocol. Every writer must use this repository. Multi-node deployments require a shared lock strategy; default host-local locks cannot serialize separate hosts.

Implementation and acceptance evidence: [CallOutcomeRepository.php](../Classes/Service/Outcome/CallOutcomeRepository.php), [ext_tables.sql](../ext_tables.sql).

## C09: Translation-draft failure semantics contradict partial writes

Translation/validation failure before DataHandler processing writes no translated fields. DataHandler can partially apply a write; after-store hooks do not roll it back. Read-back determines full versus partial storage. If verification itself fails, completeness is unknown; never claim rollback or safe retry merely because an exception occurred. Report known committed fields and remaining uncertainty. Existing after-store-hook and dropped-field fixtures define the supported partial-write behavior.

Implementation and acceptance evidence: [CreateTranslationDraftToolTest.php](../Tests/Functional/Service/Tool/CreateTranslationDraftToolTest.php).

## C10: The public write-event specification predates accepted amendments

AfterAiRecordWrittenEvent has four properties: correlationId, record, kind and workspace. Later accepted WriteKind cases include deletions. Field fills dispatch the event for the actual written workspace version, and AI marking is a shipped listener. At-least-once execution can repeat delivery; listeners deduplicate their own effects. A listener failure after storage does not imply rollback. These additive amendments replace the original exactly-three-properties/no-listener/no-delete increment boundaries.

Implementation and acceptance evidence: [AfterAiRecordWrittenEvent.php](../Classes/Event/AfterAiRecordWrittenEvent.php), [AfterAiRecordWrittenEventTest.php](../Tests/Unit/Event/AfterAiRecordWrittenEventTest.php), [MarkAiWrittenRecord.php](../Classes/EventListener/MarkAiWrittenRecord.php), [WriteKind.php](../Classes/Domain/Enum/WriteKind.php).

## C11: The current non-admin management authorization matrix is unclear

ADR-169 governs management authorization. Module visibility is not a permission grant. Administrators may manage root records; non-admin page-mounted records require page mount, table operation and field grants through TYPO3 List/FormEngine/DataHandler. A table allowing root or page placement does not itself confer root access. Skills intentionally support authorized backend authoring (012); the earlier draft restriction to three editable tables is superseded. Providers/models/configurations, skills, glossary and term lists follow their TCA plus native table/field grants; service entry points retain their documented actor checks. The [table-by-operation permission matrix](permission-matrix.md) names the native record, field and service boundaries; no new blanket admin-only rule is introduced.

Implementation and acceptance evidence: [tx_nrllm_provider.php](../Configuration/TCA/tx_nrllm_provider.php), [Adr169RecordManagementUsesTypo3Permissions.rst](../Documentation/Adr/Adr169RecordManagementUsesTypo3Permissions.rst).

## C12: Provider profiles need a maintained conformance contract

The [maintained family/endpoint/effort and replay matrix](provider-profile-matrix.md) is owned by OpenAiModelProfiles, OpenAiModelProfile and the option manual. GPT-6.1 Sol has Low as its lowest effort, including think=false and explicit None clamping; tool calls use Responses. GPT-6 Astra also starts at Low. GPT-6 Sol/Luna admit None. GPT-5/o-series retain their conservative no-invented-effort mapping. Unknown identifiers retain legacy transport and do not establish provider capability. Payload tests prove encoding, not live interoperability. Profile additions must name source/date and update table plus vectors together.

Implementation and acceptance evidence: [OpenAiModelProfiles.php](../Classes/Provider/OpenAi/OpenAiModelProfiles.php), [OptionClasses.rst](../Documentation/Api/OptionClasses.rst).

## C13: Delegated authentication leaves a released dependency decision open

AdditionalSecretHttpClientInterface is released in nr-vault 1.2.0. Static/public modes work without it; confidential token exchange requires the capability and fails before contacting the identity provider when absent. The broad Composer range intentionally permits base modes. The old proposed-interface/open-minimum decision is closed; capability detection, secret injection and cleanup tests govern this boundary.

Implementation and acceptance evidence: [McpServers.rst](../Documentation/Administration/McpServers.rst), [McpTokenExchange.php](../Classes/Service/Tool/Mcp/Auth/McpTokenExchange.php).

## RT01: Translation polishing must preserve policy denials

GuardrailPolicyException propagates from polishing: no translated result is returned. Operational provider/budget/shape failure may return the first draft with a reason. Optional diagnostics may not turn that fallback into a failure. Semantic equivalence is not proved; existing placeholder/layout checks retain their documented limits. ADR-238 amends the original unconditional fallback promise.

Implementation and acceptance evidence: [TranslationPolisher.php](../Classes/Service/Feature/TranslationPolisher.php), [Adr238ATermListBlocksAnOutputAndSaysWhy.rst](../Documentation/Adr/Adr238ATermListBlocksAnOutputAndSaysWhy.rst), [TranslationServicePolishTest.php](../Tests/Unit/Service/Feature/TranslationServicePolishTest.php).

## RT02: Term-list screening scope differs across call types

Ordinary completions and tool-free persisted runs screen the whole answer and hold streams as required. Tool-offering persisted runs exempt prose and declared read-tool arguments; write-tool and unknown-tool arguments are screened before approval. Direct loops follow their provided execution context, not an inferred persisted-run scope. Required-term checks follow the same contextual contract. Only the paths requesting holding buffer streams. Site context follows explicit call propagation; language is a separate value. ADR-238 amendments govern these intentional exemptions.

Implementation and acceptance evidence: [Adr238ATermListBlocksAnOutputAndSaysWhy.rst](../Documentation/Adr/Adr238ATermListBlocksAnOutputAndSaysWhy.rst), [TermListGuardrail.php](../Classes/Service/Guardrail/Term/TermListGuardrail.php).

## RT03: Field fills now belong to the originating workspace

ADR-265 replaces the original workspace exclusion. Queue and write in the originating workspace, even after the editor switches elsewhere. Legacy live runs remain live. Revoked membership, missing/discarded/published draft, non-editing stage and unsupported/live-edit tables refuse the write without recreating a draft. Mark the version actually written. The suppression key remains deliberately cross-workspace for field fills, distinct from editor batch identity.

Implementation and acceptance evidence: [Adr265BackgroundWorkWritesIntoItsWorkspace.rst](../Documentation/Adr/Adr265BackgroundWorkWritesIntoItsWorkspace.rst), [FieldFillWriter.php](../Classes/Service/Agent/FieldFill/FieldFillWriter.php), [FieldFillInTheWorkspaceTest.php](../Tests/Functional/Service/Agent/FieldFill/FieldFillInTheWorkspaceTest.php).

## RT04: Background-answer retention depends on explicit ceilings

The run-privacy contract and ADR-270 govern answer retention. Global metadata alone may retain a functional background answer; an explicit task/provider metadata ceiling suppresses it, even if equal to the global enum value. Actual attempted/serving providers tighten persisted ceilings monotonically. Later writes and stale handles cannot restore suppressed content. The old all-levels-except-none rule is superseded.

Implementation and acceptance evidence: [Adr270RunPrivacyAccountsForActualProviderSelection.rst](../Documentation/Adr/Adr270RunPrivacyAccountsForActualProviderSelection.rst), [RunContentPrivacyGuardTest.php](../Tests/Functional/Service/Privacy/RunContentPrivacyGuardTest.php).

## RT05: Batch limits and identity are workspace-scoped

Editor batch identity includes action, table, record and originating workspace; admission/caps count the corresponding action/table/workspace prefix. Live and separate drafts are independent batches. Module runs keep the recorded workspace; CLI queues in live and refuses users without live access. Current owner workspace does not redirect a queued writer. Provenance and AI marks name the actual version, as specified by ADR-265/266.

Implementation and acceptance evidence: [EditorActionBatchPlanner.php](../Classes/Service/Tool/EditorActionBatchPlanner.php), [Adr265BackgroundWorkWritesIntoItsWorkspace.rst](../Documentation/Adr/Adr265BackgroundWorkWritesIntoItsWorkspace.rst).

## RT06: Field-fill no-overwrite wording exceeds its accepted race guarantee

The emptiness and draft-stage checks occur immediately before DataHandler processing and are best effort, not compare-and-swap. A concurrent edit or stage transition in the remaining read/write gap can lose to the AI write. No atomic no-overwrite or stage guarantee is advertised. ADR-237 and ADR-265 explicitly accept this narrow race. Changes already visible at the final check refuse writing; post-check interleavings retain DataHandler last-writer behavior.

Implementation and acceptance evidence: [FieldFillWriter.php](../Classes/Service/Agent/FieldFill/FieldFillWriter.php), [Adr237AnEmptyFieldIsFilledAfterTheSave.rst](../Documentation/Adr/Adr237AnEmptyFieldIsFilledAfterTheSave.rst), [Adr265BackgroundWorkWritesIntoItsWorkspace.rst](../Documentation/Adr/Adr265BackgroundWorkWritesIntoItsWorkspace.rst).

## RT07: The field-fill cap can be exceeded by multi-field records

One save-hook request queues at most 20 field-fill runs across all records and fields. Pass remaining capacity into the planner and check before each enqueue in configured field order. Count each successful durable defer immediately, including when a later field throws; a failed field does not erase earlier admission accounting. Fields beyond capacity are skipped predictably.

Implementation and acceptance evidence: [FieldFillHook.php](../Classes/Service/Agent/FieldFill/FieldFillHook.php), [FieldFillPlanner.php](../Classes/Service/Agent/FieldFill/FieldFillPlanner.php), [FieldFillTest.php](../Tests/Functional/Service/Agent/FieldFill/FieldFillTest.php).

## RT08: Later queue-page failures become successful empty sweeps

Every queue page distinguishes [] (readable and empty) from null (unreadable). An unreadable later page returns a failing exit code and reports work already executed/held; completed runs remain completed. The deadline prevents starting another run and does not interrupt one already executing. Budget-held/unclaimable rows retain pagination offsets.

Implementation and acceptance evidence: [WorkAgentQueueCommand.php](../Classes/Command/WorkAgentQueueCommand.php), [AgentRunPersister.php](../Classes/Service/Tool/AgentRunPersister.php).

## RT09: Optional logging can break later fail-soft guarantees

Optional log calls at warning/error/info severity are best effort at promised save/fallback/enqueue/post-store boundaries, containing both exceptions and errors. They cannot replace an operational result, undo a committed write or conceal a durable queued run. Mandatory audit/state persistence is not optional and must retain its own failure behavior. This extends 023 only to the explicitly enumerated paths, not every repository logger.

Implementation and acceptance evidence: [FieldFillHook.php](../Classes/Service/Agent/FieldFill/FieldFillHook.php), [TranslationPolisher.php](../Classes/Service/Feature/TranslationPolisher.php), [QueuedRunCoordinator.php](../Classes/Service/Agent/QueuedRunCoordinator.php), [FieldFillWriter.php](../Classes/Service/Agent/FieldFill/FieldFillWriter.php).

## RT10: Batch deduplication lacks concurrency and retention semantics

Deduplication and action caps are sequential admission checks against retained run rows, not atomic cross-request reservations. Concurrent planners can admit duplicate cards or exceed the cap; approval remains required for each write. Retained open/decided states suppress later sequential cards. Purging the decided run removes rejection memory and permits another card; no durable rejection tombstone is promised. Deployments needing strict scheduling serialize callers externally. This explicitly narrows the earlier unqualified wording without changing run identity.

Implementation and acceptance evidence: [EditorActionBatchPlanner.php](../Classes/Service/Tool/EditorActionBatchPlanner.php), [QueueEditorActionBatchCommand.php](../Classes/Command/QueueEditorActionBatchCommand.php), [AgentRunRepository.php](../Classes/Service/Tool/AgentRunRepository.php).

## RT11: Explicit page none differs from an absent declaration

Explicit page: none resolves SkillPageNeed::NONE. An absent, empty or invalid declaration resolves null. Normalize case/whitespace as the parser does. Consumers apply their own default only to null and preserve explicit NONE. nr-llm does not resolve the consumer default.

Implementation and acceptance evidence: [SkillPageNeedTest.php](../Tests/Unit/Domain/Model/SkillPageNeedTest.php), [SkillFrontmatter.php](../Classes/Service/Skill/SkillFrontmatter.php).

## RT12: The contextual guardrail extension point needs an explicit API contract

ContextualGuardrailInterface is an additive public extension point governed by ADR-238 and the API snapshot. checkOutputInContext replaces legacy output checking when contextual execution information is present; without context the legacy check applies. holdsStream controls buffering before delivery. Mandatory denial propagates; it is not converted to optional fallback. Existing third-party GuardrailInterface implementations remain usable. Adding new required abstract members during the promised stable major boundary is breaking.

Implementation and acceptance evidence: [ContextualGuardrailInterface.php](../Classes/Service/Guardrail/ContextualGuardrailInterface.php), [constitution.md](../.specify/memory/constitution.md).

## L01: Cached answers need an explicit policy-freshness rule

Typed answer-cache replay runs the current GuardrailMiddleware with the current call context before delivery; no provider dispatch or usage recording is repeated. Tightened mandatory term policy can deny a previously cached answer. Policy-read failure follows ordinary fail-closed guardrail behavior. Privacy is checked again for the actual original serving provider. Language is part of identity; per-call cache opt-out bypasses lookup/store. Referenced-record content is not universally tracked, so TTL/explicit invalidation still bounds non-policy staleness. Generic array cache and idempotency paths retain their separate contracts.

Implementation and acceptance evidence: [CacheMiddleware.php](../Classes/Provider/Middleware/CacheMiddleware.php), [GuardrailMiddleware.php](../Classes/Provider/Middleware/GuardrailMiddleware.php), [CallMetadataFactory.php](../Classes/Service/CallMetadataFactory.php), [TranslationCacheFlushHook.php](../Classes/Hook/TranslationCacheFlushHook.php).

## L02: Lenient argument acceptance does not preserve boolean meaning

A declared boolean accepts native JSON true/false only. Strings true/false/0/1 and integer 0/1 are rejected before preview/execution; they are never passed through to PHP truthiness casts. Other documented scalar leniency remains unchanged. This deliberately tightens model-argument validation and may cause a corrective model round; it does not change a public PHP signature.

Implementation and acceptance evidence: [ToolArgumentChecker.php](../Classes/Service/Tool/ToolArgumentChecker.php), [SearchCodeTool.php](../Classes/Service/Tool/Builtin/SearchCodeTool.php).

## L03: Routing uses bounded signals rather than the written formula

For positive measured latency/cost peers, g is their geometric mean and the signal is 1/(1+clamp(x/g,0.5,2)), bounded from 1/3 to 2/3. Values 1 and 9 yield 2/3 and 1/3. Missing values remain unmeasured. Zero cost scores 1; zero latency with positive peers scores 2/3; all-zero latency scores 0.5; sole-candidate behavior stays unchanged. Existing health/reliability weighting remains dominant. The old g/(g+x) formula is superseded.

Implementation and acceptance evidence: [CallHistoryRanking.php](../Classes/Service/Routing/CallHistoryRanking.php), [CandidateRankerTest.php](../Tests/Unit/Service/Routing/CandidateRankerTest.php).

Normative relative-signal vectors (unmeasured entries remain null). Cost vectors
apply only when `everyCostedCandidateIsMeasured()` permits the measured-cost
basis. In the `null, 9` vector the unmeasured peer has no positive list price. If
any positively priced candidate lacks a cost measurement, the whole decision
uses its list-price cost basis instead; these `callCost` vectors are not emitted:

| Measured peer values | Latency signals | Cost signals |
|---|---|---|
| 5, 5 | 0.5, 0.5 | 0.5, 0.5 |
| 1, 9 | 2/3, 1/3 | 2/3, 1/3 |
| null, 9 | null, 0.5 | null, 0.5 |
| 0, 9 | 2/3, 0.5 | 1, 0.5 |
| 0, 0 | 0.5, 0.5 | 1, 1 |
| sole positive 9 | 0.5 | 0.5 |

## L04: Restricted site configurations can resolve for authorized actors

No actor: restricted selection is refused. Authorized actor: group-restricted selection may resolve. Unauthorized actor: refuse. Explicit missing/inactive/unauthorized site selection fails closed; only no selection permits caller fallback. Ordinary automatic calls without an actor keep the restrictive path. ADR-244 and ConfigurationResolverSiteTest govern this actor matrix.

Implementation and acceptance evidence: [ConfigurationResolver.php](../Classes/Service/ConfigurationResolver.php), [ConfigurationResolverSiteTest.php](../Tests/Unit/Service/ConfigurationResolverSiteTest.php), [Adr244ASiteNamesItsConfigurationAndItsDeepLKey.rst](../Documentation/Adr/Adr244ASiteNamesItsConfigurationAndItsDeepLKey.rst).

## L05: Usage periods and coverage need precise public semantics

Totals aggregate inclusive calendar days by rounding the start in the PHP process timezone; operations use exact timestamps. A partial-day query therefore names different populations and must not imply comparability. Daily request totals and logical telemetry calls differ. Retention differs; oldest data is a lower coverage bound, never proof of uninterrupted enabled telemetry. Costs are estimated USD, not invoice reconciliation. Caller authorization is required; omitting a user filter exposes installation-wide data.

Implementation and acceptance evidence: [ExtensionUsageReader.php](../Classes/Service/Usage/ExtensionUsageReader.php), [ExtensionUsageReaderInterface.php](../Classes/Service/Usage/ExtensionUsageReaderInterface.php).

## L06: Confidential OAuth clients require an undeclared Vault capability

Public OAuth clients use the base nr-vault capability. Preregistered or dynamically registered confidential client_secret_post clients require additional-secret body injection from nr-vault 1.2.0 (or equivalent capability). Without it refuse Connect early and clean temporary secrets; public clients remain usable. Authorization/refresh must inject the appropriate secrets without exposing them. Do not raise the whole dependency minimum for optional confidential modes.

Implementation and acceptance evidence: [composer.json](../composer.json), [McpOAuthHttp.php](../Classes/Service/Tool/Mcp/OAuth/McpOAuthHttp.php), [McpOAuthRegistrar.php](../Classes/Service/Tool/Mcp/OAuth/McpOAuthRegistrar.php), [McpOAuthTokenClientTest.php](../Tests/Unit/Service/Tool/Mcp/OAuth/McpOAuthTokenClientTest.php).

## L07: Category limits need overflow and boundary acceptance rules

Selectable category traversal is breadth first, with siblings ordered by sorting then UID. The configured root is excluded (depth 0); its children count as selectable depth 1 and descendant levels 1 through 10 are retained. Return the first 200 usable entries in that order and silently truncate the rest; nested mounts must not duplicate categories. This bounds prompt size, not all fetched database rows. Updating visible selections preserves assignments outside the selectable subset, including entries excluded solely by truncation. Operators needing all categories must narrow their mounts.

Implementation and acceptance evidence: [CategoryVocabulary.php](../Classes/Service/Tool/Vision/CategoryVocabulary.php), [SetFileCategoriesToolTest.php](../Tests/Functional/Service/Tool/SetFileCategoriesToolTest.php).

## L08: Operational acceptance needs an immutable evidence index

Operational acceptance belongs to the shared workflow owner and requires immutable source/script revisions, invocations, caller blobs/results and SHA-bound receipts. The evidence index records located artifacts and explicitly marks unavailable historical receipts as unverified; caller text alone is not deployment proof. No one-shot production observation may be rerun just to manufacture evidence. Workflow callers continue to track organization @main; evidence links independently bind the accepted revision.

Implementation and acceptance evidence: [ci.yml](../.github/workflows/ci.yml).

See [operational evidence index](evidence/README.md).

## L09: OAuth framing overstates automatic-registration interoperability

OAuth supports administrator preregistration and DCR as a compatibility route with a shared administrator identity. CIMD is excluded. A preregistered-only server requires administrator setup; a DCR-capable server can use that supported flow; a CIMD-only server is unsupported. Do not imply that every conforming server requires or supports automatic DCR. In the cited MCP revision CIMD is SHOULD and deprecated DCR is MAY.

## System contract map

These entries consolidate existing ownership rather than introduce competing specs.
The implementation remains governed by the current manuals and the named amendments.

| Area | Current owner / interaction boundary | Acceptance evidence |
|---|---|---|
| Execution paths (M01) | [Middleware](../Documentation/Api/Middleware.rst): fresh calls run policies and accounting; retries repeat HTTP attempts; fallback attributes actual serving usage. Answer replay re-screens current policy without dispatch; idempotency retains its separately documented semantics. Streaming has pre-output fallback and post-output failure boundaries. | [Cache replay](../Tests/Unit/Provider/Middleware/CacheMiddlewareAnswerTest.php), [usage](../Tests/Unit/Provider/Middleware/UsagePresenceTest.php), middleware and streaming suites; C01/C02/L01 |
| Final network destination (M02) | [Tool network](../Documentation/Administration/ToolNetwork.rst): application validation, nr-vault pins, redirects and configured global exceptions are one chain. An initial public lookup alone does not prove the final socket public; no universal proxy/private-network isolation claim is made. | [Operational evidence procedure](../Documentation/ResponsibleAi/OperationalEvidence.rst); qualification must name configured DNS/proxy/allowlist exceptions |
| Actor/workspace (M03) | [ADR265](../Documentation/Adr/Adr265BackgroundWorkWritesIntoItsWorkspace.rst) and subsequent writer ADRs govern recorded workspace identity; current membership/record checks fail closed at execution. Module and CLI contexts differ. | [Workspace field fills](../Tests/Functional/Service/Agent/FieldFill/FieldFillInTheWorkspaceTest.php), RT03/RT05/RT06 |
| Durable recovery (M04) | [Agent runs](../Documentation/Administration/AgentRuns.rst): claims, terminal states, suspension and at-least-once delivery are distinct transitions. Durable state/audit is mandatory; optional diagnostics do not select run fate. | Queue, deferred-run and privacy concurrency tests; RT08/RT09 |
| Accounting/budget (M05) | C01-C03/C05-C08 define grain, evidence and timestamps. Budgets remain best-effort admission rather than atomic reservations or actual invoice accounting. | [Usage middleware](../Classes/Provider/Middleware/UsageMiddleware.php), [BudgetService](../Classes/Service/BudgetService.php), outcome persistence tests |
| Retention (M06) | [Run privacy](responsible-ai-run-privacy.md), [operational evidence](../Documentation/ResponsibleAi/OperationalEvidence.rst) and installation-specific host retention each own separate stores. No inherited history/logging completeness is asserted. | Privacy fixtures; RT04 and C07 |
| Provider/features (M07) | [Support matrix](../Documentation/Api/SupportMatrix.rst), provider capability interfaces and C12 distinguish bundled profile encoding from live provider qualification. | Provider payload/replay fixtures; no unperformed live-provider claim |
| Public API/upgrades (M08) | [API stability](../Documentation/Api/Stability.rst) and [deprecation](../Documentation/Api/Deprecation.rst) govern additive/breaking classification and caller obligations before 1.0. ADR272 records this repair's additive usage arguments. | [API snapshot](../Tests/Unit/Api/api-surface.txt), consumer fixtures, current supported matrix |
