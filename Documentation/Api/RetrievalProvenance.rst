.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-retrieval-provenance:

====================
Retrieval provenance
====================

The optional provenance contract identifies a retrieval experiment
(:ref:`adr-215`). The existing :php:`EvaluatableRetrieverInterface` and
:php:`GoldenQuestionSetProviderInterface` keep their signatures and DI tags.
A registered retriever may also implement
:php:`RetrievalProvenanceProviderInterface`; no second registration is needed.

.. php:namespace:: Netresearch\NrLlm\Service\Evaluation

.. php:interface:: RetrievalProvenanceProviderInterface

   .. php:method:: getRetrievalProvenance(): RetrievalProvenance

      Return a stable declaration for the run. The evaluator takes it once,
      before retrieval. Do not perform remote requests to obtain it. Absence
      of this capability means unknown provenance, including for old rows.

.. php:class:: RetrievalProvenance

   Final readonly value object with public properties ``corpusRevision``,
   ``modelRevision``, ``chunkingIdentity``, ``pipelineIdentity`` and nullable
   ``executionRevision``. The constructor takes them in that order; the
   execution revision defaults to ``null``. ``toArray()`` returns those
   property names and scalar values.

   Each non-null value is an opaque ASCII label of 1 to
   ``MAX_IDENTITY_BYTES`` (190) bytes. It starts with a letter or digit;
   further characters are letters, digits, ``.``, ``_``, ``:``, ``+``,
   ``/`` or ``-``. URL schemes, whitespace and control characters are
   refused. Invalid labels throw :php:`\InvalidArgumentException`.

   Use revision labels or digests. Never supply document text, raw options,
   endpoint URLs or secrets: the syntax check is no credential detector.
   ``NOT_APPLICABLE`` (``not-applicable``) explicitly identifies an absent
   model or chunker for lexical retrieval. It cannot replace a corpus or
   pipeline revision. Unknown revisions cannot be presented as this sentinel.

Usage
=====

Add the optional interface to the consumer's existing registered retriever:

.. code-block:: php
   :caption: Stable lexical experiment declaration

    public function getRetrievalProvenance(): RetrievalProvenance
    {
        return new RetrievalProvenance(
            corpusRevision: 'export-sha256-abcdef',
            modelRevision: RetrievalProvenance::NOT_APPLICABLE,
            chunkingIdentity: RetrievalProvenance::NOT_APPLICABLE,
            pipelineIdentity: 'lexical-policy-v2',
            executionRevision: 'commit-abcdef',
        );
    }

The labels state what the consumer ran. They do not prove that a corpus
was frozen or that a remote model alias kept the same weights. Change the
corpus label whenever document content, membership or effective access
scope changes. Include all treatment settings that can alter ranking in
the pipeline identity, such as fusion weights, candidate depth and reranking.

Comparison contract
===================

The benchmark fingerprint binds corpus revision, the canonical golden
question labels and the versioned distinct-document top-1/top-3/no-result
scoring protocol. The label digest covers ids, question text, form, hard
class and sorted target ids; question order, presentation text and answer
gist do not change it.

The ``labels-bytes-v1`` encoding sorts the original byte strings and
Base64-encodes each free-form string before canonical JSON hashing. This
preserves existing golden questions with legacy character encodings and
distinguishes different byte sequences without lossy UTF-8 substitution.

The variant fingerprint binds model, chunking and pipeline identities.
Changing a variant remains comparable on an equal benchmark. The execution
revision records the code used and changes neither fingerprint. The stored
metadata also retains the derived labels fingerprint and scoring version,
without asking the consumer to provide them.

See :ref:`developer-quality-evaluation-retrieval` for command behavior,
privacy, retention and the transition from legacy baselines.
