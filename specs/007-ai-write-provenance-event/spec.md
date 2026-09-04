# A generic AI-write provenance event (#896)

Since ADR-182 the extension knows, as a queryable fact rather than as free-form
JSON inside a tool call's arguments, that run *R* wrote `pages:42`. That fact
was built for one reader — the observed-outcome derivation of ADR-185 — and it
answers a second question this extension has no business answering itself:
which records on this installation were written by an AI, and what the site
should say about that.

## What it must do

- Dispatch a `final readonly` PSR-14 event once per successful editorial write,
  after the write.
- Carry three values: the run's correlation id, the `RecordReference` of the
  written row, and whether that row was created or updated.
- Refuse to be constructed without a correlation id, so a provenance record can
  never point at no run.
- Be `@api`, with its stability guarantee documented where consumers look for
  it, and be reachable by an ordinary `event.listener` tag from another
  extension.

## What it explicitly does not do

- **No listener ships here, and none is planned.** An Article 50 transparency
  label, a badge in the page module, a line in an existing editorial audit trail
  and a nightly compliance report are four artefacts with four owners. Which of
  them a site wants is the site's decision.
- **No record payload.** Not the field values, not a before/after, not a rendered
  excerpt, not the tool's name. A payload is a copy; a copy of editorial content
  is a second place for it to leak from and a second place for it to go stale.
- **No actor.** Which backend user the run acted for is already persisted once,
  on the run. A listener resolves it by the correlation id.
- **No deletion case** on the kind enum. No builtin deletes, and a value nothing
  emits is a value nothing can be tested against.
- **No configuration.** There is nothing to configure about stating a fact.

## Two decisions worth recording, because both had a plausible alternative

**The kind is a new enum rather than `ToolEffect`.** `ToolEffect` classifies a
write by whether repeating it is safe, because its reader is the at-least-once
queue. A consumer deciding what to say about a record needs to know whether the
record was brought into being or changed. The axes cross —
`move_content_element` is an idempotent UPDATED, `attach_file_to_content_element`
a non-idempotent CREATED — so neither is derivable from the other, and the tool
declares both.

**The kind is a required parameter of `withWriteTarget()`, not an optional one.**
That makes a method on the frozen surface change incompatibly, four months
before 1.0 and one release after it shipped. The alternative was a default,
which would be this extension guessing what a tool did; the tool is the only
party that knows whether it minted the uid or was handed it. The requirement
also buys the invariant the dispatch site rests on: a target and a kind are set
by one method and by no other, so "a target without a kind" is not a state
`ToolResult` can be in.

## Where it is dispatched

`ToolLoopService::invoke()` — the single call site of `ToolInterface::execute()`
in this extension. "Exactly once per successful write" is then a property of the
code's shape rather than of seven writers remembering, and it stays true for the
eighth.

Two consequences of that placement, stated rather than discovered:

- It fires **before** the run trace persists the step for the same call, so a
  listener must not join the trace for this write.
- A listener that throws is logged and **does not fail the run**. The write has
  already landed and a human has already approved it; letting a consumer's label
  or report take the run down would turn a completed editorial write into a
  failed one, and the model's next move on a failed write is to try it again.
  This is the one place in the loop where foreign code runs after the side
  effect, so it is the one place that swallows — swallows, not hides: the full
  `Throwable` goes to the log.
- Every editorial write reaches it through the **resume** path, because
  `ToolApprovalRule::requiresApproval()` makes every write-effect tool
  approval-bound. Both the first-pass and the resume path go through `invoke()`,
  which is why the choke point was chosen over the four call sites around it.

## Which suite proves each requirement

| Requirement | Proof |
| --- | --- |
| Dispatched once, with record and kind, for a successful write | `ToolLoopServiceWriteEventTest::aSuccessfulWriteIsAnnouncedOnceWithItsRecordAndKind` — drives suspend → approve → resume, the only path a write takes |
| A tool that wrote nothing announces nothing | `ToolLoopServiceWriteEventTest::aToolThatWroteNothingAnnouncesNothing` |
| A write with no persisted run announces nothing | `ToolLoopServiceWriteEventTest::aWriteWithoutAPersistedRunIsNotAnnounced` |
| The dispatcher is optional and its absence is a silent no-op | `ToolLoopServiceWriteEventTest::aLoopWithoutADispatcherStillPerformsTheWrite` |
| A throwing listener does not fail the run | `ToolLoopServiceWriteEventTest::aThrowingListenerDoesNotFailTheRun` |
| The event refuses a write it cannot attribute | `AfterAiRecordWrittenEventTest::itRefusesAWriteItCannotAttribute` and `::itRefusesABlankCorrelationId` |
| The payload stays three values | `AfterAiRecordWrittenEventTest::itCarriesNoRecordPayloadBeyondTheReferenceAndTheKind` — reflection over the properties, so a fourth fails here |
| Kind and target travel together or not at all | `ToolResultTest::theWriteKindTravelsWithTheTargetAndOnlyWithIt` |
| The surface change is deliberate | `ApiSurfaceSnapshotTest` — the regenerated `api-surface.txt` diff is the review artifact, and the closure rule forces `WriteKind` to be `@api` too |
| No listener ships | `grep -rn 'AfterAiRecordWrittenEvent' Configuration/Services.yaml` finds nothing; the class is referenced only by the loop and the tests |

## Not in scope

The seven builtin writers keep their current previews, approval behaviour and
result text unchanged. Nothing about the observed-outcome derivation changes:
that reads the persisted write target, not this event.
