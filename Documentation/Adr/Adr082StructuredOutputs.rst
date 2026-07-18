.. include:: /Includes.rst.txt

.. _adr-082:

============================================================================
ADR-082: Schema-validated structured outputs with one repair round-trip
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-082-context:

Context
=======

`CompletionService::completeJson()` only enabled JSON mode and ran `json_decode`,
checking the result was valid JSON and an array. No *business* schema was
enforced — a consumer that needed, say, ``{title, description, keywords[]}`` back
got an untyped ``array<string, mixed>`` that could be missing keys or carry the
wrong types, and a single malformed response threw with no recovery. That makes
AI results unreliable to program against.

A lightweight structural JSON-Schema matcher already existed inside
`DeterministicGrader` (:ref:`ADR-060 <adr-060>`, the evaluation subsystem):
top-level ``type``, object ``required`` keys, recursive ``properties`` types.
Its logic was exactly what a validated completion path needs, but it was private
to the grader.

.. _adr-082-decision:

Decision
========

**Extract the grader's matcher into a shared `JsonSchemaValidator`** (`Service/Schema/`)
and have both `DeterministicGrader` and the new completion path use it — one
matcher, no duplication. The behaviour is byte-for-byte the grader's (including
the empty-object-decodes-to-`[]` handling); the grader now delegates to it.

**Add `completeStructured()` (and its `*ForConfiguration` twin)** to
`CompletionServiceInterface` / `CompletionService`. Given a prompt and a subset
JSON Schema, it:

1. requests JSON mode and injects the schema into the prompt (an instruction to
   return only conforming JSON),
2. decodes and validates the response with `JsonSchemaValidator`,
3. on a decode failure **or** a schema mismatch, performs **one** controlled
   repair round-trip — re-asking with the invalid output and the schema,
4. returns the validated payload, or throws `InvalidArgumentException` if the
   repair also fails.

**Provider-agnostic by design.** The guarantee is prompt-injection + local
validation + one repair, which works on every provider (OpenAI, Claude, Gemini,
Ollama, Groq, Mistral, OpenRouter) uniformly. Native provider structured outputs
were **deliberately not** used here: OpenAI's ``response_format: json_schema``
requires *strict* schemas (``additionalProperties: false`` and every property
required), which arbitrary consumer schemas do not satisfy and which would 400
the request; Claude has no native parameter at all. Wiring native, per-provider
enforcement — behind a schema-compatibility normaliser — is a separate, additive
change and is noted as a follow-up, not a blocker.

.. _adr-082-consequences:

Consequences
============

- Consumers get a reliable typed contract: a schema in, validated data out, with
  automatic single-shot repair. `completeJson()` is unchanged for callers that do
  not need a schema.
- The matcher lives in one place; a fix to the structural rules now benefits both
  the grader and structured completions.
- Validation is *structural* (the documented subset), not full JSON Schema draft
  semantics — no ``enum``, ``minLength``, ``pattern``, ``oneOf`` etc. That is the
  same contract the grader has always offered; a full validator would add a
  runtime dependency and is out of scope.
- The repair round-trip costs at most one extra provider call, only when the first
  response fails. It is capped at one attempt to bound cost.
- `CompletionService` and `DeterministicGrader` take the validator as a
  constructor dependency with a default instance, so existing direct
  constructions (tests) keep working and DI autowires the shared service.
