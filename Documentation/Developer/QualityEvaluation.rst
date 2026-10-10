.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _developer-quality-evaluation:

==================
Quality evaluation
==================

nr_llm can measure the quality of the answers a model produces against
**golden prompt sets** and detect regressions between runs. Evaluation is an
explicitly triggered, out-of-request operation — it never runs in the request
pipeline. Generation calls the configured completion service once per prompt
and follows that provider's billing. The default deterministic grader makes
no additional model call and spends no grading tokens. See
:ref:`ADR-060 <adr-060>` for the design rationale.

A golden set is a collection of prompts, each with the expectations it should
satisfy. A run executes the set against a model, grades every response,
aggregates the results to a pass rate and mean score, stores the run, and
compares it against the previous verified run with the same set, serving
provider instance, outbound model alias, reported model and grading yardstick.

.. _developer-quality-evaluation-declaring:

Declaring a golden set in your extension
========================================

Implement :php:`GoldenPromptSetProviderInterface`. The
``nr_llm.golden_prompt_set`` DI tag is applied automatically when your
extension's :file:`Services.yaml` has ``autoconfigure: true`` (the TYPO3
default):

.. code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\NrAiSearch\Evaluation;

    use Netresearch\NrLlm\Service\Evaluation\Assertion;
    use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
    use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
    use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetProviderInterface;

    final class AiSearchGoldenSetProvider implements GoldenPromptSetProviderInterface
    {
        public function getGoldenPromptSets(): array
        {
            return [
                new GoldenPromptSet(
                    identifier: 'nr_ai_search.faq',
                    name: 'AI Search FAQ answers',
                    description: 'Checks the model answers common FAQ prompts correctly.',
                    prompts: [
                        new GoldenPrompt(
                            id: 'opening-hours',
                            prompt: 'When is the office open? Answer with the days.',
                            assertions: [
                                Assertion::contains('Monday'),
                                Assertion::regex('/9(:00)?\s*(am|–|-)/i'),
                            ],
                            reference: 'Monday to Friday, 9am to 5pm.',
                        ),
                        new GoldenPrompt(
                            id: 'contact-json',
                            prompt: 'Return the contact as JSON with an "email" field.',
                            assertions: [
                                Assertion::jsonSchema('{"type":"object","required":["email"],"properties":{"email":{"type":"string"}}}'),
                            ],
                        ),
                    ],
                ),
            ];
        }
    }

The identifier is namespaced (``vendor_extension.set``) so sets from different
extensions cannot collide. Each prompt needs at least one assertion or a
reference answer.

.. _developer-quality-evaluation-assertions:

Assertion types
===============

The deterministic grader supports four assertion types; a prompt passes only
when **all** of its assertions hold, and the score is the fraction satisfied.

.. list-table::
   :header-rows: 1

   * - Type
     - Factory
     - Passes when
   * - Exact
     - :php:`Assertion::exact($value)`
     - the trimmed response equals ``$value``
   * - Contains
     - :php:`Assertion::contains($value)`
     - ``$value`` is a substring of the response
   * - Regex
     - :php:`Assertion::regex($pattern)`
     - the response matches the PCRE ``$pattern``
   * - JSON schema
     - :php:`Assertion::jsonSchema($schemaJson)`
     - the response is valid JSON satisfying the structural schema

The ``json_schema`` matcher is a lightweight structural check — a top-level
``type``, object ``required`` keys, and recursive ``properties`` types. Extra
keys are allowed. It is intentionally not a full JSON Schema draft validator.

.. _developer-quality-evaluation-graders:

Graders
=======

Two grading strategies sit behind :php:`GraderInterface`:

* **deterministic** (default) — evaluates the assertions with no LLM call and
  no tokens.
