.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-217:

==============================================================
ADR-217: Delegated MCP authentication keeps the acting identity
==============================================================

:Status: Accepted
:Date: 2026-10-09
:Amends: :ref:`ADR-116 <adr-116>` (MCP may use an audience-scoped credential
    delegated from the initiating actor, in addition to a server credential)
    and :ref:`ADR-190 <adr-190>` (retain cancellation argument positions while
    appending actor/session context on the final concrete MCP classes)
:Authors: Netresearch DTT GmbH

Context
=======

The runtime already carries the initiating actor into builtin and MCP tools,
including queue workers. Resumed backend runs currently reconstruct the owner
from the run row, which loses a named service actor. Suspensions therefore
need an explicit actor snapshot. An MCP server sees the one Vault credential
configured on its server record. That is appropriate for
a shared service identity. It cannot let a ticket, document or calendar service
enforce the individual caller's rights.

`F13 <https://f13-os.de/>`__, an open source AI system for public
administration, exchanges the caller's token for a token addressed to the
selected service.
TYPO3's backend actor is not itself an OAuth token. Delegation therefore needs
an explicit actor-to-identity-provider mapping and a credential resolver, not
an assumption that the current HTTP request contains a portable identity.

Decision
========

1. **Add delegation as an opt-in authentication mode.** Existing anonymous and
   server-Vault authentication keep their behaviour. Delegated servers name an
   exchange profile, audience and requested scopes. Configuration never stores
   a plaintext token. A malformed explicit mode is refused, not converted to
   anonymous or machine authentication.

2. **Resolve the initiating actor, not the approver.** A credential-resolver
   extension point maps the explicit execution context and exchange profile to
   a Vault subject-token identifier. The default mapping is operator supplied;
   absent or revoked mappings deny. Queue workers and resumes resolve again
   from the persisted actor. No access/refresh token enters a run request,
   suspended state, transcript or event. A service actor needs an explicit
   mapping too; it never inherits a backend user's ambient session.

   Installation profiles map ``backendUsers[uid]`` and
   ``serviceAccounts[name]`` explicitly. Each grant declares ``enabled: true``,
   a ``credentialIdentifier``, ``allowedAudiences`` and ``allowedScopes``.
   Requested scopes are explicit and nonempty. The requested audience and
   scopes must satisfy both the profile's and the
   actor grant's bounds; an absent or disabled grant denies.

   New queued and suspended payloads bind the actor to the run UUID inside the
   authenticated state envelope. A backend UID must match the stored owner; a
   service actor keeps its original name and owner zero. Validate before and
   after claiming a suspension. Malformed explicit actors and copied states
   deny; older payloads without actor bindings keep their stored backend owner.
   State clones and instruction-pin updates preserve both actor and UUID.

3. **Exchange rather than forward.** The RFC 8693 grant requests an access
   token with an explicit audience and bounded scopes. The subject token is
   injected into the form by nr-vault's body-field placement. Optional client
   authentication is Vault backed too. The original token never authenticates
   a request to the MCP endpoint. The response must declare an acceptable
   issued-token type, Bearer type and a usable finite lifetime; an invalid or
   refused response stops the call with a generic, secret-free error.

4. **Keep the existing network boundary.** Both the token endpoint and the MCP
   target pass the Vault host gate before any credential is injected, and use
   its DNS, redirect and audit protections. Exchange work spends from the MCP
   operation's existing deadline. Cancellation and exhausted budget stop
   before another token or tool request is dispatched.

5. **Use expiring Vault references downstream.** Exchange access-token values
   are stored under random, short-lived Vault identifiers with expiry and
   context metadata. Their ACL binds the initiating owner without inheriting
   backend groups or frontend access. Verify the stored ACL and delete the
   reference before denying if Vault changes its owner or grants. A background
   execution identity requires explicit Vault technical-actor access.
   The MCP transport only receives the reference, and Vault
   injects the Bearer value. Cleanup deletes temporary references when the
   operation ends; expiry bounds a crashed worker's leftover record.
   Subject and client secrets are never retrieved into nr-llm to build forms.
   Parsing the successful exchange response is the bounded point at which
   nr-llm handles the new access-token value before storing it in Vault.

6. **Check before every leg.** A handshake does not authorise a later request
   with an expired token. A request-scoped credential session checks remaining
   lifetime and exchanges again when needed. Its identity includes actor,
   exchange profile, audience and scopes. It is not cached on a shared MCP
   transport singleton, and never reused for another server/actor combination.
   Credential resolution and storage spend the operation budget before the
   transport checks cancellation and chooses the remaining HTTP timeout.

   Existing interfaces retain their signatures. Optional actor/session context
   is appended only to final concrete MCP classes; cancellation retains its
   existing positional index, type and nullable default (ADR-190).

7. **Discovery has a separate authority.** Catalogue import and connection
   probes without an execution actor do not fabricate one. A delegated server
   may explicitly configure a discovery-only Vault credential; otherwise an
   actorless operation is refused. That credential cannot authenticate calls
   executed by an agent, and failure never falls back to it.

Alternatives considered
=======================

Forwarding an incoming user token would give the target a credential intended
for another recipient and make queue behaviour depend on an HTTP session.
Putting a token in the persisted actor would turn a run record into a secret
store. Always using a machine credential preserves the current mode but cannot
provide individual downstream authorisation.

nr-vault's OAuth configuration supports client-credentials and refresh grants,
not token exchange. Treating its additional parameters as a grant override is
not a supported substitute. This change uses its checked secret placements
and temporary secret lifecycle, not a raw HTTP workaround.

Consequences
============

The additive surface is an authentication mode/profile, a subject-credential
resolver and delegated-credential session. Existing MCP method parameters keep
their position; optional execution context is added at the end where needed.
New schema columns use defaults that preserve old server records. The API
snapshot records the additions.

The implementation must verify the installed nr-vault body-field injection,
expiry/store/delete and confidential-client composition contracts against the
supported dependency range. If a required combination is unavailable, it is a
dependency requirement to resolve; secrets must not be manually copied into
an unguarded request to make a test pass.

Public OAuth clients use the existing Vault body-field injection for their
subject token. Confidential clients additionally require Vault's additive
``AdditionalSecretHttpClientInterface::withAdditionalBodyField()`` capability
to combine their client credential with the subject credential in one checked
request. That capability is proposed separately in nr-vault PR 409. Its absence
denies confidential exchanges before an IdP request, while existing static
authentication and public-client exchanges remain compatible. No unreleased
version number is invented as a Composer constraint; installation instructions
name the capability requirement until a release supplies it.

Specification: ``specs/015-delegated-mcp-auth/spec.md``. Tests exercise the wire
against fixture IdP and MCP clients, including actor isolation, renewal and
queue/resume. They do not exchange real personal credentials or imply that an
operator's identity-provider configuration has been certified.
