.. include:: /Includes.rst.txt

.. _api-decision-service:

================
Decision service
================

Typed decisions about a subject, by a profile the consumer declares, from the
model of a configuration (:ref:`ADR-211 <adr-211>`). The service answers
yes/no, choice and score questions and decides nothing: thresholds,
consequences and permission checks stay in the calling code.

A decision is a model operation like chat or embeddings. A model record that
declares the capability ``decision`` answers natively, through its provider's
decision API; any other chat-capable model answers the same questions through
structured output. Which one answers is a matter of configuration, not of code.

.. php:namespace:: Netresearch\NrLlm\Service\Decision

.. php:interface:: DecisionServiceInterface

   Public DI service. Resolve it by interface.

   .. php:method:: evaluate(DecisionRequest $request): DecisionResult

      Resolve the profile and check the subject against the fields it
      requires; resolve the configuration and its model; check the profile's
      data class against the trust zone of every provider the call can reach;
      ask the model; verify that there is exactly one answer of the right type
      and range per question.

      :param DecisionRequest $request: profile identifier, subject, optional
         configuration identifier, budget subject (``beUserUid``) and caller
         source
      :returns: DecisionResult
      :throws: ``DecisionException`` for every failure except the ones below —
         its code names the cause (see :ref:`api-decision-service-failures`);
         ``BudgetExceededException``, ``GuardrailViolationException``,
         ``GuardrailApprovalRequiredException`` and
         ``InputContextTrustZoneException`` keep their own types, because a
         caller may handle them as policy. A failure is never returned as an
         answer.

   .. php:method:: assertAvailable(string $profile, ?string $configuration = null): void

      Check what needs no subject — the profile, the configuration, a model
      that can answer it and the trust zone against the profile's data
      class — without asking anything. A caller about to spend many paid
      calls whose results only a decision can judge checks this first, as
      ``nrllm:eval:run --grader decision`` does.

      :throws: ``DecisionException`` with the code ``evaluate()`` would fail
         with

Declaring a profile
===================

A consumer extension implements
:php:`Profile\DecisionProfileProviderInterface`; autoconfiguration tags it
``nr_llm.decision_profile``. A profile carries an identifier, a version, its
questions, the subject fields it requires and the most sensitive data class
its subject can hold.

.. code-block:: php
   :caption: EXT:my_ext/Classes/Decision/MyDecisionProfiles.php

   use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
   use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
   use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
   use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;
   use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
   use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
   use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileProviderInterface;

   final class MyDecisionProfiles implements DecisionProfileProviderInterface
   {
       public function getDecisionProfiles(): array
       {
           return [
               new DecisionProfile(
                   identifier: 'my_ext.rag_answer',
                   version: 1,
                   questions: [
                       new ChoiceQuestion(
                           'verdict',
                           'What should happen to `candidate`?',
                           ['publish', 'revise', 'discard'],
                           ['revise' => 'Right in substance, needs rework'],
                       ),
                       new YesNoQuestion(
                           'supported',
                           'Is every claim in `candidate` supported by `evidence`?',
                       ),
                       new ScoreQuestion(
                           'answers_task',
                           'How completely does `candidate` answer `task`?',
                           ['Not at all', 'Partly', 'Completely'],
                       ),
                   ],
                   requires: [
                       SubjectField::Task,
                       SubjectField::Candidate,
                       SubjectField::Evidence,
                   ],
                   dataClass: ToolDataClass::EDITOR_CONTENT,
               ),
           ];
       }
   }

The questions:

*  :php:`YesNoQuestion` — optional ``yesMeans`` and ``noMeans`` criteria.
*  :php:`ChoiceQuestion` — 2 to 255 unique option names as a list and the
   descriptions as a separate map keyed by option, so options named ``"0"``
   and ``"1"`` are names like any other.
*  :php:`ScoreQuestion` — 2 to 10 levels; the answer is a level value from 0
   to the highest level index.

A question key is a lower-case identifier of at most 64 characters
(``[a-z][a-z0-9_]*``): it becomes a key of the answer map and of every wire
format.

Raise the version whenever a question, an option, a level or an instruction
changes: a threshold tuned against one version does not carry over. Criteria
come from the profile only — the subject is data and cannot replace the
rubric it is sent with. It can still try to sway the answer: TypeSafe
documents that adversarial content moves its judgement, and a chat model reads
the subject in the same prompt as the questions. Treat a decision as
information, never as a permission.

A provider whose profiles cannot be built — one throws, or declares an invalid
profile — is set aside, and an identifier declared twice is withheld; the
other providers' profiles keep working, and asking for a withheld one names
the failure (``INVALID_PROFILE``).

The data class is checked before anything is sent: a profile that holds
``SOURCE_CODE`` cannot be asked on a configuration whose provider — or any
provider in its fallback chain — sits in a trust zone that may not receive it
(:ref:`administration-governance`).

