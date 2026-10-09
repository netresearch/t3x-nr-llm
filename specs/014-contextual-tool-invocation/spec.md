<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Contextual policy before every tool invocation

Decision: ADR-216. Existing offerability is necessary but cannot decide which
arguments, target or sequence of operations is allowed. This specification
adds an installation-extensible PHP gate without an external policy service.

## What it must do

- Preserve every `ToolCallPolicyInterface` signature and existing gate.
  The invocation decision is an additional AND, never an override.
- Expose a readonly context containing tool name, actual arguments, resolved
  target (including an explicit unknown state), actor, configuration, run
  reference and observed prior invocations. Rules return a typed decision
  with a stable rule identifier and bounded, log-safe reason.
- Allow installation rules through a DI tag and a single policy facade.
  No rules preserves current behaviour. Duplicate rule identifiers fail
  registration; a rule exception or missing required fact denies the call.
- Resolve the interpretation of a target through a tool-aware resolver.
  Do not assume all tools call a field `uid`, or that a model-supplied URL or
  label establishes the resolved resource. Target-dependent rules refuse
  an unknown target. Argument-dependent rules can examine requested values.
- Evaluate immediately before each tool execution and before consuming a
  remote-call budget slot. Do not evaluate only while offering tools or at
  a controller boundary. A deny reaches neither the tool nor its transport.
- Use the same gate on the synchronous path, queue worker, approval resume
  and typed-input resume. Input resume evaluates the arguments after the
  submitted values were merged. Live availability and actor checks still
  apply; approval cannot bypass the invocation gate.
- History comes from runtime observations, includes earlier siblings in the
  same turn and distinguishes success, failure, cancellation and denial.
  Prompt truncation does not discard that history. An unsuccessful write is
  not assumed to have left its target unchanged.
- Preserve history across suspension and rehydrate it before any pending
  call executes. Old states without the metadata are recognised as incomplete,
  not as proven empty. A rule requiring complete history fails closed unless
  authoritative stored observations can restore it.
- Record the rule identifier and bounded reason through ordinary tool/run
  governance paths. Do not persist an additional copy of argument values,
  target content, credentials or transcript text for policy decisions.

## What it explicitly does not do

- No OPA, network policy call, Rego editor or second agent loop.
- No weakening of existing approval, forced-skill, trust-zone, pins, schema,
  live-user or resource-permission checks.
- No generic claim that arbitrary arguments can identify a tool's target.
- No direct MCP or builtin execution path outside `ToolLoopService`.

## Public surface and security boundaries

Additive context, target/history value objects, policy decision, invocation
rule and target-resolver interfaces. `ToolCallPolicyInterface` and
`ToolInterface` stay unchanged. Published constructors retain their existing
positional arguments and only gain optional trailing dependencies if needed.
The API snapshot is regenerated for additive signatures; no existing one is
silently removed. Configuration is by tagged PHP services, not raw user code
entered in a backend form. The old policy runs before the new one so a caller
already denied does not learn sensitive target or rule facts.

## Which suite proves each requirement

| Requirement | Suite and intended contract |
|---|---|
| Existing gates remain necessary; no rules preserves behaviour | unit `ToolInvocationPolicyTest`, existing `ToolCallPolicyTest` |
| Rule identifier collision, exception and unknown required target deny | unit `ToolInvocationPolicyTest`, `ToolTargetResolverTest` |
| Argument/target rules see the actual proposed invocation | unit `ToolInvocationPolicyTest` with two calls of the same tool |
| Denied call never executes or consumes remote budget | unit `ToolLoopInvocationPolicyTest` |
| Earlier siblings and actual outcomes are visible | unit `ToolLoopInvocationPolicyTest` |
| Context truncation does not erase prior execution | unit `ToolLoopInvocationPolicyTest` with bounded model transcript |
| Fresh, queued, approved and input-resumed paths enforce equally | functional `AgentRunInvocationPolicyTest`; unit loop contracts |
| Live rule/actor changes after suspension deny before execution | functional `AgentRunInvocationPolicyTest` |
| Final human input, not stale proposed args, is evaluated | unit input-resume invocation contract |
| New suspend metadata roundtrips; legacy incomplete history is explicit | unit `SuspendedRunStateTest`, invocation-history tests |
| Denial attribution without copying payloads | functional governance event contract |
| Any new history/argument bounds reject instead of silently widening | unit plus corresponding fuzzy bound twins |
| Public signatures and ADR reciprocal links | unit `ApiSurfaceSnapshotTest`, `AdrLifecycleTest`, `AdrReferenceIntegrityTest` |

The final implementation gate is `make gate`. Fixture tools and fake rules
prove policy behaviour; no live model or production tool call is required.
