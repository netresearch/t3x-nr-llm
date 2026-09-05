.. _adr-161:

==================================================================
ADR-161: One conformance suite for every MCP connection we support
==================================================================

:Status: Accepted (the timeouts row now covers the whole operation — see
    :ref:`ADR-170 <adr-170>`)
:Date: 2026-08-11
:Amended: 2026-08-13 by :ref:`ADR-170 <adr-170>`; 2026-08-20 by
    :ref:`ADR-181 <adr-181>` (the "no SSE" edge is now "no live stream");
    2026-09-05 by :ref:`ADR-190 <adr-190>` (the cancellation gap is closed --
    the row and decision 3 below are amended, not withdrawn)

Context
=======

:ref:`ADR-116 <adr-116>` put an MCP client in this extension and drew its
edges: HTTP only, no stdio, no SSE, no resources, prompts or sampling.
:ref:`ADR-154 <adr-154>` gave an operator a way to see whether a configured
server is alive. Between them the client is well covered by class — the
transport has its own tests, the client has its own tests, the schema
normaliser has its own tests — and covered by nothing that asks the question an
operator actually has:

    Is what this installation supports as an MCP client fully policy-, audit-
    and health-integrated?

That is a different question from "does the client work", and it is the only
one worth a conformance suite. A per-class suite answers "does `listTools()`
paginate"; it cannot answer "does a remote tool reach the trust-zone gate as a
remote tool", and it cannot notice that two of the checks below were quietly
false.

The scope here is deliberately narrow and is **not** "nr_llm can do everything
MCP can". It is: everything nr_llm supports as an MCP client is fully policy-,
audit- and health-integrated.

Decision
========

1. **One suite, one case per connection.**
   ``AbstractMcpConformanceTestCase`` holds every check. A concrete case
   supplies an ``McpConnectionProfile`` and nothing else, and the whole list
   has to pass for it. Two profiles ship: a stateless HTTP server that issues
   no session, and one that issues a session id — with different data classes
   and opposite approval declarations, so the classification checks are not
   silently asserting one constant twice.

   A profile carries only what legitimately differs between two servers this
   client speaks to: what the operator configured, and what the server does
   about session state. Every response, failure and bound is scripted
   identically for all profiles, because a check that varied with the
   connection would not be a conformance check. Adding a transport later means
   adding a profile and a three-line subclass.

   **No live network.** Everything runs through a faked PSR-18 client, so the
   real JSON-RPC encoding, the real status handling and the real handshake
   ordering are exercised rather than a description of them. There is
   deliberately no *authenticated* profile: authentication happens in
   :php:`McpHttpTransport::clientFor()`, which that seam bypasses by design, so
   an authenticated profile would send identical bytes and assert nothing about
   the credential. The one check that needs that method reaches it directly.

2. **The checklist, and what each item is.** Every item is covered here,
   covered elsewhere and named, or out of scope and named. Nothing is listed
   as covered because a test exists with a matching word in its name.

   .. list-table::
      :header-rows: 1
      :widths: 26 74

      * - Check
        - Where it stands
      * - connect
        - Covered. The handshake, the readiness notification the protocol
          requires, and the server's session decision carried through the whole
          operation — including the absence of a session header when the server
          issued none.
      * - capability discovery
        - Covered, in both directions. This client declares none, because
          declaring one invites the server to use it; what the server declared
          about itself is read and reported, protocol revision included.
      * - tool discovery
        - Covered: the paginated walk, resumed from the cursor, and the page
          ceiling that ends a cursor which never resolves.
      * - JSON schema normalization
        - Covered, on a schema that came off the connection rather than a
          literal: the five retained keys survive, the annotations around them
          do not.
      * - tool execution
        - Covered: the remote name goes on the wire, the local name is what the
          gate and the model see, and the result comes back as a
          :php:`ToolResult` — an error one when the server set the protocol's
          own ``isError``, which is how a working server reports a tool that
          failed.
      * - timeouts
        - Covered on both halves: the transport puts a finite timeout on the
          client it builds (asserted against that client, not against a
          constant), and a connection that never answers becomes a failed tool
          result rather than an escaping exception. Since
          :ref:`ADR-170 <adr-170>` the row covers the OPERATION as well: one
          budget is spent across the legs, an exhausted budget is its own
          outcome, and no leg is ever granted a timeout that would mean none.
      * - cancellation
        - **Closed by** :ref:`ADR-190 <adr-190>`. Still pinned structurally
          rather than behaviourally, for the reason decision 3 gives: the suite
          now asserts the seam EXISTS, in exactly the three places it belongs,
          so a fourth one still fails the check.
      * - server failure
        - Covered across eight shapes — 5xx, 3xx, 401, a JSON-RPC error object,
          a maintenance page, an event stream, a body with neither result nor
          error, an empty body — all ending as one failed tool result naming
          the server, with no health contact recorded.
      * - invalid schema
        - Covered at both ends of the same rule: a schema that cannot be
          represented is rejected whole rather than repaired, and a stored
          schema that no longer decodes to a JSON object has no schema to
          offer. That such a row yields no registered tool while the row itself
          survives is :php:`McpToolProvider`'s decision, which a pack built
          around one connection cannot reach; it is covered over real rows in
          ``McpImportServiceTest``.
      * - oversized response
        - Covered: a 3 MiB body is refused at the 2 MiB read cap, and what the
          far side sent does not become the message we repeat.
      * - audit
        - Covered, and it needed a decision and a fix. See decision 4.
      * - data classification
        - Covered here as the tool's own declaration: the class and the
          approval requirement travel on the tool, so the gate reads them
          without a second lookup, and nothing the server sent produces either.
          That the declaration is taken from the operator's server row and not
          from the annotations the server wrote about itself is again
          :php:`McpToolProvider`'s decision, and is asserted over real rows in
          ``McpImportServiceTest``: a tool whose annotations claim
          ``publicContent`` on a server the operator declared
          ``internalConfiguration`` resolves as internal configuration.
      * - trust-zone enforcement
        - The gate's own branch — a :php:`RemoteToolInterface` tool is refused
          above the ceiling even in ``observe`` mode — is covered by
          ``ToolCallPolicyTest`` (:ref:`ADR-154 <adr-154>`) and is not repeated
          here. What this suite adds is the other side of that seam: an MCP
          tool presents itself to the gate as the thing the branch keys on,
          with the classification the gate compares and the admin requirement
          it applies first.

