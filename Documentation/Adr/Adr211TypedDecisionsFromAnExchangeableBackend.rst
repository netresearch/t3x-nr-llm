.. include:: /Includes.rst.txt

.. _adr-211:

==================================================================
ADR-211: Typed decisions from an exchangeable backend
==================================================================

:Status: Accepted
:Date: 2026-09-28
:Amends: :ref:`ADR-013 <adr-013>` (prices stored as integers),
   :ref:`ADR-060 <adr-060>` (the LLM judge grader),
   :ref:`ADR-082 <adr-082>` (what the structured methods return),
   :ref:`ADR-128 <adr-128>` (what their callers consume),
   :ref:`ADR-129 <adr-129>` (the judge as a structured consumer)
:Authors: Netresearch DTT GmbH

Context
=======

Consumers of nr-llm increasingly need a *judgement* rather than a text: is
this passage relevant to the question, does this script keep to its source,
which of these five categories fits, how complete is this answer on a
four-level rubric. Today there is exactly one place that asks a model for
such a judgement, :php:`LlmJudgeGrader` (:ref:`ADR-060 <adr-060>`), and it is
an offline evaluation grader: it asks the default chat configuration for a
``{"score", "reason"}`` verdict, and folds a failed call into a failed grade
with score 0. ADR-060 names "selecting a dedicated judge model" as an open
follow-up.

A consumer that wants a judgement at request time has no contract for it. It
can call ``completeStructured()`` with its own schema, which works, but every
consumer then invents its own question format, its own reading of a
self-reported "confidence", and its own answer to "what does a failed call
mean".

Models now exist that make such decisions natively. In September 2026
TypeSafe published Jev (``POST /v1/systemone``), a model that generates no
text: it takes a ``state`` and a map of typed questions — ``noul`` (yes/no,
answered as the probability of yes), ``choice`` (one option out of up to 255,
with a probability per option and a ``confidence``) and ``score`` (2 to 10
ordered levels, a probability-weighted value, a probability per level and a
``confidence``) — and bills input tokens only. Its documentation is explicit
that a ``noul`` answer carries no ``confidence``, that aliases such as
``jev-latest`` move without notice, that English is the primary training
language, and that adversarial content in the state can move the answer.
Freely available natural-language-inference models answer the same three
question shapes locally as zero-shot classification — for example
``MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7`` (MIT, trained
on German among other languages) — with probabilities that are the model's
distribution, not a calibration.

The existing code decides a good part of the shape:

* Every model an administrator can choose is a ``Provider`` record, a
  ``Model`` record with capabilities, and an ``LlmConfiguration`` that a
  consumer names per use case. Budget per configuration, usage and pricing
  per model, fallback chains, the circuit breaker on provider exceptions and
  the provider's trust zone all hang on these records.
* :php:`ProviderInterface` requires chat, completion and embedding methods;
  a provider that lacks one throws :php:`UnsupportedFeatureException` there
  (Claude and Groq for embeddings), and optional abilities are separate
  contracts (streaming, tools, vision, documents).
* ``Model`` stores prices as integer cents per million tokens. Jev's
  0.042 USD is 4.2 cents and cannot be stored.
* :php:`UsageMiddleware` records a typed response per call on the telemetry
  row (:ref:`ADR-174 <adr-174>`), with ``NULL`` where nothing was measured.
* :php:`GuardrailInterface::checkOutput()` receives only the
  :php:`CompletionResponse`, and :php:`InputGuardrailInterface::checkInput()`
  one string. Neither sees the task or the evidence an answer should be
  judged against, so a judgement "does this answer the question from these
  sources" cannot be a guardrail without a new contract.
* ``completeStructuredForConfiguration()`` runs a schema-bound call against a
  named configuration, but returns only the decoded array: the model that
  answered and the tokens of a repair round-trip are lost.

Decision
========

**A decision is a model operation like chat or embeddings. A model that
decides natively is a** ``Model`` **record with the capability**
``decision`` **behind a provider adapter that implements**
:php:`DecisionCapableInterface`\ **; any chat model can answer the same
questions through structured output. A new** ``@api`` **service,**
:php:`DecisionServiceInterface`\ **, evaluates a subject against a named,
versioned profile of typed questions on a configuration and returns typed
answers.** The feature is called *decision*, not *judge* and not after a
vendor: classification, selection and rubric scoring are one operation.

