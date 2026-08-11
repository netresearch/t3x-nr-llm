.. _adr-154:

============================================================
ADR-154: An MCP server's liveness is observed, not inferred
============================================================

:Status: Accepted
:Date: 2026-08-11

Context
=======

:ref:`ADR-116 <adr-116>` moved the MCP client into nr_llm and closed with a
promise: the public surface that lands with it "will carry its own ADR when the
implementation is designed". That record was never written. This is it, scoped
to what the implementation actually grew — the operator-facing surface of the
MCP client, not a general API freeze.

The gap it closes is narrower and more embarrassing than an API question. Before
this change, ``tx_nrllm_mcp_server`` stored ``import_status``, ``import_error``,
``last_imported`` and ``tool_count``, and nothing else about the far side. All
four are written in one place, :php:`McpServerRepository::recordImportOutcome()`,
called only by :php:`McpImportService`. So:

- A server that has been answering ``tools/call`` for six weeks reads as
  untouched since whenever its catalogue was last imported. Nothing in the
  installation measured, stored or displayed that it answered.
- The only way to find out whether a server was reachable was to run the
  catalogue import — the one action that also **rewrites the catalogue** and can
  orphan tools. "Is it up?" and "re-read what it offers" were the same button.
- ``import_status`` declared a value, ``importing``, in TCA and in both language
  files that no code path has ever written.

An operator debugging a failing agent run therefore could not distinguish "the
server is down" from "the server is fine and the tool was renamed" without
performing a write.

Decision
========

1. **Two columns, written on every successful client round trip.**
   ``last_contact`` and ``last_latency_ms`` on ``tx_nrllm_mcp_server``. Any
   completed operation stamps them: a tool call, a catalogue import, a
   connection test. They answer "when did this installation last get an answer
   out of this server, and how long did it take" — which is a different
   question from "when was its catalogue last read", and needs its own storage
   because the existing column cannot be widened without lying about imports.

2. **The seam is the client, not the transport and not the repository.**
   :php:`McpHttpTransport` sees every round trip — and one operation is three to
   fifty-two of them, because each opens with a handshake and a catalogue walk
   pages. A transport that recorded would either write per round trip or keep
   mutable per-server state on a DI singleton, which is the exact shape its own
   docblock warns against for the authenticated client. So the transport
   *measures* and returns ``durationMs``; :php:`McpClient` owns the operation
   and records once, with the latency of the round trip that completed it (the
   last catalogue page, the ``tools/call``, the handshake). A failed operation
   records nothing: half a catalogue walk is not a contact.

3. **The write cannot fail the call.** :php:`McpHealthRecorder` wraps the
   repository, pins the timestamp from the request context and swallows every
   :php:`Throwable` into a warning. This sits behind a tool call that has
   already succeeded and whose answer a backend user is waiting for. A locked
   table or an unmigrated column must cost a stale timestamp, never the answer.
   The functional test asserts exactly that, with a connection pool that
   throws.

   It writes two columns and no more. ``tstamp`` stamps an operator edit and is
   left alone — bumping it on every tool call would make the record list report
   a change nobody made. ``import_status`` is left alone in the other direction:
   reaching a server says nothing about whether its tool list is current.

4. **A connection test that writes no catalogue.**
   :php:`McpClient::ping()` performs the initialize handshake, sends the
   readiness notification the protocol requires, and stops. It reports
   reachability, the latency, the protocol version the *server* chose and the
   server's self-description. It is reachable from the module over its own
   admin-gated AJAX route, beside the import.

   Only the latency is stored. The other three live exactly as long as the
   response that carries them, which decides how they must be shown — see
   decision 5.

   A failure is returned, never stored. ``import_error`` holds the reason the
   last import failed; a probe overwriting it would replace a diagnosis with a
   different diagnosis, and an operator would lose the one they were working
   on. A success *is* stored, because a successful handshake is a contact like
   any other.

   The probe does not require a declared data class, unlike the import. A data
   class governs what a server's tools may see; a handshake classifies nothing
   and returns nothing. Refusing to probe an unclassified server would make the
   first thing an operator wants to check the one thing they cannot.

