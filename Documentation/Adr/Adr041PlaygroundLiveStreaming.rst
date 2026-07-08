.. include:: /Includes.rst.txt

.. _adr-041:

==================================================================
ADR-041: Playground live run streaming
==================================================================

:Status: Accepted
:Date: 2026-07-06
:Authors: Netresearch DTT GmbH

.. _adr-041-context:

Context
=======

The playground inspector (:ref:`ADR-040 <adr-040>`) ran the whole bounded
agent loop server-side and returned the complete trace as one JSON document.
The browser therefore showed nothing until the entire run — every model
round-trip and tool execution — had finished, then rendered all at once. For a
multi-round run against a slow local model that is several seconds of a blank
pane, and it hides *when* each step happened.

The goal is a live inspector: each step appears the moment it is recorded.

.. _adr-041-decision:

Decision
========

Stream the run as newline-delimited JSON (NDJSON), one event per line, over the
existing admin AJAX route:

- **Opt-in.** The client sends ``stream=1``; the controller then streams.
  Without it the controller keeps the batch path — one
  :php:`respondJson()` document — which remains the no-JavaScript fallback and
  the shape the functional tests assert.
- **Per-step callback.** :php:`RunTrace` takes an optional ``onRecord`` closure
  fired the instant each :php:`RunStep` is recorded. The streaming controller
  passes a closure that echoes one ``{"event":"step","step":…}`` line and
  flushes; a final ``{"event":"done",…}`` (or ``{"event":"error",…}``) line
  carries the summary. When no closure is passed (every production and test
  caller) the loop is byte-for-byte unchanged.
- **Request steps stream before the call.** Each model round-trip is recorded
  as two steps: a ``request`` step (the messages sent and tools offered, no
  timing/tokens) emitted *before* the provider call, and the ``llm`` response
  step (content, timing, token split) after it. The first event reaches the
  browser within moments of the POST — the inspector is live from second zero
  and shows a waiting state while the model works — instead of staying empty
  until the first response arrives. The message array is serialised once per
  round, on the request step only.
- **Beat the proxy buffer.** A TYPO3 backend response is buffered by the reverse
  proxy until a chunk clears its flush threshold, so small lines all arrive at
  the end. The stream disables output buffering and zlib compression at runtime
  and pads every line past ~4 KB with trailing whitespace (ignored by
  ``JSON.parse``), which makes each event flush immediately.
- **NullResponse.** Having written output directly, the controller returns
  :php:`\TYPO3\CMS\Core\Http\NullResponse`, which
  :php:`AbstractApplication::sendResponse()` skips — TYPO3 emits nothing
  further, avoiding a double body or a headers-already-sent warning.
- **Same UTF-8 guard.** Each line is encoded with
  ``JSON_INVALID_UTF8_SUBSTITUTE`` (as :ref:`ADR-040 <adr-040>` established for
  the batch response), so a malformed byte substitutes rather than aborting the
  stream.

The client reads the response body stream, splits on newlines, parses each
line, appends the step to a live trace and re-renders. If the browser cannot
read a streaming body it falls back to the batch request.

.. _adr-041-consequences:

Consequences
============

- Steps render as they happen; the summary strip fills in live from the
  per-round token counts and finalises on ``done``.
- The 4 KB padding is transfer overhead (a few KB per event) that never reaches
  the user — an accepted cost for reliable incremental flushing across proxies.
- Direct ``echo``/``flush`` plus ``NullResponse`` is deliberately outside the
  PSR-7 body abstraction; it is confined to the one streaming method and the
  batch path stays a normal response.
- A run whose final model round stops on ``finishReason: length`` is now
  flagged with a truncation banner, and **Max tokens**/**Temperature** are
  exposed as per-run controls so the operator can lift the cap.