1. **Neutral question types.** :php:`YesNoQuestion`, :php:`ChoiceQuestion`
   and :php:`ScoreQuestion`, with :php:`DecisionSubject` and
   :php:`DecisionAnswer`, are domain value objects, because a provider
   contract reads them. Their constructors enforce the limits a backend
   would otherwise reject after a round-trip: a choice has 2 to 255 unique
   options, a score 2 to 10 levels, a key is a lower-case identifier. A
   choice takes its option names as a list and their descriptions as a
   separate map: one map of name to description cannot tell
   ``['red', 'green']`` from options named ``"0"`` and ``"1"``, which PHP
   stores under the same integer keys. Vendor vocabulary (``noul``) stays
   inside its adapter.

2. **Profiles are declared by the consumer, the model by the operator.** A
   consumer extension implements :php:`DecisionProfileProviderInterface`
   (tag ``nr_llm.decision_profile``, the discovery pattern of
   :ref:`ADR-056 <adr-056>` and :ref:`ADR-060 <adr-060>`). A
   :php:`DecisionProfile` carries an identifier, an integer version, its
   questions, the subject fields it requires (``task``, ``candidate``,
   ``evidence``) and the **data class** of its subject
   (:php:`ToolDataClass`, default editor content). A request names a
   configuration, or the service uses the one in the extension setting
   ``decision.configuration``; the default chat configuration is never
   used. A consumer therefore picks a decision model per use case exactly
   as it picks any other model, and never holds a key.

3. **Data policy through the trust zone.** Before any request, the service
   checks the profile's data class against the least trusted zone the call
   can reach — the provider of the model that will serve it and every
   provider one fallback hop away, a criteria-mode fallback counted as
   external-global (:php:`TrustZoneResolver`, the ceiling
   :ref:`ADR-094 <adr-094>` introduced for tools). The model is resolved
   once and that routing decision is handed to the call, so the model
   checked is the model that serves; the input-context gate reads the same
   decision. A profile whose subject is
   internal configuration is refused on an external-global provider,
   whichever model that is. This replaces a list of permitted backend names,
   which said nothing about where a backend sends its data.

4. **Two paths, chosen by the model.** The service resolves the
   configuration's model for ``ProviderOperation::Decision``. Where that
   yields no decision model — a fixed chat model, or criteria that match no
   decision model — the structured path runs as the chat call it is: it
   resolves for ``ProviderOperation::Chat`` (a model that declares ``chat``,
   or one that declares no capabilities at all) and is
   recorded under that operation, so telemetry and usage name what
   actually ran.

   * *Native* — the model declares ``decision``; its adapter must implement
     :php:`DecisionCapableInterface`, and one that does not is refused as a
     model that cannot decide rather than asked through the other path:
     ``LlmServiceManager::decideForConfiguration()`` screens every subject
     field through the input guardrails, enters the middleware pipeline
     with the configuration (budget per configuration and user, fallback,
     circuit breaker, telemetry, usage), and calls the
     adapter. The adapter returns a typed :php:`DecisionResponse`; the
     manager prices it with the model that actually served, fallback
     included. Two adapters ship: ``typesafe`` (Jev, pinned default model
     ``jev-1.13.0``, because thresholds tuned on one version must not move
     under a caller) and ``decision_sidecar``, a local zero-shot NLI service
     in ``Build/decision`` that needs no key and keeps the subject on the
     host — for tests, local development and comparison.
   * *Structured* — any other model, typically a chat model: a schema-bound
     call through ``completeStructuredForConfiguration()`` asking every
     question for a hard label (``yes``/``no``, one option, one level index),
     because a probability a chat model writes into its text is not a
     measured one.

   The structured path needs what the structured call so far threw away: the
   model that actually answered and the tokens of every attempt, the
   rejected first answer of a repair included. So ``completeStructured()``
   and ``completeStructuredForConfiguration()`` **return a**
   :php:`StructuredCompletionResponse` — the validated ``data``, the
   accepted :php:`CompletionResponse`, the summed :php:`UsageStatistics` and
   the number of attempts — instead of the bare array. This is a breaking
   change for every caller, taken instead of a second method beside each of
   the two.

