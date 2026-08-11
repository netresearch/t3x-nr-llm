.. _adr-153:

=================================================================
ADR-153: A run's uuid is the correlation id of everything it does
=================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-058 <adr-058>` (telemetry rows gain a run to belong to), :ref:`ADR-081 <adr-081>` (the persisted run gains a read surface)
:Authors: Netresearch DTT GmbH

Context
=======

An agent run made N provider calls and produced N unrelated traces.
:php:`ProviderCallContext::for()`, :php:`::forConfiguration()` and
:php:`::forService()` each minted a fresh :php:`Uuid::v4()` internally, and a
caller had no way to pass one in — only the raw constructor and the
:php:`with*()` copies preserved an existing id. So a five-round run wrote five
`tx_nrllm_telemetry` rows that nothing tied together, and nothing tied any of
them to the run.

`tx_nrllm_governance_event` was worse: it HAS an `agentrun_uid` column and no
code ever wrote a non-zero value. All three write points passed `0`. The column
was a declaration with no writer, and the comment above its `correlation_id`
sibling claimed the id linked a row "via the run's correlation, to its agent
run" — which was never true, because runs had no correlation.

The read side of a run timeline already existed and was unused:
:php:`AgentRuntimeInterface::events()` and :php:`::status()` are implemented and
authorised (:php:`AiActorContext::mayActOnRun()` with
:php:`ServiceAccountScope::AGENT_READ`) and had zero callers in ``Classes/``.

Decision
========

**No new column: the run's uuid IS the correlation id.**
`tx_nrllm_agentrun.uuid` and `tx_nrllm_telemetry.correlation_id` are both an
RFC 4122 uuid in a `varchar(36)`. A `correlation_id` column on the run would
have stored a second identifier for the same thing and needed a mapping nobody
asked for. :php:`AgentRunReference::correlationId()` states the equality in one
place; the join key already existed on both sides.

**The context is widened, not rewritten, and only where a caller exists.**
:php:`::forConfiguration()` takes an optional ``?string $correlationId``; null —
every caller that has no wider trace — mints per call exactly as before. An
EMPTY string also mints: `''` is the "no trace" marker an unpersisted run leaves
behind, and adopting it would collide every such call into one bucket.

:php:`::for()` and :php:`::forService()` were deliberately left alone. No
agent-run path reaches either — a run drives a configuration — so widening them
for symmetry would add an argument nothing passes, which is the shape this
project refuses. Widen them when a run driving a configuration-less or a
specialized-service call exists; it is the same three lines.

**The run travels on the execution context.**
:php:`ToolExecutionContext` is already built once per run, from the run's actor,
and already reaches the loop, the resume paths and the tool gate through one
parameter. Adding the run there kept :php:`ToolLoopServiceInterface` — three
methods, one of them thirteen parameters long — unchanged.

**The uid travels as pipeline metadata.** `agentrun_uid` is an int a middleware
needs, which is what the metadata map is for (`beUserUid`, `idempotencyKey`,
the cache key). :php:`CallMetadataFactory::agentRun()` produces the key
:php:`GuardrailMiddleware::METADATA_AGENT_RUN_UID`, disjoint from the other
three producers so the `+` merge at every call site keeps working.

**All three governance write points are attributed, and 0 keeps a meaning.**
The tool gate reads the run off the execution context; the guardrail middleware
reads the uid off the metadata; the input-context gate is handed it by the
manager, the same way it is handed the backend user. `0` now means "this
decision did not happen inside a run" — a plain provider call, or a bare
:php:`ToolLoopServiceInterface` consumer driving the loop without persistence —
rather than "the identity was available and dropped".

Of the three, the **input-context gate** is the one that also keeps
``correlation_id = ''`` by construction: it runs BEFORE the
:php:`ProviderCallContext` exists, so there is no trace id to write. Its
`agentrun_uid` is the join key instead.

**The view renders metadata, and only metadata.**
:php:`AgentRunController::showAction()` goes through the runtime — so the
authorisation is the runtime's and an unreadable run is indistinguishable from
an unknown one — and :php:`RunTimelineFactory` widens the released run with the
telemetry rows carrying its correlation and the governance rows carrying its uid
or correlation. What a step contributes is an ALLOW-LIST of non-content payload
keys. :php:`RunStepPrivacyFilter` already drops content at the default level;
the allow-list means an installation running at REDACTED or FULL does not
silently turn this page into a transcript viewer. `suspended_state` and
`queued_request` — stored verbatim, bypassing the filter — are never assigned to
the view; :php:`AgentRuntimeInterface::status()` strips them before the
controller sees them.

**The view is read-only.** No approve, no retry, no cancel. Those exist on the
inbox list, where they are authorised per run and per turn.

**The link is offered only where the read would succeed.** The inbox list is
deliberately wider than the read: an approval-grant holder sees every user's
run, because :php:`AiActorContext::mayActOnRun()` grants the human equivalent of
:php:`ServiceAccountScope::AGENT_APPROVE` and of no other scope (ADR-130). Read
therefore stays owner-or-admin, and offering the row a :guilabel:`Timeline` link
that can only redirect back would be an affordance for an authorisation nobody
holds. :php:`TerminalRunView::$openableByViewer` asks the same
:php:`mayActOnRun()` the controller will ask, so the two cannot drift; widening
the read to the approval grant would be a change to the runtime, not to the
template.

Consequences
============

✓ One run, one trace: its rounds, the synthesis completion, the fallback hops
inside them and the governance decisions taken along the way all resolve from
the run's uuid.

✓ `tx_nrllm_governance_event.agentrun_uid` has a writer and a reader in the same
change. So does the timeline the read surface was built for.

✓ No schema change to `tx_nrllm_agentrun`. The one DDL addition is an index
(`agentrun_uid`, `crdate`) for the query this record introduces.

◐ Streaming is not correlated. It bypasses the pipeline
(:ref:`ADR-062 <adr-062>`) and settles its own telemetry; a streamed run's rows
still carry a per-call id. Nothing in this record blocks it — the dispatcher
takes the same reference — it is simply not wired.

◐ A row written before this change keeps its per-call id and `agentrun_uid = 0`.
Historic runs therefore show their steps but no calls; there is no backfill,
because nothing recorded which call belonged to which run.

✕ The timeline orders by `crdate` (second resolution) with the step sequence as
the tiebreak, so a call and the step that made it land in the right order but
two calls within one second only order by insert order. Sub-second ordering
would need a column the log tables do not have.

Revisit when
============

The streaming path needs the same attribution, or the timeline needs sub-second
ordering — the latter is a column change to two append-only tables and a purge
window's worth of mixed data.