* **decision** (opt-in) — asks the decision service
  (:ref:`api-decision-service`) the built-in profile
  ``nr_llm.task_fulfilment``: how well the response fulfils its task, on five
  levels, with the reference answer as evidence when one is declared. The
  level is scaled to ``0.0``–``1.0`` and passes from ``0.6``. It runs on the
  configuration the extension setting ``decision.configuration`` names —
  a decision model such as TypeSafe or the local sidecar, or a chat model
  asked through structured output — so the same golden set can be graded by
  several models and the results compared. Because it spends tokens, it runs
  only when explicitly selected.

``nrllm:eval:run`` refuses a grader name it does not know — ``llm_judge``
included, which the decision grader replaces (:ref:`ADR-211 <adr-211>`).
:php:`EvaluationService::run()` called directly falls back to the
deterministic grader instead, so it never spends tokens by accident.

A run is stored under the grader its gradings report. The decision grader
reports its yardstick — ``decision:<provider>:<model>:v<profile version>``,
for example ``decision:typesafe:jev-1.13.0:v1`` — so a TypeSafe run and a
chat-model run of the same set, or runs on two model versions, are separate
series and never each other's regression baseline. A run in which some
decisions failed, or in which the grading model changed, has no single
yardstick: it
is stored as plain ``decision`` and never compared. A run in which every
decision failed is ``decision:failed``. Neither can pass a gate: with
``--fail-on-regression`` both exit non-zero, without it they are reported as
not compared. The grader judges the response against the task *and*
the system prompt the call ran with — the prompt's own or the run's base
one — so a response that ignored "answer in French" does not pass.

.. _developer-quality-evaluation-running:

Running an evaluation
=====================

Use the ``nrllm:eval:run`` command:

.. code-block:: bash

    # Deterministic grading against the configured default model
    vendor/bin/typo3 nrllm:eval:run nr_ai_search.faq

    # Evaluate a specific model with the decision grader
    vendor/bin/typo3 nrllm:eval:run nr_ai_search.faq --model gpt-5.2 --grader decision

    # Fail (non-zero exit) if quality regressed against the previous run — for CI
    vendor/bin/typo3 nrllm:eval:run nr_ai_search.faq --fail-on-regression

The command prints the per-prompt gradings and the aggregate (pass rate, mean
score), stores the run in ``tx_nrllm_eval_result``, and reports whether the run
regressed against the previous verified run of the same generator and grading
yardstick. The regression
tolerance is configurable with ``--max-pass-rate-drop`` and
``--max-mean-score-drop`` (both default to ``0.1``).

nr_llm ships an example set, ``nr_llm.smoke``, so the command is runnable out
of the box.

.. _developer-quality-evaluation-generator-identity:

Serving generator identity
==========================

A grading protocol and the model that generated an answer are separate
identities (:ref:`ADR-220 <adr-220>`). Responses from different models can
share deterministic grading. Their combined score does not measure either
model against the complete set.

After a successful configuration-driven chat or completion terminal, reserved
response metadata ``nr_llm_serving_generator`` records version one, the
configured DB provider-instance identifier, the model alias actually sent to
the adapter and the response's reported model. A fallback records its own
resolved instance; a per-call override records the alias actually sent.
``CompletionResponse.provider`` remains the adapter key, such as ``openai``.
It does not identify one configured endpoint. A requested model option or an
adapter-only call without a resolved DB instance supplies no serving proof.

Every prompt must report the same verified provider instance, alias and
reported model for its aggregate to become a generator-specific score.
Mixed, missing or invalid evidence keeps all grades but leaves the run's model
unknown. The command stores those measurements and prints an explicit
skipped-comparison warning. ``--fail-on-regression`` fails in that state.
Grader failures retain their separate yardstick checks.

The ``generator_provenance`` result column stores the content-free proof
separately from details. The default metadata privacy level drops details but
retains that proof; full privacy also records each prompt's serving identity
and adapter key. Existing retention removes the whole result row.
Idempotency cache replay and output redaction preserve the original serving
record, including when today's configuration points at another model.