Asking for a decision
=====================

.. code-block:: php
   :caption: Evaluating a RAG answer before it is shown

   use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
   use Netresearch\NrLlm\Service\Decision\DecisionRequest;

   $result = $decisionService->evaluate(new DecisionRequest(
       profile: 'my_ext.rag_answer',
       subject: new DecisionSubject(
           task: $question,
           candidate: $answer,
           evidence: $passages,
       ),
       configuration: 'rag_judge',
       callerSourceExtension: 'my_ext',
       callerSourceOperation: 'rag_answer',
   ));

   $supported = $result->answer('supported');
   // Your threshold, your consequence. Calibrate it per profile and model.
   if ($supported->value < 0.8) {
       // e.g. show the answer with a warning, or not at all
   }

The configuration is the one the request names, otherwise the one the
extension setting ``decision.configuration`` names — never the default
configuration, so a change of the generating model does not change the model
that judges it. Without either, the call fails with ``NO_CONFIGURATION``.

Without a ``beUserUid`` in the request the service asks the backend user
context, so the budget of the logged-in editor applies as it does to a chat
call.

Which model answers
===================

The service resolves the configuration's model for the operation
``decision``:

*  A model that declares the capability ``decision`` answers natively, through
   :php:`LlmServiceManagerInterface::decideForConfiguration()`. The subject
   fields are screened by the input guardrails, and the call runs the
   configuration's middleware pipeline — budget, fallback, circuit breaker,
   telemetry and usage — under the operation ``decision``.
*  Any other model that can chat — or that declares no capabilities at all —
   answers through a schema-bound structured completion on the same
   configuration. Every question is asked for a hard label (``yes``/``no``,
   one option, one level index), because a probability a chat model writes
   into its answer is text, not a measured distribution. The call resolves
   and is recorded as the chat call it is — operation ``chat``, not
   ``decision`` — and input and output guardrails, budget, fallback and
   usage apply as to any chat call.
*  A model that declares capabilities but neither ``decision`` nor ``chat``
   (an embedding model) cannot answer: ``MODEL_CANNOT_DECIDE``.

.. note::

   The profile, its required fields and its data class are the decision
   service's contract. A consumer that calls
   :php:`LlmServiceManagerInterface::decideForConfiguration()` directly gets
   the input guardrails, the input-context gate and the pipeline, but no
   profile and therefore no data-class check against the trust zone — use
   the service unless you apply that check yourself.

In criteria mode the operation ``decision`` itself narrows the choice to
models that declare ``decision`` — with operation capability enforcement on,
the default (``routing.operationCapabilityEnforcement``); in ``observe`` mode a
better-ranked chat model can win and answers through structured output. Where none matches, the service resolves the
configuration for ``chat`` and asks that model through structured output; add
``cap:decision`` to the criteria to make a configuration decision-only. Either
way the model is resolved once, and the call is served by exactly the model
the trust zone was checked against.

Reading the result
==================

.. php:class:: DecisionResult

   ``profile``, ``profileVersion``, ``configuration`` (the one that was
   asked), ``provider`` and ``model`` (as the provider reported them),
   ``probabilityKind``, ``answers`` keyed by question key, and
   ``inputTokens`` / ``outputTokens`` / ``cost`` — ``null`` when the call did
   not report them, never ``0`` in their place.

   ``cost`` is set on the native path, by the provider or from the price of
   the model that served; on the structured path only where the completion
   reported one. The usage record carries the priced cost in both cases.

   .. php:method:: answer(string $key): DecisionAnswer

      :throws: ``DecisionException`` (``NO_SUCH_ANSWER``) for a key the
         profile has no question for

.. php:class:: DecisionAnswer

   ``value``: the probability of yes (yes/no) or the level value (score,
   possibly between two levels); ``choice``: the chosen option (choice);
   ``probabilities`` and ``confidence``: exactly as the model reported them,
   empty or ``null`` where it reported none. Nothing is derived.

.. php:namespace:: Netresearch\NrLlm\Domain\ValueObject\Decision

.. php:enum:: ProbabilityKind

   What the probabilities of a result are worth.

   ``None``
      Hard labels only — a chat model asked through structured output.
   ``Distribution``
      The model's own distribution, not calibrated — the local decision
      sidecar.
   ``Calibrated``
      A distribution the provider calibrates — TypeSafe.

.. list-table:: What each model reports
   :header-rows: 1

   * - Model
     - yes/no
     - choice
     - score
     - ``probabilityKind``
   * - TypeSafe (``typesafe``)
     - probability of yes, no confidence
     - option, probability per option, confidence
     - weighted value, probability per level, confidence
     - ``Calibrated``
   * - Local sidecar (``decision_sidecar``)
     - probability of yes
     - option, probability per option
     - weighted value, probability per level
     - ``Distribution``
   * - Any chat model
     - ``1.0`` or ``0.0``
     - option
     - level index
     - ``None``

