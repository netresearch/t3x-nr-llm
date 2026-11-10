.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-279:

=====================================================================
ADR-279: A2A code sits in the agent module and calls out guarded
=====================================================================

:Status: Accepted
:Date: 2026-11-10
:Authors: Netresearch DTT GmbH

Context
=======

``Tests/Architecture/ModuleSeamTest.php`` enforces the module seams of
:ref:`ADR-090 <adr-090>`. ``Service\Tool``, ``Service\Agent`` and
``Service\Retrieval`` form the tools module; ``Controller`` and ``Widgets``
the backend module. Its rule ``testCoreDoesNotDependOnTheToolModule`` forbids
every namespace that is not a listed module (and not on its list of named
command and form exceptions) to depend on the tools module, and
``testNothingOutsideTheBackendDependsOnIt`` forbids every namespace outside the
backend to depend on ``Controller``. A2A needs the agent runtime
(:php:`AgentRuntimeInterface`), the run persistence and the address guard, all
in the tools module; a new top-level namespace such as ``Netresearch\NrLlm\A2a``
would be "core" and could import none of them. The streaming helper the backend
uses, :php:`NdjsonStream`, is a ``Controller`` class.

Outbound, nr-llm has two HTTP paths. :php:`McpHttpTransport` sends through
nr-vault's ``VaultServiceInterface::http()`` but is bound to an
:php:`McpServerRecord`, the MCP envelope and the ``Mcp-Session-Id`` header.
The address guard of :ref:`ADR-202 <adr-202>`, :php:`ExternalUrlGuard` with
:php:`ExternalFetchClientFactory`, checks scheme, own-site hosts, deny and
allow lists, ports and proxies, resolves the host, requires every address to
be public and returns a DNS pin; the factory builds its client from nr-vault's
``SecureHttpClientFactory``. :php:`ExternalUrlGuard::check()` refuses any tool
group whose egress scope is not ``EXTERNAL_FILTERED``. Egress is decided per
group by :php:`EgressPolicyService`, fail-closed (:ref:`ADR-061 <adr-061>`).

Decision
========

**All A2A code lives under** ``Netresearch\NrLlm\Service\Agent\A2a\``. That is
inside the tools module, so the seam test applies unchanged: it may use the
runtime, the persistence, the tool registry and the address guard, and must not
use ``Controller`` or ``Specialized``. This includes the frontend PSR-15
middleware that answers inbound requests and the SSE writer of stage 5a, which
does not reuse :php:`NdjsonStream`. ``ModuleSeamTest`` is not extended; a
future ``nr_llm_tools`` package would carry A2A with it, which is where it
belongs, since A2A is a way to run agents. Registration files
(``Configuration/RequestMiddlewares.php``, ``Configuration/Services.yaml``)
are configuration, not namespaces, and are unaffected.

**Outbound requests go through the address guard, never through**
:php:`McpHttpTransport`. Every request to a remote agent, a card import, a
push webhook (stage 5b) and a ``url`` part fetch (stage 5c) is checked by the
guard and sent with the pin it returns; a redirect is not followed by the
client but checked as a new target. Two egress groups are added to
:php:`EgressPolicyService`: ``a2a_agent`` for calls to a registered remote
agent's selected interface, and ``a2a_external`` for URLs that arrive from
outside (webhooks, ``url`` parts). Both are ``EXTERNAL_FILTERED``: an interface
URL comes from the remote's card, not from the operator, so it gets the same
checks as any outside URL. The guard's allow and deny lists for these groups
are settings of their own, not the ``fetchExternalUrl`` lists of the web tool.

**The credential is added per request.** The remote agent's credential is an
nr-vault identifier on its record, injected for the one request; on the command
line inside :php:`WorkerVaultScope` as ``worker.technicalBeUserUid``
(:ref:`ADR-234 <adr-234>`).

**The sender is a** ``VaultHttpClient`` **pinned to the guard's addresses.**
The stage-0 spike (2026-11-10, nr-vault 1.4.2) measured both candidates.
``VaultServiceInterface::http()`` is PSR-18, but ``sendRequest()`` has no place
for the addresses the guard checked, and it buffers: the first SSE frame
arrived only when the stream ended. A ``VaultHttpClient`` built with a
``SecureHttpClientFactory`` whose DNS resolver returns exactly the guard's
addresses reached a host that does not resolve on both send paths (the control
with the default resolver failed with "Could not resolve host"), so it pins.
Its ``sendStreaming()`` delivered each SSE frame when its last byte arrived
(first frame after 1 to 3 ms) and reassembled frames split inside a line, a
multi-byte character and the terminating blank line. Both send paths returned a
307 without touching the target and sent a 503 exactly once. So:

- unary calls go through ``sendRequest()``, streams through
  ``sendStreaming()``, both on that client;
- a 3xx is refused explicitly, never followed; a redirect target is checked by
  the guard as a new target;
- the client retries nothing; whether to try again is the caller's decision
  (an unknown outcome is never resent, :ref:`ADR-281 <adr-281>`);
- the credential goes in through the client's own authentication injection,
  so the vault audit row is kept.

**nr-vault must offer this as a supported API.** Building the client with a
per-request resolver was measured, not confirmed with nr-vault as intended use:
the default resolver memoises answers for five seconds and also serves
``isHostAllowed()``. nr-vault, which Netresearch maintains, gets a supported
way to send with pre-resolved addresses before stage 1 relies on it.

**SSE needs nr-vault 1.1 or later.** nr-llm allows ``^0.16.0 || ^1.1``;
nr-vault 0.16.0 has no streaming interface. The client role's streaming (stage
5a) is offered only with nr-vault ^1.1, and the release that adds it raises the
floor or switches streaming off below it.

Consequences
============

- ●● No seam test changes and no new exception list entry.
- ● One guard for every outbound A2A URL; its tests already cover rebinding,
  numeric hosts and redirects.
- ✕ A remote agent on a private address cannot be reached. The guard's lists
  only narrow; no allow-list entry admits a private, loopback, link-local or
  reserved address. An installation that runs an agent in its own network needs
  a further decision that amends :ref:`ADR-202 <adr-202>`. The interop and TCK
  jobs reach their private Docker network through an address classifier that
  is registered in the Testing context only.
- ◑ A frontend middleware class in a ``Service`` namespace is unusual for
  TYPO3; it is the price of the seam rule.
- ✕ The SSE writer exists twice, once for the backend (:php:`NdjsonStream`)
  and once here.

Net Score: +2

Alternatives considered
=======================

**A new** ``Netresearch\NrLlm\A2a`` **namespace with a seam exception.**
Rejected: it would add a fifth module to the split plan without a package to
split it into, and every new A2A class would need an entry in the exception
list.

**Send through** ``VaultServiceInterface::http()``. Rejected on measurement:
it cannot carry the guard's addresses and buffers streams.

**Hand the client from** :php:`ExternalFetchClientFactory` **to an SDK.**
Rejected on measurement: with a Guzzle client the SDK under test followed a
307 to its target without the guard seeing it.

**Reuse** :php:`McpHttpTransport`. Rejected: it is bound to the MCP record,
envelope and session, and does not run the address guard.

**Egress scope** ``CONFIGURED_ENDPOINT`` **for the remote agent.** Rejected:
the operator enters a card URL, but the interface URL that requests go to is
read from the card, so it is not operator-declared.
