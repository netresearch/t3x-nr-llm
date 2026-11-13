<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A2A tokens from the backend, and for editors

Governing decision: ADR-294 (amends ADR-280). Inbound A2A clients are records
that only an administrator creates, and their token could only be issued with
a CLI command. An administrator without shell access cannot issue or rotate
one, and an editor who wants an agent acting as themselves needs an
administrator-made technical user per editor.

## Requirements

- **T1 Issue and rotate.** An administrator issues and rotates the token of
  any client record from the backend module `nrllm_a2atokens`, through an AJAX
  route that checks the administrator flag itself and carries the core's route
  token. The token is shown once, in the response (`no-store`), never in a
  flash message or a redirect. The CLI command stays. Each issuing is logged
  with actor, original user, record and time, never the token.
- **T2 Self-service record.** A record with `self_service = 1` and `be_user` =
  the editor who created it, written only by `A2aSelfServiceTokens`. The owner
  comes from the session. The table stays `adminOnly`; a DataHandler write
  cannot set the flag.
- **T3 Permission.** The grant `a2a_token_self_service` on a backend group.
  No group holds it by default. An administrator is refused self-service.
- **T4 Limits per group** (fields on `be_groups`, merged over the groups of
  the user that hold the grant): live workspace (default off), maximum expiry
  (default 90 days, ceiling 730), no expiry (default off), allowed profiles
  (default none). Zero is the strict value everywhere.
- **T5 Enforcement at save time and at request time.** Save: refusal with the
  limit named. Request: every consumer reads the record through
  `A2aInboundClientRepository`, which withdraws a self-service record whose
  owner lost the grant, became an administrator, cannot work in the pinned
  workspace, or whose expiry exceeds the maximum, and removes profiles the
  groups no longer allow. A queued or suspended run reads it again.
- **T6 Scoping.** An editor sees and revokes only their own records; another
  user's record answers like a missing one. An administrator sees every record
  with owner, kind, creation, expiry, status and last task, and revokes any.
- **T7 Unchanged.** Technical-user records, the CLI command, the owner scoping
  of tasks by the record's uid, and the rule that an administrator cannot be
  the acting user.

## Not in scope

- OAuth 2.0 Authorization Code with PKCE (named in the user docs as a later
  step).
- Editing a self-service record: a changed limit means a new token.
- A write on every authenticated request to record "last used".

## Proof

| Requirement | Suite |
|-------------|-------|
| T1 | `A2aTokenAjaxControllerTest` (functional): admin only, token shown once; `A2aTokenRouteTokenTest`: route token |
| T2, T3 | `A2aSelfServiceTokensTest` (functional): owner from session, admin refused, grant needed; `A2aServerRecordSaveHookTest`: flag stripped |
| T4, T5 | `A2aSelfServicePolicyTest` (unit): merge both directions; `A2aSelfServiceEnforcementTest` (functional): save time and request time per limit |
| T6 | `A2aSelfServiceTokensTest`, `A2aTokenControllerTest` (functional) |
| T7 | existing `A2aEndpointTest`, `A2aSendMessageTest`, unchanged |
