.. include:: /Includes.rst.txt

.. _adr-211:

==================================================================
ADR-211: Typed decisions from an exchangeable backend
==================================================================

:Status: Accepted
:Date: 2026-09-28
:Amends: :ref:`ADR-060 <adr-060>` (the LLM judge grader)
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

In September 2026 TypeSafe published Jev (``POST /v1/systemone``), a model
that generates no text at all. It takes a ``state`` and a map of typed
questions — ``noul`` (yes/no, answered as the probability of yes), ``choice``
(one option out of up to 255, with a probability per option and a
``confidence``) and ``score`` (2 to 10 ordered levels, a probability-weighted
value, a probability per level and a ``confidence``) — and bills input tokens
only. The API reference is explicit that a ``noul`` answer carries no
``confidence``, that aliases such as ``jev-latest`` move without notice, that
English is the primary training language, and that the model can be steered
by adversarial content in the state.

The existing code decides a good part of the shape:

* :php:`ProviderInterface` requires chat, completion and embedding methods.
  A backend that answers typed questions and generates nothing cannot honour
  it without stub methods.
* The specialized services (:ref:`ADR-096 <adr-096>` to
  :ref:`ADR-100 <adr-100>`) already give a non-chat HTTP call the whole
  shared lifecycle: an nr-vault secret behind the audited secure client,
  input screening before egress (:ref:`ADR-098 <adr-098>`), the budget gate,
  the middleware pipeline with telemetry, circuit breaker and usage.
* :php:`UsageMiddleware` records a token-shaped result per call on the
  telemetry row (:ref:`ADR-174 <adr-174>`), with ``NULL`` where nothing was
  measured.
* :php:`GuardrailInterface::checkOutput()` receives only the
  :php:`CompletionResponse`, and :php:`InputGuardrailInterface::checkInput()`
  one string. Neither sees the task or the evidence an answer should be
  judged against, so a judgement "does this answer the question from these
  sources" cannot be a guardrail without a new contract.
* ``completeStructuredForConfiguration()`` already runs a schema-bound call
  against a *named* configuration.

Decision
========

**A new** ``@api`` **service,** :php:`DecisionServiceInterface`, **that
evaluates a subject against a named, versioned profile of typed questions
and returns typed answers, through a backend the administrator chooses.**
The feature is called *decision*, not *judge* and not after any vendor:
classification, selection and rubric scoring are the same operation.

1. **Neutral question types.** :php:`YesNoQuestion`, :php:`ChoiceQuestion`
   and :php:`ScoreQuestion`. Their constructors enforce the limits a backend
   would otherwise reject after a round-trip: a choice has 2 to 255 options,
   a score 2 to 10 levels, a key is a non-empty identifier. The vendor word
   ``noul`` stays inside the TypeSafe adapter.

2. **Profiles are declared by the consumer, the backend by the operator.** A
   consumer extension implements :php:`DecisionProfileProviderInterface`
   (tag ``nr_llm.decision_profile``, the discovery pattern of
   :ref:`ADR-056 <adr-056>` and :ref:`ADR-060 <adr-060>`). A
   :php:`DecisionProfile` carries an identifier, an integer version, its
   questions, the subject fields it requires (``task``, ``candidate``,
   ``evidence``) and, optionally, the backends its data may be sent to. The
   registry refuses a duplicate identifier at container build. Which backend
   answers is extension configuration (``decision.backend``), so a consumer
   never names a vendor or holds a key. A profile that allows no configured
   backend is refused, not sent elsewhere.

3. **Criteria come from the profile, never from the subject.** The subject is
   data. The instructions, options and levels a backend receives are the
   profile's, so a document under judgement cannot rewrite the rubric it is
   judged by. This narrows, and does not close, the manipulation risk TypeSafe
   documents; it is the reason the service returns information and decides
   nothing.

4. **Two backends behind** :php:`DecisionBackendInterface` **(tag**
   ``nr_llm.decision_backend``\ **).**

   * ``typesafe`` — a specialized service on :php:`AbstractSpecializedService`,
     so the call gets the vault-held key, input screening of every subject
     field before egress, the budget gate, telemetry, circuit breaker and
     usage without new plumbing. The model defaults to the pinned
     ``jev-1.13.0``, not an alias: thresholds calibrated against one version
     must not move under a caller silently. The versioned model id the
     response reports is kept on the result. Cost is input tokens times a
     configured price (default 0.042 USD per million input tokens, the
     published price of ``jev-1.13.0``); an empty price records no cost
     rather than a cost of zero.
   * ``llm`` — a schema-bound call through
     ``completeStructuredForConfiguration()`` against a configuration the
     operator names (``decision.llm.configuration``). It never falls back to
     the default chat configuration, so switching the generator does not
     switch the yardstick. Each question is asked for a hard label — ``yes``
     or ``no``, one option, one level index — because a probability a chat
     model writes into its output is not a measured one.

5. **The result says what was measured.** :php:`DecisionResult` carries, per
   question, the answer (the probability of yes, the chosen option, the score
   value), the per-option or per-level probabilities and the ``confidence``
   **as the backend reported them, empty or null where it reported none** —
   so a yes/no answer from TypeSafe has no confidence, and an ``llm`` answer
   has neither probabilities nor confidence. A ``calibrated`` flag names which
   of the two a caller holds, the ``model`` field names the version that
   answered, and token counts and cost are null when the backend did not
   report them (a measured zero and an absent measurement stay distinct,
   constitution principle VI).

