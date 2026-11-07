.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=================
Embedding recipes
=================

.. _feature-services-embedding:

EmbeddingService
================

**Purpose**: Text-to-vector conversion and similarity search.

.. _feature-services-embedding-use-cases:

Use cases
---------

- Semantic translation memory (textdb).
- Content similarity.
- Duplicate detection.
- Semantic search.

.. _feature-services-embedding-features:

Key features
------------

- Aggressive caching (deterministic).
- Batch processing.
- Cosine similarity calculations.
- Top-K similarity search.

.. _feature-services-embedding-example:

Example
-------

.. code-block:: php
   :caption: Example: Using EmbeddingService

   use Netresearch\NrLlm\Service\Feature\EmbeddingService;

   // Generate embedding
   $vector = $embeddingService->embed('Search query text');

   // Find similar
   $similar = $embeddingService->findMostSimilar(
       queryVector: $vector,
       candidateVectors: $allVectors,
       topK: 5
   );

.. _feature-services-embedding-methods:

Methods
-------

.. code-block:: php
   :caption: EmbeddingService methods

   // Generate embedding (cached automatically)
   $vector = $embeddingService->embed('Some text');

   // Full response with metadata
   $response = $embeddingService->embedFull('Some text');

   // Batch embedding
   $vectors = $embeddingService->embedBatch(['Text 1', 'Text 2']);

   // Calculate cosine similarity
   $similarity = $embeddingService->cosineSimilarity($vectorA, $vectorB);

   // Find most similar vectors
   $results = $embeddingService->findMostSimilar(
       $queryVector,
       $candidateVectors,
       topK: 5
   );

   // Normalize a vector
   $normalized = $embeddingService->normalize($vector);