5. **The module is the reader.** The MCP Servers module shows, per server:
   enabled state, last successful contact and its latency, transport,
   authentication mode, tools discovered, declared data class, approval
   requirement, last import and the last import error. Without a reader the two
   columns would be a declaration nothing consults.

   **The connection test's report is rendered into the card, and that action
   alone does not reload the page.** Every other action in these list modules
   POSTs and reloads, because what it changed is in the row. This one is not:
   three of the five things the probe reports are stored nowhere, so they exist
   only in the response, and a reload — which the shared helper runs in the same
   tick as the success callback — would destroy them before they could be read.
   The alternative is decision 4 in reverse: store the protocol revision and the
   self-description so a reloaded page can show them. That is columns and a
   migration for two facts an operator looks at once, and it would make a
   hostile server's self-description persistent instead of transient. So the
   module writes the report into the server's card, together with the refreshed
   contact line that a reload would have brought. Both strings are composed in
   the controller — the first render and the in-place update read the same one,
   so they cannot drift — and the front end writes them with ``textContent``,
   because part of what they say was written by the far side. A refusal is
   written into the same region, and the region is cleared before the request
   goes out: without that, a probe that stops answering leaves the last
   success standing for the fifteen seconds of the transport timeout and then
   beside its own error toast. The corollary is that the report is gone on the
   next page load, which is correct: it describes one moment, and what outlives
   the moment is the contact date and its latency.

   **Transport is stated, not stored.** HTTP is the only transport this client
   speaks (:ref:`ADR-116 <adr-116>`, "Transports: HTTP only") and a column would
   imply a choice the operator does not have. The readout still says it,
   because it is the first question asked of a server that will not answer.

   **Authentication is shown as configured, not as resolved.** The transport
   falls back to a bearer placement for any value it cannot parse, and the
   column is a plain varchar the TCA select only constrains in FormEngine. So
   the module labels the two placements TCA offers — ``bearer`` and ``header``
   — and prints any other stored value verbatim, marked as one it does not
   offer: if an operator typoed it, seeing the typo is what lets them fix it,
   while seeing the fallback would tell them the configuration is fine.

6. **``importing`` is removed rather than written.** The import runs to
   completion inside the request that starts it — there is no queue, no worker,
   no second reader. No caller could ever observe the state, so writing it would
   be a second UPDATE per import for nobody; and a request that died between the
   two writes would leave a row stuck in ``importing`` for ever, with no reaper
   to clear it. The value is gone from TCA and from both language files. It was
   never in ``ext_tables.sql``, which only carries the ``never_imported``
   default.

Consequences
============

- :php:`McpClient` takes a second constructor argument,
  :php:`McpHealthRecorderInterface`. Required rather than nullable: an optional
  health recorder is one that a mis-wired container silently drops, and the
  axis would fail open with every test still green. The interface exists so the
  unit tests can observe recording without a database; the database claim is
  asserted in the functional suite against real rows.
- :php:`McpHttpTransport::call()` returns a third key, ``durationMs``.
- ``ModuleAction.js`` gains ``post()``, the half of ``postAndReload()`` that
  does not navigate; ``postAndReload()`` is now written in terms of it. The
  connection test is the first action in these modules whose answer is the
  response rather than the reloaded page, and the reload and the reporting
  callback could not both stay in one helper. ``post()`` also takes an
  ``onFailure`` callback, for the same reason ``onSuccess`` exists: a caller
  that paints the answer into the page has to paint the refusal there too. The
  re-enabling of the triggering button stays where it was for the six existing
  consumers — failure only — because a button live again while its reload is
  pending can fire the same state-changing POST twice; ``post()`` re-enables on
  success as well, since nothing navigates away from it there.
- Both AJAX actions of :php:`McpServerController` now resolve the caller and the
  named server through one private helper. The admin gate and the "a uid, not
  something a cast would accept" validation are one implementation, reached by
  both, because both reach an external party on an administrator's behalf.
- The new classes are marked ``@internal`` under :ref:`ADR-127 <adr-127>`. What
  this record freezes is the operator surface — two columns, one route, one
  readout — not a PHP API. The public-service count authority
  (:ref:`ADR-101 <adr-101>`) is unaffected: nothing here is registered public.
- :php:`ToolCallPolicy`'s remote-tool branch — a :php:`RemoteToolInterface` tool
  is refused above the trust-zone ceiling even in ``observe`` mode
  (:ref:`ADR-115 <adr-115>`, quoted in :ref:`ADR-140 <adr-140>`) — gains a
  direct unit test. It was previously exercised only indirectly through the
  governance readout's fake tool, so the load-bearing ``||`` could have been
  deleted with the policy's own suite still green.

Deliberately not decided here
=============================

Each of these is a separate decision, and none is blocked by this one:

- **Retry, backoff and circuit breaking.** A latency number is a measurement; a
  breaker is a policy about when to stop calling, and it needs an owner for the
  half-open state and an answer for what an agent run mid-loop should do.
- **Cancelling an in-flight call.** The 15-second transport timeout is still the
  only bound.
- **Per-tool data class overrides.** The class stays a property of the server
  (:ref:`ADR-094 <adr-094>`).
- **Non-HTTP transports.** :ref:`ADR-116 <adr-116>` rules stdio out on purpose
  and dissolves ``sse`` into HTTP framing.
- **MCP resources, prompts and sampling.** This client declares no capabilities
  precisely so a server cannot invite it into any of them.
