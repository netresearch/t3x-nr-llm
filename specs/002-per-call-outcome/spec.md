# Per-call outcome signal (#772)

ADR-156's second activation criterion asks whether quality degrades on a
population served by a cheaper model. It cites ADR-060, which is the golden-set
layer: a value per **model**, produced out of request by a CLI command nothing
schedules. In a canary both arms are the same model-level value, so the
criterion cannot be evaluated. This adds the missing per-call signal.

## What it must do

1. Record an outcome per call, keyed on `correlation_id` — the key ADR-174
   already writes cost and pre-routing facts against.
2. Carry two sources and keep them apart:
   - **explicit** — a backend user rates the result;
   - **observed** — what happened to the generated text afterwards.
3. Be readable grouped by model and canary arm, so ADR-156 criterion 2 becomes
   computable.

## What it must NOT do

- **Not treat approval or refusal as quality.** An approval can be withheld for
  governance, policy or content reasons that say nothing about the model.
  Folding the approval gate into this metric would measure governance
  behaviour. #772 names this and it is the reason the signal is separate.
- **Not carry a person.** No `be_user` column on the outcome row, and no
  per-editor readout anywhere in nr_llm. Erasure rides on the installation's
  `be_users` lifecycle; nr_llm builds no anonymisation of its own, because that
  is the framework's job and duplicating it creates a second truth.
- **Not mix the two sources in one number.** See the constraint below — they do
  not cover the same population, so an average over both would describe neither.
- **Not block, delay or alter a send.** Observation only, like ADR-174.

## The constraint that shapes it, verified in the code

The two sources reach different traffic, and this was checked rather than
assumed:

| Path | Write target | Signal available |
|---|---|---|
| `AiTaskController` — task execution | none; the result is rendered into `Backend/AiTask/Execute` and the editor copies it by hand | explicit only |
| `EditorActionController` — editorial actions | `recordTable` / `recordUid`, written through the DataHandler by one of the five write tools | explicit **and** observed |

So "observed" is not a cheaper substitute for "explicit" that happens to be
available everywhere. It is available on one path. A readout that averages both
would compare a population that can be observed against one that cannot, and
the difference between the arms would carry that asymmetry rather than the
model's quality.

## Which suite proves what

| Requirement | Suite |
|---|---|
| Outcome row writes and reads back, grouped by model and arm | functional, repository test |
| The row carries no `be_user` and the table has no such column | functional, asserted against the schema rather than the writer |
| Explicit and observed never fold into one figure | unit, on the readout |
| No approval or governance state reaches the outcome | unit, and an inventory test over the writers in the ADR-130 style |
| Nothing on the send path calls the writer | unit, on the send path |

## Open, deliberately

Whether the observed signal is also derived for a suspended-then-approved
editorial write. The preview is captured at suspension (ADR-136), so a
comparison is possible in principle; it is left out of the first change because
the approval decision sits between, and the "approval is not quality" rule is
easiest to keep if the two never meet in one code path.
