# Observed editorial outcome (#772)

Spec 002 shipped the explicit half of ADR-176 and left one thing open in
writing:

> Whether the observed signal is also derived for a suspended-then-approved
> editorial write. […] it is left out of the first change because the approval
> decision sits between.

ADR-182 then made the write target persistent and ADR-185 decides how to read
it. This is that reader.

## The three facts that shape it, measured rather than assumed

**The correlation id is the run's uuid.** `tx_nrllm_agentrun` has no
`correlation_id` column; `AgentRunReference::correlationId()` returns the uuid,
and every provider round-trip of the run reports under it. So an observed
outcome is per RUN. ADR-176's "per-call" is wider than what this can deliver,
and the readout must not claim otherwise.

**`CallOutcome` has no UNKNOWN.** Absence of a row cannot stand in for it,
because absence also means "the window has not closed" — the constitution's
sixth principle forbids one storage state holding two facts.

**The write target names a record, not fields.** ADR-182 kept it to table and
uid. Enough to ask whether the record changed; not enough to ask whether our
text changed.

## What it must do

1. **Find the writes.** Persisted `tool_write` steps, each with its table, uid,
   event timestamp and — through the run — the correlation id.
2. **Wait out the window** (configurable, default seven days) before deciding.
   A write whose window is still open produces no row.
3. **Classify from `sys_history`**, over rows strictly after the write's own:
   no row and the record present → `ACCEPTED_UNCHANGED`; a `MODIFY` → `EDITED`;
   a `DELETE`, or the record gone → `DISCARDED`; the write's own row missing
   (history purged) → `UNKNOWN`.
4. **Write one outcome row per write**, once. A second run of the command over
   the same write changes nothing.
5. **Run out of request**, from a schedulable CLI command, like the four purge
   commands this extension already ships.

## What it must NOT do

- **Not read approval state.** ADR-176 keeps approval and quality apart, and
  ADR-184 added a refusal that is explicitly not a signal. A stale-refused
  approval produces no outcome, because no write happened — not an outcome that
  says something went wrong.
- **Not carry a person.** No `be_user` on the row and no per-editor readout, as
  spec 002 already fixed.
- **Not infer ACCEPTED_UNCHANGED from missing data.** That is what `UNKNOWN` is
  for, and it is the single most likely way this signal could lie.
- **Not touch the send path.** Observation only, like ADR-174.
- **Not fold into ADR-184's comparison.** Same word — changed — different
  question and different data.

## Named limitation

`EDITED` means "a human modified the record after our write, inside the window",
not "a human changed the text we generated". An editor fixing an unrelated field
on the same page makes our write read as EDITED. ADR-185 records the trigger for
fixing it — a measured, high EDITED rate — and the fix, which is widening the
write target to name fields. It is not done here because the reader can begin
without it and the number is what justifies the change.

## Which suite proves what

| Requirement | Suite | Assertion |
|---|---|---|
| No history after the write, record present | functional | `ACCEPTED_UNCHANGED` |
| A modification after the write | functional | `EDITED` |
| A deletion after the write | functional | `DISCARDED` |
| The record is gone without a delete row | functional | `DISCARDED` |
| The write's own history row purged | functional | `UNKNOWN`, never `ACCEPTED_UNCHANGED` |
| A window still open | functional | no row at all |
| The write's own row is not counted as a later edit | functional | `ACCEPTED_UNCHANGED`, not `EDITED` |
| Running twice writes once | functional | one row, unchanged on the second pass |
| Nothing reads approval state | unit | the deriver has no approval collaborator |
| `isImplemented()` turns true for the three observed cases | unit | and stays false for nothing |
| The window is configurable and has a floor | unit | a zero or negative setting clamps, as `privacy.retentionDays` does |

## Public surface

`CallOutcome` is `@internal` (ADR-127), so a new case does not move the frozen
surface. The deriver and its command are new and stay `@internal` until a
consumer asks otherwise. `api-surface.txt` is expected to be unchanged.

## Security boundary

Unchanged. `sys_history` is read for `tablename`, `recuid`, `tstamp` and
`actiontype` only — never `history_data`, which carries the old and new field
values and would put record content into a table that holds none. The deriver
runs out of request under no backend user, which is why it reads metadata and
decides nothing a user could not have decided about their own record.

## Gate

`make gate`.
