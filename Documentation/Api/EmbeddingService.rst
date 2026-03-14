.. include:: /Includes.rst.txt

.. _api-embedding-service:

================
EmbeddingService
================

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: EmbeddingService

   Text-to-vector conversion with caching and similarity operations.

   .. php:method:: embed(string $text): array

      Generate embedding vector for text (cached).

      :param string $text: The text to embed
      :returns: array<float> Vector representation

   .. php:method:: embedFull(string $text): EmbeddingResponse

      Generate embedding with full response metadata.

      :param string $text: The text to embed
      :returns: EmbeddingResponse

   .. php:method:: embedBatch(array $texts): array

      Generate embeddings for multiple texts.

      :param array $texts: Array of texts
      :returns: array<array<float>> Array of vectors

   .. php:method:: cosineSimilarity(array $a, array $b): float

      Calculate cosine similarity between two vectors.

      :param array $a: First vector
      :param array $b: Second vector
      :returns: float Similarity score (-1 to 1)

   .. php:method:: findMostSimilar(array $queryVector, array $candidates, int $topK = 5): array

      Find most similar vectors from candidates.

      :param array $queryVector: The query vector
      :param array $candidates: Array of candidate vectors
      :param int $topK: Number of results to return
      :returns: array Sorted by similarity (highest first)

   .. php:method:: pairwiseSimilarities(array $vectors): array

      Calculate pairwise similarities between all vectors.

      Returns a 2D matrix where each cell ``[i][j]`` contains the cosine
      similarity between vectors ``i`` and ``j``. Diagonal values are always 1.0.

      :param array $vectors: Array of embedding vectors
      :returns: array 2D array of similarity scores

   .. php:method:: normalize(array $vector): array

      Normalize a vector to unit length.

      :param array $vector: The vector to normalize
      :returns: array Normalized vector
