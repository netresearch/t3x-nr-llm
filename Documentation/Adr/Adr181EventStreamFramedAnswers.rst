.. include:: /Includes.rst.txt

.. _adr-181:

============================================================================
ADR-181: The client reads an event-stream framed answer, and holds no stream
============================================================================

:Status: Accepted
:Date: 2026-08-20
:Amends: :ref:`ADR-116 <adr-116>` (its transport section) and
    :ref:`ADR-161 <adr-161>` (its "no SSE" edge)
:Authors: Netresearch DTT GmbH

.. _adr-181-context:

Context
=======

:ref:`ADR-116 <adr-116>` drew the MCP client's edges as "HTTP only, no stdio,
no SSE", and the transport said so on the wire: ``Accept: application/json``,
with a comment calling that honest — a client that does not consume a stream
does not claim to accept one.

The Streamable HTTP transport reads it differently. A client **must** offer
both media types on every POST, and a server may answer a request either as
plain JSON or as ``text/event-stream`` carrying the JSON-RPC response as a
``data:`` event. The reference server SDKs enforce the first half: measured on
2026-08-20 with a plain ``initialize`` against ``mcp.deepwiki.com/mcp``,
``mcp.context7.com/mcp`` and ``learn.microsoft.com/api/mcp``, the JSON-only
header got **406** ("Client must accept both application/json and
text/event-stream") from all three; with both offered, all three answered 200
— and all three framed the answer as an event stream.

So the honest header made the client unable to speak to any public MCP server,
which is exactly what the MCP Servers module exists to show. The edge ADR-116
drew was right about what this client should *do* — not hold a stream, not
answer server-initiated requests, not speak stdio — and wrong about what it
should *say*.

.. _adr-181-decision:

Decision
========

1. The transport offers both media types:
   ``Accept: application/json, text/event-stream``.

2. An answer with content type ``text/event-stream`` is **unframed**, not
   refused: the body — already bounded by the read cap and closed by the
   server — is parsed by the SSE rules that matter for one request/response
   exchange. Events are separated by a blank line; the ``data:`` lines of one
   event join with a newline; ``event:``, ``id:``, ``retry:`` and comment lines
   carry nothing that is read; CRLF is a line ending like any other. A server
   may put its own notifications on the same stream before the response (the
   transport allows it); this client declared no capabilities, so anything
   carrying a ``method`` is passed over, and the first message that is a
   response — a ``result`` or an ``error`` — is the answer. A stream with no
   such message is a malformed answer and is named as one, with the reason
   ("no message" versus "no response to the request").

3. Nothing else moves. No stream is held open, none is resumed, no
   server-initiated request is answered, the operation budget
   (:ref:`ADR-170 <adr-170>`) and the response size cap apply unchanged, and
   stdio stays out of scope for the reasons ADR-116 gives. ADR-161's edge now
   reads "no *live* stream" rather than "no SSE".

4. The conformance suite (:ref:`ADR-161 <adr-161>`) gains the positive case —
   an event-stream framed tool answer reads like a plain one — and the two
   failing shapes: a stream with no message, and a stream carrying only a
   server notification. The transport's own tests pin the header and the
   framing rules (multi-line ``data:``, CRLF, framing lines ignored,
   notification passed over).

.. _adr-181-consequences:

Consequences
============

✓ The MCP Servers module can be pointed at a public server and shown working
— the reason the typo3-demo wanted one in its seed.

✓ The refusal vocabulary is unchanged in shape: a content type the client
cannot read is still refused by the same exception and code; only the message
stops claiming "JSON only".

✕ The client now reads a body it previously would have rejected outright.
The read stays bounded and the parse is a line scan, so the new surface is
small; but it is a surface, and the tests for it are the contract.

✕ A server that answers a single request with a *long-lived* stream — keeping
the connection open for notifications after the response — is read only up
to the size cap or the operation deadline, whichever comes first, and then
treated as answered or as timed out. That is acceptable for a request/response
client and is stated here so nobody mistakes it for support.

.. _adr-181-revisit:

Revisit when
============

Something on the far side needs more than one message per request — server
requests, progress notifications that must be surfaced, resumption. That is a
stream, it is what ADR-116 declined to hold, and it would be its own record.
