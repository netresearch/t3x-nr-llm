<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# An MCP server may be connected through OAuth

The MCP client authenticates with a static Vault credential (`legacy`) or an
exchanged, actor-bound token (`delegated`, ADR-217). A server that follows the
MCP authorization specification hands out tokens only through an OAuth 2.1
authorization code flow with PKCE, and expects a client it has never seen to
register itself. Such a server cannot be used today. Decision record: ADR-259.
Protocol basis: MCP specification revision 2026-07-28, "Authorization".

## What it must do

- Offer a third authentication mode, `oauth`, on the MCP server record. Existing
  rows keep their mode.
- Connect a server from the MCP module, administrators only:
  - send the MCP request without a token, read `resource_metadata` and `scope`
    from the `WWW-Authenticate` challenge of the 401;
  - fetch the protected resource metadata from that URL, else from the
    path-inserted well-known URI, else from the root one; its `resource` must
    have the server's origin and the endpoint's path or a parent of it, and
    is the value later sent as `resource`;
  - fetch the authorization server metadata in the order the specification
    gives for issuers with and without a path; its `issuer` must equal the
    issuer the URL was built from;
  - refuse a server whose metadata does not list `S256` in
    `code_challenge_methods_supported`;
  - use the pre-registered client of the record when one is configured,
    otherwise register through Dynamic Client Registration (RFC 7591) with
    `application_type: web`, otherwise refuse;
  - bind a registration to the issuer it came from; register again when the
    issuer or the redirect URI changes, refuse a pre-registered client whose
    issuer changed, also after a disconnect, until another client ID is
    entered;
  - choose the scope from the challenge, else `scopes_supported`, else none,
    unless the operator configured scopes on the record;
  - send `resource` (RFC 8707) on the authorization and every token request;
  - generate the PKCE verifier and `state` per attempt, keep them with the
    expected issuer in the administrator's backend session for ten minutes, and
    use each at most once;
  - validate `iss` on the response as RFC 9207 section 2.4 and the specification
    table say, before using or showing anything from the response;
  - exchange the code with the verifier and store access and refresh token in
    Vault, never in a database column, a log or a run record.
- Use the stored access token as Bearer on every MCP request of the server,
  for tool calls and for catalogue import alike.
- Refresh the access token before it expires, by the wall clock, one process
  at a time across hosts (a lease on the grant row that outlasts the request
  and is released only by its holder), and store a rotated refresh token
  before the access token.
- Renew a token the MCP server refuses with 401 once, when a refresh token
  exists, and send the request again.
- Refuse the grant for a record whose URL no longer lies within the grant's
  `resource`.
- Disconnect a server from the module: delete its tokens and its grant; for
  a pre-registered client keep only the issuer it is bound to.
- Pass every discovery, registration and token URL through the Vault host gate,
  accept only `https` there, and follow no redirect.
- Survive a backend session cookie with `SameSite=Strict`: the redirect back
  from the authorization server arrives without it.

## What it explicitly does not do

- No per-user consent. A connection is one shared identity per server, like
  `legacy`; `delegated` stays the way to keep the actor.
- No Client ID Metadata Documents. They need a public, unauthenticated HTTPS
  document that this backend extension does not serve.
- No interactive step-up during a run. A second 401 or a 403
  `insufficient_scope` fails the call with a message that names the
  reconnect.
- No `client_secret_basic`, `private_key_jwt` or mutual TLS client
  authentication; `none` and `client_secret_post` only.
- No cleanup of a grant when its server record is deleted.

## Security boundaries

- Credentials: tokens and a registered client secret are Vault secrets owned by
  the administrator who connected, without groups, as a TCA vault field
  stores the `legacy` credential. Reading them at call time follows the same
  Vault rules as the `legacy` credential; a refresh additionally needs write
  access to rotate them.
- The PKCE verifier sits in the administrator's backend session until the
  callback or for ten minutes. It is useless without the code.
- Remote text (`error_description`, response bodies) is never shown or logged.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Challenge parsing, metadata order and validation, resource coverage, S256 refusal, https and host gate | unit `McpOAuthDiscoveryTest` |
| PKCE S256 (RFC 7636 appendix B vector), `iss` table, pending flow expiry | unit `McpOAuthPkceTest` |
| Token requests, registration order, issuer binding, DCR body | unit `McpOAuthTokenClientTest` |
| Transport 401/403, session store, redirect URI rule | unit `McpOAuthWiringTest` |
| Bearer on calls, refresh, rotation, lease, changed URL | functional `McpOAuthFlowTest` |
| Callback: administrator, single-use state, deleted server | functional `McpOAuthCallbackControllerTest` |
| Whole flow against a stub authorization server and MCP server, through the real Vault HTTP client | functional `McpOAuthFlowTest` |
| Mode, record, grant table | functional `McpOAuthFlowTest` |
| Callback without the session cookie | unit `McpOAuthCallbackBounceTest` |