Run the database schema update after upgrading, then re-evaluate the golden
sets used for routing. Legacy aggregates remain readable through ordinary
history reads. Missing, malformed, unsupported-version and row-inconsistent
proof cannot contribute to core routing or generator baselines, and no
identity is assigned retroactively. A first verified run creates its own
baseline. Baselines additionally scope the reported model: a newer different
snapshot cannot hide an older eligible baseline.

Existing repository and quality-provider interfaces keep their members.
The optional ``GeneratorEvaluationResultRepositoryInterface`` adds verified
provider/model score reads and a baseline read scoped to set, provider,
alias, reported model and grader. The optional
``ProviderModelQualityScoreProviderInterface`` adds a provider/model quality
read. Core implementations expose both capabilities on the same objects.
An old custom quality provider keeps its explicit model-ID contract; an old
custom repository without serving-proof reads supplies no quality to the
core evaluation adapter and no generator-specific comparison to the command.

.. _developer-quality-evaluation-quality-routing:

Quality-aware routing (opt-in)
==============================

Verified evaluation results already feed measured ranking in
:php:`ModelSelectionService` (:ref:`ADR-142 <adr-142>`). The extension setting
``routing.policyMode`` defaults to ``providerPriority``; ``balanced``,
``quality`` and ``economy`` opt into measured quality and health. Provider
priority remains the first ordering rule. Quality is scoped to the configured
provider instance and outbound model alias, using the latest eligible run per
set with the deterministic grader. Unknown quality supplies no ranking signal;
a measured zero remains zero. The legacy unscoped score is unknown when an
alias has verified results from more than one provider instance.

A consumer can additionally use :php:`QualityAwareModelSelector` to rank by
quality alone and impose its ``minQuality`` filter:

.. code-block:: php

    use Netresearch\NrLlm\Service\Evaluation\QualityAwareModelSelector;

    // Inject QualityAwareModelSelector, then:
    $model = $selector->selectByQuality(
        ['capabilities' => ['chat']],
        minQuality: 0.7,
    );

``selectByQuality()`` takes the candidates :php:`ModelSelectionService` would
return for the criteria and re-ranks them by measured quality score (latest run
per set, averaged). Candidates without evaluation data keep their base order
behind the scored ones; with ``minQuality`` set, candidates below it (or
without data) are excluded. This filter belongs to the explicit selector;
core measured ranking does not impose a minimum quality constraint.

.. _developer-quality-evaluation-retrieval:

Retrieval evaluation
====================

The retrieval counterpart measures the retrieval step of a RAG pipeline —
which documents surface for a question — with **golden question sets** and
document-level top-1/top-3 hit rates. See :ref:`ADR-072 <adr-072>` for the
design and the methodology it adopts.

A golden question carries the question text, its form (``MATCH`` = the
vocabulary overlaps the target document, ``GAP`` = an everyday rewording —
the class retrieval problems live in), ALL document ids that answer it
(any of them counts as a hit), an optional hard class for a per-class
breakdown, and an optional answer gist documenting the label. A question
with an empty expected-document list declares that nothing in the index
answers it and scores as a hit only when the retriever returns nothing.
nr_llm ships no golden questions — labels only mean something against a
concrete corpus, so every set lives in the extension owning the content.

Declare a set by implementing :php:`GoldenQuestionSetProviderInterface`
(tag ``nr_llm.golden_question_set``, applied automatically):

.. code-block:: php

    use Netresearch\NrLlm\Domain\Enum\QuestionForm;
    use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
    use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
    use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetProviderInterface;

    final class BmdvGoldenQuestionSetProvider implements GoldenQuestionSetProviderInterface
    {
        public function getGoldenQuestionSets(): array
        {
            return [
                new GoldenQuestionSet(
                    identifier: 'nr_ai_search.bmdv',
                    name: 'BMDV retrieval eval',
                    description: 'Labeled questions over the BMDV corpus.',
                    questions: [
                        new GoldenQuestion(
                            id: 'dialogforum-termin',
                            question: 'Wann findet das Dialogforum statt?',
                            form: QuestionForm::MATCH,
                            expectedDocumentIds: ['234_0', '309_0'],
                            hardClass: 'near-duplicate',
                        ),
                    ],
                ),
            ];
        }
    }

