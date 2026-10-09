.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-218:

======================================================================
ADR-218: Document source synchronisation belongs to the search companion
======================================================================

:Status: Accepted
:Date: 2026-10-09
:Authors: Netresearch DTT GmbH

Context
=======

F13 separates source synchronisation, ingestion, embeddings and retrieval.
The useful boundary already exists here: :ref:`ADR-050 <adr-050>` assigns
persistent indexing and its lifecycle to ``nr_ai_search``. That companion
already consumes nr-llm embeddings and owns chunking, vector storage,
Messenger ingestion and hybrid retrieval. Duplicating that pipeline in
nr-llm would contradict the accepted dependency direction.

The remaining concrete addition is a repeatable source synchronisation
operation: a public local document directory with source revisions,
publication, removal and an explicit access boundary. An interface without
a working connector and lifecycle is insufficient.

Decision
========

1. Implement the directory source in the existing ``netresearch/nr-ai-search``
   package. No new package, nr-llm dependency, table or persistent index is
   introduced here. Companion ADR-036 records its implementation decision.
2. The connector accepts administrator-configured local roots and UTF-8
   text/Markdown documents only. Each file has an explicit adjacent access
   marker. Only ``public`` is admitted; absent, malformed or protected
   markers withdraw a previously published document and never send its
   content to an embedding provider. URL fetching, PDF extraction and
   private or tenant-aware indexing are outside this first connector.
3. The companion owns a persisted source manifest, revision fingerprints
   and publication state. It reuses its existing chunking, embedding and
   vector indexing implementation. An unchanged fingerprint performs no
   embedding work. A changed fingerprint stages a new generation; the
   previous generation remains readable until successful publication.
   Revision identity includes the effective embedding provider,
   configuration and model plus versioned chunking parameters; a model name
   alone cannot distinguish providers or changed chunk boundaries.
4. Complete successful source scans may identify removed documents.
   Unreadable roots, interrupted traversals and failed source inventories
   never infer removal from absence. An explicit access withdrawal can
   revoke an individual document before the scan finishes.
5. Revocation and deletion make the document unavailable before vector
   cleanup. Retrieval checks the currently published generation, so a
   failed cleanup cannot expose an old revision or a revoked document.
   The guard runs before reranking egress and also validates directory
   citations in cached answers before cached prose is returned.
6. Queue messages refer to configured sources, not user-supplied paths.
   Synchronisation uses a source-level lock and revalidates bytes and access
   markers before provider egress. Symlinks and paths escaping a root are
   rejected. No source credentials, document bodies or filesystem roots
   appear in public status output.
7. A local deterministic embedding fixture proves scan, publish, retrieval,
   unchanged revision, update, failed scan, deletion and access withdrawal
   without a remote provider. Existing nr-llm provider middleware remains
   responsible for attribution, budgets and provider boundary enforcement.

Consequences
============

The dependency stays ``nr_ai_search -> nr_llm``. Installing nr-llm alone
keeps its existing lexical site-search behaviour and PHP support range.
The optional companion continues to require PHP 8.3 or later. Public source
documents are explicitly approved for provider egress; this connector does
not promise confidential-document or tenant isolation.

The companion's publication gate is scoped to its directory-generation
documents. Existing index adapters retain their identifiers and lifecycle.
Failures can leave staged or obsolete vectors for later cleanup, but cannot
publish an incomplete generation. The source manifest records success only
after the index operation succeeds. Database transactions cannot make a
vector store transactional, so failure and retry tests exercise that gap.

Specification: ``specs/016-companion-document-source-sync/spec.md``.
