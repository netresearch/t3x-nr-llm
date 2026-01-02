.. include:: /Includes.rst.txt

.. _adr-001:

===================================
ADR-001: Provider Abstraction Layer
===================================

.. _adr-001-status:

Status
======
**Accepted** (2024-01)

.. _adr-001-context:

Context
=======
We needed to support multiple LLM providers (OpenAI, Anthropic Claude, Google Gemini)
while maintaining a consistent API for consumers. Each provider has different:

- API endpoints and authentication methods
- Request/response formats
- Model naming conventions
- Capability sets (vision, embeddings, streaming, tools)

.. _adr-001-decision:

Decision
========
Implement a **provider abstraction layer** with:

1. :php:`ProviderInterface` as the core contract.
2. Capability interfaces for optional features:

   - :php:`EmbeddingCapableInterface`.
   - :php:`VisionCapableInterface`.
   - :php:`StreamingCapableInterface`.
   - :php:`ToolCapableInterface`.

3. :php:`AbstractProvider` base class with shared functionality.
4. :php:`LlmServiceManager` as the unified entry point.

.. _adr-001-consequences:

Consequences
============
**Positive:**

- ●● Consumers use single API regardless of provider.
- ●● Easy to add new providers.
- ● Capability checking via interface detection.
- ●● Provider switching requires no code changes.

**Negative:**

- ✕ Lowest common denominator for shared features.
- ◑ Provider-specific features require direct provider access.
- ◑ Additional abstraction layer complexity.

**Net Score:** +5.5 (Strong positive impact - abstraction enables flexibility and maintainability)

.. _adr-001-alternatives:

Alternatives considered
=======================
1. **Single monolithic class**: Rejected due to maintenance complexity.
2. **Strategy pattern only**: Insufficient for capability detection.
3. **Factory pattern**: Used in combination with interfaces.
