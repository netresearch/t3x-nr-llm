.. include:: /Includes.rst.txt

.. _adr:
.. _architecture-decision-records:

==============================
Architecture Decision Records
==============================

This section documents significant architectural decisions made during the
development of the TYPO3 LLM Extension.

.. _adr-lifecycle:

Record lifecycle
================

An ADR is a record of a decision at a point in time. It is expected to become
historically wrong; what it must never do is *look* current when it is not. The
``:Status:`` field is how a reader tells the difference.

``Accepted``
   Current. The decision and the facts it reasons from still hold.

``Accepted`` with an ``:Amended:`` field
   Still current as a whole, but a later ADR overturned, widened or expired
   part of it. The status line names which part in parentheses; the
   ``:Amended:`` field names the date and the amending record.

``Superseded`` with a ``:Superseded:`` field
   No longer current. The field names the date and the replacing record.

``Deprecated``
   What it decided is being removed, with no successor decision.

The link is written from both ends. The newer record declares ``:Amends:`` or
``:Supersedes:``; the older one declares ``:Amended:`` or ``:Superseded:`` with
the date. ``Tests/Unit/AdrLifecycleTest.php`` fails when only one end of an
**ADR-to-ADR** link is written.

Not every successor is an ADR. :ref:`ADR-012 <adr-012>` was superseded by the
nr-vault integration, which no record decided, so its field names that in prose
and has no counterpart. A field whose body references no ADR is outside the
pairing check by construction — which is also why prose there must stay
specific enough to follow.

Two rules follow from the pairing:

**An amended record keeps its reasoning.** :ref:`ADR-122 <adr-122>` declined to
build a side-effecting tool contract because no tool wrote. That premise expired
with :ref:`ADR-135 <adr-135>`, but the reasoning — do not design a contract
ahead of its first consumer — is why the writer shipped without a framework. The
record stays; the status says the premise is gone.

**Amending is the amender's job.** An ADR that overturns part of an earlier one
edits that earlier record's ``:Status:`` and ``:Amended:`` in the same change.
An accepted ADR with an expired premise and a clean ``Accepted`` status is a
defect, not history.

.. _adr-symbol-legend:

Symbol legend
=============

Each consequence in the ADRs is marked with severity
symbols to indicate impact weight:

+--------+------------------+-------------+
| Symbol | Meaning          | Weight      |
+========+==================+=============+
| ●●     | Strong Positive  | +2 to +3    |
+--------+------------------+-------------+
| ●      | Medium Positive  | +1 to +2    |
+--------+------------------+-------------+
| ◐      | Light Positive   | +0.5 to +1  |
+--------+------------------+-------------+
| ✕      | Medium Negative  | -1 to -2    |
+--------+------------------+-------------+
| ✕✕     | Strong Negative  | -2 to -3    |
+--------+------------------+-------------+
| ◑      | Light Negative   | -0.5 to -1  |
+--------+------------------+-------------+

Net Score indicates the overall impact of the decision (sum of weights).

.. _adr-decision-records:

Decision records
================

.. _adr-foundation:

Foundation
----------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-001: Provider abstraction layer

      Unified interface for OpenAI, Claude, Gemini,
      Ollama, and more.

      .. card-footer:: :ref:`Read <adr-001>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-002: Feature services architecture

      Translation, vision, embeddings, completion as
      injectable services.

      .. card-footer:: :ref:`Read <adr-002>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-003: Typed response objects

      Immutable value objects for all LLM responses.

      .. card-footer:: :ref:`Read <adr-003>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-007: Multi-provider strategy

      Fallback chains and provider selection logic.

      .. card-footer:: :ref:`Read <adr-007>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-013: Three-level configuration

      Provider -> Model -> Configuration hierarchy.

      .. card-footer:: :ref:`Read <adr-013>`
         :button-style: btn btn-secondary stretched-link

.. _adr-integration:

TYPO3 integration
-----------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-004: PSR-14 event system

      Extension points via TYPO3 events.

      .. card-footer:: :ref:`Read <adr-004>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-005: Caching framework

      Instance-default backend, ``nrllm`` cache group.

      .. card-footer:: :ref:`Read <adr-005>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-012: API key encryption

      Superseded — now via nr-vault envelope encryption.

      .. card-footer:: :ref:`Read <adr-012>`
         :button-style: btn btn-secondary stretched-link

.. _adr-api-design:

API design
----------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-006: Option objects vs arrays

      Typed option objects for API calls.

      .. card-footer:: :ref:`Read <adr-006>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-008: Error handling strategy

      Exception hierarchy and retry logic.

      .. card-footer:: :ref:`Read <adr-008>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-009: Streaming implementation

      Chunked transfer for real-time output.

      .. card-footer:: :ref:`Read <adr-009>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-010: Tool/function calling

      Provider-agnostic tool call abstraction.

      .. card-footer:: :ref:`Read <adr-010>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-011: Object-only options API

      Removed array support, typed objects only.

      .. card-footer:: :ref:`Read <adr-011>`
         :button-style: btn btn-secondary stretched-link

