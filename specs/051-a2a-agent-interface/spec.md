<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A2A: nr-llm calls remote agents and answers as one

Governing decisions: ADR-278 (protocol and roles), ADR-279 (placement and
outbound HTTP), ADR-280 (inbound identity), ADR-281 (a run waits durably for
a dependency or a decision), ADR-282 (the result store and the A2A event
journal), ADR-283 (server execution and streaming), ADR-284 (private files and
authorised FAL adoption), ADR-285 (push inbox and bounded outbox delivery).
Baseline: `main` at 515c370ba.

The Agent2Agent protocol (A2A) lets one agent hand a task to another over HTTP.
This specification covers both roles. As a **client**, a run of nr-llm delegates
a step to a remote agent an administrator registered, waits for it without
holding a request or a worker, and continues with its result. As a **server**,
nr-llm publishes selected configurations as agent skills that a registered
remote client may call. It is delivered in stages; each stage below names what
it adds, and a later stage builds exactly what this document says.

The protocol is A2A **1.0**, JSON-RPC binding only. The normative source is
`specification/a2a.proto` at tag v1.0.1 of `a2aproject/A2A` (sha256
`e195bf96ab630c69797851970203e1b2b6b19528f2e9803b7d904b91a5104016`), as the
specification's own §1.4 states; the prose specification is read at
<https://a2a-protocol.org/v1.0.1/specification/> (the `/latest/` path follows
the development branch). Where the prose and the proto disagree, this document
implements the proto and lists the disagreement under "Prose and proto".
Messages and fields are named as the proto names them, with the JSON
(camelCase) name in brackets where the two differ. Requirement IDs carry the
item of the cross-check against a second plan they come from in brackets
(`[B7]`), where one applies.

## Why it is not built on what exists

- **No inbound surface.** nr-llm has no frontend middleware and no token-
  authenticated endpoint. `Configuration/RequestMiddlewares.php` has a
  `backend` stack with one entry, the MCP OAuth callback bounce, and the one
  route with `access => public` (`nrllm_mcp_oauth_callback`) still requires a
  backend session. The server role, and the push receiver of the client role,
  are new code.
- **No caller-to-actor mapping.** A run's tools act as the run's backend user
  (`ActingBackendUserResolver::resolve()`), which returns null for
  `backendUserUid <= 0`, so every user-scoped tool fails closed for a service
  account without a backend user. An inbound caller therefore has to be mapped
  to a real, technical backend user.
- **No waiting state for anything but a person.** `AgentRunStatus` has
  `WAITING_FOR_APPROVAL` and `WAITING_FOR_INPUT`; both mean a human. A tool is
  synchronous (`ToolInterface::execute()` returns a `ToolResult`, with no
  pending variant). A remote task that takes minutes cannot be a tool call
  inside the loop.
- **Live callbacks run before persistence.** `AgentRunExecutor::trace()`
  hands each step to the live observer first and records it afterwards
  ("emit-before-persist"), and text deltas go to the observer only. An A2A
  stream or webhook fed from those callbacks could announce what was never
  stored.
- **No transport to reuse as is.** `McpHttpTransport` is bound to an
  `McpServerRecord`, the MCP envelope and the `Mcp-Session-Id` header. The
  address guard of ADR-202 (`ExternalUrlGuard`, `ExternalFetchClientFactory`)
  is the generic part.
- **Results are not kept for a caller.** `QueuedRunCoordinator::keepAnswer()`
  keeps an answer only for a queued run with an origin that COMPLETED, writes it
  after the run settled (a failure is only logged), and keeps none for a run
  that went through an approval. A remote client that polls `GetTask` needs the
  result whenever the task says COMPLETED.
- **Decisions run in the request.** An approval or an input continues the run
  inside the deciding request (ADR-276). A decision that arrives over A2A has no
  such request to continue in.

## Stages

| Stage | Adds | Depends on |
| --- | --- | --- |
| 0 | This specification and ADR-278 to ADR-285; the stage-0 spike (SDK and HTTP-adapter measurement, interop smoke, pinned proto fixture); a re-estimate | — |
| 1 | Client: remote-agent records, card import, `A2aClient` (SendMessage, GetTask, ListTasks, CancelTask), send-intent journal, client-side ownership, CLI example | 0 |
| 2 | Runtime: the dependency status and checkpoint, the delegation tool, the polling coordinator, resume, cancel propagation, the result store and the A2A event journal | 1 |
| 3 | Server: frontend endpoint, client records, publication profiles, agent card, JSON-RPC methods, transactional acceptance, worker execution, TCK fixture and CI job | 2 |
| 4 | Multi-turn: INPUT_REQUIRED and AUTH_REQUIRED, durable decision intake, the first write capability | 3 |
| 5a | SSE: `SendStreamingMessage`, `SubscribeToTask` (both roles) | 3 |
| 5b | Push notifications: sender outbox (server role), receiver inbox (client role) | 3 |
| 5c | Files: `raw` and `url` parts, private file store, protected download, FAL export and adoption | 3 |

Each stage is released only with its own gates (see "Verification") and the
rollout order of A2A-OPS1.

## Protocol profile (both roles)

- **A2A-P1 Binding.** JSON-RPC 2.0 over HTTPS, `Content-Type:
  application/json` for request and response (§9.1). Method names are the proto
  rpc names: `SendMessage`, `SendStreamingMessage`, `GetTask`, `ListTasks`,
  `CancelTask`, `SubscribeToTask`, `CreateTaskPushNotificationConfig`,
  `GetTaskPushNotificationConfig`, `ListTaskPushNotificationConfigs`,
  `DeleteTaskPushNotificationConfig`, `GetExtendedAgentCard`. An unknown method
  is `-32601`.
- **A2A-P2 Version.** The client sends `A2A-Version: 1.0` on every request.
  The server reads the header, or the `A2A-Version` query parameter when the
  header is absent (§3.6.1). It accepts `1.0` and any `1.0.x` (the patch level
  is ignored, §3.6). An absent or empty value means 0.3 (§3.6.2) and is refused
  with `-32009` VersionNotSupportedError, like every other value.
- **A2A-P3 Serialisation.** camelCase field names, enum values as their proto
  names (`TASK_STATE_COMPLETED`, `ROLE_AGENT`) (§5.5). Timestamps are ISO 8601
  in UTC with `Z` and millisecond precision; no other offset is emitted or
  accepted (§5.6.1). `Part.raw` is base64 in JSON.
- **A2A-P4 Forward compatibility.** Both roles ignore unrecognised fields
  (§5.7). A `StreamResponse` or `SendMessageResponse` whose only member is
  unknown (a v1.1 payload kind) is skipped by the client, logged once per kind,
  and does not end the stream. Unknown enum values in a received `TaskState`
  are treated as non-terminal and logged; they never map to COMPLETED.