3. **Cancellation is a gap, and the suite says so.**

   *Superseded by* :ref:`ADR-190 <adr-190>` *on 2026-09-05. The four paragraphs
   below record why this was left open and what the suite asserted while it was.
   They describe the state up to that date and are kept because the amendment at
   the end of this decision only reads against them; for what holds now, read
   that amendment.*

   :php:`AgentRuntime::cancel()` flips persisted state and the loop stops at the
   next step boundary. The transport has no abort path, so a cancellation
   raised while a request is in flight changes nothing about that request: the
   run waits the leg out, bounded only by a timeout — the 15-second per-request
   one when this was written, the whole-operation deadline of
   :ref:`ADR-170 <adr-170>` since (:ref:`ADR-154 <adr-154>` already listed this
   under what it did not decide).

   The suite does not fake a passing cancellation test, and it does not pretend
   to a behavioural one either. There is no behaviour to assert: a cancellation
   cannot reach an in-flight call because nothing on this path can be told
   about one. So what the suite asserts is that absence, structurally —
   :php:`McpClient` and :php:`McpHttpTransport` take no cancellation
   collaborator, no cancellation argument, and expose no method to abort what
   is open.

   That check FAILS the day such a seam appears, which is the only property
   worth having here. The rejected alternative was a fake client running a
   closure mid-request: it looks behavioural, but the closure has nothing
   cancellable to raise, so the check would have stayed green through the very
   change it claimed to guard. A check whose name promises more than it asserts
   is worse than the prose it replaces.

   It is not built here because it is not one change. Guzzle's synchronous
   client has no cancellation, so it means an async request plus a poll loop, a
   decision about what a half-sent ``tools/call`` means for a non-idempotent
   remote write, and a bound on how long cancellation itself may take. That is
   its own record.

   **Amended 2026-09-05.** That record is :ref:`ADR-190 <adr-190>`, and it
   answers all three: nr-vault owns the poll loop and hands this extension a
   signal rather than a promise; a cancelled run is terminal and its write fence
   stays stamped, so a half-sent call is never retried; and the bound is about a
   second. The check above is inverted rather than deleted --
   :php:`McpClient::callTool()` and the two transport sends it drives,
   :php:`McpHttpTransport::call()` and :php:`McpHttpTransport::notify()`, are now
   the places a cancellation may enter, and the suite asserts that list exactly.
   The property worth having is unchanged: a seam appearing anywhere else fails
   it.

