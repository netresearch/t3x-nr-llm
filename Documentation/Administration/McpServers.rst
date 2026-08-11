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

A failed connection test is reported on screen and stored nowhere. Only a
success moves the contact date.

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

See :ref:`ADR-116 <adr-116>` for the design rationale, and
:ref:`ADR-154 <adr-154>` for what liveness is measured on and why the
connection test writes nothing.
