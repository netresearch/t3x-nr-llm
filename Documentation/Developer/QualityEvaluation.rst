.. include:: /Includes.rst.txt

.. _developer-quality-evaluation:

==================
Quality evaluation
==================

nr_llm can measure the quality of the answers a model produces against
**golden prompt sets** and detect regressions between runs. Evaluation is an
explicitly triggered, out-of-request operation — it never runs in the request
pipeline and, with the default grader, spends no tokens. See
:ref:`ADR-060 <adr-060>` for the design rationale.

A golden set is a collection of prompts, each with the expectations it should
satisfy. A run executes the set against a model, grades every response,
aggregates the results to a pass rate and mean score, stores the run, and
compares it against the previous run for the same set and model.

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
* **llm_judge** (opt-in) — asks a judge model through :php:`CompletionService`
  to score the response ``0.0``–``1.0`` with a justification. It uses the
  reference answer when one is declared. Because it spends tokens, it runs only
  when explicitly selected; an unknown grader name falls back to the
  deterministic grader.

.. _developer-quality-evaluation-running:

Running an evaluation
=====================

Use the ``nrllm:eval:run`` command:

.. code-block:: bash

    # Deterministic grading against the configured default model
    vendor/bin/typo3 nrllm:eval:run nr_ai_search.faq

    # Evaluate a specific model with the LLM judge
    vendor/bin/typo3 nrllm:eval:run nr_ai_search.faq --model gpt-5.2 --grader llm_judge

    # Fail (non-zero exit) if quality regressed against the previous run — for CI
    vendor/bin/typo3 nrllm:eval:run nr_ai_search.faq --fail-on-regression

The command prints the per-prompt gradings and the aggregate (pass rate, mean
score), stores the run in ``tx_nrllm_eval_result``, and reports whether the run
regressed against the previous run for the same set and model. The regression
tolerance is configurable with ``--max-pass-rate-drop`` and
``--max-mean-score-drop`` (both default to ``0.1``).

nr_llm ships an example set, ``nr_llm.smoke``, so the command is runnable out
of the box.

.. _developer-quality-evaluation-quality-routing:

Quality-aware routing (opt-in)
==============================

Stored evaluation results feed an **opt-in** routing hook. The existing
cost/latency selection modes of :php:`ModelSelectionService` are unchanged;
nothing routes by quality unless you call the hook explicitly:

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
without data) are excluded. Making quality a first-class sort key inside
:php:`ModelSelectionService` is a planned follow-up (see :ref:`ADR-060 <adr-060>`).

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
and reports whether the run regressed against the previous one for the
same set and retriever.
