.. include:: /Includes.rst.txt

.. _adr-116:

============================================================================
ADR-116: Central tooling authority — nr_llm owns builtin + MCP tools
============================================================================

:Status: Accepted (its transport section no longer refuses an event-stream framed
    answer — see :ref:`ADR-181 <adr-181>`)
:Date: 2026-07-22
:Amended: 2026-08-20 by :ref:`ADR-181 <adr-181>`
:Authors: Netresearch DTT GmbH

.. _adr-116-context:

Context
=======

Two tooling systems exist in the stack today, and only one of them is nr_llm.

nr_llm is already the tooling authority for its own agent runs. It ships ~45
builtin tools under ``Classes/Service/Tool/Builtin/`` (among them
``FetchLogsTool``, ``GetLastExceptionTool``, ``ListDeprecationsTool``,
``GetSystemStatusTool``, ``SiteRagQueryTool`` and ``GetPageContentTool``),
collects them in ``ToolRegistry`` (``Classes/Service/Tool/ToolRegistry.php``),
and executes them through ``ToolLoopService`` (:ref:`ADR-038 <adr-038>`) behind
the tool gate (:ref:`ADR-093 <adr-093>`) and the global availability state
(:ref:`ADR-039 <adr-039>`). ``AgentRuntime`` (:ref:`ADR-101 <adr-101>`,
``Classes/Service/Agent/``) is the public application service that owns the
run lifecycle — approval (:ref:`ADR-084 <adr-084>`), guardrails
(:ref:`ADR-085 <adr-085>`) and context-window bounding
(:ref:`ADR-107 <adr-107>`) — with the interface
``run`` / ``enqueue`` / ``runQueued`` / ``approve`` / ``submitInput`` /
``cancel`` / ``events`` / ``status``.

The separate ``nr_mcp_agent`` extension is a **second, parallel** tool stack
that bypasses all of the above. It is itself an MCP client
(``Classes/Mcp/McpToolProvider``, ``Classes/Mcp/McpConnection`` — a stdio
JSON-RPC client over ``proc_open`` — and ``McpServerRepository`` over the
``tx_nrmcpagent_mcp_server`` table, whose ``transport`` field offers ``stdio``
and ``sse``). It **re-implements its own agent loop** in
``ChatService::runAgentLoop`` and sources tools *only* from external MCP
servers (e.g. the suggested ``hn/typo3-mcp-server`` dependency), mapping the
MCP wire shape onto nr_llm's ``ToolSpec``. It uses nr_llm merely as a
completion provider (``ProviderInterface``), never its ``ToolRegistry`` or
``AgentRuntime``.

The result is two agent loops with two independently maintained sets of
fail-closed, approval, guardrail and context rules, and two disjoint tool
sources (builtin-only in nr_llm, MCP-only in nr_mcp_agent) that no consumer
can obtain together.

.. _adr-116-decision:

Decision
========

nr_llm is **the single tooling authority** for the AI stack. All tooling —
builtin and MCP — is aggregated and executed there; consumers never reach an
MCP server directly.

- **Add an MCP client to nr_llm.** nr_llm gains the ability to connect to
  external MCP servers over HTTP, list their tools, and register
  those tools into ``ToolRegistry`` **alongside** the builtin tools. MCP tools
  then flow through the exact same path as builtins: ``ToolRegistry`` →
  ``ToolLoopService`` / ``AgentRuntime``, subject to the same tool gate
  (:ref:`ADR-093 <adr-093>`), the same availability state
  (:ref:`ADR-039 <adr-039>`), the same approval (:ref:`ADR-084 <adr-084>`),
  guardrail (:ref:`ADR-085 <adr-085>`) and context-window
  (:ref:`ADR-107 <adr-107>`) enforcement.
- **Consumers obtain all tooling exclusively via nr_llm.** A consumer (for
  example a backend AI-chat module) takes its tools from ``ToolRegistry`` and
  drives runs through ``AgentRuntime``. It **never** opens an MCP connection
  itself. MCP servers are wired *only* through nr_llm.
- **One loop, one trust boundary.** There is a single agent loop
  (``AgentRuntime`` over ``ToolLoopService``). Builtin and MCP tools share the
  one tool-data trust zone (:ref:`ADR-094 <adr-094>`) and the one gate, so a
  tool's origin does not change how it is authorised, approved or audited.

.. _adr-116-consequences:

Consequences
============

- The MCP-client capability moves **out of** nr_mcp_agent and **into** nr_llm:
  the connection, tool-listing and schema-normalisation logic that today lives
  in ``McpToolProvider`` / ``McpConnection`` becomes an nr_llm concern, and the
  server-configuration storage (transport, command/arguments, url/auth token)
  moves with it.
