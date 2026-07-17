.. include:: /Includes.rst.txt

.. _adr-075:

==================================================
ADR-075: Neutral cross-encoder reranker protocol
==================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-075-context:

Context
=======

``nr_ai_search`` measured (NRFE-3960, its ADR-029) that a cross-encoder
reranker over the bi-encoder candidate pool lifts top-1 retrieval
accuracy where the bi-encoder alone and a naive LLM reranker do not —
on the real BMDV corpus from 6/9 to 8/9. It shipped the capability as a
consumer-private client (``RerankerClientInterface`` over its own
``RetrievedDocument`` DTO) plus an HTTP sidecar in its
``Build/reranker`` serving the one heavy dependency
(sentence-transformers + torch) outside PHP.

Its ADR-029 kept that placement deliberately consumer-side because no
second consumer existed. The revisit trigger has fired: cross-encoder
scoring is wanted for nr_llm-side ranking too (upgrading embed-and-rank
from bi-encoder to cross-encoder quality, e.g. link-target ranking),
which makes it a shared capability — exactly the category
:ref:`ADR-050 <adr-050>` assigns to nr_llm, as long as it stays
stateless.

.. _adr-075-decision:

Decision
========

**1. Neutral protocol in nr_llm.**
``Netresearch\NrLlm\Service\Rerank\RerankerInterface``::

    rerank(string $query, array $candidates): array
    // $candidates: list<array{id: string, text: string}>
    // return:      list<array{id: string, score: float}>

No consumer DTO crosses the boundary. Implementations:

- ``HttpReranker`` — speaks the sidecar contract
  (``POST {endpoint}/rerank {"query", "documents": [{"id", "text"}]}``
  → ``{"scores": [{"id", "score"}]}``, input order). Pools above the
  sidecar's batch cap (``RERANKER_MAX_DOCUMENTS``, default 128) are
  split into sequential requests; per-pair scoring makes the split
  score-neutral. A transport failure, non-200 status or off-protocol
  body throws the typed ``RerankerException``
  (``Service\Rerank\Exception``) — nr_llm never decides degradation.
- ``NullReranker`` — one entry per candidate in input order with a
  uniform score of ``0.0``. The uniform value carries no ranking
  signal (a stable sort preserves the caller's ordering) and keeps the
  result shape-identical to ``HttpReranker`` so consumer merge code
  needs no null branch.

**2. Selection rule.** ``RerankerFactory`` builds the
``RerankerInterface`` container service from the extension
configuration: an empty ``rerankerEndpoint`` selects ``NullReranker``,
a configured endpoint selects ``HttpReranker`` with the configurable
``rerankerTimeout`` (default 30 s — a CPU cross-encoder can be slow for
a wide pool). Unreadable configuration fails open to ``NullReranker``.
This mirrors the selection nr_ai_search's ``RerankerClientFactory``
made consumer-side.

**3. Sidecar moves alongside the client.** ``Build/reranker``
(``app.py``, ``Dockerfile``, ``requirements.txt``, ``README.md``) now
lives in nr_llm so client and server version together. The HTTP
contract is unchanged.

**4. What stays consumer-side.** DTO mapping (id/text extraction,
score attachment), the ordering merge (including how an unscored
candidate ranks), the degradation policy on ``RerankerException``
(e.g. fall back to the pre-rerank cosine ordering), and any
score-threshold gate (the score scale is model-specific).

**Boundary with ADR-050.** This supersedes the nr_llm-side reading of
:ref:`ADR-050 <adr-050>` / nr_ai_search ADR-029 that cross-encoder
reranking lives only in nr_ai_search. ADR-050's guardrail is untouched:
the reranker is a stateless capability (candidates in, scores out) — no
persistent index, no chunking, no reindex pipeline. nr_ai_search amends
its ADR-028/ADR-029 in its own adoption change.

**Public-service count: 29 → 30** (after ADR-076's 28 → 29). The ``RerankerInterface``
factory-built entry joins Category A (documented downstream contract).
This ADR is the new count authority, superseding ADR-076's count. The
breakdown is now 17 + 5 + 1 + 5 + 2 = **30**
(``PublicServicesPolicyTest``).

.. _adr-075-consequences:

Consequences
============

- A consumer depends on ``RerankerInterface`` and drops its own client,
  factory and sidecar copy; only its DTO mapping, merge, degradation
  and gate code remain.
- Two new extension-configuration keys: ``rerankerEndpoint``,
  ``rerankerTimeout``. Both default to off/30 s — nothing changes until
  an operator opts in.
- The sidecar container is an optional runtime dependency of nr_llm
  deployments that enable reranking; nr_llm itself never requires it.
- ``RerankerException`` implements ``NrLlmExceptionInterface``
  (ADR-053), so blanket consumer catch blocks keep working.
