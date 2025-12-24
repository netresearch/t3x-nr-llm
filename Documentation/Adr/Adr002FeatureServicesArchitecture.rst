.. include:: /Includes.rst.txt

.. _adr-002:

========================================
ADR-002: Feature Services Architecture
========================================

Status
======
**Accepted** (2024-02)

Context
=======
Common LLM tasks (translation, image analysis, embeddings) require:

- Specialized prompts and configurations
- Pre/post-processing logic
- Caching strategies
- Quality control measures

Decision
========
Create **dedicated Feature Services** for high-level operations:

- ``CompletionService``: Text generation with format control
- ``EmbeddingService``: Vector operations with caching
- ``VisionService``: Image analysis with specialized prompts
- ``TranslationService``: Language translation with quality scoring

Each service:

- Uses ``LlmServiceManager`` internally
- Provides domain-specific methods
- Handles caching and optimization
- Returns typed response objects

Consequences
============
**Positive:**

- ●● Clear separation of concerns
- ● Reusable, tested implementations
- ●● Consistent behavior across use cases
- ● Built-in best practices (caching, prompts)

**Negative:**

- ◑ Additional classes to maintain
- ◑ Potential duplication with manager methods
- ◑ Learning curve for service selection

**Net Score:** +6.5 (Strong positive impact - services provide high-level abstractions with best practices)