- ``ToolRegistry`` becomes the aggregation point for builtin **and** MCP tools;
  the allow-list, availability toggle and gate apply uniformly regardless of
  where a tool came from.
- ``AgentRuntime`` is the single agent loop. ``nr_mcp_agent`` **deletes**
  ``ChatService::runAgentLoop`` and stops assembling its own lifecycle; its
  divergent fail-closed / approval behaviour disappears with it.
- ``nr_mcp_agent`` is reduced to a thin backend chat UI — module, toolbar and
  conversation store — driving ``AgentRuntime``. With MCP gone it is arguably
  mis-named: a rename (candidate ``nr_llm_chat``) or an outright fold-in to
  nr_llm are both on the table (see follow-up).
- ``hn/typo3-mcp-server`` becomes *an* MCP server that nr_llm connects to, not
  a per-consumer composer dependency; any number of external MCP servers attach
  the same way.
- New public surface lands in nr_llm (MCP client configuration + registration).
  It is a minor-release growth path and will carry its own ADR when the
  implementation is designed; the public-service count authority
  (:ref:`ADR-101 <adr-101>`) is updated then, not here.

.. _adr-116-transports:

Transports: HTTP only
=====================

This ADR first named "stdio / http / sse". That list was wrong in two ways and
is corrected here, because it would otherwise be read as a build order.

``sse`` is not a peer of ``http``. It is a response framing for an HTTP
connection, not a separate transport to select. Offering both as values of one
field invites a configuration that cannot be satisfied — which is what the
``transport`` field in ``nr_mcp_agent``'s own table already shows, where
``stdio`` and ``sse`` are the only values and plain ``http`` does not exist.

``stdio`` is out of scope, and not merely for performance. It means spawning a
process on the TYPO3 host from a request, with the command line taken from an
operator-editable record. Every control in the tool stack classifies a tool's
OUTPUT (:ref:`ADR-094 <adr-094>`) or authorises its CALLER — nothing classifies
what a tool may do to the host it runs on. "One registry, one gate" is a true
statement about offering, approving and auditing a tool; it says nothing about
launching a process, and it must not be read as covering one. If a stdio
transport is ever wanted, it belongs on the CLI and queue paths behind an
explicit allow-list of executables, decided in its own ADR.

The client therefore speaks HTTP, and only HTTP.

.. _adr-116-classification:

What an MCP tool resolves to, and why it needs a declaration
============================================================

An MCP tool arrives without a group, and the two resolvers answer that
differently — one fail-closed, one fail-open. Both answers are wrong for MCP,
in opposite directions, and an implementation that does not address both is not
"the same gate as a builtin".

**Data class fails closed to unusable.** A tool whose group is unknown resolves
to ``SECRET_ADJACENT``, which only a ``LOCAL`` trust zone permits. Against every
hosted provider such a tool is withheld. So MCP tools are not merely
"classified strictly" by default — they do not run at all outside a local
model.

**Effect fails open.** A registered tool that declares nothing resolves to
``READ_ONLY``, which is correct for the builtins (all of them read) and wrong
for an MCP server, where writes are ordinary. An undeclared remote write would
lose the write fence and the fail-closed audit of :ref:`ADR-111 <adr-111>`.

The implementation therefore requires a **per-server data-class declaration**
by the operator, and must treat an externally-sourced tool as a write unless
declared otherwise — the inverse of the builtin default. Neither is a
weakening: the first replaces "denied everywhere" with a stated ceiling, the
second replaces a guess with the strict answer.

**Why any of this is nr_llm's problem at all**, given that the MCP server
authorises its own resources: three things the server cannot see. Its output
travels onward to an LLM provider we chose, under a data-protection obligation
that is ours. Its authorisation is against one operator credential, so without
a gate here every backend user inherits the full rights of that credential. And
the write fence is our own retry bookkeeping, about our queue, which the server
knows nothing about. Securing the resource stays the server's job; where its
answers flow and in whose name we ask are ours.

.. _adr-116-migration:

Migration and follow-up
=======================

Implementation is separate follow-up work; this ADR records the target only.

- Build the MCP client in nr_llm: the HTTP transport, the ``tools/list``
  handshake, inputSchema-to-provider-schema normalisation (the concern
  ``McpToolProvider`` already solves), and registration of the resulting tools
  into ``ToolRegistry``.
- Move the MCP server configuration model (``transport``, ``command`` /
  ``arguments``, ``url`` / ``auth_token``) into nr_llm.
- Repoint ``nr_mcp_agent``'s ``ChatService`` onto ``AgentRuntime`` and delete
  its ``Classes/Mcp/`` client and ``runAgentLoop``.
- Decide rename versus fold-in for ``nr_mcp_agent`` as a discrete step.
- Reconcile streaming and context-window parity for MCP-sourced tools per the
  scope note in :ref:`ADR-107 <adr-107>`.