The retriever under test implements :php:`EvaluatableRetrieverInterface`
(tag ``nr_llm.evaluatable_retriever``): a question string and a limit in,
ranked document ids out. The adapter owns the mapping from its native
results to document ids, which must use the same identity scheme as the
set's labels. nr_llm ships :php:`LexicalSearchRetriever`
(``nr_llm.lexical``) over its own search cascade as the pattern to copy;
a consumer wraps its vector retrieval the same way.

Run with the ``nrllm:eval:retrieval`` command:

.. code-block:: bash

    # Measure the built-in lexical cascade against a labeled set
    vendor/bin/typo3 nrllm:eval:retrieval nr_ai_search.bmdv nr_llm.lexical

    # Fail (non-zero exit) if hit rates regressed — for CI
    vendor/bin/typo3 nrllm:eval:retrieval nr_ai_search.bmdv nr_ai_search.vector \
        --fail-on-regression --max-top1-drop 0.05 --max-top3-drop 0.05

The command prints the per-question hits, the top-1/top-3 hit rates with
by-form and by-hard-class breakdowns, stores the run in
``tx_nrllm_eval_result`` (grader ``retrieval_hit_rate``; the stored pass
rate is the top-1 hit rate and the stored mean score the top-3 hit rate),
and compares the run with the previous one for the same set and retriever
when the benchmark provenance is known and equal.


Benchmark provenance and legacy baselines
----------------------------------------

A registered retriever can declare the optional
:php:`RetrievalProvenanceProviderInterface` capability; its bounded value
object is documented in :ref:`api-retrieval-provenance`. Existing retrievers
continue to run without changes. Their provenance is ``unknown``. The
built-in lexical cascade likewise declares no frozen corpus: wrap it in a
consumer adapter when a benchmark uses an identified export or snapshot.

The command prints the declared corpus, model, chunking, pipeline and
execution revisions, plus one baseline state:

* ``baseline``: a known first run establishes a baseline.
* ``comparable``: both runs identify the same corpus, labels and scoring
  protocol. Existing top-1/top-3 thresholds decide the regression result.
* ``mismatch``: a corpus or scoring-label change prevents a numeric
  comparison. The new measurement is still recorded.
* ``unknown``: current or previous benchmark provenance is missing or
  invalid. The new measurement is still recorded.

Without ``--fail-on-regression``, a mismatch or unknown state succeeds with
an explicit skipped-comparison warning. With that flag, either state fails,
including an unknown first run. No numeric delta or no-regression verdict
is produced in those states. Legacy rows retain unknown provenance; no
revision is assigned retroactively. Run a declared benchmark once to record
its identity, then run it again for a comparable strict check.

A changed model, chunker or pipeline on the same benchmark is reported as a
treatment change and remains numerically comparable. Changing only the
execution revision does not change the benchmark or variant identity.
These are consumer declarations, not verification of an immutable corpus
or pinned remote weights. Mock-based tests prove comparison behavior; they
do not establish retrieval quality on a real corpus.

The same ``tx_nrllm_eval_result`` row stores the benchmark and variant
fingerprints. Its provenance metadata contains the declarations, derived
labels fingerprint and scoring-protocol version, alongside the permitted
detail snapshot. Details retain the measured distinct
top-three document ids, question form, hard class, hit verdicts and latency;
they do not preserve the full raw candidate ranking. The existing privacy
policy filters details: metadata-only drops them while retaining safe
revision labels and fingerprints. Existing retention removes the whole row.

At full privacy, legacy byte strings in the detail fields use the versioned
representation documented in :ref:`api-retrieval-provenance`. Ordinary
UTF-8 records keep their JSON format. The original byte value is filtered
before encoding, and the complete payload still passes the existing
privacy filter. Redacted mode does not encode values around its scrubber.
