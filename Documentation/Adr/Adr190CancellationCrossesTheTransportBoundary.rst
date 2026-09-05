.. include:: /Includes.rst.txt

.. _adr-190:

======================================================================
ADR-190: Cancellation crosses the transport boundary as a signal
======================================================================

:Status: Accepted
:Date: 2026-09-05
:Amends: :ref:`ADR-161 <adr-161>` — its cancellation row and decision 3, which
    recorded this as a gap and said it needed its own record
:Extends: :ref:`ADR-103 <adr-103>` (cooperative cancellation at step
    boundaries) and :ref:`ADR-111 <adr-111>` (the in-flight write fence)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-103 <adr-103>` gave an operator a cancel that works between steps: the
run row moves to ``CANCELLED``, the loop reads that at the next step boundary and
stops. An HTTP call already on the wire never learned about it. A cancelled run
therefore ended while the MCP request it had started ran to its own deadline —
under :ref:`ADR-170 <adr-170>` up to the whole operation budget, about 45
seconds — with an operator watching a run they had stopped.

:ref:`ADR-161 <adr-161>` recorded that gap deliberately rather than papering
over it, and pinned it structurally: its conformance suite asserted that
:php:`McpClient` and :php:`McpHttpTransport` took no cancellation collaborator
and no cancellation argument, so the check would fail the day such a seam
appeared. It also named the three things that had to be decided before one
could: an async request plus a poll loop, what a half-sent ``tools/call``
means for a non-idempotent remote write, and a bound on how long cancellation
itself may take.

Two of them were not this extension's to decide. PSR-18 returns a response and
never a handle, and Guzzle's synchronous branch settles its promise before it
exists, so ``cancel()`` is a no-op there. Reaching a real abort needs
``CurlMultiHandler`` and a reference to the loop it runs on — both private to
nr-vault, and rightly so: going around it would drop credential injection, the
request-time SSRF DNS pin and the audit write. nr-vault 0.16.0 supplies the
primitive.

Decision
========

1. **Cancellation crosses the boundary as a caller-owned signal, never as a
   promise.** nr-vault's :php:`CancellableHttpClientInterface` takes a
   :php:`CancellationSignalInterface` — one method, ``isCancelled()`` — and
   returns a PSR-7 response. No transport type crosses it in either direction.
   This extension therefore never holds a Guzzle promise, a handler or a
   client it could tick itself, and a later transport change inside nr-vault
   is not a change here. That shape is nr-vault's decision; adopting it rather
   than asking for a handle is ours.

   Feature-detected, not version-gated: the interface is additive, so
   :php:`McpHttpTransport` asks
   ``$client instanceof CancellableHttpClientInterface && $client->supportsCancellation()``
   and otherwise sends exactly as before. A platform without ``curl_multi`` and
   a caller outside a persisted run take the same blocking path they always
   took.

2. **The signal asks the same question the step boundary asks.**
   :php:`AgentRunCancellationSignal` reads the run row and compares its status
   to ``CANCELLED`` — the identical probe :php:`AgentRunExecutor` runs between
   steps. One definition of "cancelled", not a second one beside it that can
   drift.

   Three properties follow from the poll rate and from the interface's contract,
   and each is pinned by a test:

   - **Throttled.** nr-vault polls up to ten times a second per in-flight
     request. The row is read at most once per second, so observing a state an
     operator changes by hand costs one indexed read per second rather than ten.
   - **Fail-soft.** ``isCancelled()`` must not throw: an exception would escape
     mid-transfer, after the credential had gone out. It cannot —
     :php:`AgentRunPersister::findRun()` catches ``Throwable`` and answers null,
     which reads as "not cancelled". A store hiccup must never fabricate a
     cancellation either, which is the rule the executor's own probe already
     follows.
   - **Monotonic.** Once true it stays true without reading again. A run that
     somehow left ``CANCELLED`` cannot un-cancel a transfer already torn down,
     and re-reading would only add a way to answer differently twice about one
     transfer.

   The first call always reads. nr-vault asks before it touches the secret and
   refuses there under ``http_call_cancelled_before_send``, so a signal that
   opened its window at construction would answer false on entry and let a
   credential go out for a run that was already cancelled — turning the cheap
   case into the expensive one.

3. **The bound on cancellation is about a second.** One second of signal
   throttle plus nr-vault's tick interval of a tenth, with its wall-clock budget
   as the backstop that only fires if the handler misbehaves. That is the answer
   to the third question :ref:`ADR-161 <adr-161>` left open, and it is the
   difference between ending a call and waiting out a 45-second deadline.

4. **A half-sent ``tools/call`` is not retried, and nothing here pretends to
   know whether the write landed.** Every imported MCP tool declares
   ``NON_IDEMPOTENT_WRITE``, so a transfer torn down mid-flight may or may not
   have performed a remote mutation. Three existing rules already answer what
   follows, and this record changes none of them:

   - ``CANCELLED`` is terminal. :php:`AgentRunPersister::cancel()` drops the
     run's resumable state and refuses a run that is already terminal, so no
     resume, approval or retry path in this extension accepts one.
   - The fence stays stamped. The cancellation branch persists the step and
     stops; it does not reach the clear in
     :php:`AgentRunExecutor::renewOrClearFence()`, so the row keeps the
     pending-effect mark that :php:`AgentRuntime::mayRetryAfterFence()` reads as
     "do not retry".
   - The operator gets rows rather than a guess. nr-vault distinguishes the two
     cases itself: ``http_call_cancelled`` when the transfer was in flight, so
     the credential went out, and ``http_call_cancelled_before_send`` when the
     signal was already true on entry, so no secret was read and nothing
     egressed. This extension claims neither; the run's own step carries the
     cancelled-call text and nr-vault's row says which of the two it was.

   What is deliberately NOT decided here is compensation. Asking the remote
   server what happened, or undoing it, needs a protocol MCP does not have.

5. **The tool level still reports this as a failed tool result.** The transport
   raises its own :php:`McpTransportException` variant and
   :php:`McpTool::execute()` returns it as ``ToolResult::error()``, the way it
   returns every transport fault. The text says cancelled rather than failed,
   because it is the sentence the model sees and the server may have been
   perfectly healthy. Giving the tool level an outcome of its own — a
   cancellation that is not an error — is a separate change with its own effect
   on the frozen surface, and belongs in its own record.

Consequences
============

- ``netresearch/nr-vault`` is required at ``^0.16.0``.
- :php:`McpClient::callTool()`, :php:`McpHttpTransport::call()` and
  :php:`McpHttpTransport::notify()` take a nullable cancellation signal as their
  last argument. Neither class is ``@api``; the frozen surface does not move.
- :php:`McpToolProvider` carries an
  :php:`AgentRunCancellationSignalFactory` so :php:`McpTool` — built by hand per
  catalogue row — gains one argument rather than two.
- :ref:`ADR-161 <adr-161>`'s cancellation row and its decision 3 are amended:
  the suite now asserts the seam exists, in exactly the shape above, and still
  fails if a third one appears.
- The handshake carries the signal too, both legs of it: ``initialize`` and the
  ``notifications/initialized`` that confirms it are full round trips to the same
  server under the same operation deadline, so leaving either out would keep the
  stall one leg earlier. That is why the seam list has three entries and not
  one.
