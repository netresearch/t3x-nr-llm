.. _adr-170:

==============================================================
ADR-170: One deadline per MCP operation, spent across its legs
==============================================================

:Status: Accepted
:Date: 2026-08-13
:Amends: :ref:`ADR-154 <adr-154>` (the transport timeout is no longer the bound
    an operation is measured by) and :ref:`ADR-161 <adr-161>` (its conformance
    table gains an operation-level timeout row)
:Authors: Netresearch DTT GmbH

Context
=======

An MCP operation is not one HTTP request. The protocol prescribes an opening
sequence, so :php:`McpClient::callTool()` sends three:

.. code-block:: text

   initialize                 the handshake
   notifications/initialized  its confirmation
   tools/call                 the request that carries the work

:php:`McpHttpTransport` put a 15-second timeout on each of them, because a
transport sees one request at a time and has no notion of the operation around
it. So the bound an operator was told about — "an unreachable server fails while
the answer is still worth having" — was the bound on a *leg*, and the bound on
the operation was that number multiplied by however many legs the protocol
happened to need.

A hard timeout on the first leg still ends early, so the failure is not the loud
one. The expensive case is the slow one: each leg answers just inside its own
limit and the tool call runs to roughly 45 seconds, with a backend user watching
an agent run wait it out. The catalogue walk is worse — :php:`listTools()` walks
up to 50 pages, each with its own 15 seconds.

That window is also where a cancelled run keeps waiting, because nothing on this
path can abort a request in flight (:ref:`ADR-161 <adr-161>`, decision 3). This
record does not change that, and is careful not to look as though it does.

Decision
========

1. **The budget belongs to the operation, and travels with the call.**
   :php:`McpClient` opens one :php:`McpOperationDeadline` per public method and
   passes it to every leg; :php:`McpHttpTransport` grants each request what is
   left of it. The handshake spending six seconds is six seconds
   :php:`tools/call` does not get.

   It is a value handed down rather than state on the transport for the reason
   the transport already builds a fresh client per call: it is a DI singleton on
   a long-lived worker, so a remaining-budget field on it would be shared
   between two operations against two different servers. The deadline is
   likewise not a constructor argument of the client, because the client is a
   singleton too and an operation is not.

   Rejected: keeping the budget in the transport and passing a leg *index* or a
   "this is the last leg" flag. That encodes the client's knowledge of the
   protocol in the class that exists precisely not to have it, and it still
   cannot answer the question that matters — how much time is left.

2. **Time is read through a clock seam, and a monotonic one.**
   :php:`McpClockInterface` returns a monotonic nanosecond reading;
   :php:`McpMonotonicClock` is :php:`hrtime()`, the same source the transport
   already measures a round trip with.

   :php:`Psr\\Clock\\ClockInterface` was rejected rather than added: it returns
   a :php:`DateTimeImmutable`, which is a wall-clock instant, and a wall clock
   stepped by an NTP correction mid-operation would either grant a leg a budget
   nobody authorised or declare it exhausted with seconds to spare. The
   extension's existing time seam — the request-pinned ``date`` aspect
   :php:`McpHealthRecorder` stamps rows with — cannot serve either: it is
   constant for the whole request, so every reading inside one operation is the
   same and nothing ever expires.

3. **An exhausted budget is its own outcome, and is not a cancellation.**
   :php:`McpTransportException::forExhaustedDeadline()` carries its own code and
   a sentence that names what happened: the request was not sent, the number
   that ran out is one this installation chose, and the server was not asked. An
   operator reading ``import_error`` or a failed step can tell that apart from
   every other factory on that class, all of which describe a far side that
   answered badly or not at all.

   It stays the same exception *type*. Every caller still does the same thing
   with it — record it against the server and stop — which is the reason that
   class has one type, and a second type would buy a distinction no catch block
   wants to make.

   The check sits at the top of :php:`send()`, before the request is built and
   outside the ``catch`` that maps a :php:`Throwable` onto "could not be
   reached". Putting it in the client builder instead would have left the PSR-18
   test seam unguarded and would have reported a spent budget as a failing
   server.

4. **A leg is never granted zero seconds.**
   The number goes to
   :php:`VaultHttpClientInterface::withTimeout()`, which treats a non-positive
   value as *no override* and rebuilds the client from
   ``$GLOBALS['TYPO3_CONF_VARS']['HTTP']['timeout']`` — whose TYPO3 default is
   ``0``, and which Guzzle reads as *wait forever*. A leg handed zero would
   therefore be the one leg with no bound at all: the exact opposite of a
   deadline, arriving exactly when the budget is tightest.

   So a remainder is rounded **up** and floored at
   :php:`McpOperationDeadline::MINIMUM_LEG_SECONDS` (one second), and below that
   the operation refuses to send rather than sending unbounded. The overrun this
   admits is under a second per operation.

