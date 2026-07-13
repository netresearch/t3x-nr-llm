.. include:: /Includes.rst.txt

.. _adr-054:

===============================================================
ADR-054: Typed tool turns on ChatMessage instead of wire arrays
===============================================================

:Status: Accepted
:Date: 2026-07-13
:Authors: Netresearch DTT GmbH

.. _adr-054-context:

Context
=======

``ChatMessage`` modelled only ``(role, content)``. Two message shapes
that every tool-calling conversation needs could not be expressed as
value objects:

1. the **assistant turn** that carries the model's ``tool_calls``, and
2. the **tool turn** that answers one call via its ``tool_call_id``.

So every tool loop hand-built raw OpenAI-wire arrays:
``ToolLoopService`` assembled both turns as associative arrays (with the
``arguments`` JSON-encoding and the empty-``{}``-not-``[]`` subtlety
inlined at the call site), and consuming extensions copied the pattern.
The developer documentation taught the raw-array shape and had drifted
further: its example still treated ``CompletionResponse::$toolCalls``
elements as nested arrays (``$toolCall['function']['name']``) although
they have been typed :php:`ToolCall` value objects since that migration.

Untyped wire arrays at the public seam mean no validation (a ``tool``
message without a ``tool_call_id`` fails only at the provider, with a
provider-specific 400), duplicated serialisation subtleties, and drift
between code and documentation.

.. _adr-054-decision:

Decision
========

``ChatMessage`` gains two optional, validated tail fields and two named
constructors — it stays the single value object for all four roles
rather than growing per-shape subclasses:

- ``?array $toolCalls`` (``list<ToolCall>``), allowed only on the
  ``assistant`` role; every element must be a :php:`ToolCall`; an empty
  list is rejected (providers 400 on ``tool_calls: []``).
- ``?string $toolCallId``, allowed only on the ``tool`` role and must be
  non-empty.
- :php:`ChatMessage::assistantToolCalls(array $toolCalls, ?string
  $content = null)` — ``null`` content (what providers send alongside
  tool calls) is stored as ``''`` because the ``$content`` property
  stays a non-nullable string.
- :php:`ChatMessage::toolResult(string $toolCallId, string $content)`.

**Wire shape.** ``toArray()`` (and ``jsonSerialize()``) emits the
OpenAI-compatible request form: ``tool_calls`` entries carry
``function.arguments`` as a JSON-encoded **string**, with empty
arguments encoding to ``{}`` (an object), never ``[]``; ``tool_call_id``
is emitted when set. This deliberately differs from
:php:`ToolCall::toArray()`, which keeps the legacy decoded-map form for
``CompletionResponse`` consumers — :php:`ToolCall::fromArray()` accepts
both variants, so ``ChatMessage::fromArray()`` round-trips either shape
(and accepts ``content: null`` alongside ``tool_calls``).

**Transport path.** Every provider adapter flattens messages via
``$m instanceof ChatMessage ? $m->toArray() : $m`` before building its
payload, and ``LlmServiceManager::normaliseMessages()`` passes
``ChatMessage`` instances through untouched — no layer rebuilds messages
from ``role`` + ``content`` alone, so the new fields reach the HTTP
payload intact. ``ClaudeProvider`` / ``GeminiProvider`` / ``Ollama``
already convert *from* that OpenAI wire shape into their native formats.
A unit test pins the path end-to-end at the mocked HTTP boundary.

``ToolLoopService`` builds both turns through the new factories; its
private raw-array assembly is deleted.

.. _adr-054-consequences:

Consequences
============

- Tool loops — in this extension and in consumers — compose typed,
  validated turns; invalid shapes (a tool result without an id, tool
  calls on a user message) fail fast with nr_llm's
  :php:`InvalidArgumentException` instead of a provider 400.
- The ``arguments``-encoding and ``{}``-vs-``[]`` subtleties live in
  exactly one place, ``ChatMessage::toArray()``.
- Raw-array messages remain accepted everywhere for back-compat: the
  manager's normalisation still passes richer arrays through unchanged,
  and ``fromArray()`` now understands the two tool-turn keys.
- ``ChatMessage::toArray()``'s return shape gains two optional keys;
  callers that assumed exactly ``{role, content}`` for *tool-loop*
  messages must use the documented shape. Plain messages serialise
  byte-identically to before.
- The developer documentation example is rewritten on top of the value
  objects, closing the ``$toolCall['function']['name']`` drift.
