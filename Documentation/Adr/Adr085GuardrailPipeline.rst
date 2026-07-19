.. include:: /Includes.rst.txt

.. _adr-085:

============================================================================
ADR-085: Guardrail pipeline for provider responses
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-085-context:

Context
=======

nr-llm has good, targeted safety mechanisms (skill/tool egress, budgets, secret
sanitisation on logged errors), but no general content-policy layer over a
provider response. LLM output is untrusted content, and any editor-facing or
autonomous use needs to be able to inspect it, rewrite it, block it, or route it
for review — with a richer answer than a boolean.

.. _adr-085-decision:

Decision
========

**A guardrail is a `GuardrailInterface`** whose `checkOutput()` returns a
`GuardrailResult` verdict — ALLOW, REDACT, RETRY, REQUIRE_APPROVAL, or DENY.
Guardrails are auto-collected through the ``nr_llm.guardrail`` DI tag (the same
pattern as ``nr_llm.tool``), so a new guardrail is active simply by existing
under ``Classes/``.

**`GuardrailMiddleware` runs them in the existing ADR-026 provider pipeline**, at
priority 115 — outermost, above Telemetry (110) *(superseded — see the*
*2026-07-19 update under Consequences: the guardrail moved to priority 90, inside*
*the persistence layers)*. It screens every non-streaming `CompletionResponse`
after the downstream chain produces it:

- ALLOW passes on; REDACT rewrites the content and keeps screening (a later
  guardrail may still deny); DENY throws `GuardrailViolationException`;
  REQUIRE_APPROVAL throws `GuardrailApprovalRequiredException`; RETRY re-asks the
  provider once and re-screens the fresh response (capped at one retry).
- It sits **above** Telemetry deliberately: a guardrail denial is a *policy*
  outcome, not a provider failure, so provider telemetry stays accurate.
- Non-`CompletionResponse` results (embeddings, vision, raw payloads) pass
  through untouched.

**Two reference guardrails ship active:** `SecretRedactionGuardrail` (REDACT —
masks secret-shaped strings a model may have echoed, reusing
`ErrorMessageSanitizerTrait` plus API-key/Bearer patterns) and
`ProviderContentFilterGuardrail` (DENY — turns a silent ``content_filter``
response into an explicit, catchable denial).

.. _adr-085-consequences:

Consequences
============

**Update 2026-07-19 — corrected pipeline placement (priority 115 → 90).** Placing
the guardrail *outermost* meant it redacted the response only AFTER
`IdempotencyMiddleware` (105) had already serialised the *unredacted*
`CompletionResponse` into the ``nrllm_idempotency`` cache (24h, possibly a shared
backend) — so a model that echoed a secret leaked it into persistence even though
the caller saw the redacted value. The guardrail now runs at priority **90**,
INSIDE both persistence layers (Idempotency 105, Cache 100) and above the
behavioural stack, so it redacts (or blocks) *before* anything is stored; a
DENY/REQUIRE_APPROVAL throws before the store, so a blocked response is never a
replayable result. Telemetry accuracy is preserved differently now that the
guardrail sits inside Telemetry (110): the two guardrail exceptions implement a
`GuardrailPolicyException` marker, and `TelemetryMiddleware` records such a policy
outcome as a *successful* provider run (the provider produced a response; the
guardrail refused to release it) — so a denial still does not distort the
provider failure-rate. A side benefit: a RETRY now genuinely re-runs the provider
instead of replaying the idempotency-cached response.

- Consumers get allow/redact/deny/retry/require-approval over model output, not
  true/false. A denial is a typed, catchable exception (mirroring
  `BudgetExceededException`).
- **Behaviour change:** a provider ``content_filter`` response now raises
  `GuardrailViolationException` instead of returning silently — the degraded/empty
  response surfaces rather than passing through.
- **Output only, for now.** This screens the *response*. Screening the *prompt*
  (input redaction / injection detection) is a separate step: the prompt payload
  is captured in the pipeline's terminal closure, not on the immutable
  `ProviderCallContext`, so input guardrails require threading the messages
  through the context first — a scoped follow-up, not built here.
- **Streaming is not covered.** `StreamingDispatcher` bypasses the middleware
  pipeline (ADR-062), so streamed responses are not screened. Covering them is a
  separate integration in the streaming path.
- The REQUIRE_APPROVAL verdict is the seam to human review: on a plain completion
  it raises `GuardrailApprovalRequiredException` (distinct from a denial) so a
  consumer with a run/review context (the human-in-the-loop epic, ADR-084, or a
  review queue) can route it to approval instead of an error.
