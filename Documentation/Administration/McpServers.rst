..  include:: /Includes.rst.txt

..  _administration-mcp-servers:

===========
MCP servers
===========

The :guilabel:`MCP Servers` module (admin-only) connects agent runs to
tools offered by an external `Model Context Protocol
<https://modelcontextprotocol.io/>`__ server — a translation service, a
ticket system, any MCP-speaking backend.

How it works
============

1. **Configure a server** — endpoint, authentication, the class of data
   its tools may see, and whether its tools need human approval. A server
   that declares no data class supplies **nothing**: there is no default
   anybody silently inherits (fail-closed, :ref:`ADR-113 <adr-113>`).
2. **Import its catalogue** — an explicit action that fetches the tools
   the server advertises. Import is the only network call that happens
   outside an agent run; nothing talks to the server just because a page
   rendered. Tool input schemas are normalised into the supported subset
   on import; a tool whose schema cannot be expressed is skipped rather
   than silently weakened.
3. **Enable individual tools** — imported tools start disabled and are
   switched on one by one, exactly like the builtin tools in the
   :ref:`Tools module <administration-tools>`.

What the client speaks
======================

Plain HTTP: one POST per JSON-RPC message to the server's endpoint, offering
both media types the Streamable HTTP transport requires (``application/json``
and ``text/event-stream``). A server may answer a request as plain JSON or as
a single event-stream framed message; both read the same here. What the
client does **not** do: hold a stream open, resume one, answer a request the
server initiates, or speak stdio (:ref:`ADR-116 <adr-116>`,
:ref:`ADR-181 <adr-181>`).

Is the server alive?
====================

:guilabel:`Test connection` performs the MCP handshake and reports what came
back: how long it took, which protocol revision the server chose and what the
server calls itself. The report appears on the server's card and stays there
until you leave the page — only the latency is stored, so the rest would be
lost to a page reload, and this is the one action in the module that therefore
does not reload. It **writes no catalogue** — no tool is added, removed or
orphaned, and the import status and the last import error stay exactly as they
were. Use it to check a server before enabling it, and to tell "the server is
down" from "the server is fine and this tool is gone".

Each server card also shows **Last successful contact**. That is the last time
this installation completed *any* round trip against the server — a tool call,
an import or a connection test — together with how long that round trip took.
It is deliberately not the same as :guilabel:`Last import`: a server that has
been answering tool calls all month can still show an import from six weeks
ago, and previously there was no way to see the difference.

A failed connection test replaces the report on the card with its reason, and
is stored nowhere. Only a success moves the contact date.

Guard rails
===========

- Remote tools always require an administrator, and count as
  non-idempotent **writes** — unconditionally, whatever the server's
  catalogue says about them: they are never replayed on a retry, and
  never waved through the trust-zone gate in observe mode. That the
  classification cannot be argued down from the far side is what makes
  the setting below the only place the question is answered.
- :guilabel:`Requires approval` decides whether an agent run stops and
  waits for a person before it calls any tool of this server. It is on
  for a newly configured server: what a remote tool actually does cannot
  be inspected from here, and the server's own annotations do not get to
  answer the question about themselves (:ref:`ADR-134 <adr-134>`). Switch
  it off per server once you know what its tools do. This holds for every
  server, including one configured before the setting existed: an update
  leaves it requiring approval, and nothing switches that off on your
  behalf.
- The number of remote calls one run may make is bounded (default: 20)
  — a remote call crosses the network while a backend user waits, and
  nothing else limits how many a model asks for at once.

What a remote answer can contain
================================

An MCP server may answer a tool call with several typed blocks: text,
images, embedded resources. This client reads **text only**. When a
server sends anything else, the blocks are dropped and the answer opens
with a line saying how many were dropped and of which type — so a model
reading a partial answer is told it is partial, and a run whose tool
returned only an image is not told the tool returned nothing. The line
comes first because a long answer is shortened before the model sees it,
and a note at the end would be the part that is cut. If you need the
image itself, the tool is not usable from here yet.

A call that fails does not fail the run. It comes back as a failed tool
result naming the server, the model is told, and the run carries on.
This covers both ways a call fails: the server not answering usefully —
it is down, it refuses the credential, it sends something that is not
JSON-RPC — and the server answering that the tool itself failed, which
is the ordinary case of a missing page or a rejected argument. Both are
recorded as failures in the run's event stream, so a server that is
flaky is visible without reading transcripts.

How long one operation may take
===============================

An operation against an MCP server — a tool call, a catalogue import, a
connection test — is several HTTP requests: the protocol handshake, its
confirmation, then the request that carries the work. All of them share
**one** budget, set by the extension configuration field *MCP operation
budget (seconds)* (``mcpOperationTimeout``, 20 seconds by default). What
the handshake spends is no longer available to the request behind it, and
a large catalogue walks its pages under the same budget.

Raise it for a server that is legitimately slow — including one that is
slow to *open*: a handshake costing more than five seconds leaves the work
request less than the fifteen a single request used to have, so a server
that is slow at both ends can be refused where it previously succeeded.

When the budget runs out
the operation stops with a message naming the number and the server, and
saying plainly that nothing was asked of the server — that is this
installation's budget, not a server that failed to answer.

See :ref:`ADR-116 <adr-116>` for the design rationale,
:ref:`ADR-154 <adr-154>` for what liveness is measured on and why the
connection test writes nothing, :ref:`ADR-170 <adr-170>` for the operation
budget, and :ref:`ADR-161 <adr-161>` for the conformance suite every
supported connection is held to — including the one thing it does **not**
do: cancelling a call that is already in flight. Cancelling a run stops it
at the next step, but an outstanding remote call still runs until the
server answers or the operation's remaining budget is gone.