4. **The run's audit IS the audit; the MCP client keeps none of its own.**

   The premise worth checking was that a :php:`ToolLoopService` run without an
   :php:`AgentRunPersister` executes MCP tools and writes nothing to
   ``tx_nrllm_agentrun_event``. That is true of the bare wiring — and it is not
   reachable through the runtime, because of a guarantee that already exists:

   - :php:`McpTool::getEffect()` is ``NON_IDEMPOTENT_WRITE`` for every imported
     tool, a pure search included (:ref:`ADR-116 <adr-116>`).
   - :ref:`ADR-141 <adr-141>`'s fence refuses a write-effect tool in a segment
     that holds no persisted run and no lease, **before** the call happens.
   - :ref:`ADR-111 <adr-111>`'s audit is fail-closed for a write step: a tool
     step that cannot be stored fails the run.

   So "an MCP tool executed and nothing was recorded" is not a state the
   runtime can reach. That is asserted, not reasoned about: ``McpRunAuditTest``
   drives a real run through the real persister and finds the TOOL event, and
   drives a second one whose run row cannot be written and finds that the
   server received nothing at all.

   Two things therefore stay as they are, deliberately:

   - **The MCP client writes no audit rows of its own.** A second audit stream
     beside the run's would answer the same question in two places and disagree
     the first time one of them failed. What the client does write is liveness
     (:ref:`ADR-154 <adr-154>`), which is a different fact with a different
     reader.
   - **``tx_nrllm_governance_event`` stays denial-only.** It records what policy
     refused. An executed call is not a policy event, and turning that table
     into a second call log would make "which direction leaks" — the question
     it exists for — a query over a mixture.

   One thing did change, on both of the two paths a remote call fails on.
   :php:`McpTool::execute()` returned a transport failure as ordinary TEXT, so a
   server that was down was persisted as a **successful** tool step whose
   content happened to read like an error. The same held — and mattered more —
   for the protocol's own ``isError``, which is how a *working* server reports
   that the tool failed: :php:`McpClient::callTool()` folded it into a prefixed
   sentence, and the flag was lost at the return type. It now returns an
   :php:`McpCallOutcome` carrying the flag, and both paths produce an error
   result. Nothing about the run's control flow changes — the loop still carries
   on, the model is still told plainly what failed — but "how often does this
   server fail" now has an answer that is not a string search, for the common
   failure as well as the loud one.

5. **A dropped content block is named, not swallowed.**
   MCP lets a server answer with typed content blocks. This client reads text
   and cannot carry an image or an embedded resource, which is correct — but it
   said nothing, so a model handed a partial answer could not tell, and a model
   handed only non-text blocks was told the tool "returned no textual content"
   about a call that in fact returned an image.

   The answer now **begins** with a note counting what was dropped and naming
   the types. It leads rather than trails because a tool result is cut to a byte
   bound before the model sees it (:php:`ToolResultBounder`, 50 000 bytes), and
   a trailing note is removed by exactly the cut that makes the answer partial —
   losing the sentence on the long answers where being told is worth most. The
   note is ours, not the server's, and contains none of its bytes: a dropped
   block is named by matching its type against the protocol's own four non-text
   types (``image``, ``audio``, ``resource``, ``resource_link``) and anything
   else is ``other``. Sanitising and clipping the remote string was the first
   attempt; it bounds the note's length but not its authorship, and a server
   that invents a type per block would have been writing words into a sentence
   the model reads as ours. The count stays exact, so such a server moves the
   number and nothing else. Rendering the blocks
   instead is the other decision, and it is not this one: it needs a data
   class for binary content the operator never declared, and a place to put
   bytes that a tool result has no channel for.

Consequences
============

- Two production changes ride with the suite, both listed above: a failed
  remote call is an error result, whether the wire failed or the tool did
  (decision 4), and a dropped content block is reported (decision 5). The
  first changes :php:`McpClient::callTool()`'s return type from :php:`string`
  to :php:`McpCallOutcome`. That is a signature change, but not a break of the
  frozen surface: neither the class nor the method appears in
  ``Tests/Unit/Api/api-surface.txt``, and :php:`McpTool` is its only production
  caller.
- The suite is 34 checks per connection and runs in well under a second: it
  drives a faked PSR-18 client, so a run costs no network and no database. It
  is unit-level, and the checks that are claims about rows live in the
  functional suite instead and are named where the table lists them: the audit
  half in ``McpRunAuditTest``, and the two the catalogue resolves —
  classification and an undecodable stored schema — in
  ``McpImportServiceTest``. A per-connection pack cannot assert them: it holds
  one connection and builds its tool directly, so it can pin what a tool
  declares but not what turned a row into that declaration.
- ``AbstractMcpConformanceTestCase`` is named ``…TestCase``, not ``…Test``,
  because ``Build/phpunit.xml`` globs ``*Test.php`` and PHPUnit turns an
  abstract match into a runner warning — which ``failOnWarning`` turns into a
  red suite.
- A new transport does not get a new suite. It gets a profile and a subclass,
  and the existing list decides whether it is supported.
- The adapter conformance suite (ADR-160, a sibling change at the time of
  writing) is the shape this follows: an abstract case, per-scenario fixtures,
  a faked client at the bottom. The two suites share no code, because a
  provider adapter and an MCP connection have no common contract to abstract —
  what they share is the discipline.

Deliberately not decided here
=============================

- **Cancelling an in-flight call.** Decision 3 says why. The check that asserts
  the seam does not exist is the entry price for building one: it goes red on
  the first commit that adds it, and this record has to be revisited.
- **Retry, backoff and circuit breaking.** Unchanged from
  :ref:`ADR-154 <adr-154>`.
- **Rendering non-text content blocks.** Decision 5.
- **Non-HTTP transports, resources, prompts and sampling.** The edges
  :ref:`ADR-116 <adr-116>` drew; this record holds the client to what is inside
  them rather than widening them.
- **A per-server call log.** Liveness answers "is it up" and the run's event
  stream answers "what did it do". A third table between them has no reader.