A TypeSafe confidence of ``0.95`` is a property of one answer's distribution,
not a measured accuracy of your decisions. Measure thresholds per profile,
model and language — ``nrllm:eval:run --grader decision`` runs a golden set
on whichever configuration ``decision.configuration`` names
(:ref:`developer-quality-evaluation-graders`).

.. _api-decision-service-failures:

Failures
========

.. list-table::
   :header-rows: 1

   * - ``DecisionException`` code
     - Meaning
   * - ``UNKNOWN_PROFILE``
     - No profile with that identifier is declared.
   * - ``INVALID_PROFILE``
     - A profile provider declares an invalid profile or an identifier twice.
   * - ``MISSING_SUBJECT_FIELD``
     - The subject lacks a field the profile requires.
   * - ``NO_CONFIGURATION``
     - Neither the request nor ``decision.configuration`` names one.
   * - ``UNKNOWN_CONFIGURATION``
     - No active configuration with that identifier exists.
   * - ``DATA_CLASS_NOT_PERMITTED``
     - The profile's data class may not reach a provider the call can reach.
   * - ``MODEL_CANNOT_DECIDE``
     - The configuration resolves no model that can answer, or its model
       declares ``decision`` on a provider that cannot make any.
   * - ``REJECTED``
     - The provider refused the request — any 4xx but 429: a wrong key, a
       subject or body over the limit, a question it cannot take. Asking
       again unchanged does not help.
   * - ``INVALID_ANSWER``
     - The model answered, but not with one valid answer per question — a
       chat model included whose reply missed the schema after the repair
       round-trip.
   * - ``FAILED``
     - Every other failure: an outage, a timeout, a rate limit, an exhausted
       fallback chain. The cause is the previous exception. An ``\Error`` — a
       defect in code — is not wrapped and propagates.
   * - ``NO_SUCH_ANSWER``
     - :php:`DecisionResult::answer()` was asked for a key the profile does
       not have.

Decision models
===============

TypeSafe
   Adapter type ``typesafe``: TypeSafe's System One API
   (``POST /v1/systemone``). Create a provider with the endpoint
   ``https://api.typesafe.ai/v1`` and the API key as an nr-vault identifier,
   then a model with the capability ``decision``. Model discovery offers the
   pinned version ``jev-1.13.0`` — recommended, because the aliases
   ``jev-latest`` and ``jev-preview`` move without notice — and prices it at
   the published 0.042 USD per million input tokens, output free. The subject
   leaves the installation: settle region, retention and contract for
   confidential content before enabling it, and set the provider's trust zone
   accordingly.

Local decision sidecar
   Adapter type ``decision_sidecar``: a zero-shot natural-language-inference
   model served on the host (:file:`Build/decision/`), by default the
   multilingual ``MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7``.
   It needs no key, carries no price and keeps the subject on the host — the
   model for tests, local development and a comparison with a hosted one. Its
   probabilities are the model's own distribution, and bare yes/no questions
   lean towards yes; state what yes means. As a local service it refuses a
   few requests the questions themselves allow — an empty subject, more than
   ``DECISION_MAX_QUESTIONS`` (32) questions, choice options that render to
   the same label — with a 422, which the service reports as ``REJECTED``.
   Like Ollama it is reached through a private hostname, so add that host
   (``decision`` when the service carries that name on the container
   network) to
   ``$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']``
   (:ref:`administration-providers-test`). It speaks plain HTTP; anywhere
   beyond a private container network, put a TLS-terminating reverse proxy in
   front of it.

Another native decision model
   Implement :php:`Netresearch\NrLlm\Provider\Contract\DecisionCapableInterface`
   — or extend :php:`Netresearch\NrLlm\Provider\AbstractDecisionProvider`,
   which refuses chat, completion and embeddings and reads answers strictly
   — register the adapter type, and declare ``decision`` on its model
   records.

The backend module's model and configuration tests send such a model one
yes/no probe instead of a chat prompt.

.. note::

   A configuration whose model makes decisions only cannot answer a chat
   call. Do not make it the default configuration: every chat call without a
   configuration would fail with ``UnsupportedFeatureException``.

Configuration
=============

Extension configuration key (``nr_llm``, category *decision*):

``decision.configuration``
   Identifier of the configuration a request without one is asked on. Empty
   by default: such a request fails with ``NO_CONFIGURATION``.

Testing
=======

:php:`Netresearch\NrLlm\Testing\FakeDecisionService` returns queued results
in order, records every request and throws a set throwable once — the way to
test a caller's handling of a failed decision. It checks nothing: not the
profile, not the subject's fields, not the answers. A test of that contract
belongs against the real service.
