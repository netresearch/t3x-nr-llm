<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Actor-aware delegated authentication for MCP

Decision: ADR-217. Depends on the invocation contract in ADR-216/spec014;
it keeps the runtime's initiating actor through the downstream auth boundary.

## What it must do

- Add an opt-in delegated server authentication mode. Existing anonymous and
  static Vault modes remain compatible; unknown explicit modes fail closed.
- Configure an exchange profile, explicit audience and allowed scopes, plus
  optional discovery-only Vault credential. Profile/client/subject secrets
  are Vault identifiers; configuration holds no plaintext token.
- Supply an installation-extensible subject resolver keyed by the explicit
  initiating actor and exchange profile. Missing, revoked or invalid mappings
  and disabled grants deny. Requested audience and scopes must satisfy both
  the profile and that actor's grant. Do not substitute the approver,
  `$GLOBALS['BE_USER']` or an HTTP header.
- Send RFC8693 `grant_type`, `subject_token_type`, `requested_token_type`,
  audience and scope. Inject `subject_token` through nr-vault BodyField.
  Support Vault-backed confidential client authentication when configured;
  no manual retrieval of subject/client secrets into form strings.
- Gate IdP and MCP hosts before secret injection, retain Vault SSRF,
  DNS-pinning, redirect refusal and audit behaviour, and spend exchange work
  from the same MCP operation deadline. Cancellation is honoured too.
- Require a successful JSON response, acceptable issued-token type, Bearer
  token type, nonempty access token and finite positive `expires_in`.
  Refuse broadened reported scopes. Where the response omits scope, use the
  requested scope set as specified by OAuth, never infer extra rights.
- Store the exchanged access-token value under a random expiring Vault
  identifier, use only that reference for MCP Bearer injection, delete it
  when the operation ends and retain expiry as a crash-cleanup bound.
- Check freshness before every HTTP leg, including after a slow handshake.
  Renew through exchange when the held token is no longer usable. Credentials
  and cache identity cannot cross actors, profiles, audiences or scope sets.
- Persist only actor identity, profile/server references and non-secret run
  metadata. Queued and resumed runs resolve fresh credentials for the original
  actor. New actor snapshots are bound to the run UUID inside the existing
  authenticated payload. Backend UID must match its run owner; preserve service
  actor names with owner zero. Check before and after claim; malformed explicit
  actors and ciphertext copied between new runs deny. Legacy missing bindings
  retain the old stored backend owner. Clones carry actor and UUID unchanged.
  No access/refresh token enters messages, events or suspend payloads.
- Discovery without a caller uses only an explicitly configured discovery
  credential or refuses. A tool execution never falls back to that credential,
  anonymous mode, static machine mode or the original user token.
- Bound exchange responses and return sanitised failure codes without echoing
  IdP response bodies, form fields, credentials or Bearer values.

## What it explicitly does not do

- No automatic login linking, IdP provisioning, token minting in TYPO3,
  consent UI or implied permission to impersonate an arbitrary backend user.
- No change to the admin-only MCP tool rule, approval defaults, classifications,
  invocation policy or remote-call budget.
- No general transport redesign, persistent stream support or direct raw HTTP
  fallback when Vault cannot satisfy an injection contract.
- No alteration of an existing server's authentication through an upgrade.

## Public surface and security boundaries

Additive authentication mode/profile values, subject-resolver interface and
credential-session API. Existing interfaces remain unchanged. The final concrete
MCP client and HTTP transport gain optional trailing actor/session context where
necessary; existing positional callers retain their static mode. The existing
cancellation parameter retains its position, nullable type and default; ADR-190
conformance pins the exact appended auth context.
New server fields have legacy-preserving defaults and localised TCA labels.
The source credential stays behind Vault's injection boundary. A successful
exchange response briefly contains a new access-token value; store it with
expiry and do not place it in reusable transport fields or logging contexts.

Dependency feasibility is an acceptance gate: verify supported nr-vault
BodyField injection, temporary `store(expiresAt)`/`delete`, combined subject
and confidential-client authentication, cancellation and host-gate behaviour.
Public-client exchange uses the supported existing BodyField contract. A
confidential client additionally requires the additive
`AdditionalSecretHttpClientInterface::withAdditionalBodyField()` capability
proposed in nr-vault PR 409. Without it the configured confidential exchange
must fail before contacting the IdP. Existing static modes and public clients
remain usable with the current stable dependency. Document that capability
requirement and, once released, its real minimum version; do not invent an
unreleased version constraint or bypass Vault to emulate missing capability.

## Which suite proves each requirement

| Requirement | Suite and intended contract |
|---|---|
| Old records/callers keep static or anonymous auth | unit MCP client/transport tests; functional schema default test |
| Audience/scope bounds apply to both profile and individual actor grant; overreach and explicit unknown mode deny | unit auth configuration and actor-grant tests; fuzzy bounds twins |
| Original actor resolves subject; absent/revoked mapping and disabled grant deny | unit delegated credential resolver tests |
| RFC8693 wire and Vault-injected subject/client fields | integration delegated exchange contract with recording Vault fixture |
| Original token never sent to MCP | integration actor×server auth matrix |
| Two actors and two recipients cannot reuse credentials | unit delegated credential-session tests; integration auth matrix |
| Expiry between initialize and tools/call renews | unit client contract with fake clock and IdP |
| Malformed/401/403/5xx/broadened-scope response cannot fall back | unit exchange contracts; integration no-MCP-contact assertions |
| IdP/MCP host gates precede secrets; redirects refused | integration Vault-backed exchange and MCP contracts |
| Exchange spends the operation deadline; cancellation prevents next leg | unit deadline/cancellation contracts |
| Temporary store expiry and delete on success/error/cancel | unit credential-session lifecycle contracts |
| Queue and approval/input resume resolve original actor afresh | functional `AgentRunDelegatedMcpTest` |
| Discovery authority is explicit and never used for tool calls | unit MCP import/probe and delegated-client contracts |
| Tokens absent from persisted run/messages/events and error strings | functional persistence contract; unit sanitisation tests |
| Supported Vault composition fulfils the dependency contract | integration Vault adapter tests at supported minimum and current versions |
| API and ADR references remain compatible | unit API snapshot and ADR lifecycle/reference suites |

Run the scoped suites during implementation and `make gate` before push.
Fixtures prove contracts without real IdP accounts, MCP writes or model calls.