- **A2A-P5 Required fields.** A received message missing a field the proto
  marks `REQUIRED` is invalid: the server answers `-32602` with a
  `google.rpc.BadRequest` detail naming the field; the client treats it as
  `InvalidAgentResponseError` (`-32006` semantics, no retry). A REQUIRED
  repeated field must hold at least one element (§5.7): `Message.parts`,
  `Artifact.parts`, `AgentCard.supported_interfaces`, `default_input_modes`,
  `default_output_modes`, `skills`, `AgentSkill.tags`.
- **A2A-P6 Errors.** An error is a JSON-RPC error object; `error.data` is an
  **array** of objects, each with an `@type` (ProtoJSON `Any`) (§9.5). A2A
  errors use the codes of §5.4: `-32001` TaskNotFound, `-32002`
  TaskNotCancelable, `-32003` PushNotificationNotSupported, `-32004`
  UnsupportedOperation, `-32005` ContentTypeNotSupported, `-32006`
  InvalidAgentResponse, `-32007` ExtendedAgentCardNotConfigured, `-32008`
  ExtensionSupportRequired, `-32009` VersionNotSupported. Every A2A error
  carries a `type.googleapis.com/google.rpc.ErrorInfo` detail with `reason`
  (e.g. `TASK_NOT_FOUND`) and `domain` `a2a-protocol.org`, unless this
  specification names an nr-llm reason for it (as `RESULT_NOT_RETAINABLE` with
  `-32004`), whose `domain` is `nr-llm.netresearch.de`. The codes
  `-32001` to `-32099` belong to A2A (v1.1.0-rc already assigns `-32010`), so
  nr-llm's own refusals use `-32000` (or `-32603` for system errors) with an
  `ErrorInfo` whose `domain` is `nr-llm.netresearch.de` and whose `reason` is
  one of the values listed in this specification.
- **A2A-P7 Capabilities.** The server validates a call against its own card
  (§3.3.4): an undeclared `streaming` refuses `SendStreamingMessage` and
  `SubscribeToTask` with `-32004`; an undeclared `push_notifications`
  [`pushNotifications`] refuses the four push methods with `-32003`; an
  undeclared `extended_agent_card` refuses `GetExtendedAgentCard` with
  `-32004`, a declared one without a configured card with `-32007`. A
  `SendMessage` carrying `configuration.task_push_notification_config` is
  refused with `-32003` while push is undeclared. nr-llm declares no extension
  and requires none.
- **A2A-P8 Out of scope.** HTTP+JSON/REST binding, gRPC binding, protocol 0.3
  in either role, and agent card signing (§8.4) are not built. A remote card
  that offers only those bindings is not importable (A2A-C2).

## Prose and proto

Each line is a requirement with a test of its own; the implementation follows
the proto column. Where the proto is silent, the tie-breaker is named.

