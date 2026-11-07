.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-005:

============================================
ADR-005: TYPO3 Caching Framework Integration
============================================

:Status: Accepted (amended; current contract below)
:Date: 2024-03
:Amended: 2026-11-07 by :ref:`ADR-249 <adr-249>`
:Authors: Netresearch DTT GmbH

.. _adr-005-current-contract:

.. note::

    Current contract (reviewed 2026-11-07): Plain configuration answers can be cached through an opt-in TTL, subject to
    privacy and path restrictions. Embedding callers can opt out. See
    :ref:`configuration-caching`; temperature is not the answer-cache switch.

    The decision text below records its original context; the linked successor
    explains the changed contract.




.. _adr-005-context:

Context
=======
LLM API calls are:

- Expensive (cost per token).
- Relatively slow (network latency).
- Often deterministic (embeddings, some completions).

.. _adr-005-decision:

Decision
========
Integrate with **TYPO3's caching framework**:

- Cache identifier: ``nrllm_responses``.
- **No backend specified** — TYPO3 uses the instance's
  default cache backend (respects Redis/Valkey/Memcached).
- Cache keys based on: provider + model + input hash.
- TTL: 3600s default (configurable).
- Cache group: ``nrllm`` (flush via
  ``cache:flush --group=nrllm``).

Caching strategy:

- **Always cache**: Embeddings (deterministic).
- **Optional cache**: Completions with temperature=0.
- **Never cache**: Streaming, tool calls, high temperature.

.. _adr-005-consequences:

Consequences
============
**Positive:**

- ●● Reduced API costs.
- ●● Faster responses for cached content.
- ● Follows TYPO3 patterns.
- ◐ Configurable per deployment.

**Negative:**

- ✕ Cache invalidation complexity.
- ◑ Storage requirements.
- ✕ Stale responses if TTL too long.

**Net Score:** +4.5 (Positive impact - significant
cost/performance gains with manageable cache
complexity)
