.. _adr-128:

===========================================
ADR-128: Provider-native structured output
===========================================

:Status: Accepted
:Date: 2026-08-05

Context
=======

ADR-082 built structured completions on a prompt instruction plus local
validation and one repair round-trip, and named native, per-provider
enforcement as a follow-up behind a schema-compatibility normaliser.
ADR-126 delivered the missing precondition: a named, pre-flighted schema
subset. What remained was the transport — the recon found that only the
OpenAI adapter emitted any ``response_format`` at all; the other six
adapters silently dropped the option, so even plain JSON mode was
prompt-only on six of seven providers.

Decision
========

``ChatOptions`` gains ``withResponseSchema(array)`` (declared outside the
constructor, like ``suppressRequestCount`` — the
``@phpstan-consistent-constructor``/``ToolOptions`` constraint). Both
``completeStructured*()`` methods attach the already-pre-flighted schema;
it reaches the adapter as ``response_schema`` in the flat options array.
Each adapter emits its provider's dialect:

- **OpenAI, Groq, Mistral, OpenRouter** share
  ``OpenAiResponseFormatTrait``. A schema that qualifies for OpenAI's
  strict mode (conservative profile: object root, every object
  ``additionalProperties: false`` with all properties required, allowlisted
  keywords only) is sent as ``response_format: {type: json_schema, strict:
  true}``; any other schema degrades to ``{type: json_object}`` — strict
  mode 400s on schemas outside its rules, and a provider error for a valid
  ADR-126 schema is the failure mode this profile exists to prevent. Plain
  ``response_format: 'json'`` now emits JSON mode on all four (previously:
  OpenAI only).
- **Gemini** sets ``generationConfig.responseMimeType: application/json``
  and, when the root is expressible, ``responseSchema`` in Gemini's
  dialect. The dialect conversion only ever **widens** (drops keywords it
  cannot express — a partially-converted ``enum`` would narrow below the
  real schema and block valid values, so inexpressible keywords are dropped
  whole).
- **Ollama** sends the schema verbatim as the top-level ``format`` field
  (like ``think``), or ``format: 'json'`` for plain JSON mode.
- **Claude** has no response-format parameter; the native idiom is a single
  forced tool whose ``input_schema`` is the schema, and whose ``tool_use``
  input is returned as the JSON string every other provider returns.
  Object-root schemas only (Claude's requirement); anything else stays
  prompt-only.

The invariant that makes all of this safe: **native enforcement narrows
what the model can emit; the local strict validation (ADR-126) remains
authoritative on the response.** Native emission may be weaker than the
schema (Gemini dialect, json_object fallback) but must never be stronger;
the prompt instruction and the repair round-trip stay untouched.

Consequences
============

- ``completeJson()`` now gets real JSON mode on Groq, Mistral, OpenRouter,
  Ollama and Gemini instead of relying on the prompt alone — fewer
  "Failed to decode JSON response" failures, no API change.
- Streaming is deliberately out of scope: structured completions never
  stream (ADR-062), so ``streamChatCompletion()`` does not emit schemas.
- The strict-mode profile and the Gemini dialect are conservative by
  design; widening them (more keywords, union types) is additive and needs
  no API change.
- Ollama receives the full subset schema; its grammar conversion handles
  the ADR-126 keyword set, but an exotic schema a future Ollama version
  rejects would surface as a provider error — accepted, since Ollama is
  the local reference provider and the schema was pre-flighted.
- On Claude, a structured completion's ``finishReason`` reflects the
  forced tool call (``tool_calls``) rather than ``stop``; callers of
  ``completeStructured*()`` consume the decoded array, not the reason.
- ADR-082's "no native parameter at all" statement about Claude is
  superseded by the tool-forcing idiom; its prompt+repair architecture
  stands.
