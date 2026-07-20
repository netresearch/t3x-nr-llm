.. include:: /Includes.rst.txt

.. _adr-098:

============================================================================
ADR-098: Input-guardrail screening for specialized prompts
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-098-context:

Context
=======

The chat send path screens an outgoing message list through the input
guardrails before it reaches a provider (:ref:`ADR-087 <adr-087>`), so a REDACT
verdict rewrites a secret out of the prompt and a DENY / REQUIRE_APPROVAL throws
before the call. The output guardrails run inside the pipeline
(:ref:`ADR-085 <adr-085>`), but they only ever see a model-generated
``CompletionResponse`` — the prompt payload is not reachable there, which is why
input screening runs on the send path.

The specialized services — DALL·E, FAL, TTS, DeepL — now route their dispatch
through the pipeline (:ref:`ADR-097 <adr-097>`), but the pipeline still cannot
screen their *input*: a specialized call sends a prompt string, not a
``CompletionResponse``, and a middleware could not rewrite that prompt back into
the terminal closure a REDACT verdict requires. So the same user-supplied text
that a chat message would have had screened reached the image / speech /
translation providers unscreened.

.. _adr-098-decision:

Decision
========

``InputGuardrailScreener`` gains ``screenText(string): string`` — the single-
string sibling of ``screen(array $messages)`` — sharing one private
``screenContent()`` loop so both apply identical guardrails and verdict handling.

``AbstractSpecializedService`` takes the ``InputGuardrailScreener`` as a required
dependency (after ``pipeline``) and exposes ``protected screenPrompt(string):
string``. Each service screens its user-supplied prompt on the send path, before
the request payload is built:

- **DALL·E** — ``generate``, ``generateMultiple`` (DALL·E 2 branch; the DALL·E 3
  branch screens via its delegation to ``generate()``), ``edit``.
- **FAL** — ``generate``, ``generateMultiple``.
- **TTS** — ``synthesize`` (``synthesizeToFile`` / ``synthesizeLong`` delegate to
  it, so every chunk is screened).
- **DeepL** — ``translate``, ``translateBatch`` (each element).

**Whisper is out of scope**: its payload is audio, not a prompt. The optional
transcription-hint field is not screened here — if that becomes a concern it is
its own step.

.. _adr-098-consequences:

Consequences
============

- **Breaking:** ``AbstractSpecializedService`` gained a required
  ``InputGuardrailScreener`` constructor parameter (after ``pipeline``, before the
  optional repositories). A subclass or manual construction must pass it; an
  ``InputGuardrailScreener([])`` with no guardrails is a valid pass-through for
  tests that do not exercise screening. Required, not optional, for the same
  reason as the budget gate (:ref:`ADR-078 <adr-078>`): a secret / injection
  screener that silently disappears when unwired is fail-open on exactly the
  control it provides.
- A specialized prompt is now screened identically to a chat prompt: a REDACT
  verdict rewrites the text that is sent (and, where the service echoes the
  prompt back in its result, what it echoes), and a DENY / REQUIRE_APPROVAL
  throws the same typed exception before any spend — screening runs before
  ``enforceBudget()`` reaches the dispatch, so a denied prompt costs nothing.
- Production wiring is unchanged: DI autowires the concrete
  ``InputGuardrailScreener`` into every specialized service. With no input
  guardrails tagged, ``screenText()`` is a pass-through and behaviour is
  identical to before.
- Still deferred: the fail-closed dispatch seam (``getSecureClient()`` throwing
  when no lifecycle context is active); and folding the per-service usage
  recording into a tagged pipeline extractor.
