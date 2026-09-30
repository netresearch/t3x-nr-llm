<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Typed decisions from an exchangeable backend

Consumers need judgements rather than text — is this passage relevant, does
this script keep to its source, which category fits — and nr-llm had only an
offline judge bound to the default chat configuration. This change adds a
decision service with typed questions and consumer-declared profiles, and
makes a decision a model operation: a model that declares the capability
`decision` answers natively through its provider (TypeSafe, a local NLI
sidecar, any adapter implementing `DecisionCapableInterface`), any other chat
model through structured output. Decision record: ADR-211 (amends ADR-060,
ADR-082, ADR-128, ADR-129).

## What it must do

- `DecisionServiceInterface::evaluate()` resolves a declared profile, refuses
  a request that lacks a subject field the profile requires, and asks the
  configuration the request names — otherwise the one in
  `decision.configuration`, never the default configuration.
- Three question types with the providers' limits in their constructors:
  yes/no; choice with 2 to 255 unique option names, given as a list with the
  descriptions apart (so `"0"`, `"1"` are names, and the wire carries an
  object); score with 2 to 10 distinct levels; no blank instruction,
  option, description, level or yes/no meaning; lower-case keys of at most
  64 characters, a trailing newline included in "not lower-case".
- The model is resolved for the operation `decision`. A model declaring
  `decision` is asked through `LlmServiceManager::decideForConfiguration()`:
  every subject field screened by the input guardrails, the configuration's
  pipeline run as `decision`, the response priced with the model that served
  unless the provider priced it, an adapter that cannot decide refused. A
  chat model — or one declaring nothing — is asked through a strict schema
  for hard labels. A model declaring neither (embeddings) cannot decide.
- The profile's data class is checked against the least trusted zone of the
  providers the call can reach, fallbacks included, before anything is sent.
  The model is resolved once and that routing decision is handed to the call,
  so the model checked is the model that serves.
