.. include:: /Includes.rst.txt

.. _adr-072:

==============================================================================
ADR-072: Retrieval-quality evaluation — golden questions and top-k hit rates
==============================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-072-context:

Context
=======

:ref:`ADR-060 <adr-060>` gave nr_llm a quality-evaluation layer for
**generated answers**: golden prompt sets, graders, result persistence, and
regression detection. It measures the model, not the retrieval in front of
it — yet in a RAG pipeline the retrieval step decides which evidence the
model ever sees, and retrieval changes (an embedding swap, a reranker, a
chunking retune, a new lexical backend) can silently degrade what gets found
with nothing to catch it.

A working methodology for exactly this already exists downstream: the
nr_ai_search extension built a 48-question labeled retrieval-eval set over
its BMDV corpus. Its method is corpus-agnostic and proven:

* **Question forms.** ``MATCH`` questions share vocabulary with the target
  document; ``GAP`` questions are everyday rewordings with little vocabulary
  overlap — the class retrieval quality problems live in and the primary
  split for reporting.
* **Hard classes.** Questions can be tagged with a known-difficult retrieval
  class (``near-duplicate``, ``specific-vs-general``, ``prose-free``,
  ``boilerplate``, ``normal``) for a secondary per-class breakdown.
* **Multi-target labels.** A question lists ALL document ids that answer it;
  any of them counts as a hit.
* **Top-k document-level hit rate.** The primary metric is the top-1 and
  top-3 hit rate — a hit means any of the k best-ranked distinct documents
  is a target — reported overall, split by form, and broken down by hard
  class.

The methodology (schema and scoring protocol) belongs in nr_llm so every
consumer can measure a retrieval change; the questions themselves do not —
labels only mean something against a concrete corpus, so the BMDV set stays
in nr_ai_search.

The building blocks again already existed: the DI-tag provider/registry
pattern and the persistence + regression machinery from ADR-060.

.. _adr-072-decision:

Decision
========

**Add the retrieval counterpart of ADR-060 — golden question sets, a
pluggable retriever contract, document-level top-1/top-3 hit-rate scoring,
and a CLI — reusing the ADR-060 persistence and regression machinery
unchanged.** nr_llm ships the methodology, not the questions.

1. **Golden question sets via DI tag.** ``GoldenQuestion`` carries the
   question text, its ``QuestionForm`` (``MATCH``/``GAP``), the expected
   document ids (multi-target), an optional free-form hard class, and an
   optional answer gist (label documentation, never scored). A question
   with an EMPTY expected-document list declares that no indexed document
   answers it; it scores as a hit only when the retriever correctly returns
   nothing. ``GoldenQuestionSetProviderInterface`` (tag
   ``nr_llm.golden_question_set``) and ``GoldenQuestionSetRegistry`` mirror
   the ADR-060 provider/registry pair. No built-in set ships — unlike golden
   prompts, golden questions are meaningless without the consumer's corpus.

2. **Pluggable retrievers.** ``EvaluatableRetrieverInterface`` (tag
   ``nr_llm.evaluatable_retriever``) is deliberately minimal — a question
   string and a limit in, ranked document ids out — so ANY retrieval
   pipeline can be measured: nr_llm's own lexical cascade, a consumer's
   vector retrieval, a reranked variant. The adapter owns the mapping from
   its native results to document ids, which must use the same identity
   scheme as the golden set's labels (e.g. chunk-id prefixes for chunked
   vector stores). nr_llm ships one adapter, ``LexicalSearchRetriever``
   (identifier ``nr_llm.lexical``), over the ADR-049 retrieval cascade —
   both a runnable target and the pattern to copy.

3. **Hit-rate scoring.** ``RetrievalEvaluationService`` asks the retriever
   for an overfetched raw ranking per question
   (``TOP_K * OVERFETCH_MULTIPLIER`` results), collapses duplicate ids to
   the first occurrence (so top-3 always means the three best DISTINCT
   documents, even when a retriever hands back one id per chunk — the
   overfetch keeps a chunk-grained ranking from collapsing to fewer than
   three documents), and scores top-1/top-3 hits over the three best
   distinct documents. ``RetrievalSetEvaluationResult`` aggregates the
   rates overall, by form, and by hard class. Like ``EvaluationService`` it
   neither persists nor compares.