5. **The budget is 20 seconds, and it is a configuration field.**
   ``mcpOperationTimeout`` in ``ext_conf_template.txt``, not a constant. The
   number is a trade only the installation can make — too low cuts off a server
   that is slow but legitimate, too high keeps a backend user waiting — so it
   belongs where an operator can see and change it.

   20 is composed rather than inherited: 15 seconds is what a single request
   had before this existed, and the 5 on top pay for the two handshake legs in
   front of the work. What that composition does **not** do is guarantee the
   payload leg those 15. There is one budget and the legs spend it in order, so
   ``tools/call`` is granted what the handshake left — 20 minus its elapsed
   time. The test
   :php:`McpClientTest::theLastLegOfAToolCallGetsOnlyWhatTheHandshakeLeft`
   asserts precisely that: a 4-second ``initialize`` and a 2-second readiness
   notification produce ``[20, 16, 14]``, so the payload leg runs for 14 — not
   for 15, and not for a fresh 20.

   The 5 is therefore a wager on the shape of a healthy server, not a floor
   under the payload leg. On a healthy server the wager wins comfortably:
   ``initialize`` answers out of memory and the readiness notification is
   answered with a 202, both far under a second, so the work request is granted
   about 20 — *more* than the 15 it used to have. The wager is lost when the
   handshake alone costs more than 5 seconds. Then the payload leg gets less
   than 15, and a server whose handshake is slow **and** whose work is slow can
   be cut off where a fresh per-request timeout let it through. That is a real
   regression for that one server shape, and it is accepted rather than
   designed away: it is visible in the refusal, which names the budget, and
   raising ``mcpOperationTimeout`` is the answer an operator has. A floor under
   the payload leg would buy it back only by putting the operation's worst case
   above the budget an operator was shown, which is the defect this record
   exists to close.

   Inheriting the existing 15 as a *total* was rejected as strictly worse on
   that same axis: it would leave the payload leg under 15 on *every* multi-leg
   call, not only against a slow-handshaking server.

   An empty, non-numeric or non-positive value falls back to 20; a total below
   the leg floor is raised to it. There is no upper clamp — a long budget costs
   the operator who set it the wait they asked for. That wait is not paid
   gracefully at the far end of the range, and the decision is made knowing it:
   a backend tool call runs synchronously inside the AJAX request, so
   ``mcpOperationTimeout = 600`` against a server that stalls does not end with
   a budget message after 600 seconds. It blocks until a PHP or gateway limit
   ends the request first — ``max_execution_time``, FPM's
   ``request_terminate_timeout``, or the proxy in front — and the backend user
   gets a 500 or a 504 with no body, not the failed tool result
   :php:`McpTool::execute()` writes for every :php:`McpTransportException` it
   catches. So a budget raised far past the installation's PHP limit buys a
   broken request rather than a patient one. The clamp is still refused: the
   ceiling that matters is that PHP limit, this extension does not know it, and
   a number hard-coded against a guess at it would cut off the legitimately slow
   server this field exists for.

.. _adr-170-not:

What this does not do
=====================

**It does not cancel anything.** No :php:`cancel()`, no cancellation token, no
abort path. A request already on the wire runs until the server answers or its
own timeout fires; what this decides is what the *next* leg is granted, and
whether it is sent at all. The stall is reduced, not removed — a cancelled run
still waits out the leg in flight, now bounded by the operation's remaining
budget rather than by a fresh 15 seconds.

Cancelling in flight is
`#774 <https://github.com/netresearch/t3x-nr-llm/issues/774>`__ and is
unchanged by this record: it needs a seam through the vault HTTP client
that does not exist, which is why :ref:`ADR-161 <adr-161>` pins the gap
structurally rather than behaviourally. That structural check still passes —
nothing added here reads as a cancellation seam — and it must keep failing the
day one appears.

**It does not add retry, backoff or circuit breaking.** A budget says when to
stop waiting, not when to stop calling. :ref:`ADR-154 <adr-154>` left that open
and it stays open.

**It does not change what a timed-out call looks like to a run.** A connection
that dies still becomes a failed tool result naming the server, the model is
told, and the loop carries on.

Consequences
============

An installation that changes nothing gets a tool call bounded at 20 seconds
instead of about 45, and a catalogue import bounded at 20 seconds instead of a
page ceiling times a per-request timeout — in both cases plus the sub-second
overrun decision 4 admits.

The import is where a real installation can notice this. A catalogue that walks
several pages against a slow server now spends one budget across all of them,
and can be refused where it previously succeeded slowly. The refusal names the
budget and the server, and raising ``mcpOperationTimeout`` is the answer; the
alternative — a per-operation bound that a paginating server can extend
indefinitely — is the defect this record closes.

:php:`McpClient` takes a third constructor argument and
:php:`McpHttpTransport::call()` / :php:`::notify()` a fourth. Neither class is
part of the ``@api`` surface (:ref:`ADR-127 <adr-127>`), so no frozen signature
moves; an integrator constructing either by hand does have to pass the new
collaborator.
