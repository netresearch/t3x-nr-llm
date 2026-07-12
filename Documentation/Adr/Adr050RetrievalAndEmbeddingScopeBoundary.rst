.. include:: /Includes.rst.txt

.. _adr-050:

=======================================================================
ADR-050: Retrieval and embedding scope — the boundary with nr_ai_search
=======================================================================

:Status: Accepted
:Date: 2026-07-11
:Authors: Netresearch DTT GmbH

.. _adr-050-context:

Context
=======

:ref:`ADR-049 <adr-049>` shipped lexical site-search tools
(``site_rag_query`` / ``site_fetch_source``, the ``rag`` group) that read
whichever TYPO3 search index is installed (EXT:solr, ke_search,
indexed_search) with an always-available ``pages``/``tt_content``
database fallback. A sibling extension, ``netresearch/nr_ai_search``,
owns *vector* RAG for TYPO3: content chunking, a persistent vector
store, an indexing pipeline, a retrieval-augmented query flow with an
anti-hallucination gate, and frontend chat/search — and it already
depends on ``nr_llm`` for embeddings and chat.

Two questions follow, and answering them keeps both extensions from
growing into each other:

1. Does retrieval belong in ``nr_llm`` at all, or is it out of scope for
   an "LLM provider" extension?
2. Where is the line — which retrieval work is ``nr_llm``'s and which is
   ``nr_ai_search``'s?

``nr_llm`` is not an LLM SDK; it is the **LLM-to-TYPO3 integration
layer**. Its forty-plus built-in tools marry the model to TYPO3 core
APIs (TCA, TypoScript, FAL, pages/content, logs). Grounding a model in
the site's own content sits squarely in that mission — it is the same
category as ``search_records`` and ``get_page_content``, not a foreign
concern. Waiting for search-extension maintainers to contribute their
own ``nr_llm.tool`` implementations is not a viable adoption path; the
capability has to work out of the box.

.. _adr-050-decision:

Decision
========

**Retrieval grounding over TYPO3 content is in scope for nr_llm as a
primitive**, on the same footing as the content and introspection tools.
The boundary is drawn by one operational rule:

    nr_llm may **read** from indexes that others own and maintain —
    TYPO3 search extensions, or a transient embed-and-compare — but it
    must **never own a persistent index it has to keep synchronised with
    content.**

**In scope for nr_llm:**

- ``site_rag_query`` / ``site_fetch_source`` and the ``rag`` group
  (ADR-049).
- The lexical search-backend adapters (Solr, ke_search, indexed_search):
  they *read* indexes those extensions own and keep fresh; nr_llm owns
  none of them.
- The always-available ``pages``/``tt_content`` database fallback: it
  queries live tables, there is no index.
- ``EmbeddingService::embed()`` as a **stateless capability** (string in,
  vector out) and ad-hoc embed-and-rank over a caller-supplied candidate
  set (for example: rank twenty link-target pages by similarity to a
  paragraph). No persistent index is involved.

**Out of scope for nr_llm — this is nr_ai_search:**

- A persistent vector store, chunking strategy, a reindex-on-change
  pipeline, chunk-level access control, dimension and compaction
  management. There is no "small" vector store; it grows into exactly the
  pipeline nr_ai_search already maintains.

**Stopping rule for future tools.** The line is **capability / primitive
versus vertical domain**, not core-versus-extension. nr_llm ships tools
for TYPO3 core APIs and for retrieval as a cross-cutting primitive; it
does **not** accrete domain tools for vertical extensions (news,
commerce, and so on). The adoption argument — "cannot wait for
maintainers" — justifies shipping the *capability* out of the box, which
the ``nr_llm.retrieval_backend`` tag (ADR-049) already enables; it does
not justify hard-coding every search engine into the core forever. A
niche engine's adapter registers through that tag without a core
release.

**Sibling and third-party tools are contributed into nr_llm's runtime,
not built here.** When nr_ai_search (or any extension) wants to expose a
semantic-retrieval or other tool, it registers it via the ``nr_llm.tool``
tag under its own group (recommended value: the providing extension's
key, per :ref:`ADR-043 <adr-043>`). nr_llm owns the tool *runtime*; each
extension owns its *tools*.

.. _adr-050-open:

Decision (2026-07): no nr_llm-owned persistent index
====================================================

The previously deferred question — whether **site-wide semantic**
retrieval for backend/API consumers (semantic auto-linking, "related
content" suggestions for editors, thematic matches for other extensions)
is primitive enough to justify a *minimal* nr_llm-owned vector store — is
now decided: **it is not.** nr_llm never owns a persistent vector store.
Such retrieval always routes through ``nr_ai_search``, which owns the
persistent semantic index and the pipeline that keeps it correct.
nr_llm's contribution stays **stateless**: ``EmbeddingService`` embeds a
string on demand, and a small or dynamic candidate set may be ranked in
memory (embed-and-rank), but nothing is persisted or kept synchronised
with content. A consumer that needs site-wide semantic answering installs
``nr_ai_search`` on top of nr_llm. ``nr_ai_search`` records the same
decision from its side in ADR-028.

.. _adr-050-consequences:

Consequences
============

- ``site_rag_query`` stays in nr_llm on principle, not as a temporary
  convenience: it is retrieval-as-primitive for every backend and API
  consumer (editorial content-finding, admin debugging, extensions that
  need to locate matching content), none of which should have to pull in
  a vector-store product.
- nr_llm carries a hard guardrail: no chunking, no persistent vector
  index, no reindex pipeline. The moment a feature needs one, it is
  nr_ai_search.
- The two extensions compose cleanly: nr_llm provides embeddings as a
  capability and the tool runtime; nr_ai_search consumes both and owns
  the persistent vector RAG product. Neither duplicates the other.
