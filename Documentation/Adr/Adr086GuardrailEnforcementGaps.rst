.. include:: /Includes.rst.txt

.. _adr-086:

============================================================================
ADR-086: Guardrail enforcement gaps — playground verdicts and streamed output
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-086-context:

Context
=======

ADR-085 added the guardrail pipeline: ``GuardrailMiddleware`` (priority 115)
screens every non-streaming ``CompletionResponse`` and, on a DENY or
REQUIRE_APPROVAL verdict, throws ``GuardrailViolationException`` /
``GuardrailApprovalRequiredException``. Two gaps remained:

- **Tool playground.** The tool loop (ADR-084) calls the provider through the
  same pipeline, so those guardrail exceptions can surface mid-run. The
  playground caught only ``ToolApprovalRequiredException`` and
  ``BudgetExceededException``; a guardrail verdict fell through to the generic
  ``catch (Throwable)`` and returned an HTTP 500, so a *policy* outcome looked
  like a server error and the run row was recorded as a crash.
- **Streaming.** A streamed response cannot go through the pipeline — a lazy
  generator can't be the pipeline terminal (ADR-062) — so ``GuardrailMiddleware``
  never runs on streamed output. Streamed responses were an unscreened,
  unaudited blind spot.

.. _adr-086-decision:

Decision
========

**Playground: a guardrail verdict is a policy outcome, not a 500.** The
``ToolPlaygroundController`` catches ``GuardrailViolationException`` and
``GuardrailApprovalRequiredException`` before the generic ``Throwable`` in all
three run paths (batch ``runAction``, ``resumeAction``, streamed ``streamRun``).
The run is settled as blocked (so it is not left RUNNING) and a clean payload is
returned — HTTP 200 with ``success:false`` and a ``status`` of
``guardrail_blocked`` (DENY) or ``guardrail_approval_required``
(REQUIRE_APPROVAL), naming the deciding guardrail and its reason. The streamed
path emits the same as a terminal event instead of the generic ``error``.

A full response-release resume for REQUIRE_APPROVAL (persisting the flagged
response and re-delivering it on approval, mirroring ADR-084 for a completion
rather than for pending tool calls) is **not** built here: the exception carries
only the guardrail and reason, not a resumable state. The verdict is surfaced
and recorded; releasing an approved response is a separate future step.

**Streaming: end-of-stream guardrail audit.** ``StreamingDispatcher`` collects
the guardrail iterator (``nr_llm.guardrail``) and, once a stream drains
successfully, screens the assembled completion via ``checkOutput()`` and records
any non-ALLOW verdict (a structured ``warning``). This is an **audit**, not
enforcement: the chunks have already been yielded to the caller, so a DENY or
REDACT cannot retract or live-redact them. The buffer is bounded
(``MAX_GUARDRAIL_BUFFER_BYTES``, 50 000 bytes) so a pathologically long stream
keeps streaming's memory benefit; a model echoing a secret does so near where it
was given, so the leading window is enough to screen. Screening is fail-soft — a
broken guardrail never turns a delivered stream into an error.

.. _adr-086-consequences:

Consequences
============

- A guardrail decision in the playground is a clean, distinguishable outcome
  (blocked vs flagged-for-approval). The ``AgentRun`` row reflects it rather
  than a spurious failure since :ref:`ADR-092 <adr-092>`, which stores the
  verdict as the run's termination reason — until then the row was written
  through the generic failure path and was indistinguishable from a crash.
- Streamed output is no longer a guardrail blind spot: a leaked secret or a
  policy trip is recorded for audit even though it cannot be un-sent.
- Live protection of a stream (redacting a secret *before* the chunk is sent, or
  denying mid-stream) needs a delta-oriented guardrail contract and per-chunk
  buffering — deliberately out of scope here and tracked as a follow-up.
- Input-side screening (redacting or blocking the outgoing prompt) is a separate
  change on the send path, tracked in its own ADR.
