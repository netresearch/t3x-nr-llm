.. include:: /Includes.rst.txt

.. _adr-049:

=============================================================
ADR-049: RAG site-search tools over installed search indexes
=============================================================

:Status: Accepted
:Date: 2026-07-09
:Authors: Netresearch DTT GmbH

.. _adr-049-context:

Context
=======

Agent runs should be able to answer questions about the *website's own
content* with cited evidence instead of model world-knowledge. The
retrieval source already exists in most installations: a TYPO3 search
index — EXT:solr, ke_search or the core's indexed_search. What is missing
is a controlled retrieval layer that (a) uses whichever index is
installed, (b) degrades gracefully when none is, and (c) hands the model
a curated evidence package with resolvable sources rather than raw
search hits.

A generic per-engine tool list (``solr_search``, ``ke_search_query``, …)
was rejected: the model would need to know what is installed, every
engine would leak its own result shape into prompts, and the tool count
would grow per engine. Embedding/vector retrieval is deliberately **out
of scope** for this iteration — keyword retrieval over the existing
index is measured first; a vector store would add tables, chunking and
reindex pipelines whose benefit is unproven for the target sites.

.. _adr-049-decision:

Decision
========

**One retrieval core, many backends.** A new ``Service/Retrieval``
layer defines ``SearchBackendInterface`` (``isAvailable()``,
``getPriority()``, ``search(RetrievalQuery, AccessContext)``) with four
implementations, collected via the ``nr_llm.retrieval_backend`` tag:

#. ``SolrSearchBackend`` — talks to the Solr server EXT:solr
   provisioned over the HTTP select API instead of EXT:solr's
   ``@internal`` PHP classes (for TYPO3 14 only a beta of EXT:solr
   exists): endpoints come from the documented site-configuration
   ``solr_*_read`` keys with per-language overrides, every query
   carries the ``{!typo3access}0,-1`` public filter, and no composer
   dependency on EXT:solr exists.
#. ``KeSearchBackend`` — reads ``tx_kesearch_index`` directly:
   ``MATCH … AGAINST`` on MySQL/MariaDB, ``LIKE`` elsewhere. Matches
   ``title``/``content`` only — never ``hidden_content``, which
   ke_search itself never renders.
#. ``IndexedSearchBackend`` — reads the ``index_*`` tables directly
   (word-hash join with the md5 computed in PHP; ``LIKE`` over
   ``index_fulltext`` when ``useMysqlFulltext`` left the word tables
   empty).
#. ``DatabaseSearchBackend`` — always-available fallback: ``LIKE``
   across ``pages``/``tt_content`` search fields, grouped per page.

``RetrievalService`` asks the backends in priority order and uses the
**first available** one — no cross-engine score merging, because
Solr relevance, MySQL fulltext scores and LIKE hits are not comparable;
re-ranking is a future embedding concern. The answering backend is named
in the result so the model knows the evidence quality.

**Two tools, one new group** ``rag``: ``site_rag_query`` (question →
evidence package: ``source_id · title · url`` plus a match excerpt per
source) and ``site_fetch_source`` (``source_id`` → the indexed full
text, capped). Tool arguments are model-chosen and untrusted: length
caps, source-id grammar validation and result caps apply.

**Access model, fail-closed.** Index-level filtering is always
*public-only* (``fe_group`` ``''``/``0``, ``gr_list`` ``0,-1``, Solr
access filter ``{!typo3access}0,-1``): RAG evidence is what the
anonymous website visitor could read. Because that content is by
definition readable by every backend user, no per-user page narrowing
applies (unlike ``search_records``, which exposes non-public backend
records); the tools stay fail-closed without a backend user like every
builtin. ``AccessContext`` (backend user / frontend groups / public)
travels through the retrieval core so a later frontend endpoint can
widen filtering per fe_group without touching the backends' call
sites — it is *not* consumed beyond public-only in this iteration.

**Web search stays an interface.** ``WebSearchBackendInterface``
(site-limited external search) is defined but has no implementation;
no network egress ships with this decision.

.. _adr-049-consequences:

Consequences
============

- Installations get grounded site answers with whatever index they
  already run; a bare instance still works through the database
  fallback, visibly labelled as such in the evidence header.
- Direct table access to ``tx_kesearch_index`` and ``index_*`` trades
  API stability for decoupling: both schemas are verified against the
  currently supported versions (ke_search v6.6/v7, core 13.4/14.x —
  identical), but future majors can drift; ``isAvailable()`` checks
  table presence, and functional tests pin the expected schema.
- The Solr adapter depends on the documented site-configuration keys
  and on the ``typo3access`` query parser from EXT:solr's configsets,
  not on EXT:solr PHP internals; any HTTP or configuration failure is
  treated as "backend unavailable" and the cascade continues.
- Stale indexes cite stale content (ke_search incremental runs never
  delete; indexed_search updates on render) — a known property of
  search-index RAG, documented for editors.
- A future vector/hybrid retriever or web-search implementation slots
  in as another backend behind the same interface and cascade.
