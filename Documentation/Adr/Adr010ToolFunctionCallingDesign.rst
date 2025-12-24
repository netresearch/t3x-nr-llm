.. include:: /Includes.rst.txt

.. _adr-010:

=======================================
ADR-010: Tool/Function Calling Design
=======================================

Status
======
**Accepted** (2024-04)

Context
=======
Modern LLMs support tool/function calling for:

- External data retrieval
- Action execution
- Structured output generation

Decision
========
Support **OpenAI-compatible tool format**:

.. code-block:: php

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

Tool calls returned in ``CompletionResponse::$toolCalls``:

- Array of tool call objects
- Includes function name and arguments
- JSON-encoded arguments for parsing

Consequences
============
**Positive:**

- ●● Industry-standard format
- ●● Cross-provider compatibility
- ● Flexible tool definitions
- ● Type-safe parameters

**Negative:**

- ◑ Complex nested structure
- ◑ Provider translation needed
- ✕ No automatic execution
- ◑ Testing complexity

**Net Score:** +5.0 (Positive impact - OpenAI-compatible format ensures broad compatibility)
