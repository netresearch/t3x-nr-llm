.. include:: /Includes.rst.txt

.. _api-reranker:

========
Reranker
========

.. php:namespace:: Netresearch\NrLlm\Service\Rerank

.. php:interface:: RerankerInterface

   Neutral cross-encoder reranking protocol (:ref:`adr-075`): scores
   retrieval candidates against a query. Candidates go in as plain
   ``id``/``text`` shapes and scores come back as plain ``id``/``score``
   shapes — no consumer DTOs cross the boundary. Consumers own DTO
   mapping, the ordering merge, the degradation policy on failure, and
   any score-threshold gate.

   The container service is factory-built from the extension
   configuration: an empty ``rerankerEndpoint`` resolves to
   ``NullReranker``, a configured endpoint to ``HttpReranker``.

   .. php:method:: rerank(string $query, array $candidates): array

      Score each (query, candidate text) pair. Returns one entry per
      scored candidate in input order; an entry the backend failed to
      score may be omitted — merge by ``id``.

      :param string $query: The query the candidates are scored against
      :param array $candidates: ``list<array{id: string, text: string}>``
      :returns: ``list<array{id: string, score: float}>``
      :throws: ``RerankerException`` when the reranker backend is
         unreachable or answers outside the protocol

.. php:class:: HttpReranker

   Speaks the cross-encoder sidecar contract (``Build/reranker``):
   ``POST {endpoint}/rerank`` with ``{"query", "documents"}``, scores
   returned in input order. Pools above the sidecar's batch cap
   (128 documents) are split into sequential requests. The score scale
   is model-specific (default ``BAAI/bge-reranker-v2-m3``).

.. php:class:: NullReranker

   Selected when no sidecar endpoint is configured. Returns one entry
   per candidate in input order with a uniform score of ``0.0`` — no
   ranking signal, shape-identical to ``HttpReranker``.

.. php:namespace:: Netresearch\NrLlm\Service\Rerank\Exception

.. php:class:: RerankerException

   Typed failure of the reranker backend: unreachable endpoint
   (code ``1784750001``), non-200 status (``1784750002``), invalid JSON
   (``1784750003``), or a response missing the ``scores`` array
   (``1784750004``). Implements ``NrLlmExceptionInterface``
   (:ref:`ADR-053 <adr-053>`); the caller decides how to degrade —
   nr_llm never silently falls back.

Configuration
=============

Extension configuration keys (``nr_llm``):

- ``rerankerEndpoint`` — base URL of the cross-encoder sidecar, e.g.
  ``http://reranker:8081``. Empty (default) disables reranking.
- ``rerankerTimeout`` — request timeout in seconds (default 30; a CPU
  cross-encoder can be slow for a wide candidate pool).

See ``Build/reranker/README.md`` for running the sidecar.