.. _adr-modern:

Modern architecture (v0.4+)
----------------------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-014: AI-powered wizard system

      Natural language -> structured configuration
      generation with fallback defaults.

      .. card-footer:: :ref:`Read <adr-014>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-015: Type-safe domain models

      PHP 8.1+ enums, DTOs, and value objects.

      .. card-footer:: :ref:`Read <adr-015>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-016: Thinking block extraction

      Reasoning blocks from Claude, DeepSeek, Qwen.

      .. card-footer:: :ref:`Read <adr-016>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-017: SafeCastTrait

      PHPStan level 10 compliance for mixed input.

      .. card-footer:: :ref:`Read <adr-017>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-018: Model discovery

      Multi-provider model listing with fallback
      catalogs.

      .. card-footer:: :ref:`Read <adr-018>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-019: Internationalization

      XLIFF + locale-aware features with {lang}
      placeholders.

      .. card-footer:: :ref:`Read <adr-019>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-020: Output format rendering

      Client-side plain/markdown/HTML toggle.

      .. card-footer:: :ref:`Read <adr-020>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-037: Backend AJAX admin guard

      Shared trait requires a backend admin on every
      backend AJAX endpoint (403 otherwise).

      .. card-footer:: :ref:`Read <adr-037>`
         :button-style: btn btn-secondary stretched-link

.. _adr-skills:

Skills
------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-035: Skill ingest

      GitHub-hosted ``SKILL.md`` sources: host
      allowlist, SHA-pin + checksum, disabled-by-default
      review.

      .. card-footer:: :ref:`Read <adr-035>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-036: Skill injection

      Attach skills to tasks/configurations; compose into
      the user prompt (text-gen only), budgeted and
      checksum-verified.

      .. card-footer:: :ref:`Read <adr-036>`
         :button-style: btn btn-secondary stretched-link

.. _adr-tools:

