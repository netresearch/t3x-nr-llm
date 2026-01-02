.. include:: /Includes.rst.txt

.. _adr-010:

=======================================
ADR-010: Tool/Function Calling Design
=======================================

.. _adr-010-status:

Status
======
**Accepted** (2024-04)

.. _adr-010-context:

Context
=======
Modern LLMs support tool/function calling for:

- External data retrieval.
- Action execution.
- Structured output generation.

.. _adr-010-decision:

Decision
========
Support **OpenAI-compatible tool format**:

.. code-block:: php
   :caption: Example: Tool definition

   $tools = [
       [
           'type' => 'function',
           'function' => [
               'name' => 'get_weather',
               'description' => 'Get weather for location',
               'parameters' => [
                   'type' => 'object',
                   'properties' => [
                       'location' => ['type' => 'string'],
                   ],
                   'required' => ['location'],
               ],
           ],
       ],
   ];

Tool calls returned in :php:`CompletionResponse::$toolCalls`:

- Array of tool call objects.
- Includes function name and arguments.
- JSON-encoded arguments for parsing.

.. _adr-010-consequences:

Consequences
============
**Positive:**

- ●● Industry-standard format.
- ●● Cross-provider compatibility.
- ● Flexible tool definitions.
- ● Type-safe parameters.

**Negative:**

- ◑ Complex nested structure.
- ◑ Provider translation needed.
- ✕ No automatic execution.
- ◑ Testing complexity.

**Net Score:** +5.0 (Positive impact - OpenAI-compatible format ensures broad compatibility)
