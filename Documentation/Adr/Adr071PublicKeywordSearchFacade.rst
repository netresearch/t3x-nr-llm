.. include:: /Includes.rst.txt

.. _adr-071:

==================================================================
ADR-071: Public keyword-search facade over the retrieval cascade
==================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-071-context:

Context
=======

ADR-049 built the site-search retrieval cascade
(:php:`Service\Retrieval\RetrievalService` over the tagged
``nr_llm.retrieval_backend`` implementations: Solr, ke_search,
indexed_search, database LIKE fallback). Every class in that namespace
is private — the only public faces of the capability are the LLM tools
``site_rag_query`` / ``site_fetch_source``. Yet ADR-050 explicitly
promises keyword content-finding to downstream extensions ("a consumer
that only needs keyword content-finding uses nr_llm alone"), and
ADR-028 / ADR-065 require cross-extension consumption to go through the
documented, audited public surface.

That gap has a demonstrated cost: nr_ai_search's hybrid retrieval binds
its sparse arm to the **private concrete** service
:php:`Service\Retrieval\SolrSearchBackend` via an ``@``-reference in
its own :file:`Services.yaml` and imports four non-contract classes
(:php:`RetrievalQuery` including its bounds constants,
:php:`AccessContext`, :php:`SearchBackendInterface`,
:php:`EvidenceSource`). A private service id carries no semver signal:
any rename or restructuring inside nr_llm breaks the consumer's
container compile silently, across the consumer's whole version
constraint.

.. _adr-071-decision:

Decision
========

Close the gap with a deliberately **narrow** public facade instead of
exposing the retrieval internals.

The contract
------------

:php:`Netresearch\NrLlm\Service\Retrieval\KeywordSearchInterface` with
exactly two methods:

.. code-block:: php

   public function search(string $query, int $limit, ?int $languageId = null): array; // list<KeywordHit>
   public function isAvailable(): bool;

:php:`KeywordHit` is a final readonly DTO carrying ``sourceId``,
``title``, ``url``, ``excerpt``, ``languageId`` (int), ``score``
(?float, backend-native, not comparable across backends) and
``pageUid`` (?int) — the fields of the internal :php:`EvidenceSource`
minus the backend label, so cascade internals can evolve without
touching the contract.

Semantics
---------

- **Clamp, never throw.** The query is trimmed and truncated to
  :php:`RetrievalQuery::MAX_QUERY_LENGTH`; a query shorter than
  :php:`RetrievalQuery::MIN_QUERY_LENGTH` returns an empty list; the
  limit is clamped to 1..\ :php:`RetrievalQuery::MAX_SOURCES`; a
  negative language id is clamped to 0. Out-of-range input is a normal
  call, not an exception.
- **Public-only.** The facade always searches with
  :php:`AccessContext::publicOnly()` — hits are what the anonymous
  visitor could read.
- **Degrade to empty.** Any backend failure yields an empty list; the
  facade never throws. Cascade order, first-available-wins, URL
  deduplication and the result cap are ADR-049 semantics, reused
  unchanged (the implementation composes :php:`RetrievalService`).

The pinning question: index-backed-only mode
--------------------------------------------

A hybrid dense+sparse consumer (nr_ai_search's RRF fusion) pins an
index-backed engine and must treat "index unavailable" as an **empty
sparse arm** — fusing hits from the priority-0 database LIKE fallback
would silently mix engines of incomparable relevance into the fusion.
The facade therefore ships in two container registrations of the same
implementation class:

- The :php:`KeywordSearchInterface` **alias** resolves the full cascade
  including the database fallback — the right default for "find the
  page about X" consumers.
- The **named service** ``nr_llm.keyword_search.index_backed`` resolves
  a variant constructed with ``$indexBackedOnly: true`` that excludes
  the priority-0 tier, per the :php:`SearchBackendInterface::getPriority()`
  contract ("the always-available database fallback uses 0,
  index-backed engines use higher values"). Its :php:`isAvailable()`
  answers for index-backed engines only.

A named service variant was chosen over a second interface method so
the contract stays at two methods and each variant gives a coherent
:php:`isAvailable()` answer for its own mode. Consumers wire it as a
named argument, e.g.:

.. code-block:: yaml

   Vendor\Ext\Search\SparseArm:
     arguments:
       $keywordSearch: '@nr_llm.keyword_search.index_backed'

Both registrations are ``public: true`` and part of the audited,
semver-guarded surface.

.. _adr-071-consequences:

Consequences
============

- **Public-service count: 26 → 28.** The interface alias and the named
  index-backed variant join ADR-065's Category A (documented downstream
  contract). This ADR is the new count authority, superseding ADR-069's
  count exactly as ADR-069 superseded ADR-065's. The breakdown is now
  16 + 5 + 1 + 4 + 2 = **28**. The
  :php:`Tests\Unit\Configuration\PublicServicesPolicyTest`
  ``EXPECTED_PUBLIC_TRUE_COUNT`` constant and this ADR are the audit
  trail; ADR-065's Category A list is amended in place.
- **The facade constrains Retrieval refactors.** The clamping,
  public-only and degrade-to-empty semantics plus the
  ``getPriority() > 0`` index-backed distinction are now contract;
  cascade internals (backends, :php:`RetrievalService`,
  :php:`EvidenceSource`) remain private and free to change as long as
  the facade is preserved.
- **Consumers drop private references.** nr_ai_search can replace its
  ``@Netresearch\NrLlm\Service\Retrieval\SolrSearchBackend`` binding
  and its :php:`RetrievalQuery` / :php:`AccessContext` /
  :php:`EvidenceSource` imports with the interface (or the named
  variant) and :php:`KeywordHit`, guarded by a normal version
  constraint bump.
- **Documentation.** The facade is documented in
  :file:`Documentation/Api/KeywordSearch.rst`.

.. _adr-071-alternatives:

Alternatives considered
=======================

- **Publish the internals** (:php:`SearchBackendInterface`,
  :php:`RetrievalService`, :php:`RetrievalQuery`, ...). Rejected: far
  wider surface, freezes the cascade's internal shape pre-1.0, and
  repeats the coupling this ADR removes one level down.
- **A second interface method** (``searchIndexBacked()``). Rejected:
  the mode would double every future method and leave
  :php:`isAvailable()` ambiguous about which mode it answers for.
- **Constructor flag without a named registration.** Rejected: a
  consumer could only reach the index-backed variant by redefining the
  service in its own container configuration — reintroducing exactly
  the unversioned wiring knowledge this facade exists to remove.