Tools
-----

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-038: Tool runtime

      DI-tagged tool registry + bounded agent loop on the
      config's vault key/model/pricing; allow-list gated,
      admin-only.

      .. card-footer:: :ref:`Read <adr-038>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-039: Global tool availability

      Site-wide per-tool enable/disable override
      (``tx_nrllm_tool_state``, no TCA) intersected with every
      run's allow-list — a hard admin kill switch.

      .. card-footer:: :ref:`Read <adr-039>`
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :hidden:

   Adr001ProviderAbstractionLayer
   Adr002FeatureServicesArchitecture
   Adr003TypedResponseObjects
   Adr004Psr14EventSystem
   Adr005Typo3CachingFrameworkIntegration
   Adr006OptionObjectsVsArrays
   Adr007MultiProviderStrategy
   Adr008ErrorHandlingStrategy
   Adr009StreamingImplementation
   Adr010ToolFunctionCallingDesign
   Adr011ObjectOnlyOptionsApi
   Adr012ApiKeyEncryption
   Adr013ThreeLevelConfigurationArchitecture
   Adr014AiPoweredWizardSystem
   Adr015TypeSafeDomainModels
   Adr016ThinkingReasoningBlockExtraction
   Adr017SafeCastTrait
   Adr018MultiProviderModelDiscovery
   Adr019InternationalizationStrategy
   Adr020BackendOutputFormatRendering
   Adr021ProviderFallbackChain
   Adr022AttributeBasedProviderRegistration
   Adr023BackendCapabilityPermissions
   Adr024DashboardWidgets
   Adr025PerUserBudgets
   Adr026ProviderMiddlewarePipeline
   Adr027SplitTaskController
   Adr028PublicServicesPolicy
   Adr029UsageAnalyticsDashboard
   Adr030SpecializedServicesVaultMigration
   Adr031PromptSnippetLibrary
   Adr032SpecializedUsageAndPricingCatalog
   Adr033SpecializedModelRegistry
   Adr034RemoveExtensionConfigDefaultProvider
   Adr035SkillIngest
   Adr036SkillInjection
   Adr037BackendAjaxAdminGuard
   Adr038ToolRuntime
   Adr039GlobalToolAvailabilityState
   Adr040PlaygroundRunTrace
   Adr041PlaygroundLiveStreaming
   Adr042ContentAndIntrospectionTools
   Adr043ToolGroups
   Adr044ErrorAnalysisTools
   Adr045SchemaAndResolutionTools
   Adr046HistoryUrlAndValidationTools
   Adr047FalTools
   Adr048DiagnosticsTools
   Adr049RagSiteSearchTools
   Adr050RetrievalAndEmbeddingScopeBoundary
   Adr051ToolCallingFeatureService
   Adr052UsageAttributionViaOptions
   Adr053ExceptionMarkerInterface
   Adr054TypedToolTurnMessages
   Adr055EmbeddingConfigurationPath
   Adr056ConfigurationPresets
   Adr057SpecializedServiceAttribution
   Adr058TelemetryMiddleware
   Adr059DecomposeLlmServiceManager
   Adr060QualityEvaluation
   Adr061SkillTrustEgressAndAudit
   Adr062StreamingLifecycle
   Adr063ProviderResilience
   Adr064CentralPrivacyModel
   Adr065ReducePublicServiceSurface
   Adr066CriteriaConfigurationResolution
   Adr067SolrPerLanguageCoreFilter
   Adr069RemovePromptTemplateStack
   Adr070UserlessConfigurationResolutionByIdentifier
   Adr071PublicKeywordSearchFacade
   Adr072RetrievalQualityEvaluation
   Adr073FirstPartyTestingFixtures
   Adr074ReciprocalRankFusionUtility
   Adr075RerankerProtocol
   Adr076DocumentUnderstanding
   Adr077NamedConfigurationCompletion
   Adr078SpecializedServiceBudgetEnforcement
   Adr079FirstPartyCompletionVisionBudgetFakes
   Adr080TypedProviderHttpExceptions
   Adr081AgentRunPersistence
   Adr082StructuredOutputs
   Adr083ConversationSessions
   Adr084HumanInTheLoopApproval
   Adr085GuardrailPipeline
   Adr086GuardrailEnforcementGaps
   Adr087InputGuardrails
   Adr088StreamingRedaction
   Adr089GuardrailBoundaryCompleteness
   Adr090SingleExtensionUntil10
   Adr091ConversationActorContext
   Adr092AgentRunTerminationReason
   Adr093ToolGateChokepoint
   Adr094ToolDataClassTrustZone
   Adr095FailureTaxonomy
   Adr096PipelineCallContext
   Adr097SpecializedServicesOnPipeline
   Adr098SpecializedInputGuardrailScreening
   Adr099SpecializedFailClosedDispatch
   Adr100SpecializedUsageExtractors
   Adr101AgentRuntime
   Adr102QueuedAgentRuns
   Adr103CooperativeCancellation
   Adr104StaleRunReaperAndRetry
   Adr105TypedInputSuspension
   Adr106PerConfigurationGuardrailPolicy
   Adr107ContextWindowManagement
   Adr108TypedToolResultWithArtifacts
   Adr109AgentRunsApprovalsInbox
   Adr110ServiceAccountScopes
   Adr111ToolEffectAndWriteAudit
   Adr112LeaseBeforeOpWriteFence
   Adr113FailClosedToolDataClassEnforcement
   Adr114EncryptAgentStateAtRest
   Adr115EnforceByDefaultForNewInstalls
   Adr116CentralToolingAuthority
   Adr117WithdrawCapabilityPermissions
   Adr118SpecializedServiceVerification
   Adr119BackendModulePlacement
   Adr120RequiredToolGate
   Adr121ConversationContextWindow
   Adr122ToolEffectContractDeferred
   Adr123OneSecretShapeCatalogue
   Adr124ProviderKeyFromTheCommandLine
   Adr125PerAdapterCollaborators
   Adr126StrictSchemaSubset
   Adr127ApiSurfaceMarkers
   Adr128ProviderNativeStructuredOutput
   Adr129StructuredConsumers
   Adr130BackendUserGrants
   Adr131EditorModule
   Adr132ApprovalAuditAndTurnBinding
   Adr133ApproverToolGate
   Adr134WriteEffectImpliesApproval
   Adr135UpdatePageMetadataTool
   Adr136WritePreviewAtSuspend
   Adr137OneCandidateResolution
   Adr138OperationCapabilityMatch
   Adr139ContextAssemblyIsASeam
   Adr140EffectivePolicyReadoutWithoutApplyPath
   Adr141EveryExecutingSegmentHoldsALease
   Adr142OneRoutingDecision
   Adr143BoundEverySendAgainstTheServingModel
   Adr144InjectedContextCarriesADataClass
   Adr145GovernanceProfilesDescribeNeverApply
   Adr146ThreeMoreEditorialWriters
   Adr147NoSymfonyAiBridgeYet
   Adr148RoutingReadoutOnTheGovernanceTab
   Adr149CriteriaModeZoneFromTheResolvedModel
   Adr152EditorActionDeclaration
   Adr154McpServerHealth
   Adr155SystemPromptCarriesADataClass
   Adr156PersistTheRoutingDecisionObserveComplexity
   Adr157TheSimulationCoversTheRunAndAnswersForAnActor
   Adr158EditorActionCenter
   Adr159OneExtensionConfirmedAtTheFreeze
   Adr160AdapterContractAndCapabilityProvenance
   Adr162BulkEditorActionsAsOrdinaryRuns
