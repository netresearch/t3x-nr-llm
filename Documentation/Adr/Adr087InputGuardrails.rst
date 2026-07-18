.. include:: /Includes.rst.txt

.. _adr-087:

============================================================================
ADR-087: Input-side guardrails — screening and redacting the outgoing prompt
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-087-context:

Context
=======

ADR-085 established the guardrail pipeline for provider *responses*:
``GuardrailMiddleware`` screens every non-streaming ``CompletionResponse`` and a
guardrail returns an ALLOW / REDACT / DENY / RETRY / REQUIRE_APPROVAL verdict.
It screens output only, and said so: the prompt payload is not on the
``ProviderCallContext`` (ADR-026 keeps the context payload-free), so a
middleware cannot see — let alone rewrite — the outgoing messages.

The prompt is untrusted too. A user can paste a secret (an API key, a
credential-bearing URL) that should not be forwarded verbatim to a third-party
provider, and an operator may want to block a prompt on policy before spending a
call.

.. _adr-087-decision:

Decision
========

**An input guardrail is an ``InputGuardrailInterface``** whose ``checkInput()``
returns the same ``GuardrailResult`` verdict type as the output side.
Implementers are auto-collected through the ``nr_llm.input_guardrail`` DI tag —
separate from ``nr_llm.guardrail`` so an output-only guardrail (e.g. the
provider content-filter, which reacts to a response attribute) is not forced to
implement a meaningless input check.

**Screening runs on the send path, not in the pipeline.** An
``InputGuardrailScreener`` runs the input guardrails over the message list inside
``LlmServiceManager`` — before the pipeline, where the messages are reachable.
This is the key difference from the output side: because the screener holds the
payload, a **REDACT verdict rewrites the prompt in place** (a middleware could
not). A DENY / REQUIRE_APPROVAL throws the same
``GuardrailViolationException`` / ``GuardrailApprovalRequiredException`` the
output side uses, so a caller handles both identically; RETRY (re-ask the
provider) has no meaning before the call and is ignored.

Screening is applied at the configuration-driven entry points —
``chatWithConfiguration``, ``chatWithToolsForConfiguration``,
``streamChatWithConfiguration`` — which is where ``chat()`` / ``streamChat()``
funnel once a default configuration resolves (ADR-034). The raw-string
``completeWithConfiguration`` (where ``complete()`` funnels) takes a prompt
string rather than a message list, so it screens through a string variant that
wraps, screens, and unwraps the prompt. For streaming, screening runs before the
opener captures the messages, so a redaction reaches the provider and a DENY
throws at call time, not on first iteration. The screener handles both
typed ``ChatMessage`` and legacy array messages; a message with no string
content (an assistant tool-call turn) passes through untouched.

``SecretRedactionInputGuardrail`` ships as the reference implementation,
applying the same secret masking to the prompt that ``SecretRedactionGuardrail``
applies to the response (shared via ``RedactsSecretsTrait``). They are separate
classes because a single class cannot implement both ``GuardrailInterface`` and
``InputGuardrailInterface`` — their ``TAG_NAME`` constants would collide.

.. _adr-087-consequences:

Consequences
============

- The prompt is screened and can be redacted before it leaves the extension —
  the input complement of ADR-085.
- Guardrail execution is deliberately split: output in the pipeline (ADR-085),
  input on the send path (here). The alternative — threading the payload onto
  ``ProviderCallContext`` — was rejected to keep the context payload-free
  (ADR-026); the trade is two locations instead of one.
- Screening covers both the configuration-driven surface and the ad-hoc
  pinned-provider-key path. The ad-hoc branches of ``chat()`` / ``complete()`` /
  ``chatWithTools()`` / ``streamChat()`` (an explicit provider, no DB
  configuration — the ADR-034 escape hatch) screen the prompt as well, so every
  message-carrying send path is covered.
- Input guardrails support ALLOW / REDACT / DENY / REQUIRE_APPROVAL. RETRY is
  ignored on the input side.
