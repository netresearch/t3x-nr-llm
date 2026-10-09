.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-215:

=================================================
ADR-215: Retrieval evaluation keeps its provenance
=================================================

:Status: Accepted
:Date: 2026-10-09
:Amends: :ref:`ADR-060 <adr-060>` (retrieval results retain their provenance
    and details in the existing result store); :ref:`ADR-072 <adr-072>`
    (rankings survive persistence and a regression needs a comparable
    benchmark)
:Authors: Netresearch DTT GmbH

.. _adr-215-context:

Context
=======

The retrieval evaluator already measures distinct-document top-1 and top-3
hits, question forms, hard classes and per-question latency. Registries let
consumer extensions contribute corpora and retrievers. None needs to be
replaced to compare lexical, dense, fused or reranked retrieval.

Two facts are lost after that measurement. First,
:php:`RetrievalSetEvaluationResult::toSetEvaluationResult()` converts a
question into a grading verdict and latency, dropping the returned document
ids, form and hard class. Second, the persistence key names the set,
retriever and grader, but not the corpus snapshot, labels, embedding model,
chunking or pipeline parameters. Two runs with the same identifiers can
therefore be different experiments and still look like one regression
series.

An unchanged corpus with a new model or chunking policy is a useful
comparison. Changed documents or relevance labels are a changed benchmark.
Putting all provenance into one equality check would prevent the very
model and pipeline comparisons the evaluator exists to make.

.. _adr-215-decision:

Decision
========

1. **Keep the existing registries and contracts.** Neither
   :php:`EvaluatableRetrieverInterface` nor
   :php:`GoldenQuestionSetProviderInterface` gains a required member.
   An additive, optional provenance capability lets a retriever declare its
   corpus revision, embedding-model revision, chunking identity, pipeline
   identity and execution revision. Implementations without it continue to
   run. It is an operator/consumer declaration, not proof that the corpus
   was immutable.

2. **Separate benchmark from treatment.** A versioned benchmark fingerprint
   covers the corpus revision, canonical relevance-label digest and
   scoring-protocol revision. A separate variant fingerprint covers model,
   chunking and pipeline identity. Execution revision is retained for
   reproduction but does not invalidate a baseline merely because code
   changed. A model-less lexical retriever explicitly declares the model
   and chunking fields not applicable; absent provenance is unknown.

3. **Derive the labels, do not trust a supplied label hash.** Hash the
   question id, question text, form, hard class and expected document ids
   under a versioned canonical encoding. Question order and target-id order
   do not change the digest. A relevant value change does. Human commentary
   such as the answer gist is not a scoring label and does not affect it.

4. **Preserve the measured ranking.** Retrieval details keep each question's
   distinct returned document ids, hit verdicts, form, hard class and
   latency. They describe what the current top-3 evaluator measured, not an
   unbounded raw chunk ranking. The existing top-k semantics, overfetching
   and no-result scoring stay unchanged.

5. **Reuse the result store and privacy policy.** Extend
   ``tx_nrllm_eval_result`` with bounded, content-free provenance and
   fingerprint metadata; keep retrieval details in its existing
   privacy-filtered details payload. Metadata-only mode does not persist
   document ids or question text there. Existing retention purges the
   entire row. Neither raw options JSON nor secrets nor document bodies
   belong in provenance. Existing prompt-evaluation writes remain valid.

6. **Compare only a known, compatible benchmark.** The retrieval command
   compares the previous run for the same set, retriever and grader only
   when both runs have known, equal benchmark fingerprints. A variant
   difference is reported as the treatment change, not rejected as a
   benchmark mismatch. A different or unknown benchmark is reported as
   ``mismatch`` or ``unknown`` and has no numeric regression verdict. A
   first known run is recorded as the baseline. A non-comparable prior run
   cannot pass ``--fail-on-regression``; without that flag the command
   records the measurement and reports why comparison was skipped.

7. **Old records remain old records.** New columns default to an absent
   measurement. Do not infer provenance from identifiers, upgrade legacy
   rows to a fabricated snapshot, or replace an unknown revision with zero.
   Existing PHP construction remains source-compatible through trailing
   optional values. The new capability and value object are additive
   public API and enter the API snapshot in the implementation change.

.. _adr-215-consequences:

Consequences
============

The same registry and command can explain a retrieval change without
introducing another evaluation subsystem. Existing consumer retrievers
need no code change to run, but must declare provenance before their
measurements can be trusted by a regression gate. The stricter gate is an
intentional CLI behavior change: an unknown comparison is no longer a
successful quality check.

Corpus revisions and model revisions must be supplied accurately by the
consumer. A fingerprint identifies the declared experiment; it cannot
establish that a remote model served fixed weights. A mutable alias is not
an immutable model revision. The implementation validates shape and
limits, and the documentation makes this distinction explicit.

Rankings are retained only when the existing privacy policy permits
details. The summary and benchmark identity remain available independently
of those content details. This decision adds no vector store, corpus
ingestion, model call, retrieval pipeline or relevance metric.

.. _adr-215-alternatives:

Alternatives considered
=======================

**One hash over every field.** Rejected: changing the model or chunker is
the experiment, not a reason to declare the benchmark incomparable.

**Required methods on the existing retriever interface.** Rejected: the
published interface is an extension point; adding abstract members would
break consumer implementations.

**Another results table.** Rejected: the existing result log already owns
retention, privacy and regression summaries. Its additive metadata and
details payload can carry the missing facts.

**Backfill corpus revisions from set names.** Rejected: names identify a
set, not the documents and labels present at an earlier run. This would
turn an absent measurement into invented evidence.

**Persist full options and corpus content.** Rejected: provenance needs
bounded identity declarations and hashes, not sensitive payloads.

Implementation acceptance
==========================

:file:`specs/013-retrieval-provenance/spec.md` specifies the contract,
compatibility, persistence, CLI states and the tests that prove each one.
