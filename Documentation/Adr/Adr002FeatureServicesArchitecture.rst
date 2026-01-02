.. include:: /Includes.rst.txt

.. _adr-002:

========================================
ADR-002: Feature Services Architecture
========================================

.. _adr-002-status:

Status
======
**Accepted** (2024-02)

.. _adr-002-context:

Context
=======
Common LLM tasks (translation, image analysis, embeddings) require:

- Specialized prompts and configurations
- Pre/post-processing logic
- Caching strategies
- Quality control measures

.. _adr-002-decision:

Decision
========
Create **dedicated Feature Services** for high-level operations:

- :php:`CompletionService`: Text generation with format control.
- :php:`EmbeddingService`: Vector operations with caching.
- :php:`VisionService`: Image analysis with specialized prompts.
- :php:`TranslationService`: Language translation with quality scoring.

Each service:

- Uses :php:`LlmServiceManager` internally.
- Provides domain-specific methods.
- Handles caching and optimization.
- Returns typed response objects.

.. _adr-002-consequences:

Consequences
============
**Positive:**

- ●● Clear separation of concerns.
- ● Reusable, tested implementations.
- ●● Consistent behavior across use cases.
- ● Built-in best practices (caching, prompts).

**Negative:**

- ◑ Additional classes to maintain.
- ◑ Potential duplication with manager methods.
- ◑ Learning curve for service selection.

**Net Score:** +6.5 (Strong positive impact - services provide high-level abstractions with best practices)
