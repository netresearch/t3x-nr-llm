.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-278:

====================================================
ADR-278: A2A is an agent interface in both roles
====================================================

:Status: Accepted
:Date: 2026-11-10
:Authors: Netresearch DTT GmbH

Context
=======

The Agent2Agent protocol (A2A) lets one agent give a task to another over
HTTP and follow it to a result. Two requests reached nr-llm: a run should be
able to hand a step to a remote agent, and an installation should be able to
offer some of its configurations to remote agents. MCP
(:ref:`ADR-116 <adr-116>`, :ref:`ADR-259 <adr-259>`) does not cover either:
an MCP server offers tools that the calling model drives step by step, while an
A2A agent accepts a task, runs it on its own and reports a state and artifacts.

A2A 1.0 was published as tag v1.0.1 of ``a2aproject/A2A``. The specification
names ``specification/a2a.proto`` as its single normative definition (its
section 1.4); the prose disagrees with the proto in several places, the most
consequential being that ``SendMessage`` blocks by default
(``SendMessageConfiguration.return_immediately`` defaults to false) while the
prose of section 3.1.1 says it returns immediately. A v1.1.0 release candidate
was tagged on 2026-10-29 and adds fields and an error code. Three bindings
exist: JSON-RPC, gRPC and HTTP+JSON/REST. Protocol 0.3 is still in use and is
not compatible with 1.0 (a different blocking flag, a ``kind`` discriminator,
other member names).

Decision
========

**nr-llm speaks A2A in both roles.** As a client, a run delegates to a remote
agent an administrator registered. As a server, nr-llm publishes selected
configurations as skills to remote clients an administrator registered. The
roles share the protocol code and nothing else; each can be enabled alone.

**Protocol 1.0, JSON-RPC binding, nothing else.** Requests carry
``A2A-Version: 1.0``. The server answers an absent version (which the
specification defines as 0.3) and every version other than 1.0 with
``VersionNotSupportedError`` (``-32009``). The REST and gRPC bindings and
protocol 0.3 are not built in either role; they are added when a partner needs
one, by a decision of their own. JSON-RPC is one of the three transports the
conformance kit (``a2aproject/a2a-tck``) tests, needs no protobuf runtime on
the wire, and runs in a PHP-FPM request.

**The normative source is the proto, pinned.** ``a2a.proto`` at v1.0.1 (sha256
``e195bf96ab630c69797851970203e1b2b6b19528f2e9803b7d904b91a5104016``) is
committed as a test fixture. Where prose and proto disagree, nr-llm implements
the proto, and each disagreement is a requirement with its own test
(``specs/051-a2a-agent-interface``, "Prose and proto"). Documentation links
point at ``https://a2a-protocol.org/v1.0.1/specification/``; ``/latest/``
follows the development branch.

**nr-llm writes its own JSON-RPC layer and value objects; no A2A SDK is a
runtime dependency.** No official PHP SDK exists. The stage-0 spike (report of
2026-11-10, branch ``spike/a2a-stage0``) measured ``praveendias1180/a2a-php``
v1.0.3, generated from the v1.0.0 proto (which differs from v1.0.1 in comments
only):

- Its pure-PHP protobuf runtime (google/protobuf v5.36.2) calls ``bccomp()``
  for every int32 and timestamp read from JSON. Without ``ext-bcmath``, which
  TYPO3 14.3 neither requires nor suggests, its core suite fails with 40 errors
  and 18 failures, and ``ListTasks`` against ``a2a-python`` 1.3.0 died with an
  uncaught ``Error``. The substitutes are ``ext-bcmath``, ``ext-protobuf``
  (3 failures remain) or a polyfill (phpseclib plus two packages, 374 files).
- Its client parses responses with unknown fields rejected; a task carrying
  v1.1 fields (``generation``, ``status.progressHint``) failed with
  "progressHint is unknown". That breaks section 5.7 and the forward
  compatibility below. ``a2a-python`` 1.3.0 read the same responses.
- nr-llm needs its own transport anyway (address guard, explicit 3xx refusal,
  the vault credential; :ref:`ADR-279 <adr-279>`) and its own public types for
  the client API. In the spike the transport took 95 code lines and a
  hand-written JSON-RPC client 39, which passed every interop cell the SDK
  passed plus the missing-version cell the SDK cannot produce.
- It adds four packages, 655 files and 4,680 kB, one of them a Composer
  plugin; on 2026-11-10 it had 203 downloads, one dependent and one
  maintainer.

The SDK and ``a2a-python`` 1.3.0 remain test partners in an interop CI job.

**Parsing is forward-compatible.** Unrecognised fields are ignored, as the
specification asks (section 5.7). A stream or response member of an unknown
kind is skipped and logged, not treated as an error, so a v1.1 server does not
break the client. An unknown task state is never read as completed, and an
unknown ``Part`` kind is skipped and logged rather than read as an empty part.
Optional fields stay optional: ``a2a-python`` 1.3.0 sends a SUBMITTED status
without ``timestamp``.

Consequences
============

- ●● One protocol version and one binding keep the surface testable: one
  proto fixture, one codec, one TCK transport.
- ● Implementing the proto where the prose differs follows the source the
  specification itself makes normative; the interop job against
  ``a2a-python`` 1.3.0 checks that a reference SDK reads it the same way.
- ◑ A partner that speaks only 0.3, REST or gRPC cannot connect until a
  further decision adds it.
- ◑ A v1.1 server can send things the client skips; nothing of v1.1 is used.
- ✕ Two new attack surfaces exist where there were none; ADR-280 and the
  specification's security requirements bound them.

Net Score: +3

Alternatives considered
=======================

**Client role only.** Rejected: the server role was requested, and building the
protocol code once for both roles costs less than twice.

**Support 0.3 as well.** Rejected for now: two incompatible wire formats double
the codec and the tests for no current partner.

**Use** ``praveendias1180/a2a-php`` **as codec or client.** Rejected for the
measured reasons in the decision: a bcmath requirement TYPO3 does not carry,
strict parsing against section 5.7, and nothing left for it to do once the
transport and the public types are nr-llm's own.

**Follow the prose where it is explicit.** Rejected: the specification itself
makes the proto normative, and a non-blocking ``SendMessage`` would break every
1.0 client that relies on the default.