5. **Prices are decimal.** ``Model`` keeps its unit, cents per million
   tokens, and stores it with two decimals — the precision TYPO3's backend
   form keeps for a decimal field — so a price of 4.2 cents is a price and
   not 4 or 0; the smallest price stored is 0.01 cents per million tokens.
   This is a breaking change of ``Model``'s price getters from ``int`` to
   ``float``, and of the criteria cap on the input price with them.

6. **The result says what was measured.** :php:`DecisionResult` carries, per
   question, the answer (the probability of yes, the chosen option, the score
   value), the per-option or per-level probabilities and the ``confidence``
   **as the model reported them, empty or null where it reported none**. Its
   :php:`ProbabilityKind` names which kind a caller holds: ``Calibrated``
   (a vendor's calibrated distribution, TypeSafe), ``Distribution`` (the
   model's own probabilities, uncalibrated — the NLI sidecar) or ``None``
   (hard labels, the structured path). The result names the configuration,
   the provider and the model that answered; token counts and cost are
   ``null`` when nothing reported them (a measured zero and an absent
   measurement stay distinct, constitution principle VI).

7. **A failure is an exception, never a result.** An unknown profile or
   configuration, a missing subject field, a data class the provider may not
   receive, a model that can neither decide nor chat, a request the provider
   rejects as invalid, a transport failure and an answer that does not match
   the questions asked — wrong key, type, option, level range or probability
   keys — all throw :php:`DecisionException` with a named code. Budget,
   guardrail and input-context trust-zone denials keep their own types, as
   policy a caller may handle. No code path turns "the model could not be
   asked" into an answer. A fallback configuration whose model cannot serve
   the operation at all is skipped rather than reported in place of the
   primary's failure — for every operation, since the same holds for a
   sibling that lacks embeddings or vision.

8. **No verdict, no enforcement.** The service returns answers. Thresholds,
   what follows from an answer (warn, block, retry, ask a human) and every
   permission check stay in the caller's code.

9. **Criteria come from the profile, never from the subject.** The
   instructions, options and levels a model receives are the profile's, so a
   document under judgement cannot replace the rubric it is sent with. It can
   still try to sway the answer — TypeSafe documents that, and a chat model
   reads the subject in the same prompt as the questions. This narrows the
   manipulation risk and does not close it, which is the reason for point 8.

10. **An operation and a capability.** ``ProviderOperation::Decision``
    labels the native call and maps to ``ModelCapability::DECISION``, which
    the operation map enforces for criteria-mode selection. Model discovery
    writes the capability for the two decision adapters, and the backend
    module's model and configuration tests send a decision probe to a
    decision model instead of a completion it cannot answer. A decision model
    answers no chat call, so it must not serve generic ``chat()`` calls: the
    setup wizard never makes such a model the default model, and the
    operator documentation says not to make a decision configuration the
    default configuration. A chat call that still reaches one fails with
    ``UnsupportedFeatureException`` rather than an answer.

11. **The decision grader replaces the LLM judge.** :php:`DecisionGrader`
    (grader identifier ``decision``) grades a golden prompt through the
    built-in profile ``nr_llm.task_fulfilment`` on the configured decision
    configuration, and :php:`LlmJudgeGrader` (``llm_judge``) is removed:
    keeping both would leave two judges with two failure semantics, one of
    them bound to the default chat configuration. The grader, not the
    service, folds a failure into a failed grade, because in an offline run
    one bad call must not abort the set. What would fail every grade — no
    configuration, a profile that cannot be used, a model that cannot answer,
    a trust zone that may not receive the task — is checked before the run
    spends its first completion (``assertAvailable()``), and refuses the run
    instead. The grader judges the response
    against the task and the system prompt the call ran with, so an ignored
    instruction counts against it.

    A run is stored and compared under the grader its gradings report, and
    the decision grader reports its yardstick,
    ``decision:<provider>:<model>:v<profile version>``. Runs on two providers
    or two model versions are separate series and never each other's
    regression baseline. A run whose gradings disagree (a decision failed for
    some prompts) is stored under ``decision`` and never compared; a failed
    decision reports ``decision:failed``, so no clean run shares either
    identifier. With ``--fail-on-regression`` both are a failure: a gate that
    stays green while the judge is down is the outage-read-as-pass this
    record rules out.

Consequences
============

* A consumer asks for a judgement through one typed contract and picks the
  model per use case like any other; an operator changes the model — TypeSafe,
  a local NLI sidecar, any chat model — without a code change in the
  consumer, and the trust zone decides where a subject may go.
* A decision call is budgeted, priced, observed and failed over like every
  other model call, through the same records.
* TypeSafe is a new external data recipient. It is reached only through a
  configuration an operator creates, receives only screened subject fields,
  and a profile's data class can keep a subject away from it. Region,
  retention and contract questions for confidential data are the operator's
  to settle; nothing here answers them.
* Breaking: ``completeStructured*()`` returns a
  :php:`StructuredCompletionResponse`; a caller reads ``->data``.
* Breaking: ``Model`` prices are ``float`` cents per million tokens.
* Breaking: ``--grader llm_judge`` and :php:`LlmJudgeGrader` are gone. Stored
  ``llm_judge`` results stay in ``tx_nrllm_eval_result`` but are no
  regression baseline for ``decision`` runs.
* ``LlmServiceManagerInterface`` gains ``decideForConfiguration()``; an
  implementation outside nr-llm must add it.
* ``ProviderOperation::Decision`` and ``ModelCapability::DECISION`` are new
  cases. A consumer that matches either enum exhaustively must add them.
* The structured path's result carries the tokens of every attempt and a cost
  only where the provider reported one; the priced cost of a chat call is in
  its usage record. Surfacing it on the response is a change to the chat
  pipeline, not to this service.

Follow-ups, each with the reason it is not here:

* **A profile-to-configuration assignment in the backend.** Today a consumer
  passes a configuration or the extension setting names one for all
  profiles. An assignment per profile needs its own record and module view.
* **Guardrail, cache and streaming integration.** A decision needs task and
  evidence that neither guardrail contract carries; a cached decision would
  need profile version, model and access context in its key; a check after a
  streamed response prevents nothing.
* **Enforcement modes, cascades, routing signals, agent checkpoints and a
  reranker on a decision model.** They consume this service, and each needs
  measured error rates per profile first — which ``--grader decision`` on
  TypeSafe, the NLI sidecar and a chat model now produces, German content
  included.

Alternatives considered
=======================

**Decisions as a specialized service configured in the extension settings**
(the first draft of this record: one backend per installation, the key in
``decision.typesafe.*``). Rejected: without a ``Model`` record a consumer
cannot pick a decision model per use case, an installation cannot run two,
there is no budget per configuration, the circuit breaker never trips on a
specialized-service exception, and the only data policy was a list of backend
names. The review of that draft found each of these as a separate defect.

**A** ``ProviderInterface`` **method for decisions.** Rejected:
``ProviderInterface`` is an extension point that gains no abstract member
within a major version (:ref:`ADR-127 <adr-127>`); an opt-in contract beside
streaming, tools and vision is the established shape.

**A** ``ModelType::JUDGE`` **or a** ``supportsJudge`` **flag.** Rejected: a
generative model can judge, and a decision model cannot generate. The
capability states what the model can answer; the role is the profile's.

**A boolean** ``calibrated`` **flag.** Rejected: it cannot tell an NLI
model's own probabilities from a calibrated vendor distribution, and calling
the former calibrated would mislead every threshold built on it.

**A status field instead of exceptions** (evaluated / undeterminable /
unavailable / invalid). Rejected: every consumer would have to check it, and
one that did not would read an outage as an empty set of answers.

**Reuse or keep** :php:`LlmJudgeGrader`\ **.** Rejected: its failure mode
(score 0) is wrong at request time, it is bound to the default chat
configuration, and beside the decision grader it would be a second judge for
one purpose.

**Follow** ``jev-latest``\ **.** Rejected as the default: TypeSafe itself
advises pinning a version once thresholds are tuned. An operator can still
name the alias as the model id.
