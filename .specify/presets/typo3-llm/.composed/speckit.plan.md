---
description: Create a plan and store it in plan.md.
---


## User Input

```text
$ARGUMENTS
```

## Outline

1. Read `.specify/feature.json` to get the feature directory path.

2. **Load context**: `.specify/memory/constitution.md` and `<feature_directory>/spec.md`.

3. Create an implementation plan and store it in `<feature_directory>/plan.md`.
   - Technical context: tech stack, dependencies, project structure
   - Design decisions, architecture, file structure


## TYPO3 extension obligations — plan stage

The plan is where the repository's own rules get consulted. Read them; do not reconstruct them from the specification or from memory of a similar project.

### Read before planning

1. **The root `AGENTS.md`**, and then **every scoped `AGENTS.md` that governs a path this change touches**. The closest one wins for its scope. A plan that contradicts one of them is wrong by construction, not merely unconventional.
2. **The existing architecture decision records.** Search them for the area being changed. A plan that contradicts an accepted record either names the record it supersedes or is not the plan. Check the *status* field too — a record can be `Superseded` and still be the first hit.
3. **The frozen public API surface**, where the project keeps one. Read whether the classes involved are marked as public API, then state in the plan whether the change is additive or breaking. Answering this here is the whole point of asking it here.

### State in the plan

- **What already exists.** Before designing a column, a service or a signal, check whether it is there. A requirement that is already satisfied is worth a line saying so — planning it again is how a feature grows a duplicate of its own data.
- **Which convention you are following, and where it comes from.** When a table already distinguishes "not measured" from zero, follow that; when it forbids it structurally (a `NOT NULL` column with a default), say so and put the new value somewhere that can carry the distinction. Cite the file you read it in.
- **The compatibility consequence** per version in the range: what differs across the TYPO3 and PHP cells, and which cell is the risky one.
- **Every obligation that follows**, each as a thing that must happen and not as a hope: an ADR when the surface moves, the API snapshot, translations for a new label in *every* shipped language, the documentation page, the changelog entry.
- **Where the project's gates cannot see this change.** Name it. A plan that lists a green gate the change never reached is worse than one that admits the gap.

### One rule about accepted costs

Where the plan chooses one option and the other would have been better in some respect, write the price down in the plan. A trade-off recorded here is a decision; the same trade-off discovered during implementation is a surprise, and it gets resolved by whoever hits it, at the worst moment, without the context that produced it.


## AI feature obligations — plan stage

The base preset already told you to read the repository's own instructions and its decision records. These are the additional things an AI plan has to settle, and each is a place where a plausible design turns out to be wrong.

### Capability and fallback

- **Which providers actually have the capability**, checked against their adapters rather than their marketing. State it per provider, because "most support it" is what produces the bug report.
- **Where the fallback decision is enforced.** If the project has a middleware or chain for this, the plan goes through it; a feature that calls a provider directly to "keep it simple" bypasses retries, ordering and error mapping and will be found later by someone else.
- **What the caller receives when every candidate lacked the capability.** An exception, a null, a degraded answer — and whether that is distinguishable from a provider error.

### Boundaries

- **Where untrusted output crosses into something that interprets it.** Name every such point, not the first one: a sanitiser at the request boundary does nothing for the response boundary, and fixing one of two is how the same defect ships twice.
- **What is logged.** Prompts and completions carry whatever the user pasted. Say what is written to the log and what is deliberately not, and check the project's existing sanitiser rather than writing a second one.

### Measurement

- **Where a recorded value can be absent, say which column carries the absence.** A `NOT NULL` column with a default cannot express "not measured", so either the value goes somewhere that can, or the plan states the accepted loss in writing. Check the surrounding table first: if it already keeps unmeasured values as `NULL`, follow that convention rather than inventing one.
- **What the value is derived from**, and what happens when one input of the derivation is missing. Deriving from a substitute produces a number that looks measured and is not.

### Testing a non-deterministic feature

- **Name the assertion shape** the tests will use, and where the boundary between a unit test and a test that needs a real provider lies. If a suite in this project mocks providers, say which behaviour is therefore untested by it.
- **Say what a recorded fixture proves and what it does not.** A captured response pins your parsing; it says nothing about the provider still behaving that way.
