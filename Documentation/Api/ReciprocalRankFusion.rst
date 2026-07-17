.. include:: /Includes.rst.txt

.. _api-reciprocal-rank-fusion:

====================
ReciprocalRankFusion
====================

.. php:namespace:: Netresearch\NrLlm\Service\Retrieval

.. php:class:: ReciprocalRankFusion

   Reciprocal Rank Fusion (Cormack et al., 2009) for hybrid retrieval
   (:ref:`adr-074`). Fuses several ranked key lists using only per-list
   rank, never score magnitude — so it combines rankings on incomparable
   score scales (dense cosine similarity, sparse BM25) without any
   normalization.

   Final readonly class, newable: construct it with ``new``, it is not a
   DI service. nr_llm's own retrieval cascade (:ref:`adr-049`) does not
   call it — it exists for hybrid consumers that fan out to several
   retrieval arms themselves.

   .. php:method:: fuse(array $rankedKeyLists, int $k = 60, array $weights = []): array

      Fuse ranked key lists into one list ordered by descending RRF
      score. For each key the fused score is
      Σ\ :sub:`i` weight\ :sub:`i` / (k + rank\ :sub:`i`), where
      rank\ :sub:`i` is the key's 1-based position in list *i*; a key
      absent from a list contributes nothing there. Duplicates within a
      list are ignored past their first rank. Equal scores keep
      first-seen order (list 0 before list 1's new keys). A ``$k``
      below 1 is clamped to 1.

      :param array $rankedKeyLists: list<list<string>> — each inner list
         is keys best-first
      :param int $k: rank-smoothing constant; smaller values let top
         ranks dominate
      :param array $weights: list<float> — per-list weight (same index);
         missing or extra entries default to 1.0
      :returns: list<int|string> — fused keys, highest RRF score first.
         PHP array-key coercion applies: numeric-string keys (e.g.
         ``'42'``) come back as ``int``, so compare fused keys loosely or
         cast before a strict comparison.

Usage
=====

.. code-block:: php

   use Netresearch\NrLlm\Service\Retrieval\ReciprocalRankFusion;

   $denseKeys = ['page:12', 'page:7', 'page:3'];   // embedding arm, best-first
   $sparseKeys = ['page:7', 'page:9'];             // keyword arm, best-first

   $fused = (new ReciprocalRankFusion())->fuse(
       [$denseKeys, $sparseKeys],
       60,
       [1.0, 0.5],  // trust the dense arm twice as much
   );
   // ['page:7', ...] — ranked in both arms, so it wins
