.. include:: /Includes.rst.txt

.. _adr-003:

=================================
ADR-003: Typed Response Objects
=================================

.. _adr-003-status:

Status
======
**Accepted** (2024-01)

.. _adr-003-context:

Context
=======
Provider APIs return different response structures. We needed to:

- Provide consistent response format to consumers.
- Enable IDE autocompletion and type checking.
- Include relevant metadata (usage, model, finish reason).

.. _adr-003-decision:

Decision
========
Use **immutable value objects** for responses:

.. code-block:: php
   :caption: Example: CompletionResponse value object

   final class CompletionResponse
   {
       public function __construct(
           public readonly string $content,
           public readonly string $model,
           public readonly UsageStatistics $usage,
           public readonly string $finishReason,
           public readonly string $provider,
           public readonly ?array $toolCalls = null,
       ) {}
   }

Key characteristics:

- :php:`final` classes prevent inheritance issues.
- :php:`readonly` properties ensure immutability.
- Constructor promotion for concise definition.
- Nullable for optional data.

.. _adr-003-consequences:

Consequences
============
**Positive:**

- ●● Strong typing with IDE support.
- ● Immutable objects are thread-safe.
- ●● Clear API contract.
- ● Easy testing and mocking.

**Negative:**

- ◑ Cannot extend responses.
- ✕ Breaking changes require new properties.
- ◑ Slight memory overhead vs arrays.

**Net Score:** +5.5 (Strong positive impact - type safety and immutability outweigh flexibility limitations)
