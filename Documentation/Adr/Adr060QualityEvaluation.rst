.. include:: /Includes.rst.txt

.. _adr-060:

===========================================================================
ADR-060: Quality evaluation — golden sets, grading and regression detection
===========================================================================

:Status: Accepted
:Date: 2026-07-14
:Authors: Netresearch DTT GmbH

.. _adr-060-context:

Context
=======

nr_llm had no way to measure the quality of the answers it produces. There
were connectivity probes (``TestPromptResolverService``) and a property-based
fuzzing suite, but no golden prompts, no graders, and no regression harness —
so a model or configuration change could silently degrade answer quality with
nothing to catch it. The reference extension ``aim`` runs LLM-as-a-judge
grading and routes by grade; nr_llm had neither the measurement nor the
feedback loop.

Two constraints shaped the design. First, evaluation must never run in the
request path: it is an operator activity that spends time and, for the judge,
tokens. Second, it must not enlarge the audited public service surface
(:ref:`ADR-028 <adr-028>`) without cause.

The building blocks already existed: DI-tag discovery with a tagged-iterator
registry (``nr_llm.configuration_preset`` — :ref:`ADR-056 <adr-056>`), the
``CompletionService`` for model calls, and ``ModelSelectionService`` for
criteria-based routing.

.. _adr-060-decision:

Decision
========

**Add an opt-in evaluation layer — declarative golden sets, pluggable graders,
a run/aggregate service, result persistence with regression detection, and a
CLI — none of which touches the request pipeline.**

1. **Golden sets via DI tag.** A consumer implements
   ``GoldenPromptSetProviderInterface`` (tag ``nr_llm.golden_prompt_set``,
   auto-applied by ``AutoconfigureTag``, mirroring
   :ref:`ADR-056 <adr-056>`) and returns ``GoldenPromptSet`` value objects.
   ``GoldenPromptSetRegistry`` collects them through a tagged iterator and
   fails fast on duplicate identifiers. Each ``GoldenPrompt`` carries
   deterministic ``Assertion`` s (exact / contains / regex / json_schema)
   and an optional reference answer. nr_llm ships one example set
   (``nr_llm.smoke``) as the pattern to copy and a runnable target.

2. **Graders behind an interface.** ``GraderInterface`` has two
   implementations. ``DeterministicGrader`` (the default) evaluates the
   assertions with no LLM call and no tokens; its json_schema matcher is a
   lightweight structural check (required keys + per-key type), deliberately
   not a full JSON Schema draft validator, to avoid a runtime dependency.
   ``LlmJudgeGrader`` is opt-in: it asks a judge model through the existing
   ``CompletionService`` for a ``{"score", "reason"}`` verdict and handles a
   malformed or failed judge response defensively. ``GradingService`` selects
   the grader by identifier and falls back to the deterministic grader for an
   unknown one, so evaluation never silently spends tokens.

3. **Run and aggregate.** ``EvaluationService`` runs a set against a model —
   one ``CompletionService`` call per prompt — grades each response, records
   the wall-clock latency, and aggregates to a pass rate and mean score. It
   neither persists nor compares, which keeps it unit-testable without a
   database.

4. **Persistence + regression detection.** Runs are stored in the new
   ``tx_nrllm_eval_result`` table (a UI-less result log, no TCA, mirroring
   ``tx_nrllm_service_usage``) as aggregate summaries plus a JSON snapshot of
   the per-prompt outcomes. ``RegressionDetector`` compares a run against the
   previous run for the same (set, model) and flags a regression when the
   pass rate or mean score falls beyond a configurable ``RegressionThresholds``
   tolerance (default 0.1 absolute).

5. **Quality dimension as a routing hook.** The quality signal is exposed as
   an opt-in hook, not a change to ``ModelSelectionService``.
   ``EvaluationQualityScoreProvider`` derives a per-model quality score from
   stored results (latest run per set, averaged), and
   ``QualityAwareModelSelector`` re-ranks that service's existing candidate
   list by score. ``ModelSelectionService`` is unchanged, so the cost/latency
   selection modes behave exactly as before; nothing routes through the hook
   unless a consumer opts in. See *Consequences* for why the deeper
   integration is deferred.

6. **CLI, not request path.** ``nrllm:eval:run`` runs a set, prints the
   per-prompt gradings and the aggregate, saves the run, and reports the
   regression verdict (``--fail-on-regression`` makes a regression a non-zero
   exit for CI). Registration is via the ``console.command`` tag — made public
   by TYPO3's ``ConsoleCommandPass``, so it adds no ``public: true`` override
   to the audited surface (:ref:`ADR-028 <adr-028>`).

7. **No public-surface growth.** The persistence class is instantiated
   directly in its functional test (its only dependency is ``ConnectionPool``)
   and autowired as an interface into the command elsewhere, so nothing in the
   subsystem needs to be a public service.

.. _adr-060-consequences:

Consequences
============

Positive
--------

* Answer quality becomes measurable and regressions become detectable, on an
  operator's schedule, without any per-request cost.
* Consumer extensions declare their own golden sets through the same tested
  discovery mechanism they already use for presets and tools.
* The deterministic default spends no tokens; the judge is a conscious opt-in.
* The public service surface is unchanged.

Negative / limitations
-----------------------

* The LLM judge spends tokens and uses whatever default chat configuration the
  admin has set up; selecting a dedicated judge model is a follow-up.
* The json_schema grader is a structural matcher, not a full JSON Schema draft
  validator.
* Quality routing is a hook only: ``QualityAwareModelSelector`` re-ranks
  candidates on demand, but quality is not yet a first-class sort key inside
  ``ModelSelectionService``. Making it one — alongside provider priority and
  cost — is a deliberate follow-up, because pulling the eval subsystem into the
  core selection path would invert the layering (selection must not depend on
  the opt-in evaluation store) and change existing selection behaviour.
* ``tx_nrllm_eval_result`` grows by one row per run; a retention/pruning policy
  is a documented follow-up.

.. _adr-060-alternatives:

Alternatives considered
=======================

**Golden sets as YAML files under Configuration/.** Rejected for the same
reasons as :ref:`ADR-056 <adr-056>`: the DI tag reuses the established
discovery mechanism, needs zero per-extension configuration, and gives
compile-time class references instead of stringly-typed files.

**A full JSON Schema draft validator for structured assertions.** Rejected:
it would add a runtime dependency for a capability the lightweight structural
matcher already covers for practical golden-set assertions.

**Wiring quality directly into ModelSelectionService now.** Rejected for this
slice: it inverts the layering and risks changing the behaviour of the
existing selection modes. Delivered instead as an additive, opt-in hook with
the full integration marked as a follow-up.

**Storing results as file snapshots instead of a table.** Rejected: a table
fits TYPO3, is queryable for the per-model quality aggregate the routing hook
needs, and matches the precedent of ``tx_nrllm_service_usage``.