6. **A failure is an exception, never a result.** An unknown profile, a
   missing subject field, an unconfigured or disallowed backend, a transport
   failure and an answer that does not match the questions asked all throw
   :php:`DecisionException`. Budget denials and input-guardrail denials keep
   their own types. No code path turns "the judge could not be asked" into an
   answer, so a caller cannot treat an outage as a pass by forgetting a
   status check.

7. **No verdict, no enforcement.** The service returns answers. Thresholds,
   what follows from an answer (warn, block, retry, ask a human) and every
   permission check stay in the caller's code. Nothing in nr-llm acts on a
   decision in this change.

8. **An operation of its own.** ``ProviderOperation::Decision`` labels the
   TypeSafe call in telemetry and usage. It maps to no
   :php:`ModelCapability`: the TypeSafe backend is not a ``Model`` record and
   reaches no model selection, so a capability would be a declaration nothing
   reads (the same reasoning :php:`OperationCapabilityMap` gives for
   translation). The ``llm`` backend is labelled as the chat call it is.

9. **The decision grader replaces the LLM judge.** :php:`DecisionGrader`
   (grader identifier ``decision``) grades a golden prompt through the
   built-in profile ``nr_llm.task_fulfilment`` on whichever backend is
   configured, and :php:`LlmJudgeGrader` (``llm_judge``) is removed. Keeping
   both would leave two judges with two failure semantics, one of them bound
   to the default chat configuration; the ``llm`` backend is that judge with a
   named configuration. ``nrllm:eval:run --grader decision`` therefore
   compares a TypeSafe judgement with an ``llm`` judgement on the same golden
   sets, which closes ADR-060's open "dedicated judge" follow-up. The grader,
   not the service, folds a failure into a failed grade, because in an offline
   run one bad call must not abort the set — the rule ADR-060 set for the
   judge it replaces. The judge's free-text ``reason`` is not carried over:
   the grading reason names the level the backend chose, and a model-written
   justification is no evidence of how it decided.

Consequences
============

* A consumer asks for a judgement through one typed contract, and an operator
  can change the backend, or keep the data in-house with the ``llm`` backend,
  without a code change in the consumer.
* TypeSafe is a new external data recipient. It is reached only when the
  operator configures it, receives only screened subject fields, and a profile
  can forbid it. Region, retention and contract questions for confidential
  data are the operator's to settle before enabling it; nothing here answers
  them.
* A TypeSafe call is billed, budgeted and visible like every other AI call,
  under its own operation. An ``llm`` call is billed under the configuration
  it ran on; its result carries no token counts, because the structured call
  returns none to the caller.
* Breaking: ``--grader llm_judge`` and :php:`LlmJudgeGrader` are gone; a run
  that used them passes ``--grader decision`` and configures a backend.
  Stored ``llm_judge`` results stay in ``tx_nrllm_eval_result`` but are no
  regression baseline for ``decision`` runs, because the yardstick changed.
* ``ProviderOperation::Decision`` is a new case. A consumer that matches the
  enum exhaustively without a default arm must add it.

Not in this change, each with the reason:

* **Guardrail, cache and streaming integration.** A decision needs task and
  evidence that neither guardrail contract carries; a cached decision would
  need profile version, backend model and access context in its key; a check
  after a streamed response prevents nothing. Each needs its own design once a
  consumer runs decisions in the request path.
* **Enforcement modes** (observe / warn / block / review). They belong with the
  first implicit use, and must be proven against measured error rates per
  profile first.
* **A reranker on the decision backend, routing signals, cascades and agent
  checkpoints.** They consume this service; each is justified by its own
  measurement, not by this one.
* **Quality on German content.** TypeSafe states English is where accuracy is
  best. Whether a profile works on German content is measured per profile with
  ``--grader decision``, not assumed.

Alternatives considered
=======================

**Implement TypeSafe as a** :php:`ProviderInterface` **adapter.** Rejected: it
would need chat, completion and embedding methods that can only throw, and it
would appear in every provider list as a model that cannot chat.

**A** ``ModelType::JUDGE`` **or a** ``supportsJudge`` **flag.** Rejected: a
generative model can judge, and a decision model cannot generate. The role is
the profile's, not the model's.

**A status field instead of exceptions** (evaluated / undeterminable /
unavailable / invalid). Rejected: every consumer would have to check it, and
one that did not would read an outage as an empty set of answers. The typed
exceptions of the reranker and the translators are the precedent.

**Reuse** :php:`LlmJudgeGrader` **at request time.** Rejected: its failure
mode (score 0) is right for an offline run and wrong for a request, and it is
bound to the default chat configuration.

**Keep** :php:`LlmJudgeGrader` **next to the decision grader.** Rejected: two
judges for one purpose, of which the older can only ever ask the default
configuration.

**Follow** ``jev-latest``\ **.** Rejected as the default: TypeSafe itself
advises pinning a version once thresholds are tuned. An operator can still
configure the alias.
