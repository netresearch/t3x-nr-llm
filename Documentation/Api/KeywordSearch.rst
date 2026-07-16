.. include:: /Includes.rst.txt

.. _api-keyword-search:

=============
KeywordSearch
=============

.. php:namespace:: Netresearch\NrLlm\Service\Retrieval

.. php:interface:: KeywordSearchInterface

   Public keyword-search facade over the site-search retrieval cascade
   (:ref:`adr-071`). Searches the first available backend (Solr,
   ke_search, indexed_search, database fallback), always filtered
   public-only — hits are what the anonymous visitor could read.

   Input is clamped, never rejected, and any backend failure degrades
   to an empty result: the facade never throws.

   .. php:method:: search(string $query, int $limit, ?int $languageId = null): array

      Run a public-only keyword search. The query is trimmed and
      truncated to 200 characters; a query shorter than 2 characters
      returns an empty list. The limit is clamped to 1–20; a negative
      language id is clamped to 0. Hits are deduplicated by URL and
      capped at the limit.

      :param string $query: Free-text query
      :param int $limit: Maximum number of hits (clamped to 1–20)
      :param ?int $languageId: sys_language uid; null means default (0)
      :returns: list<KeywordHit> — empty when nothing matched, the
         query is too short, or no backend is available

   .. php:method:: isAvailable(): bool

      Whether at least one search backend of this variant can answer
      right now. Never throws.

.. php:class:: KeywordHit

   One keyword-search hit. Final readonly DTO.

   .. php:attr:: sourceId

      string — Stable source id; format is backend-internal.

   .. php:attr:: title

      string — Result title.

   .. php:attr:: url

      string — Public URL of the hit (may be empty when the backend
      cannot resolve one).

   .. php:attr:: excerpt

      string — Short indexed-content excerpt.

   .. php:attr:: languageId

      int — sys_language uid the hit belongs to.

   .. php:attr:: score

      ?float — Backend-native relevance score; not comparable across
      backends; null when the backend reports none.

   .. php:attr:: pageUid

      ?int — Page uid when the answering backend can resolve the hit
      to a page, null otherwise.

Service variants
================

Two container registrations exist (:ref:`adr-071`):

- :php:`KeywordSearchInterface` — the full cascade including the
  database LIKE fallback. Wire it via constructor type hint or resolve
  it from the container.
- ``nr_llm.keyword_search.index_backed`` — a named variant that
  excludes the fallback tier. Use it when "index unavailable" must
  yield an empty result instead of LIKE hits (e.g. hybrid dense+sparse
  fusion). Its :php:`isAvailable()` answers for index-backed engines
  only.

Usage
=====

.. code-block:: php

   use Netresearch\NrLlm\Service\Retrieval\KeywordSearchInterface;

   final class PageFinder
   {
       public function __construct(
           private readonly KeywordSearchInterface $keywordSearch,
       ) {}

       public function findCandidates(string $topic): array
       {
           if (!$this->keywordSearch->isAvailable()) {
               return [];
           }

           return $this->keywordSearch->search($topic, 10);
       }
   }

Wiring the index-backed-only variant:

.. code-block:: yaml

   Vendor\Ext\Search\SparseArm:
     arguments:
       $keywordSearch: '@nr_llm.keyword_search.index_backed'