| ID | Prose | Implemented | Source of the decision |
| --- | --- | --- | --- |
| A2A-D1 | §3.1.1 "MUST return immediately" | `SendMessageConfiguration.return_immediately` [`returnImmediately`] defaults to false: **blocking**, until a terminal or interrupted state (§3.2.2 agrees with the proto). This inverts 0.3's `blocking` flag. | proto field comment; TCK `CORE-EXECUTION-MODE-001` |
| A2A-D2 | stream members `taskStatusUpdate` / `taskArtifactUpdate` (whats-new) | `StreamResponse.status_update` [`statusUpdate`], `artifact_update` [`artifactUpdate`] | proto |
| A2A-D3 | `AgentCard.security` (§3.1.11, §13.3) | `AgentCard.security_requirements` [`securityRequirements`] | proto |
| A2A-D4 | whats-new lists `Task.createdAt`, `Task.lastModified`, `PushNotificationConfig.configId` and `createdAt` | none of these exist in the proto; the push config's identifier is `TaskPushNotificationConfig.id`. nr-llm neither emits nor requires them; a received one is an unrecognised field (A2A-P4). | proto |
| A2A-D5 | §3.1.2 and §3.1.6: a stream closes at a terminal state; §11.7: at a terminal **or** interrupted state; §7.6.1: SHOULD keep streams open after AUTH_REQUIRED | a stream closes after the event that carries a terminal state (COMPLETED, FAILED, CANCELED, REJECTED) or INPUT_REQUIRED. After AUTH_REQUIRED it stays open, because the credential arrives out of band and the task may continue without a client message, until the next terminal or interrupted state or the stream's time limit (A2A-SSE3). The client reads further events whenever a server keeps a stream open and treats a server close at either interrupted state as normal. | proto silent; §11.7 for INPUT_REQUIRED, §7.6.1 and TCK `AUTH-INTASK-005` (SHOULD) for AUTH_REQUIRED. `CORE-STREAM-003` and `STREAM-SUB-002` test only terminal states. |
| A2A-D6 | §3.3.2 "Error Details (optional)" | `error.data` is always an array of `@type` objects when present, never an object | §9.5; TCK `tck_test_error_handling.py` asserts the array |
| A2A-D7 | §3.1.4 "sorted by last update time" and "by their status timestamp" | `ListTasks` sorts by `TaskStatus.timestamp` descending, ties by task id | §3.1.4 "Ordering"; TCK `CORE-LIST-003` |
| A2A-D8 | §6.6 example (until a2aproject/A2A PR #1793) sent the push `token` as `X-A2A-Notification-Token` | no `X-` header; a push request's credential travels in `Authorization` only, and `TaskPushNotificationConfig.token` is never turned into a header [B22] | a2aproject/A2A#1594 (closed 2026-05-23; maintainer: "Any credentials sent by the push notification should be sent in the Authorization header"), PR #1793 merged the same day; the v1.0.1 prose no longer contains the header |

## Stage 0: specification and spike

- **A2A-S0-1** This specification and ADR-278 to ADR-285 are merged.
- **A2A-S0-2 SDK measurement.** The spike decides whether nr-llm uses
  `praveendias1180/a2a-php` (v1.0.3, Apache-2.0, PHP ^8.2, generated from the
  v1.0.0 proto, which is wire-identical to v1.0.1, depends on
  `google/protobuf` and `google/common-protos`) or its own value objects. It
  records: whether the client can run over the HTTP client ADR-279 names
  (PSR-18 or an adapter), the installed size and file count the protobuf
  packages add to a TER package, whether the licence is compatible with
  GPL-2.0-or-later distribution, and whether the library retries on its own (a
  retry would break A2A-C6). `vbcherepanov/a2a-php-sdk` is excluded: it needs
  PHP 8.4 and `ext-bcmath`, above the extension's PHP 8.2 floor. No official
  PHP SDK exists. Whatever is chosen, the public PHP API of the client takes
  and returns nr-llm types, never SDK types [B8]. The result and the measured
  numbers go into an ADR of their own; nothing in ADR-278 to ADR-285 depends
  on the answer.
- **A2A-S0-3 Streaming adapter.** The spike measures whether nr-vault's
  `VaultServiceInterface::http()`, and the client of
  `ExternalFetchClientFactory`, deliver a response body incrementally (first
  SSE frame readable before the response ends) while keeping the guard's DNS
  pin [B14]. Stage 5a's client uses the one that does; if neither does, 5a
  names the adapter it adds.
- **A2A-S0-4 Pinned proto.** `v1.0.1` `a2a.proto` is committed as a test
  fixture with its sha256 asserted, and the serialisation tests of every later
  stage read field names from it.
- **A2A-S0-5 Interop smoke.** A script starts `a2a-python` v1.3.0 (which
  implements protocol 1.0) in Docker and sends one `SendMessage` from a PHP
  probe, and one from the Python client to a PHP stub, both with
  `A2A-Version: 1.0`.
- **A2A-S0-6 Re-estimate.** Stages 1 to 5c are re-estimated from the spike's
  findings before stage 1 starts, and compared with the 66 to 100 person-days
  of the cross-checked second plan.

## Stage 1: client

- **A2A-C1 Remote agent record.** A new record type `tx_nrllm_a2a_agent`
  (Extbase model `A2aRemoteAgent`) holds: title, identifier, the agent card URL
  the operator entered, the imported card and its fetch time and `ETag`, the
  selected interface URL, an nr-vault credential identifier (never a secret),
  `data_class`, `enabled`, `requires_approval`, and limits (maximum wait,
  minimum and maximum poll interval, maximum response bytes, maximum delegations
  per run). Defaults copy `tx_nrllm_mcp_server`: `data_class` has no default and
  a record without one does nothing; `enabled` is 0; `requires_approval` is 1.
  The remote never decides its own trust: nothing read from its card sets
  these fields.
- **A2A-C2 Card import.** Import fetches the card through the address guard
  (ADR-279), validates it against the proto (A2A-P5), and selects the first
  `supported_interfaces` [`supportedInterfaces`] entry with `protocol_binding`
  `JSONRPC` and `protocol_version` `1.0` (§8.3.2). A card without such an entry
  is refused with a message naming what it offers. The interface `url` passes
  the same guard. When the entry carries `tenant`, every request sets
  `tenant` to exactly that value; otherwise it is omitted (§8.3.2 rule 4).
  Re-import is explicit; a changed `capabilities` or interface URL requires the
  operator to save the record again.
- **A2A-C3 Client.** `A2aClient` sends `SendMessage`, `GetTask`, `ListTasks`
  and `CancelTask` and returns typed nr-llm results: a `SendMessageResponse` is
  either a task or a message, and the caller has to handle both.
- **A2A-C4 Send-intent journal.** Before any network call for `SendMessage`,
  a row in `tx_nrllm_a2a_send` is committed with: owner (the local actor),
  remote agent uid, run uid when there is one, the `message_id` [`messageId`]
  (UUIDv7, generated unless the caller passes one), the sha256 of the
  serialised request, and state `intended`. The message id is generated once
  and reused by every later look at the same intent.
- **A2A-C5 Intent identity [B7].** Same owner, same `message_id` and same
  request digest name the same local operation: a second call returns the first
  call's journal row and sends nothing. The same `message_id` with a different
  digest is refused locally before anything is sent.
- **A2A-C6 Outcomes and no automatic resend.** The journal row ends in exactly
  one of: `accepted` (a task came back; remote task and context id stored the
  moment the response is read), `answered` (a message came back), `refused` (a
  JSON-RPC error came back; code and reason stored), `outcome_unknown` (the
  request may have reached the remote and no response was read: timeout after
  sending, connection reset, a 5xx without a JSON-RPC body, a stream that broke
  before the task was acknowledged). An `outcome_unknown` intent is never sent
  again automatically, neither by the client, nor by the polling coordinator,
  nor by a reaper retry of the run. The run shows "outcome unknown" with the
  message id; an operator may look the task up or resend explicitly, which
  creates a new intent with a new message id. nr-llm does not rely on
  §3.3.1's optional idempotency.
- **A2A-C7 Client-side ownership [B8].** A stored remote task handle belongs
  to the local actor whose intent created it. Another local user cannot read,
  poll, cancel or continue it through the PHP API or the backend. A remote
  `ListTasks` result never creates a local handle or local ownership; with a
  remote credential shared by several local users, the PHP API returns only
  the remote tasks mapped to the calling local actor.
- **A2A-C8 Limits.** A response larger than the record's maximum response
  bytes is not parsed; the call is `refused` with a local reason. Connect and
  read timeouts are bounded per record (defaults 5 s and 30 s). Redirects are
  not followed automatically; a redirect target passes the guard again or the
  call fails (ADR-202's rule).
- **A2A-C9 CLI example.** `nrllm:a2a:send <agent> <text>` sends one message
  with `return_immediately` true, prints the task id and exits;
  `nrllm:a2a:get <agent> <task-id>` prints the task. Both run inside
  `WorkerVaultScope` so the credential is read as `worker.technicalBeUserUid`.

## Stage 2: runtime integration

- **A2A-R1 Dependency status [B3].** A new `AgentRunStatus` case
  `WAITING_FOR_DEPENDENCY` (`waiting_for_dependency`): the run waits for
  something outside it that no person of the installation decides; a remote
  A2A task is the first such dependency. It is not terminal, it is not in
  `awaitingValues()`, and it holds no lease, so the reaper (which acts on
  RUNNING with an expired lease only) does not touch it. `AgentRunOutcome`
  gains `AWAITING_DEPENDENCY` for the segment that suspends. Public API
  impact: one added case on each of two `@api` enums, **additive** by
  `Documentation/Api/Stability.rst`, but it changes behaviour for any consumer
  that maps "not waiting, not running, not queued" to "settled". The existing
  synchronous signatures stay; new behaviour comes through additive
  interfaces.
- **A2A-R2 Consumers before activation [B3, B26].** Every consumer that
  handles statuses exhaustively learns the new value before nr-llm writes it:
  the inbox, the run list and detail page, `nrllm:agent:status`, the reaper,
  and nr_mcp_agent. At nr_mcp_agent `main` 8e301436,
  `ChatService::reconcile()` treats every status other than the two waiting
  ones, RUNNING and QUEUED as settled and marks the conversation failed ("This
  run finished outside the chat"). nr_mcp_agent sets no `origin` on the runs
  of its chat, so such a run cannot be told apart from others. The rule is
  therefore per installation: while nr_mcp_agent is loaded in a version below
  the compatible one, no delegation tool is offered to any run, and the
  remote-agent module says why. The stage-2 release notes name that version
  under "Upgrading".
- **A2A-R3 Checkpoint [B3].** A suspended segment stores, sealed: the tool-call
  id the dependency answers, the external operation reference (the journal
  uid, and through it the remote task id), the actor, the workspace, the
  deadlines and the budget state.
- **A2A-R4 Single-shot resolution [B3].** A dependency is resolved once. The
  resume answers exactly the stored tool-call id with the remote's result and
  never executes the delegation tool again.
- **A2A-R5 One pending call per segment [B4].** A model turn that calls a
  delegation tool together with any other tool is rejected before any of them
  executes, with a tool error telling the model to call the delegation alone.
  At most one dependency is pending per run segment.
- **A2A-R6 Lease fencing [B5].** Resume claims the run with the atomic claim
  and lease of an approval resume (ADR-141). A worker that lost its lease
  cannot write the run, its checkpoint or its result; of two workers that see
  the same remote result, exactly one resolves the dependency.
- **A2A-R7 Budgets [B6].** The run's deadline, delegation count (against the
  record's limit), transferred bytes and the local token and cost budgets
  persist across suspensions and resumes. The limit counts only delegations
  nr-llm itself makes; a remote agent's own sub-delegations are outside
  nr-llm's control, and the documentation says so.
- **A2A-R8 Delegation tool.** One tool per enabled remote agent and card
  skill, generated from the records alone; listing the tools makes no network
  call. A record that is disabled, has no `data_class`, or whose card is not
  imported yields no tool. The tool is subject to `ToolCallPolicy` like every
  tool (trust zone by `data_class`), to approval when `requires_approval` is
  set (ADR-084), and to egress (ADR-279).
- **A2A-R9 Suspension.** Calling the delegation tool writes the send intent,
  sends with `return_immediately` true, and on `accepted` with a non-terminal
  state suspends the run in `WAITING_FOR_DEPENDENCY`. A message answer or a
  terminal task is a normal tool result and the loop continues without
  suspending.
- **A2A-R10 Polling coordinator.** A scheduler/CLI task polls `GetTask` for
  waiting runs: interval from the record's minimum, doubling to its maximum,
  with jitter; a run whose remote has not settled by the record's maximum wait
  is resumed with a "timed out" tool result and the remote task is cancelled
  (A2A-R12). Polling runs inside `WorkerVaultScope`. A poll that fails at the
  transport level counts as "no news", not as a remote failure, until the
  deadline. Polling stays the repair path when push (5b) is in use.
- **A2A-R11 Resume.** When the remote task reaches a terminal or interrupted
  state, the run continues as the same actor, in the workspace the run
  recorded (ADR-260), with the budgets of A2A-R7; the remote's artifacts become
  the tool result. An interrupted remote state (INPUT_REQUIRED, AUTH_REQUIRED)
  is returned to the model as such; stage 2 does not answer it.
- **A2A-R12 Cancel propagation.** Cancelling a waiting run sends `CancelTask`
  for its remote task (once, no retry beyond the call's own timeout) and
  settles the run CANCELLED whatever the remote answers; the answer is recorded
  in the journal.
- **A2A-R13 Result store [B2].** ADR-282: the result of an A2A **server** task
  is committed in the transaction that completes its run, under the run-row
  lock of ADR-270 (stage 3 uses it). Stage 2 builds the store and the
  transactional write; the client side keeps the remote result in the sealed
  checkpoint until resume. The store follows the run's answer ceiling
  (`RunPrivacyLevelResolver::forRunAnswer()`): `full` keeps the result,
  `redacted` the redacted text. A ceiling of `metadata` or `none` at
  completion stores nothing and ends the run FAILED and the task FAILED with
  `Task.metadata` reason `RESULT_NOT_RETAINABLE`, never COMPLETED without
  artifacts. A run that went through an approval keeps its result. The task's
  `history` (client messages and status messages) is sealed and kept by the
  same rule, and purged with the run.
- **A2A-R14 Event journal [B1].** One A2A-facing journal of task events
  (`tx_nrllm_a2a_event`) is the only source for `GetTask`, `ListTasks`, SSE and
  push. An event is written only after the state it describes is committed,
  never from the live callbacks of `AgentRunExecutor`. It holds only what may
  leave the installation: task state, status message, artifacts as stored by
  A2A-R13. No prompt, no tool argument, no raw provider output, no audit
  artefact.
- **A2A-R15 Idempotent projector [B2].** The journal and the task view are
  written by a projector after the completing transaction. It is idempotent
  per run revision: projecting the same revision twice yields the same events
  once. A projector failure is retried by the worker from the committed state
  and never re-executes the model or a tool.

## Stage 3: server

- **A2A-SV1 Endpoint.** A frontend PSR-15 middleware (class under
  `Service\Agent\A2a\`, ADR-279) answers, below a site's configured A2A base
  URL, the JSON-RPC endpoint and `/.well-known/agent-card.json`. Every other
  request passes through. The base URL is a site setting; there is no default,
  and a site without it answers neither path.
- **A2A-SV2 No Host-header trust.** URLs in the card and in any response are
  built from the configured base URL only. A request whose scheme and host do
  not match a configured base is passed on as not an A2A request.
- **A2A-SV3 Agent card.** `AgentCard` with `name`, `description`, `version`
  (the extension version), `supported_interfaces` holding exactly one
  `{url, protocol_binding: "JSONRPC", protocol_version: "1.0"}`, `capabilities`
  declaring only what the installation has enabled (A2A-P7, A2A-OPS2),
  `security_schemes` [`securitySchemes`] with one `HTTPAuthSecurityScheme`
  (`scheme: "Bearer"`) and a matching `security_requirements` entry,
  `default_input_modes` and `default_output_modes` (`text/plain` until 5c),
  and one `AgentSkill` per publication profile with `id`, `name`,
  `description` and at least one tag. The response carries
  `Cache-Control: max-age=300` and an `ETag` from a hash of the card body, and
  answers `If-None-Match` with 304 (§8.6.1). The public card lists only
  profiles marked public; `GetExtendedAgentCard` is not offered in stage 3
  (`extended_agent_card` absent).
- **A2A-SV4 Publication profile [B11].** A record `tx_nrllm_a2a_profile`
  (`A2aSkillProfile`) publishes one skill and binds: a registered domain
  handler (the PHP service that turns the A2A message into the run's request
  and the run's answer into artifacts), the LLM configuration, the allowed
  tools and resources, the execution identity (the client's technical backend
  user, ADR-280), an optional pinned workspace, the input and output schema and
  modes, quotas, the retention, whether the full text must be checked before
  release (A2A-SSE5) and whether it may write (stage 4). The first stage-3
  capability is read-only: a text check or summary that calls no writing tool.
  A profile whose configuration resolves to an answer level of `metadata` (set
  on its provider or task) or `none` cannot be saved as public, and a call to
  it is refused before a task exists with `-32004` and reason
  `RESULT_NOT_RETAINABLE` (ADR-282), blocking calls included.
- **A2A-SV5 Transactional acceptance [B10].** The dedup record (client and
  `message_id`), the A2A task, the run and the binding between the two commit
  in one transaction; the response is sent only after the commit. If the
  commit fails, no part exists and the client gets `-32603`. One internal run
  per A2A task in this scope; a later turn of the same task (stage 4) is a
  continuation of that run, not a second run.
- **A2A-SV6 Methods.** `SendMessage`, `GetTask`, `ListTasks`, `CancelTask`
  (stage 3); streaming methods 5a; push methods 5b. Per method:
  - `SendMessage` with no `task_id` creates a task in SUBMITTED, generates
    `Task.id` and, if the message has none, `context_id` [`contextId`]
    (§3.4.1). A client-supplied `context_id` is accepted only if it is a
    context this client owns or a new UUID; otherwise `-32602`. A `task_id`
    must reference a task of the caller, else `-32001`; a mismatching
    `context_id` is `-32602` (§3.4.3); a terminal task is `-32004`. A
    non-empty `tenant` is refused with `-32602` on every method, because the
    card's interface declares none.
  - A repeated `message_id` from the same client within a task's retention
    returns the task the first one created and creates nothing (§3.3.1). The
    same `message_id` with a different message is `-32602`.
  - `history_length` [`historyLength`]: unset returns the whole stored history,
    0 omits `history`, n returns at most the n most recent messages in
    chronological order (§3.2.4).
  - `GetTask` returns the task of the caller or `-32001`.
  - `ListTasks` returns only the caller's tasks, filtered by `context_id`,
    `status` and `status_timestamp_after`, `page_size` 1 to 100 (default 50;
    outside the range `-32602`), an opaque cursor in `page_token`. A cursor is
    bound to the principal, the profile set and the filter it was issued for;
    used with anything else it is `-32602` [B11]. `next_page_token` is always
    present and `""` on the last page; `total_size` counts the caller's
    matching tasks only; with `include_artifacts` false or unset the
    `artifacts` member is absent from every task, not empty (§3.1.4).
  - `CancelTask` on a non-terminal task of the caller cancels its run and
    returns the task CANCELED; on a terminal one `-32002`.
- **A2A-SV7 Execution.** ADR-283: an accepted task is a run queued with
  `AgentRuntimeInterface::defer()` as the client's technical backend user.
  The web request never executes the agent loop.
- **A2A-SV8 Blocking [B9].** With `return_immediately` unset or false, the
  request waits for the task to reach a terminal or interrupted state by
  reading the journal; it never answers early with a WORKING task in place of
  the wait. Its own deadline is `a2a.server.blockingWaitSeconds` (default 20,
  maximum 100), which the documentation requires to be lower than the read
  timeout of every proxy in front of PHP-FPM. When that deadline passes, the
  request answers a system error: HTTP 504, JSON-RPC `-32603`, `ErrorInfo`
  reason `BLOCKING_DEADLINE_EXCEEDED` with the task id in its `metadata`. The
  task stays as accepted and is retrievable by `GetTask` and, scoped to the
  client, by `ListTasks`. A proxy that cuts the request earlier produces a
  transport error with the same effect on the task.
- **A2A-SV9 Capacity and worker refusal.** Before creating a task, the
  server refuses with `-32000`, HTTP 503 and `Retry-After`, an `ErrorInfo`
  (`domain` `nr-llm.netresearch.de`) and a `google.rpc.RetryInfo` detail, when:
  no worker can be shown to run (`reason` `WORKER_UNAVAILABLE`: no live
  `messenger:consume` heartbeat and no sweep tick within the last 120 s,
  ADR-283), the client's queued and running tasks reach its limit
  (`CLIENT_CAPACITY_EXHAUSTED`), or the installation's waiting blocking
  requests reach `a2a.server.maxWaitingRequests` (default 4,
  `WAITING_CAPACITY_EXHAUSTED`). Long streams have a capacity of their own
  (A2A-SSE3). Nothing is stored for a refused call.
- **A2A-SV10 Task states.** SUBMITTED while queued, WORKING while running,
  COMPLETED with one artifact per answer, FAILED with a status message that
  names no internal detail, CANCELED, REJECTED when a guardrail refuses the
  input before the run starts. `TaskStatus.timestamp` is set on every change.
- **A2A-SV11 TCK fixture.** In the Testing context only, a fixture profile
  reacts to the TCK's message-id prefixes (`tck-complete-task`,
  `tck-artifact-text`, `tck-artifact-data`, `tck-artifact-file`,
  `tck-artifact-file-url`, `tck-message-response`, `tck-input-required`,
  `tck-reject-task`, `tck-stream-*`, `test-resubscribe-message-id`) without
  calling a model. It is not registered in any other context; a functional
  test asserts its absence in Production.

## Stage 4: multi-turn

- **A2A-MT1 Interrupted states.** A server run suspended in
  `WAITING_FOR_INPUT` maps to INPUT_REQUIRED with the question as the status
  message. AUTH_REQUIRED is used only when the run needs a credential the
  client must supply out of band (§7.6); it is never used for a refused
  permission, which is FAILED. While the task is in AUTH_REQUIRED, a message
  to it is accepted (§7.6.1, TCK `AUTH-INTASK-006`) and recorded like an input.
  A suspension for an approval by a person of the installation stays WORKING:
  §7.6 names human approval as one kind of in-task authorization, but here the
  installation obtains it itself and the client cannot give it.
- **A2A-MT2 Durable decision intake [B12].** A decision (an input, an
  approval, a denial) is taken in by an additive interface that stores it and
  returns a storage receipt; it does not continue the run in the deciding
  request. Before storing, it checks: the pause is still pending and has the
  pause generation the decision was made for; the run's owner and the deciding
  actor; the input against its schema; the approved content against the turn
  digest; the decider's current permission. The run then goes to QUEUED with a
  continuation reference, and the worker checks the rights again before the
  first effect. The inbox's synchronous path (`approve()`, `submitInput()`,
  ADR-276) and the durable path share these checks and the atomic claim:
  exactly one of them wins a pause. ADR-281 amends ADR-276 for this.
- **A2A-MT3 Continuation over A2A.** The client continues with a `Message`
  carrying the task's `task_id` and `context_id`; the server takes it in
  through A2A-MT2.
- **A2A-MT4 Rights on resume.** On every resume the client record, its
  technical backend user, its pinned workspace and the profile are checked
  again; a revoked client or profile fails the task.
- **A2A-MT5 First write capability.** A profile may write only drafts, only in
  the client's pinned workspace, never live (ADR-260, ADR-265). A pinned
  workspace of 0 (live) refuses a writing profile at configuration time.
- **A2A-MT6 A write result names the writes [B13].** The artifact of a writing
  task lists the changes actually executed (table, record uid, workspace,
  field), taken from the write events, not from the model's answer. A completed
  run with no executed write says so; a completed LLM run is not proof of a
  CMS change.

## Stage 5a: SSE

- **A2A-SSE1 Server.** `SendStreamingMessage` and `SubscribeToTask` answer
  `text/event-stream`, one `data:` line per JSON-RPC response whose `result`
  is a `StreamResponse`, written by the extension's own SSE writer under
  `Service\Agent\A2a\` (the backend `NdjsonStream` is not reachable from there,
  ADR-279). Headers: `Cache-Control: no-cache`, `X-Accel-Buffering: no`.
  `ignore_user_abort` stays false: the stream only reads, the worker executes.
  Closing a stream never cancels the task [B17].
- **A2A-SSE2 Snapshot and cursor [B15].** A stream reads the event journal
  (A2A-R14). It takes the task snapshot and its cursor at the same journal
  revision, sends the snapshot as the first event, and then only events newer
  than that revision, in order. The snapshot replaces whatever partial artifact
  the client composed before. A2A gives no replay guarantee: recovery is a new
  snapshot plus `GetTask`; a `Last-Event-ID` header is ignored and creates no
  further contract.
- **A2A-SSE3 Operations [B17].** Long streams have their own capacity, per
  task, per client and per installation (`a2a.server.maxStreamsPerTask`,
  `maxStreamsPerClient`, `maxStreams`); one over is refused like A2A-SV9 with
  `STREAM_CAPACITY_EXHAUSTED`. A stream ends after `a2a.server.streamMaxSeconds`
  (default 300); it sends a comment line at least every 15 s; a frame is at
  most `a2a.server.maxFrameBytes` and the writer buffers at most one frame.
  The behaviour of the PHP-FPM pool and of nginx and Apache with buffering on
  is tested, not assumed.
- **A2A-SSE4 Close and subscribe.** A stream closes per A2A-D5.
  `SubscribeToTask` on a terminal task is `-32004` (proto comment, §9.4.6). On
  a task in INPUT_REQUIRED it sends the task and closes; on one in
  AUTH_REQUIRED it sends the task and stays open.
- **A2A-SSE5 Hold-back for a full-text check [B16].** When a profile requires
  the full text to be checked before release, and by default whenever its
  configuration has an output guardrail, no content fragment leaves the
  installation before the check has passed: the stream carries status progress
  only, and the artifact follows in one event after the check. A failed check
  ends the task FAILED without content. This is a product decision of ADR-283.
- **A2A-SSE6 Client [B14].** The client consumes SSE from a remote agent that
  declares `streaming`: it reads incrementally, checks `Content-Type:
  text/event-stream` and that each JSON-RPC `id` matches its request, and
  parses frames by the WHATWG event-stream rules, including UTF-8 sequences
  and frames split across reads. The remote task reference is persisted as
  soon as the first task event arrives; a stream that breaks before that is an
  unknown outcome (A2A-C6). On a later disconnect it falls back to polling.

## Stage 5b: push notifications

Server role (sender):

- **A2A-PUSH1 Config operations [B22].** `Create`, `Get`, `List` and
  `DeleteTaskPushNotificationConfig`, and a config embedded in `SendMessage`,
  for the caller's own tasks; another client's task is `-32001`. Delete is
  idempotent (§3.1.10). There is no update method: a change is a new config and
  the deletion of the old one.
- **A2A-PUSH2 Credentials [B22].** The first supported scheme is a Bearer
  credential in `authentication`, sent as `Authorization: Bearer`. The optional
  `token` field is stored and returned but never turned into a header (A2A-D8).
  Credentials are stored as sealed references, masked in the backend and in
  audit rows, and never returned in full.
- **A2A-PUSH3 Outbox [B24].** A task event and its delivery jobs, one per
  config, are written in the same transaction (outbox). A worker task
  delivers; no request, the JSON-RPC handler included, ever makes a webhook
  call. Before each attempt the worker reads the config, checks the target
  through the address guard, the credential and the delivery deadline again.
- **A2A-PUSH4 Payload.** HTTP POST of a bare `StreamResponse` (not a JSON-RPC
  envelope), `Content-Type: application/a2a+json` (§4.3.3), HTTPS only,
  timeout 15 s (within §4.3.3's 10 to 30 s).
- **A2A-PUSH5 Delivery outcomes [B24].** 2xx acknowledges. A timeout, 429 or
  502/503/504 retries with bounded exponential backoff and jitter (at most
  eight attempts, then abandoned and logged). 401 or 403 marks the config
  "authentication failed", visible in the backend, and pauses it. A target the
  guard refuses, or 404/410, stops the config. A redirect is not followed
  unchecked: it is a failed attempt. After a config is deleted no new attempt
  starts; a request already in flight may still arrive, which the
  documentation states and a test shows.

Client role (receiver):

- **A2A-PUSH6 Receiver endpoint [B23].** A public endpoint of its own below the
  site's configured A2A base URL accepts callbacks for push registrations
  nr-llm created. Each registration has its own callback credential (random,
  shown to the remote only, stored hashed like ADR-280's tokens) and is
  checked by `Authorization: Bearer`. A wrong or missing credential is 401 and
  stores nothing.
- **A2A-PUSH7 Registration order [B23].** Without an embedded config: send,
  store the acknowledged remote task id, create the local registration, create
  the remote registration, then `GetTask` once to close the gap. With an
  embedded config, the pending local registration is written before the
  message is sent.
- **A2A-PUSH8 Inbox [B23].** An authenticated callback is stored durably in a
  bounded inbox (per registration and in total; over the bound it is 429)
  before the endpoint answers 2xx; 2xx means received, nothing more. A
  callback that arrives before its remote task id is known is kept against the
  pending registration. A stored callback is a signal: a worker reconciles by
  `GetTask` as the owning actor. A duplicate or late body never triggers a
  second execution; polling stays the repair path.

## Stage 5c: files

- **A2A-FILE1 Media types [B18].** Accepted and emitted: `text/plain`,
  `application/json`, `application/pdf`, `image/png`, `image/jpeg`, each with a
  content validator (encoding, JSON parse, PDF header and structure, image
  decode). Archives, HTML and SVG are refused with `-32005`. A declared
  `media_type` [`mediaType`] must match the validator's verdict and the
  profile's input modes, else `-32005`.
- **A2A-FILE2 Inbound limits [B19].** The request size limit (A2A-SEC6), a
  limit on base64 text before decoding and a limit on decoded bytes per part
  and per message (`a2a.maxRawPartBytes`, default 5 MiB) are each enforced;
  larger is `-32602`.
- **A2A-FILE3 url parts [B19].** A `Part.url` is fetched only by the worker,
  through the address guard, under an allow-list of file hosts separate from
  the agent endpoint, with the same size limits. Credentials of the remote
  agent or client are never sent to a file URL.
- **A2A-FILE4 Private store [B19].** Every inbound file lands in a private
  functional store (`tx_nrllm_a2a_file` plus a non-public storage location)
  recording owner, task, origin (raw or url), real size, verified media type,
  sha256, check and approval state, and expiry. The executor checks the
  task's approved input manifest before the first model or tool call. An
  approved url input is the stored copy; the URL is never fetched again as
  "the same" input.
- **A2A-FILE5 Outbound [B20].** A file up to `a2a.maxInlineArtifactBytes`
  goes out inline as `raw`. A larger one goes out as a `url` to a protected
  download: authenticated with the client's bearer token, or a short-lived
  capability URL scoped to one file and one client. A capability URL is a
  transferable bearer right; the documentation says so. Re-issuing a download
  right keeps the task status, artifact id, digest and content unchanged. No
  artifact part carries a URL of the installation's own storage (`fileadmin`,
  a FAL public URL, a backend route).
- **A2A-FILE6 FAL export and adoption [B21].** Exporting a FAL file into an
  artifact checks the client's technical user against the concrete file mount
  (`FalStorageGate::isFileAccessible()`) and the profile's recipient approval.
  Importing an inbound file into FAL is a separate, authorised write of its
  own (target storage, folder, naming, collision rule), by an actor allowed to
  write there. Cleanup deletes only temporary A2A objects, never an adopted FAL
  file.
- **A2A-FILE7 Fresh permissions in long-running workers [B21].**
  `FalStorageGate` caches the storage it builds per user in `$userStorages`,
  keyed by user and storage, with no invalidation. A gate shared across the
  messages of a long-running `messenger:consume` process would keep a user's
  file mounts from its first build. The FAL checks of A2A therefore use a gate
  whose cache is reset per run execution; a test changes a user's mounts
  between two runs in one worker process and asserts the second run sees the
  change.

## Security

- **A2A-SEC1 Inbound authentication.** Every JSON-RPC request needs
  `Authorization: Bearer <token>`. A token names its client record by a public
  prefix and is checked against a stored hash with a constant-time comparison
  (ADR-280). Missing, unknown, disabled or expired: HTTP 401,
  `WWW-Authenticate: Bearer realm="a2a"`, JSON-RPC `-32000` with `reason`
  `UNAUTHENTICATED`. The agent card is the only unauthenticated response.
  Failed attempts are logged without the token and rate-limited per source
  address.
- **A2A-SEC2 Scoping before lookup.** The owner of a task is the client
  record uid. Every query that reads a task, a context, a cursor, a file or a
  push config has the owner in its `WHERE` clause; a task id of another client
  and a non-existent one give the same `-32001` (§3.3.2, §13.1).
- **A2A-SEC3 Identity of the work.** A task runs as the client's technical
  backend user (uid > 0, not admin), in its pinned workspace, and may use only
  the client's allowed publication profiles. A profile outside that set is
  `-32602` with a `BadRequest` naming the skill, never "not found".
- **A2A-SEC4 Egress.** Outbound calls to a remote agent go through an egress
  group of their own (ADR-279), fail-closed like every group (ADR-061), and
  through the address guard: public addresses only, DNS pinned, no own-site
  host, no automatic redirect.
- **A2A-SEC5 SSRF.** Every URL that comes from outside the operator's typing
  (an interface URL from a card, a push webhook, a `url` part) passes the
  guard at creation and again at use. The guard's lists only narrow: no
  allow-list entry admits a private, loopback, link-local or reserved address
  (`ExternalUrlGuard` class docblock). A remote agent or webhook inside the
  installation's own network is therefore unreachable; opening that would
  amend ADR-202 and is out of scope.
- **A2A-SEC6 Size limits.** Request bodies above `a2a.server.maxRequestBytes`
  (default 1 MiB without files) are refused with HTTP 413 before parsing;
  parts per message, history length and artifact size are capped.
- **A2A-SEC7 No resend on an unknown outcome.** A2A-C6 applies to every
  outbound call that can create remote state (`SendMessage`, push create).
- **A2A-SEC8 Untrusted content.** Text from a remote agent is tool output: it
  passes the guardrails as any tool output does and never becomes a system
  prompt. Text from a remote client is user input to the run.
- **A2A-SEC9 Boundary statement (constitution V).** New boundaries: two public
  HTTP entry points into the frontend stack (the server endpoint and the push
  receiver); bearer credentials of remote clients and callback credentials
  stored hashed; credentials for remote agents and push targets stored as
  nr-vault references; provider input that can now come from a remote client;
  provider output that can now leave the installation through `GetTask`, a
  stream, a webhook or a download. None of the existing boundaries
  (vault-held provider keys, provider input guardrails, output redaction) is
  relaxed.
- **A2A-SEC10 Test networks.** The interop and TCK jobs run their partner in a
  private Docker network. They reach it because the Testing context replaces
  the address classifier with one that treats the job's network as public.
  That replacement, like the TCK fixture (A2A-SV11), is registered in the
  Testing context only, and a functional test asserts that Production uses
  the real classifier.

## Rollout and operations

- **A2A-OPS1 Additive rollout [B26].** Each stage ships in this order: schema;
  readers and workers that understand the new states and payload versions;
  the new processing; then activation. A new payload version or state is
  written only once every process of the installation understands it (A2A-R2
  for the status). Rollback means switching off acceptance of new tasks;
  accepted tasks keep their status, cancel and cleanup within their deadlines.
- **A2A-OPS2 Feature switches [B26].** The server role, the client role, SSE
  and push can each be switched off. With SSE or push off, the card stops
  declaring the capability and the methods answer per A2A-P7, while polling
  and result retrieval keep working.
- **A2A-OPS3 Concurrency on a production database [B25].** The concurrency
  tests (acceptance, dedup, claim, single-shot resolution, projector, outbox)
  run on MariaDB as well as on the SQLite of the gate.

## Data model sketch

All new tables are prefixed `tx_nrllm_a2a_`; new classes are prefixed `A2a`
because `Task`, `Skill` and `ToolArtifact` are taken.

| Table | Purpose | Key columns |
| --- | --- | --- |
| `tx_nrllm_a2a_agent` | remote agent (client role) | card URL, card JSON, card ETag, interface URL, tenant, vault credential id, `data_class` (no default), `enabled` 0, `requires_approval` 1, limits |
| `tx_nrllm_a2a_send` | send-intent journal | owner, run uid, agent uid, `message_id`, request sha256, state, remote task id, remote context id, last remote state, error code; unique (owner, `message_id`) |
| `tx_nrllm_a2a_client` | remote client (server role) | title, token prefix (unique), token hash, technical backend user uid, pinned workspace uid, allowed profiles, task limit, enabled, expires |
| `tx_nrllm_a2a_profile` | publication profile | skill id (unique), name, description, tags, examples, domain handler, configuration uid, tools, schemas, modes, quotas, retention, full-text check, public, may write |
| `tx_nrllm_a2a_task` | server task view | task id (UUID), context id, owner client uid, run uid, profile uid, state, status timestamp (ms), status message, artifacts (sealed), history (sealed), journal revision, outcome reason |
| `tx_nrllm_a2a_dedup` | accepted messages | owner client uid, `message_id`, message sha256, task uid; unique (owner, `message_id`) |
| `tx_nrllm_a2a_event` | A2A event journal | task uid, revision, kind, payload (sealed); unique (task, revision) |
| `tx_nrllm_a2a_file` | private file store (5c) | owner, task uid, origin, size, media type, sha256, check state, approval state, expiry, storage location |
| `tx_nrllm_a2a_push_config` | push config, sender (5b) | config id, task uid, owner, URL, token and credential references, state |
| `tx_nrllm_a2a_push_outbox` | delivery jobs, sender (5b) | config uid, event revision, attempts, next attempt, deadline, state |
| `tx_nrllm_a2a_push_registration` | push registration, receiver (5b) | send-intent uid, remote task id, callback credential prefix and hash, remote config id, state |
| `tx_nrllm_a2a_push_inbox` | received callbacks (5b) | registration uid, received at, body (sealed, bounded), reconciled at |

The run checkpoint of A2A-R3 lives in the existing sealed suspension state of
the run, not in a table of its own.

A missing measurement stays distinguishable (constitution VI): a status
timestamp that was never set is NULL, not 0; a remote task id not yet known is
empty, and the journal state says why.

## Out of scope

- HTTP+JSON/REST and gRPC bindings; protocol 0.3 in either role.
- Signing or verifying agent cards (§8.4); the TCK's `CARD-SIGN-001` to
  `CARD-SIGN-004` stay manual.
- An A2A-only chat UI; nr-llm's existing run views show A2A runs.
- A registry or catalogue of remote agents; the operator enters a card URL.
- OAuth 2.0, OpenID Connect or mTLS for inbound clients or push targets;
  bearer credentials only.
- Remote agents, webhooks or file hosts on private addresses (A2A-SEC5).
- Several pending dependencies per run segment (A2A-R5) and several runs per
  A2A task (A2A-SV5).
- A2A v1.1 features (`SendLiveMessage`, `Task.generation`, timeline,
  `StreamError`); A2A-P4 keeps them from breaking the client.

## Verification

| Requirement | Evidence |
| --- | --- |
| A2A-P1 to A2A-P7, A2A-D1 to A2A-D8 | unit `A2aJsonRpcCodecTest` and `A2aErrorMapperTest` against the pinned proto fixture; one case per drift line |
| A2A-S0-2 to A2A-S0-5 | spike report with measured numbers, committed with the stage-0 ADR; interop script output bound to a revision |
| A2A-C1 to A2A-C3 | functional `A2aRemoteAgentRepositoryTest` (defaults 0/1/empty), unit `A2aCardImporterTest` (interface selection, tenant, guard refusal) |
| A2A-C4 to A2A-C8 | unit `A2aClientTest` with a scripted transport: intent committed before the first byte; same id and digest returns the first intent, same id with another digest is refused; timeout after send ends `outcome_unknown`; no second send in any path; oversized response refused; functional `A2aClientOwnershipTest` (another user cannot read or cancel a handle; a remote `ListTasks` creates no handle) |
| A2A-R1, A2A-R2 | unit `AgentRunStatusTest`; API surface snapshot (additive); unit test that no delegation tool is listed while nr_mcp_agent is loaded below the compatible version, and that one is listed when it is absent or compatible; a test per listed consumer for the new value |
| A2A-R3 to A2A-R12 | functional `A2aDelegationTest`: tool listing makes no request; a turn mixing delegation with another tool executes neither; suspend, poll, resume with identity, workspace and budgets preserved; resume answers the stored tool-call id and the delegation tool runs once; a worker whose lease was taken writes nothing; deadline; cancel sends `CancelTask` once |
| A2A-R13 to A2A-R15, ADR-282 | functional `A2aResultStoreTest`: completion and result in one transaction (a forced failure leaves neither); ceiling `metadata`/`none` at completion ends FAILED with `RESULT_NOT_RETAINABLE`; approval-continued run keeps its result; `A2aProjectorTest`: no event before commit, projecting a revision twice yields one event, a projector failure re-projects without a model call |
| A2A-SV1 to A2A-SV10 | functional `A2aEndpointTest` per method and error; `A2aAcceptanceTest` (a forced failure in the acceptance transaction leaves no dedup row, task or run; no response before commit); `A2aOwnerScopingTest` (another client's id and an unknown id are indistinguishable; a cursor used with another filter is refused); `A2aBlockingTest` (no early WORKING answer; deadline answers 504 with the task id, task retrievable); `A2aCapacityTest` (no worker, client limit, waiting limit) |
| A2A-SV11, TCK | CI job: `run_tck.py --sut-host <url> --transport jsonrpc` against the fixture profile; the score is reported, the manual items (`AUTH-TLS-001`, `AUTH-TLS-002`, `AUTH-SERVER-001`, `CARD-CLIENT-001`, `CARD-SIGN-001` to `004`, `BIND-CUSTOM-002`) are listed as open, and no conformance claim is made from the score |
| Interop | CI job from stage 1: `a2a-python` v1.3.0 in Docker as server for the PHP client, and from stage 3 as client against nr-llm |
| A2A-MT1 to A2A-MT6 | functional `A2aMultiTurnTest`; `DurableDecisionTest` (stale pause generation refused; synchronous and durable decision race for one pause, exactly one wins; worker re-checks a revoked permission before the first effect); `A2aWriteProfileTest` (draft in the pinned workspace, live refused, the artifact lists the executed writes and only those) |
| A2A-SSE1 to A2A-SSE6 | functional `A2aSseTest` (snapshot and cursor at one revision, order, close at terminal and INPUT_REQUIRED, open after AUTH_REQUIRED, subscribe on terminal `-32004`, caps, closing a stream leaves the task running, hold-back sends no fragment before the check); unit `A2aSseParserTest` (split UTF-8 and split frames, id and content-type mismatch); manual check behind nginx and Apache with buffering on |
| A2A-PUSH1 to A2A-PUSH8 | functional `A2aPushSenderTest` (owner scoping, sealed and masked credential, no call from the request path, outbox written with the event, each delivery outcome, no attempt after delete); `A2aPushReceiverTest` (wrong credential stores nothing, early callback kept, bounded inbox, duplicate triggers no second execution, reconciliation by `GetTask` as the owner) |
| A2A-FILE1 to A2A-FILE7 | functional `A2aFilePartTest` (each validator, refused types, each limit, guarded fetch without credentials, manifest checked before the first call, no re-fetch); `A2aDownloadTest` (capability URL scope and expiry, re-issue keeps digest); `A2aFalAdoptionTest` (mount check on export, separate authorised import, cleanup spares adopted files, mount change between two runs in one worker process takes effect) |
| A2A-SEC1 to A2A-SEC10 | the tests above; `A2aTestingOnlyServicesTest` (fixture profile and test classifier absent outside Testing); a security review of the inbound surfaces before stage 3 and stage 5b are released |
| A2A-OPS1 to A2A-OPS3 | rollout steps in each stage's upgrade notes; `A2aFeatureSwitchTest` (card and methods per switch, polling unaffected); the concurrency tests in a MariaDB CI job |
| Each stage | `make gate`; red-before tests per behaviour; docs and a `changelog.d` fragment; this specification's row in `specs/README.md` updated with the stage's evidence |

This stage-0 change is documentation only. `make gate` has nothing in it to
check; the repository checks that apply are `composer ci:test:repo`,
`AdrLifecycleTest`, `AdrCodeCitationTest` and the documentation render.
