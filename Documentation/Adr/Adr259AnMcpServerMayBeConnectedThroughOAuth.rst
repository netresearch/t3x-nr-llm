.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-259:

=====================================================
ADR-259: An MCP server may be connected through OAuth
=====================================================

:Status: Accepted
:Date: 2026-11-04
:Amends: :ref:`ADR-217 <adr-217>` (a third authentication mode beside
    ``legacy`` and ``delegated``)
:Authors: Netresearch DTT GmbH

Context
=======

The MCP client (:ref:`ADR-116 <adr-116>`) authenticates with one Vault
credential per server or, since :ref:`ADR-217 <adr-217>`, with a token
exchanged for the acting user. A server that implements the authorization part
of the MCP specification offers neither. It answers an unauthenticated request
with ``401`` and a ``WWW-Authenticate`` challenge, names its authorization
server in OAuth protected resource metadata (RFC 9728), and issues tokens only
through the authorization code flow with PKCE (RFC 7636). A client it has never
seen registers itself first, through Dynamic Client Registration (RFC 7591) or
a Client ID Metadata Document. The gap analysis of 2026-09-23 listed "MCP
client: OAuth 2.0 with PKCE and Dynamic Client Registration" as missing.

This record follows the specification revision 2026-07-28. That revision makes
Client ID Metadata Documents the registration a client SHOULD support and
marks Dynamic Client Registration deprecated, kept for authorization servers
that do not support the documents yet.

Decision
========

1. **A third mode, ``oauth``.** :php:`McpAuthenticationMode` gains the case.
   A server record in this mode carries an optional pre-registered client ID,
   an optional Vault reference to its secret and optional scopes. Existing
   records keep their mode.

2. **One connection per server, made by an administrator.** The MCP module
   offers "Connect" for an ``oauth`` server. The grant that results is used by
   every run, catalogue import and connection test of that server. It is a
   shared identity, like ``legacy``. Consent per backend user would need a
   browser session per user, and a queue worker has none; ``delegated``
   remains the mode that keeps the actor.

3. **Discovery as the specification orders it.** An unauthenticated MCP
   request yields the challenge. Its ``resource_metadata`` is used, otherwise
   the path-inserted well-known URI, then the root one. The metadata's
   ``resource`` must identify the server: the same origin, and a path that
   is the endpoint's path or a parent of it by whole segments, since a
   document at the root names the origin (RFC 9728 section 3.3). That
   declared value is what the authorization and token requests send as
   ``resource``. The first listed
   authorization server is used; its metadata is fetched from the RFC 8414 and
   OpenID Connect locations in the specified order, and its ``issuer`` must
   equal the issuer the URL was built from. Metadata that does not list
   ``S256`` in ``code_challenge_methods_supported`` stops the connection.

4. **Registration order.** A client pre-registered on the record comes first.
   Otherwise the client registers through Dynamic Client Registration as a
   public ``web`` client with the callback as its only redirect URI. Without a
   registration endpoint the connection stops. A registration is stored with
   the issuer that issued it and the redirect URI it names; a change of either
   registers again, and a pre-registered client whose issuer changed is
   refused. That binding outlasts "Disconnect": the grant row stays without
   any secret reference, so the stored client secret is never sent to an
   issuer the client was not used with. Entering another client ID on the
   record ends it. Client ID Metadata Documents are not supported: the client ID is
   the URL of a public, unauthenticated JSON document, and this backend
   extension serves no public URL.

5. **The flow.** The verifier, ``state`` and the expected issuer live in the
   administrator's backend session for ten minutes and are used once. The
   authorization and every token request carry ``resource`` (RFC 8707). The
   callback applies RFC 9207's ``iss`` check before reading the code or the
   error, and shows no remote text. The backend session cookie is
   ``SameSite=Strict`` by default, so the redirect back from the
   authorization server arrives without it: a middleware answers that request
   with a page that loads the same URL again from this site, and the second
   request carries the session.

6. **Vault holds every secret.** The access token, the refresh token and a
   registered client secret are Vault secrets, owned by the connecting
   administrator and without groups, as the TCA vault field stores a
   ``legacy`` credential. The grant row holds their references, the issuer,
   the client ID, the expiry and the granted scope. A token request reaches
   the token endpoint with the refresh token and the client secret injected by
   Vault's body-field placement, as in :ref:`ADR-217 <adr-217>`. The only place
   nr-llm holds a token value is the parsing of a successful token response,
   before it stores the value in Vault.

