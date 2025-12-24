.. include:: /Includes.rst.txt

.. _adr-005:

==============================================
ADR-005: TYPO3 Caching Framework Integration
==============================================

Status
======
**Accepted** (2024-03)

Context
=======
LLM API calls are:

- Expensive (cost per token)
- Relatively slow (network latency)
- Often deterministic (embeddings, some completions)

Decision
========
Integrate with **TYPO3's caching framework**:

- Cache identifier: ``nrllm_responses``
- Configurable backend (default: database)
- Cache keys based on: provider + model + input hash
- TTL: 3600s default (configurable)

Caching strategy:

- **Always cache**: Embeddings (deterministic)
- **Optional cache**: Completions with temperature=0
- **Never cache**: Streaming, tool calls, high temperature

Consequences
============
**Positive:**

- ●● Reduced API costs
- ●● Faster responses for cached content
- ● Follows TYPO3 patterns
- ◐ Configurable per deployment

**Negative:**

- ✕ Cache invalidation complexity
- ◑ Storage requirements
- ✕ Stale responses if TTL too long

**Net Score:** +4.5 (Positive impact - significant cost/performance gains with manageable cache complexity)
