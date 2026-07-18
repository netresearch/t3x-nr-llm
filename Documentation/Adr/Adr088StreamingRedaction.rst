.. include:: /Includes.rst.txt

.. _adr-088:

============================================================================
ADR-088: Live streaming redaction with a holdback buffer
============================================================================

:Status: Accepted
:Date: 2026-07-18
:Authors: Netresearch DTT GmbH

.. _adr-088-context:

Context
=======

ADR-086 added an end-of-stream guardrail *audit*: once a stream finishes,
``StreamingDispatcher`` screens the assembled completion and records any
non-ALLOW verdict. It is audit-only — the chunks have already been yielded to
the caller, so a secret a model streamed was recorded but not masked. The gap
left open was live redaction: masking a secret *before* the byte is sent.

The hard part is chunk boundaries. A secret can be split across two chunks
(``sk-abcdef012`` + ``3456789…``), so redacting each chunk in isolation misses
it. Catching it requires not emitting a byte until enough following bytes have
arrived to know it is not part of an in-progress secret.

.. _adr-088-decision:

Decision
========

**Redact the raw buffer fresh, emit its stable prefix.** ``drain()`` accumulates
the raw completion and, each chunk, redacts the whole raw buffer
(``redactStream()`` applies the output guardrails' REDACT verdicts) and emits
only the redacted prefix beyond the last ``HOLDBACK_BYTES`` (128); the remainder
is flushed at end-of-stream. Redacting the RAW text every time — never
re-processing an earlier redaction marker — is essential: a marker such as
``sk-***`` breaks the pattern's own character class, so re-redacting
``sk-*** + continuation`` would leave the continuation of a boundary-split key
unmatched and leak it. Because the raw buffer re-matches a secret in full on
every chunk, a complete secret always collapses to its marker; the holdback then
withholds only a match still in progress at the tail (whose reach-back is an
anchor plus the pattern minimum, far under 128), so no unredacted secret byte is
emitted, including one split across chunk boundaries.

- **Only REDACT is actionable live.** DENY / REQUIRE_APPROVAL cannot retract a
  sent stream; they remain the job of the end-of-stream audit (ADR-086), which
  still runs and records them. The audit's log wording now reflects that REDACT
  was masked live.
- **Only redaction-capable guardrails trigger the buffer.** A guardrail opts into
  live redaction by implementing the ``StreamRedactableInterface`` marker
  (``SecretRedactionGuardrail`` does). When none is registered — or only
  policy-only DENY guardrails are — the loop passes chunks straight through with
  no buffer and no latency.
- **Bounded.** The raw buffer is capped at ``MAX_GUARDRAIL_BUFFER_BYTES`` (50 KB,
  the same cap the audit buffer uses); past it the stream is flushed and passed
  through raw, so memory and the per-chunk rescan stay bounded on a
  pathologically long stream.
- **Multibyte-safe.** The emit boundary is backed off a UTF-8 continuation run so
  a codepoint is never split across two yielded deltas.
- **Usage/telemetry count the RAW provider output**, unchanged — the redacted
  emitted length is not the billable token count.

.. _adr-088-consequences:

Consequences
============

- Streamed secrets in the common shapes (``sk-…`` keys, ``Bearer …`` tokens,
  credential-bearing URL params) are masked before delivery, closing the
  streaming blind spot the ADR-086 audit only recorded.
- Cost: the last ``HOLDBACK_BYTES`` of every stream that has a redacting
  guardrail arrive at end-of-stream rather than incrementally — a small,
  bounded latency on the stream tail. Accepted as the price of live masking.
- Limits: a credential whose anchor / URL-param name alone exceeds the holdback
  window and straddles the final boundary can still partially leak. Past the
  50 KB cap a secret is neither masked nor recorded — the audit buffer caps at
  the same size — so it can leak silently on a runaway (>50 KB) single
  completion; accepted as the bound on memory and rescan cost. Correctness relies
  on the redactor collapsing a complete secret to a marker outside its own
  character class (so a partial anchor at the tail is the only unstable region) —
  true for the shipped ``SecretRedactionGuardrail``.
- DENY on a stream stays unenforceable — a hard block needs the non-streaming
  path.