7. **Refresh before expiry, one process at a time.** The credential session
   refreshes a token that expires within a minute. Two processes refreshing
   together would present the same rotated refresh token twice, and an
   authorization server may revoke the whole grant for that. The refresh
   therefore takes a lease on the grant row — one conditional ``UPDATE``,
   which holds across hosts, where the locking strategies TYPO3 ships hold on
   one host only — and reads the grant again under it. The refresh request
   has a fixed timeout of its own and is not cut off by the run's deadline
   or a cancel: once it is out, the authorization server may already have
   retired the old refresh token, and losing the answer would cost every
   run against the server a reconnect. A deadline or a cancel ends the run
   once the new tokens are stored. The lease lasts that timeout plus a
   margin, and only the process that took it gives it back. A process that finds the lease taken keeps using a
   token that has not expired yet, and otherwise waits for the other one's
   result; the wait spends from the operation's deadline and stops on a
   cancel. Expiry and lease arithmetic read a wall clock, not TYPO3's
   ``date`` aspect, which a queue worker sets once when it starts. Every
   refresh and every connect raises the grant's generation; a refusal names
   the generation it refused, so a token another process has already
   replaced is not refreshed again. Writes of a refresh apply only to the
   grant that was refreshed, so a connect in the meantime is kept. A refresh
   ahead of expiry that fails for a reason a later attempt may get past
   keeps the current token. A rotated
   refresh token is written to Vault before the access token, because the
   authorization server has already retired the old one; when that write
   fails — a Vault policy that grants reading but not rotating does so on
   every expiry — the grant is marked for reconnect at once and the message
   says the refresh token could not be stored. A refused refresh marks the
   grant as needing a reconnect too.

   A grant is used only for the server it was issued for: when the record's
   URL no longer lies within the grant's ``resource``, calls are refused
   until the server is connected again, rather than sending the token to the
   new address.

8. **One renewal, no step-up inside a run.** A ``401`` from the MCP server
   renews the token once, when a refresh token exists, and sends the request
   again; a ``401`` is decided before the server acts on the request, so the
   repeat has no effect of its own. A second ``401``, or a ``403
   insufficient_scope``, fails the call with a message that names the
   reconnect: a run has no browser to send to the authorization server. A
   failure that a later attempt may get past — an unreachable token
   endpoint, a refresh another process holds — says to try again instead.

9. **The same network boundary.** Every metadata, registration and token URL
   must be ``https``, passes the Vault host gate before it is contacted and is
   requested without following redirects. ``authorization_servers`` can name
   any host, so this gate is what stops the metadata from steering requests
   into the internal network.

Alternatives considered
=======================

``league/oauth2-client`` (2.9.1) implements the authorization code grant with
PKCE. It implements neither protected resource metadata, authorization server
discovery, Dynamic Client Registration, the ``iss`` check nor ``resource``, and
it sends its requests through its own HTTP client with the refresh token and
the client secret as plain parameters. That would bypass the Vault host gate,
its audit and its secret injection, which every other MCP leg goes through.

nr-vault's :php:`OAuthTokenManager` refreshes only with a client secret, so a
publicly registered client cannot use it, and it falls back to the client
credentials grant when a refresh is refused, which is wrong for a grant a
person authorised.

Consequences
============

- Additive surface: the enum case, three record columns, the
  ``tx_nrllm_mcp_oauth_grant`` table, the module actions "Connect" and
  "Disconnect", the backend route ``nrllm_mcp_oauth_callback`` and a backend
  middleware that only acts on that route.
- :ref:`ADR-161 <adr-161>` keeps no authenticated conformance profile. The
  OAuth wire behaviour is covered by its own unit suites, by a flow test
  that drives discovery, registration, the code redemption, a tool call with
  the token and a refresh against a stub authorization server and MCP
  server through the real Vault HTTP client, and by a functional test of the
  callback controller.
- A run that cannot write the token secrets cannot refresh them; the call
  fails and names the reconnect. Reading them follows the same Vault rules as
  a ``legacy`` credential.
- A client with a secret refreshes with two Vault secrets in one form, which
  needs nr-vault's additional body-field capability. Without it the
  connection is refused at "Connect" instead of failing at the first
  refresh.
- Deleting a server record leaves its grant and its Vault secrets until
  "Disconnect" is used before the deletion.
- A client secret registered for a flow whose code redemption failed stays
  in Vault: a second callback for the same flow, a reloaded tab, may have
  succeeded and stored a grant that references it. A flow that fails before
  the code is sent removes it.
- Specification: ``specs/043-mcp-oauth-authorization/spec.md``.
