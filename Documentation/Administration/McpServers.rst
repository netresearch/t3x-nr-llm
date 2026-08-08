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
  it off per server once you know what its tools do. Servers that were
  already importing tools before this setting existed are left switched
  off by an upgrade wizard, so an update does not quietly halt a running
  integration — review them and switch approval on where it belongs.
- The number of remote calls one run may make is bounded (default: 20)
  — a remote call crosses the network while a backend user waits, and
  nothing else limits how many a model asks for at once.

See :ref:`ADR-116 <adr-116>` for the design rationale.
