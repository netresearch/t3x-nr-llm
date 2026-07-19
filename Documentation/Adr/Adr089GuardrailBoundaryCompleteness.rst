.. include:: /Includes.rst.txt

.. _adr-089:

============================================================================
ADR-089: Guardrail boundary completeness — reasoning, system prompt, vision
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-089-context:

Context
=======

ADR-085/087 guard the model's answer (output) and the user turns (input). An
adversarial audit of the merged layer found the secret-redaction guardrail — a
defense against a model echoing a secret it was given, or a user pasting one —
missed three reachable boundaries, so the same secret masked in one place leaked
in another:

- the model's **reasoning / ``thinking`` block** (surfaced in the playground
  glass-box, ADR-040) — screened on the answer, not the reasoning;
- the **system prompt** — added after input screening, so it reached the
  provider unscreened on every path except ``completeForConfiguration``;
- the **vision** text prompt — ``vision()`` forwarded its text items to the
  provider without screening, on both sides.

.. _adr-089-decision:

Decision
========

**Cover every boundary the same secret can cross.**

- **Reasoning.** ``GuardrailResult`` gains an optional ``redactedThinking``;
  ``SecretRedactionGuardrail`` masks both the content and the ``thinking`` block,
  and ``GuardrailMiddleware`` rebuilds the response with the redacted reasoning
  (null = leave as-is). Tool-call **arguments are deliberately not redacted** —
  they are functional parameters the tool consumes, and masking them would break
  the call.
- **System prompt.** The six ``applySystemPrompt()`` call sites in
  ``LlmServiceManager`` route through ``applyAndScreenSystemPrompt()``, which
  screens the *final assembled* message list — so the prepended system turn is
  screened too. Re-screening the already-screened user turns is an idempotent
  no-op.
- **Vision.** ``vision()`` screens the text items of its ``VisionContent`` before
  dispatch, matching the chat/tool paths.

.. _adr-089-consequences:

Consequences
============

- A secret is now masked (or the prompt blocked) across answer, reasoning,
  user turn, system prompt and vision text — the "enumerate all boundaries"
  completion of ADR-085/087.
- ``embed()`` remains unscreened: its input is embedding text, not a chat
  prompt; screening/redacting it would corrupt the vector semantics. Documented
  as out of scope.
- Redacting reasoning depends on the provider populating ``thinking``
  (Ollama ``message.thinking``, ADR-016); providers that fold reasoning into
  the content are covered by the content redaction already.
- On the streaming paths the system prompt is screened inside the lazy opener
  (that is where ``applySystemPrompt`` runs), so a REDACT is applied before the
  provider send, but a DENY / REQUIRE_APPROVAL triggered *only* by system-prompt
  content would throw on first drain rather than at call time — unlike the eager
  user-turn screening. Latent: the shipped ``SecretRedactionInputGuardrail`` only
  REDACTs, which is correct here; a future DENY-returning input guardrail would
  need the effective system prompt hoisted and screened before the opener.
