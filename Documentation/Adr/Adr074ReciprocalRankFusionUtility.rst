.. include:: /Includes.rst.txt

.. _adr-074:

====================================================
ADR-074: Reciprocal Rank Fusion as a hosted utility
====================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-074-context:

Context
=======

``nr_ai_search`` fuses its dense (embedding) and sparse (keyword) retrieval
arms with Reciprocal Rank Fusion (Cormack et al., 2009): rank-only fusion
combines rankings whose scores live on incomparable scales — dense cosine
similarity versus BM25 — without any score normalization. Its implementation
is 55 lines, pure and dependency-free, and the sparse arm it fuses is
nr_llm's own keyword-search facade (ADR-071).

The 2026-07 downstream-extraction analysis flagged the class as genuinely
portable but deferred hosting it here: ADR-049 decided the retrieval
cascade is first-available-wins with no cross-backend merging, so RRF would
have been public API with zero callers inside nr_llm. The revisit trigger
was a second consumer of rank fusion, or a fan-out-and-merge retrieval
mode superseding ADR-049.

Every hybrid consumer that pairs its own dense arm with the ADR-071 sparse
facade needs this same fusion step, and each copy re-derives the identical
math. The maintainer pulled the second-consumer trigger forward: host the
utility now so hybrid consumers share one implementation instead of each
carrying a private copy.

.. _adr-074-decision:

Decision
========

Host the class as ``Netresearch\NrLlm\Service\Retrieval\ReciprocalRankFusion``
with the signature identical to the ``nr_ai_search`` original —
``fuse(array $rankedKeyLists, int $k = 60, array $weights = []): array`` —
so a consumer migrates by swapping the namespace import, nothing else.

- **Pure final class, no interface.** The math has exactly one correct
  implementation; an interface would add an abstraction with nothing to
  substitute.
- **Newable, not a DI service.** The class is stateless with a no-argument
  constructor; consumers instantiate it with ``new``. It is excluded from
  container autoconfiguration in ``Configuration/Services.yaml`` and adds
  no ``public: true`` override, so the audited public-service count
  (ADR-028 / ADR-065 / ADR-071) is unchanged.
- **ADR-049 is unchanged.** nr_llm's own retrieval cascade remains
  first-available-wins: ``RetrievalService`` does not fan out across
  backends and does not merge their results. ``ReciprocalRankFusion`` is a
  consumer-facing utility; no nr_llm code path calls it.

.. _adr-074-consequences:

Consequences
============

- ``nr_ai_search`` (and any later hybrid consumer) deletes its own copy and
  imports ``Netresearch\NrLlm\Service\Retrieval\ReciprocalRankFusion``;
  behaviour is bit-identical.
- The class is supported public API surface: changing ``fuse()``'s
  signature or tie-breaking semantics is a breaking change for consumers
  and belongs in a release note.
- Should ADR-049's cascade ever gain a fan-out-and-merge mode, the fusion
  math is already in place; adopting it there would be a new ADR
  superseding ADR-049, not a change to this one.
