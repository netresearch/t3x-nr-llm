# An approval binds to the state its preview showed (#887, #888)

ADR-136 stores what a pending write WOULD do and shows it on the approval card.
It stores nothing that ties the write executed after the approval to the state
the approver read. ADR-184 decides that it should, and names why the question is
open again: `attach_file_to_content_element` appends, so its preview line
`field image: 2 reference(s) → 3, appended last` describes operands of the write
rather than decoration on it.

## The mechanism, once the code is read

**The persisted preview IS the fingerprint.** `SuspendedRunState::$callPreviews`
already holds the bounded lines every previewing tool produced at suspend
(ADR-136). Nothing new has to be stored: at resume the loop re-runs
`previewCall()` for the approved call, applies the same bounding, and compares
the lines with the ones the approver was shown.

That is why this specification does not build the identity-plus-fingerprint pair
ADR-184 sketched. The lines ARE what was shown, so comparing them is the literal
form of the record's own title, and it needs no hash, no new persisted field, no
`SuspendedRunState` migration and no second contract on tools. A workspace,
language or deletion mismatch does not need its own vocabulary either: the tools
already answer such a call with their neutral refusal string, so the mismatch
arrives as a changed preview and the fresh card states it in the tool's own
words.

**ADR-184 is `Proposed`.** The change that implements this rewrites its Decision
section to describe what was built, and accepts it — per the lifecycle in
`Adr/Index.rst`, both are the accepting change's job.

## What it must do

1. **Re-preview at resume, before the approved call executes**, in the RUN's
   actor context (ADR-083) — the same context the imminent `execute()` uses,
   never the approver's.
2. **Compare bounded lines to bounded lines.** Equal, the call runs exactly as
   today.
3. **Refuse without mutating.** Different, and no write happens: the run
   re-suspends for approval carrying the FRESH preview, so the approver decides
   against the current state.
4. **Say why on the card**, in words an editor understands: the record changed
   since the preview, please review it again.

## What it must NOT do

- **Not fence on the record.** Only the displayed lines are compared. A field
  the preview never showed can change freely — fencing on state nobody reasoned
  about is what ADR-136 correctly refused.
- **Not use `tstamp`.** A hook moves it without touching a relevant field, and a
  FAL or relation write is not covered by a record timestamp at all.
- **Not extend ADR-132's turn digest.** That binds the decision to the tool
  CALL, exactly, because the loop owns both sides. This binds to a resource
  TYPO3 owns and people edit; merging them makes the exact one lossy.
- **Not touch who may approve.** ADR-130, ADR-133 and ADR-172 keep that.
- **Not become a quality signal.** ADR-176 keeps approval and outcome apart.

## Five decisions, stated rather than discovered

**1. The excerpt boundary is the fence boundary.** A preview shows a
120-character excerpt per field, bounded again to 20 lines of 500 characters.
A change beyond what the excerpt displayed does not block. That is consistent
rather than a gap: the fence binds what was SHOWN, and the tail was not shown.
It is named here so nobody has to rediscover it from a passing test.

**2. The fence applies to runs suspended before it ships.** Those states already
carry `callPreviews`, so there is nothing to migrate and no reason to exempt
them. Safe because refusing is not a dead end here: the run re-suspends with a
fresh preview. This is the opposite of the "resumes unfenced" carve-out an
earlier draft of this spec assumed, and the reason is the repair path.

**3. Failed previews, asymmetrically.** A preview that FAILED at suspend
(`failed: true`) showed nothing, so it binds nothing and the call resumes as
today. A re-preview that throws at resume cannot be compared, so it refuses and
re-suspends. Unknown is not equality — constitution principle VI.

**4. A preview must be deterministic, and that becomes a written contract.**
A volatile line — a timestamp, an unordered list — would stale every approval
forever. `ToolPreviewInterface` gains the sentence; the seven current writers
were checked and none reads a clock or a random source inside `previewCall()`
(the one `uniqid()` in the set sits in `execute()`, building a DataHandler NEW
placeholder).

**5. The bounce is a state on the suspension, not a terminal outcome and not a
new event kind.** Mechanically the resume path throws
`ToolApprovalRequiredException` again with a rebuilt state, so the run goes back
to WAITING_FOR_APPROVAL carrying the fresh previews. `SuspendedRunState` gains
one field — the indexes of the calls whose preview changed — so the card can
mark them and say why. It degrades to `[]` like `callPreviews` does.

The notice deliberately does NOT ride as an extra preview line. It would then be
part of the next comparison, and a second approval against an unchanged record
would find the notice missing and bounce again, forever. Keeping it out of the
compared lines is what makes the loop terminate.

It is NOT a new persisted event kind either: `AgentEventKind` is already missing
`dropped` and `tool_write` (#900), and adding a third unlisted kind would deepen
a drift this specification has no business deepening.

**6. Every pending call is checked before ANY of them executes.** A turn is
approved as a whole (ADR-132), and the resume path executes its calls in a
loop.
Checking inside that loop would let call one mutate before call two is found
stale — a partial write against a state nobody approved, which is the exact
failure this exists to prevent. The check is a pre-flight pass over the whole
turn.

## Which suite proves what

| Requirement | Suite | Assertion |
|---|---|---|
| Unchanged preview → the write executes | functional | approve after no edit; the record carries the new value |
| Changed preview → no mutation | functional | approve after an external edit; the record is untouched and the run is WAITING_FOR_APPROVAL again |
| The append case | functional | attach a second reference between suspend and approval; the approval refuses and the fresh preview shows the new count |
| Change to an undisplayed field → no block | functional | edit a field the preview never showed; the write executes |
| The fresh preview replaces the stale one | functional | the re-suspended state's `callPreviews` hold the CURRENT lines, not the old |
| Suspend-time failed preview → no binding | unit | `failed: true` skips the comparison |
| Resume-time preview throws → refuses | unit | no execute, re-suspend |
| Bounded compares to bounded | unit | a >20-line preview does not stale on its own overflow marker |
| Re-preview runs in the run's actor context | unit | the context passed to `previewCall()` is the run's, not the approver's |
| Two approvals racing one suspended run | functional | one wins the guarded transition, the other is refused; no double write |
| A stale call in a multi-call turn blocks the whole turn | functional | the turn's other call does not execute either |
| The re-suspended state marks which call went stale | unit | the index list names it, and is `[]` on a first suspension |
| The notice is not part of the comparison | functional | approve the re-suspended run against an unchanged record; it executes rather than bouncing again |
| The card states why | functional (e2e-backend) | the approval view renders the stale notice |

## Public surface

Additive, and this section was wrong when it was first written. It said
`SuspendedRunState` is not on the frozen surface; it is — ADR-127's closure rule
pulls in every type an `@api` signature mentions, and the approval path mentions
this one. The optional parameter and the property it adds are additive for
callers, so `Tests/Unit/Api/api-surface.txt` is regenerated in the same change
and the addition is announced under `### Added`.

`ToolPreviewInterface` gains documentation, not a method, so it does not move.

## Security boundary

Unchanged. The comparison reads what ADR-136 already persisted inside the
encrypted blob (ADR-114), and the re-preview runs under the run's acting user
with the tool's own authorisation — the same read ADR-136 authorises twice, once
at production and once for the viewer.

## Gate

`make gate`. The card assertion rides the Playwright suite, which the gate does
not run; CI does.