4. **Persistence and regression via ADR-060, by mapping.**
   ``RetrievalSetEvaluationResult::toSetEvaluationResult()`` maps a run onto
   the existing result model: the retriever identifier takes the model
   column, top-1 hit becomes the per-question pass, top-3 hit becomes the
   per-question score — so the stored ``passRate`` is the top-1 hit rate and
   the stored ``meanScore`` is the top-3 hit rate. The grader column is
   fixed to ``retrieval_hit_rate``, which scopes the (set, model, grader)
   key so retrieval rates are never compared against prompt-grading scores.
   ``EvaluationResultRepositoryInterface``, ``tx_nrllm_eval_result``,
   ``RegressionDetector`` and ``RegressionThresholds`` are reused without
   change.

5. **CLI, not request path.** ``nrllm:eval:retrieval <set> <retriever>``
   runs a set against a retriever, prints per-question hits, the aggregate
   hit rates and both breakdowns, saves the run, and reports the regression
   verdict (``--max-top1-drop`` / ``--max-top3-drop`` map onto the ADR-060
   thresholds; ``--fail-on-regression`` makes a regression a non-zero exit
   for CI). No LLM is involved — a run costs one retrieval call per
   question.

6. **No public-surface growth.** Everything is private; the command is made
   public by the ``console.command`` tag as in ADR-060, and the two provider
   interfaces are discovered via their DI tags. The audited ADR-028 count is
   unchanged.

.. _adr-072-consequences:

Consequences
============

Positive
--------

* Retrieval quality becomes measurable with the same accept/reject
  discipline ADR-060 gave answer quality: a consumer labels a question set
  once, then every embedding swap, reranker trial or chunking retune is a
  before/after hit-rate comparison with regression detection in CI.
* One retriever contract lets the same golden set measure competing
  pipelines (lexical vs. vector vs. reranked) side by side — the stored
  (set, retriever) history keeps their baselines separate.
* The persistence and regression machinery is reused, not duplicated.

Negative / limitations
-----------------------

* The metric mapping overloads the stored columns' names: for retrieval runs
  ``passRate`` means top-1 hit rate and ``meanScore`` means top-3 hit rate.
  The dedicated ``retrieval_hit_rate`` grader value makes the reinterpretation
  explicit and keeps the histories separate.
* The retrieval depth is fixed at top-3 (the methodology's deepest metric);
  configurable k is a follow-up if a consumer needs top-5/top-10.
* Latency is recorded per question but not part of regression detection.
* The by-form and by-hard-class breakdowns are reported by the CLI but not
  persisted individually — only the aggregate rates are stored, so
  regressions inside one class that cancel out across classes are not
  auto-detected.

.. _adr-072-alternatives:

Alternatives considered
=======================

**Shipping golden questions with nr_llm.** Rejected: relevance labels are
statements about one concrete corpus. The BMDV set stays in nr_ai_search;
nr_llm ships the schema, the scoring protocol, and test fixtures only.

**A separate retrieval-result table and regression detector.** Rejected:
the ADR-060 summary (two 0.0–1.0 rates per run, keyed by set/model/grader)
fits retrieval runs exactly; a parallel subsystem would duplicate
persistence, retention (ADR-064) and regression logic for no expressiveness
gain.

**Scoring at chunk level.** Rejected: the methodology deliberately scores at
document level (a document is identified by its chunk-id prefix) because
"did the right document surface" is the question a retrieval change must
answer; chunk-level ranks are an implementation detail of the store.

**Reranking-aware metrics (MRR, nDCG).** Rejected for this slice: top-1/
top-3 hit rates are what the established acceptance criteria use, are
robust with multi-target labels, and stay interpretable for small sets.
Graded-relevance metrics need graded labels the methodology does not
collect.
