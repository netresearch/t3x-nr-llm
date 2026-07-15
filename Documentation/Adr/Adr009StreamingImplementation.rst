.. include:: /Includes.rst.txt

.. _adr-009:

===================================
ADR-009: Streaming Implementation
===================================

.. _adr-009-status:

Status
======
**Accepted** (2024-03)

.. _adr-009-context:

Context
=======
Streaming responses provide:

- Better UX for long responses.
- Lower time-to-first-token.
- Real-time feedback.

.. _adr-009-decision:

Decision
========
Use **PHP Generators** for streaming:

.. code-block:: php
   :caption: Example: Streaming chat responses

   public function streamChat(array $messages, array $options = []): Generator
   {
       $response = $this->sendStreamingRequest($messages, $options);

       foreach ($this->parseSSE($response) as $chunk) {
           yield $chunk;
       }
   }

   // Usage
   foreach ($llmManager->streamChat($messages) as $chunk) {
       echo $chunk;
       flush();
   }

Implementation details:

- Server-Sent Events (SSE) parsing.
- Chunked transfer encoding.
- Memory-efficient iteration.
- Provider-specific adaptations.

.. _adr-009-consequences:

Consequences
============
**Positive:**

- ●● Memory efficient.
- ● Natural iteration syntax.
- ●● Real-time output.
- ◐ Works with output buffering.

**Negative:**

- ✕ No response object until complete.
- ◑ Error handling complexity.
- ◑ Connection management.
- ✕ No caching possible.

**Net Score:** +3.5 (Positive impact - streaming UX
benefits outweigh implementation complexity)

.. _adr-009-lifecycle:

Update (2026-07): streaming is in the request lifecycle
=======================================================

The generator mechanism decided here is unchanged, but streamed calls no longer
sidestep the cross-cutting concerns the non-streaming path enforces. Originally
a streamed call ran no budget pre-flight and produced neither a usage nor a
telemetry row — a live budget hole.

Streamed calls now run through a dedicated streaming lifecycle
(:ref:`adr-062`): an eager budget pre-flight before the first chunk,
pre-first-chunk provider fallback, time-to-first-token measurement, and a
``finally`` that records usage (:sql:`tx_nrllm_service_usage`) and telemetry
(:sql:`tx_nrllm_telemetry`) on every exit — completion, exception, or an
abandoned generator. The lifecycle wraps the generator; the public
:php:`Generator`\<int, string, mixed, void> contract is unchanged.

Two of the "Negative" consequences above are now bounded rather than open:
"error handling complexity" and "connection management" are handled once, in the
lifecycle, instead of at each call site. "No response object until complete" and
"no caching possible" remain intrinsic to streaming. Token usage on this path is
*estimated* (providers expose no usage frame mid-stream); see :ref:`adr-062` for
the rationale and the follow-up toward exact figures.