- Every failure throws: unknown or invalid profile, missing field, no or
  unknown configuration, a data class the zone may not receive, a model that
  cannot decide (a decision model on a provider that cannot decide
  included), a rejected request (any 4xx but 429), a malformed answer (a chat
  model's reply that misses the schema after the repair included), any other
  failure (wrapped with its cause), and a response that does not answer
  exactly the profile's questions with the right key, type, option, range
  and probability keys. Budget, guardrail and input-context trust-zone
  denials keep their type.
- The result carries only what was measured: the configuration, provider and
  reported model; a `ProbabilityKind` (`None`, `Distribution`,
  `Calibrated`); no confidence for a TypeSafe yes/no answer, no
  probabilities from a chat model; `null` — not 0 — for tokens and cost that
  were not reported.
- TypeSafe (`typesafe`): `POST /v1/systemone` with `noul` / `choice` /
  `score`, criteria from the profile only, the pinned `jev-1.13.0` by
  default, the reported model version kept; 401, 422 and 429 map to the
  typed provider exceptions, 5xx to a connection failure.
- Local sidecar (`decision_sidecar`, `Build/decision`): `POST /decide` with
  the same three types, no key; its own tests and lock check run in CI.
- Model prices are decimal (`decimal(12,2)`, cents per million tokens), so
  0.042 USD is a price; an unpriced model ranks last on cost.
- The backend module's model and configuration tests send a decision model
  one yes/no probe instead of a chat prompt.
- `nrllm:eval:run --grader decision` grades a golden set through the built-in
  profile `nr_llm.task_fulfilment`; a failed decision is a failed grading. A
  run is stored under `decision:<provider>:<model>:v<version>`; a run without
  one yardstick is stored as `decision`, one where all failed as
  `decision:failed`, and neither is compared nor passes
  `--fail-on-regression`. The grader judges against the task and the system
  prompt the call ran with.

## What it explicitly does not do

- No enforcement: nothing in nr-llm acts on a decision. Thresholds and
  consequences are the caller's.
- No profile-to-configuration assignment field, no guardrail, cache or
  streaming integration, no reranker, routing signal, cascade or agent
  checkpoint (ADR-211 names why; each is a follow-up with its own ADR).
- No claim about quality on German content. That is measured per profile with
  `--grader decision`; this change ships the instrument, not the result.
- No live call to TypeSafe or a model download in any suite: the contracts
  are exercised against the documented request and response shapes.

## Public surface and security boundaries

- Additive: the `Service\Decision` namespace, the value objects under
  `Domain\ValueObject\Decision`, `DecisionResponse`, `DecisionOptions`,
  `DecisionCapableInterface`, `AbstractDecisionProvider`, the two adapters,
  `ModelCapability::DECISION`, `ProviderOperation::Decision`,
  `StructuredCompletionResponse`, `UsageStatistics::plus()`,
  `FakeDecisionService`. Public services rise from 38 to 39.
- Breaking (0.x, under a BREAKING heading): `LlmServiceManagerInterface`
  gains `decideForConfiguration()`; `Model` prices are `float`;
  `completeStructured*()` return a `StructuredCompletionResponse`;
  `LlmJudgeGrader` and `--grader llm_judge` are removed.
- New external data recipient: TypeSafe, only as a configured provider, only
  screened subject fields, key as nr-vault identifier, gated by the
  provider's trust zone against the profile's data class.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Question limits, key pattern (trailing newline), unique and described options, numeric option names | unit `DecisionQuestionTest`; fuzzy `DecisionQuestionBoundsFuzzyTest` (option and level counts) |
| Probability and score ranges, profile validation, exception codes | unit `DecisionValueObjectsTest`; fuzzy `DecisionQuestionBoundsFuzzyTest` (probability of yes) |
| Configuration from request or setting, never the default; unknown and inactive refused | unit `DecisionServiceTest` |
| Native vs structured by capability, embeddings refused, criteria fallback to chat | unit `DecisionServiceTest` |
| Data class against the trust zone, before anything is asked | unit `DecisionServiceTest::aProfileWhoseDataTheZoneMayNotReceiveIsRefusedBeforeAnythingIsAsked` |
| One routing decision checked and served | unit `DecisionServiceTest` (the handed-over resolution), `LlmServiceManagerDecisionTest::aHandedOverRoutingDecisionServesThePrimaryConfiguration`, `StructuredDecisionAskerTest::aHandedOverRoutingDecisionReachesTheCompletion` |
| Failure mapping (422, 401, 429, 529, malformed), cause kept, policy denials pass through | unit `DecisionServiceTest` |
| No response handed out that does not match the questions | unit `DecisionServiceTest::aResponseThatDoesNotMatchTheQuestionsIsNeverHandedOut` |
| Budget subject from request or backend user, caller source | unit `DecisionServiceTest` |
| Duplicate or invalid profiles fail only their own identifiers | unit `DecisionServiceTest::oneBrokenProviderLeavesTheOthersProfilesUsable`, `anIdentifierDeclaredTwiceIsWithheldWhileTheRestStays` |
| Availability checked without spending; the grader refuses a run before its first completion | unit `DecisionServiceTest::availabilityIsCheckedWithoutAskingAnything`, `EvalRunCommandTest::aDecisionGraderThatCannotRunFailsBeforeAnyCompletionIsPaidFor` |
| A fallback that cannot serve the operation is skipped, the primary's failure kept | unit `FallbackMiddlewareTest::aFallbackThatCannotServeTheOperationIsSkipped`, `aChainOfFallbacksThatCannotServeKeepsThePrimarysFailure` |
| Native call: screening, pipeline as `decision`, attribution, adapter refused, pricing | unit `LlmServiceManagerDecisionTest` |
| Structured path: schema, prompt, hard labels, options, usage of every attempt | unit `StructuredDecisionAskerTest` |
| TypeSafe wire format, answers as reported, malformed answers, error statuses | unit `TypeSafeProviderTest` |
| Sidecar wire format, distribution, validation detail | unit `DecisionSidecarProviderTest`; `Build/decision/test_app.py` (CI workflow `decision-sidecar.yml`) |
| Decision usage recorded | unit `UsageMiddlewareTest::tracksDecisionResponse` |
| Discovery of both adapters, pinned version, prices, fallbacks | unit `DecisionModelDiscovererTest`, `CapabilitySeedTest` |
| Decimal prices, rounding, unpriced ranks last | unit `ModelTest`, `CandidateRankerTest`; fuzzy `ModelFuzzyTest` |
| Decision probe in model and configuration tests | unit `ModelTestControllerTest`, `ConfigurationControllerTest` |
| Structured result: data, answering response, summed usage, attempts | unit `CompletionServiceTest`, `UsageStatisticsTest` |
| Decision grader scaling, threshold, failure folding, subject, series name | unit `DecisionGraderTest`, `GradingServiceTest` |
| Runs without one yardstick or all failed: stored, not compared, gate fails | unit `EvaluationServiceTest`, `EvalRunCommandTest` |
| The grader sees the effective system prompt | unit `EvaluationServiceTest::theGraderSeesTheSystemPromptTheCallRanWith`, `DecisionGraderTest::theSystemPromptTheModelWasGivenIsPartOfTheTask` |
| A stored `llm_judge` run is no baseline for `decision` | functional `EvaluationResultRepositoryTest::findLatestSegregatesByGrader` |
| `Decision` requires `decision` in the capability map | unit `OperationCapabilityMapTest` |
| Adapter types, registry, detection | unit `AdapterTypeTest`, `ProviderAdapterRegistryTest`, `ProviderDetectorTest` |
| Public service count | unit `PublicServicesPolicyTest` |
| API snapshot | unit `ApiSurfaceSnapshotTest` |
