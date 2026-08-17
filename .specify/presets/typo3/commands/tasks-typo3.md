## TYPO3 extension obligations — task stage

Each item below is its own task with its own checkbox. They are not a "polish" phase: every one of them is a merge gate somewhere in a TYPO3 extension, and folding them into "implement the feature" is how they get skipped.

### Order

1. **A failing test per requirement, before the implementation.** Each one must fail for the reason it names — not because a column or class is missing yet. State which suite it belongs to, because a test in a directory no suite lists runs in no job and reads as coverage.
2. **The implementation**, smallest coherent steps.
3. **The obligations below**, each separately.
4. **The project's own gate**, last, named by its actual command.

### Obligations that are always their own task

- **Regenerate the public API snapshot** when the surface moved, and check the diff is the kind the plan predicted. If it is not additive where the plan said additive, stop and decide — do not accept the snapshot to make the test pass.
- **Write the ADR** when the surface moved or an architectural decision was taken, in the project's own format and naming, and register it in whatever index the project keeps. An ADR that exists but is not indexed is not findable.
- **Translations for every shipped language**, not only the default one. A new label with an English entry and no German one ships a key to the user.
- **The documentation page**, named. Plus screenshots where the project keeps them, taken against a populated instance rather than an empty one.
- **The changelog entry**, appended under the existing heading for its category. Check whether that heading is already there before adding one — inserting before the first heading of a section is how a second `### Changed` appears with the original forty lines below it.
- **A schema or upgrade step** where an installed instance has to act, and a note in the release documentation saying so.

### Two things that are tasks only when they apply, and are easy to forget when they do

- **A contract change** — a clamp, a validation range, a coercion — usually has assertion twins in a property or fuzz suite. Grep for them; changing the contract without them leaves the suite asserting the old behaviour.
- **Anything that turns dead code live.** The blast radius is larger than the diff, and the static check that flagged it cannot see it. Run the suite that exercises the now-reachable path.

### Closing task

Verify the outcome against stored state, not only against a passing test. Where the change was about what gets recorded, query the recorded rows and confirm the population looks the way the specification demanded. A green test proves the path; it does not prove the data.
